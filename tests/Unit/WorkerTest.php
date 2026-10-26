<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Core\Settings;
use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Worker;
use EGMail\Repositories\Queue_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour le Worker WP-Cron.
 */
final class WorkerTest extends TestCase {

	private Queue_Repository $repository;
	private Attachment_Spooler $spooler;
	private Settings $settings;
	private Worker $worker;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['eg_test_transients'] = [];
		$GLOBALS['eg_test_options']    = [];
		$GLOBALS['eg_test_crons']      = [];

		$this->repository = $this->createMock( Queue_Repository::class );
		$this->spooler    = $this->createMock( Attachment_Spooler::class );
		$this->settings   = new Settings();
		$this->worker     = new Worker( $this->repository, $this->spooler, $this->settings );
	}

	/**
	 * Vérifie le comportement du Mutex : impossible de poser deux verrous simultanés.
	 */
	public function test_mutex_concurrency_lock(): void {
		$this->assertTrue( $this->worker->acquire_lock() );

		// Deuxième tentative : doit échouer car le verrou est actif.
		$this->assertFalse( $this->worker->acquire_lock() );

		// Libération du verrou : nouvelle acquisition possible.
		$this->worker->release_lock();
		$this->assertTrue( $this->worker->acquire_lock() );
		$this->worker->release_lock();
	}

	/**
	 * Vérifie que le Worker s'arrête immédiatement si le quota effectif est 0.
	 */
	public function test_process_queue_stops_when_effective_quota_is_zero(): void {
		// Quota horaire saturé : 100 max et 100 envoyés.
		$this->settings->update( [ 'quota_hour' => 100 ] );
		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_hour' )
			->willReturn( 100 );

		// fetch_eligible_batch ne doit JAMAIS être appelé.
		$this->repository->expects( $this->never() )
			->method( 'fetch_eligible_batch' );

		$sent = $this->worker->run();
		$this->assertSame( 0, $sent );
	}

	/**
	 * Vérifie le coupe-circuit temps réel intra-boucle : un lot révoqué est ignoré sans envoi.
	 */
	public function test_circuit_breaker_skips_aborted_batch(): void {
		$aborted_batch_id = 'batch_canceled_123';
		Worker::abort_batch( $aborted_batch_id );

		$item = new Queue_Item(
			id: 10,
			batch_id: $aborted_batch_id,
			dedup_hash: 'hash_abc',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'dest@example.com',
			subject: 'Campagne annulée',
			message: 'Ne pas envoyer',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			created_at: '2026-10-25 10:00:00',
			scheduled_at: '2026-10-25 10:00:00',
		);

		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_hour' )
			->willReturn( 0 );

		$this->repository->expects( $this->exactly( 2 ) )
			->method( 'fetch_eligible_batch' )
			->willReturn( [ $item ] );

		// update_status vers PROCESSING ne doit pas être appelé pour cet item annulé.
		$this->repository->expects( $this->never() )
			->method( 'update_status' );

		$sent = $this->worker->run();
		$this->assertSame( 0, $sent );

		// Après la fin du cycle (release_lock), le marqueur d'annulation est purgé : un nouvel envoi avec le même ID n'est plus bloqué.
		$this->assertFalse( $this->worker->is_batch_aborted( $aborted_batch_id ) );
	}

	/**
	 * Vérifie l'envoi réussi d'un message éligible.
	 */
	public function test_successful_item_dispatch(): void {
		$item = new Queue_Item(
			id: 25,
			batch_id: 'batch_valid_456',
			dedup_hash: 'hash_xyz',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'valid@example.com',
			subject: 'Message valide',
			message: 'Contenu',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			created_at: '2026-10-25 10:00:00',
			scheduled_at: '2026-10-25 10:00:00',
		);

		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_hour' )
			->willReturn( 0 );

		$this->repository->expects( $this->exactly( 2 ) )
			->method( 'fetch_eligible_batch' )
			->willReturn( [ $item ] );

		$this->repository->expects( $this->once() )
			->method( 'get_by_id' )
			->with( 25 )
			->willReturn( $item );

		// Vérifie le passage en processing puis sent.
		$matcher = $this->exactly( 2 );
		$this->repository->expects( $matcher )
			->method( 'update_status' )
			->willReturnCallback(
				function ( int $id, Queue_Status $status ) use ( $matcher ): bool {
					match ( $matcher->numberOfInvocations() ) {
						1 => $this->assertSame( Queue_Status::PROCESSING, $status ),
						2 => $this->assertSame( Queue_Status::SENT, $status ),
					};
					return true;
				}
			);

		$sent = $this->worker->run();
		$this->assertSame( 1, $sent );
	}

	/**
	 * Vérifie que la fenêtre glissante sur la dernière minute bloque tout nouvel envoi rapproché (< 55s)
	 * et que le recalage anti-rattrapage aligne l'échéance WP-Cron sur la minute pleine suivante (:00).
	 */
	public function test_process_queue_stops_when_minute_sliding_quota_is_saturated_and_reschedules_cron(): void {
		$this->settings->update(
			[
				'quota_minute_day'   => 20,
				'quota_minute_night' => 20,
				'quota_hour'         => 0,
			]
		);

		// Déjà 20 messages envoyés dans les 55 dernières secondes.
		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_minute' )
			->with( null, Worker::COOLDOWN_SECONDS )
			->willReturn( 20 );

		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_hour' )
			->willReturn( 20 );

		$this->repository->expects( $this->never() )
			->method( 'fetch_eligible_batch' );

		$sent = $this->worker->run();
		$this->assertSame( 0, $sent );
	}

	/**
	 * Vérifie que lorsqu'un lot est envoyé et que WP-Cron avait programmé l'échéance suivante trop tôt (ex: à :55 dans 20s),
	 * le Worker la recale à au moins +55s et l'aligne sur la seconde :00.
	 */
	public function test_worker_reschedules_drifted_cron_to_aligned_minute_after_sending(): void {
		$now = time();
		// Simule une échéance WP-Cron trop proche (+15s) et non alignée sur :00.
		$GLOBALS['eg_test_crons']['eg_mail_cron_worker'] = $now + 15;

		$item = new Queue_Item(
			id: 42,
			batch_id: 'batch_cron_align',
			dedup_hash: 'hash_align',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'cron@example.com',
			subject: 'Test alignement cron',
			message: 'Corps',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			created_at: '2026-10-25 10:00:00',
			scheduled_at: '2026-10-25 10:00:00',
		);

		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_minute' )
			->willReturn( 0 );

		$this->repository->expects( $this->once() )
			->method( 'count_sent_in_last_hour' )
			->willReturn( 0 );

		$this->repository->expects( $this->exactly( 2 ) )
			->method( 'fetch_eligible_batch' )
			->willReturn( [ $item ] );

		$this->repository->expects( $this->once() )
			->method( 'get_by_id' )
			->with( 42 )
			->willReturn( $item );

		$this->repository->expects( $this->exactly( 2 ) )
			->method( 'update_status' )
			->willReturn( true );

		$sent = $this->worker->run();
		$this->assertSame( 1, $sent );

		$new_scheduled = wp_next_scheduled( 'eg_mail_cron_worker' );
		$this->assertIsInt( $new_scheduled );
		// Doit être aligné sur la seconde :00 (multiple de 60) et strictement supérieur à $now + 15.
		$this->assertSame( 0, $new_scheduled % 60 );
		$this->assertGreaterThan( $now + 15, $new_scheduled );
	}
}
