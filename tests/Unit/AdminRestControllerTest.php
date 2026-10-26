<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\API\Admin_Rest_Controller;
use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use EGMail\Queue\Attachment_Spooler;
use EGMail\Repositories\Queue_Repository;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Tests unitaires pour le contrôleur REST d'administration.
 */
final class AdminRestControllerTest extends TestCase {

	private Queue_Repository $repository;
	private Attachment_Spooler $spooler;
	private Admin_Rest_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = $this->createMock( Queue_Repository::class );
		$this->spooler    = $this->createMock( Attachment_Spooler::class );
		$this->controller = new Admin_Rest_Controller( $this->repository, $this->spooler );
	}

	/**
	 * Vérifie la mise en pause d'un lot via REST.
	 */
	public function test_handle_pause_batch(): void {
		$this->repository->expects( $this->once() )
			->method( 'pause_batch' )
			->with( 'batch_123' )
			->willReturn( 15 );

		$request  = new WP_REST_Request( 'POST', '/eg-mail/v1/batches/batch_123/pause', [ 'batch_id' => 'batch_123' ] );
		$response = $this->controller->handle_pause_batch( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 15, $data['paused'] );
	}

	/**
	 * Vérifie la reprise d'un lot via REST.
	 */
	public function test_handle_resume_batch(): void {
		$this->repository->expects( $this->once() )
			->method( 'resume_batch' )
			->with( 'batch_123' )
			->willReturn( 10 );

		$request  = new WP_REST_Request( 'POST', '/eg-mail/v1/batches/batch_123/resume', [ 'batch_id' => 'batch_123' ] );
		$response = $this->controller->handle_resume_batch( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 10, $data['resumed'] );
	}

	/**
	 * Vérifie l'annulation d'un lot via REST.
	 */
	public function test_handle_cancel_batch(): void {
		$this->repository->expects( $this->once() )
			->method( 'cancel_batch' )
			->with( 'batch_cancel_me' )
			->willReturn( 20 );

		$this->repository->expects( $this->once() )
			->method( 'get_active_attachment_paths' )
			->willReturn( [] );

		$this->spooler->expects( $this->once() )
			->method( 'clean_orphaned_attachments' )
			->with( [] );

		$request  = new WP_REST_Request( 'POST', '/eg-mail/v1/batches/batch_cancel_me/cancel', [ 'batch_id' => 'batch_cancel_me' ] );
		$response = $this->controller->handle_cancel_batch( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 20, $data['deleted'] );
	}

	/**
	 * Vérifie l'aperçu du message via REST.
	 */
	public function test_handle_preview_batch(): void {
		$sample = new Queue_Item(
			id: 1,
			batch_id: 'batch_preview_1',
			dedup_hash: 'hash_prev',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'dest@test.com',
			subject: 'Mon Sujet',
			message: '<p>Corps HTML</p>',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			created_at: '2026-10-25 15:00:00',
			scheduled_at: '2026-10-25 15:00:00',
		);

		$this->repository->expects( $this->once() )
			->method( 'get_first_by_batch_id' )
			->with( 'batch_preview_1' )
			->willReturn( $sample );

		$request  = new WP_REST_Request( 'GET', '/eg-mail/v1/batches/batch_preview_1/preview', [ 'batch_id' => 'batch_preview_1' ] );
		$response = $this->controller->handle_preview_batch( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Mon Sujet', $data['subject'] );
		$this->assertSame( '<p>Corps HTML</p>', $data['message'] );
	}

	/**
	 * Vérifie la purge complète de la file d'attente via REST.
	 */
	public function test_handle_purge_pending(): void {
		$this->repository->expects( $this->once() )
			->method( 'purge_all_pending' )
			->willReturn( 50 );

		$this->repository->expects( $this->once() )
			->method( 'get_active_attachment_paths' )
			->willReturn( [] );

		$this->spooler->expects( $this->once() )
			->method( 'clean_orphaned_attachments' );

		$response = $this->controller->handle_purge_pending();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 50, $data['purged'] );
	}

	/**
	 * Vérifie le déclenchement du Worker via l'endpoint REST /queue/process.
	 */
	public function test_handle_process_queue(): void {
		$worker = $this->createMock( \EGMail\Queue\Worker::class );
		$worker->expects( $this->once() )
			->method( 'run' )
			->willReturn( 12 );

		$controller = new Admin_Rest_Controller( $this->repository, $this->spooler, $worker );
		$response   = $controller->handle_process_queue();
		$data       = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 12, $data['sent_count'] );
	}

	/**
	 * Vérifie la reprogrammation d'un lot via REST (priorité + date/heure).
	 */
	public function test_handle_reschedule_batch(): void {
		$this->repository->expects( $this->once() )
			->method( 'reschedule_batch' )
			->with( 'batch_resched_1', Queue_Priority::LOW, '2026-10-30 22:00:00' )
			->willReturn( 42 );

		$request = new WP_REST_Request(
			'POST',
			'/eg-mail/v1/batches/batch_resched_1/reschedule',
			[
				'batch_id'     => 'batch_resched_1',
				'priority'     => 20,
				'scheduled_at' => '2026-10-30T22:00',
			]
		);

		$response = $this->controller->handle_reschedule_batch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 42, $data['updated'] );
		$this->assertSame( 20, $data['priority'] );
		$this->assertSame( '2026-10-30 22:00:00', $data['scheduled_at'] );
	}

	/**
	 * Vérifie le rejet d'une reprogrammation avec des paramètres invalides.
	 */
	public function test_handle_reschedule_batch_invalid_input(): void {
		$request_bad_date = new WP_REST_Request(
			'POST',
			'/eg-mail/v1/batches/batch_resched_1/reschedule',
			[
				'batch_id'     => 'batch_resched_1',
				'priority'     => 10,
				'scheduled_at' => 'invalid-date',
			]
		);

		$response = $this->controller->handle_reschedule_batch( $request_bad_date );
		$this->assertSame( 400, $response->get_status() );

		$request_bypass = new WP_REST_Request(
			'POST',
			'/eg-mail/v1/batches/batch_resched_1/reschedule',
			[
				'batch_id'     => 'batch_resched_1',
				'priority'     => 0,
				'scheduled_at' => '2026-10-30T14:00',
			]
		);

		$response_bypass = $this->controller->handle_reschedule_batch( $request_bypass );
		$this->assertSame( 400, $response_bypass->get_status() );
	}
}

