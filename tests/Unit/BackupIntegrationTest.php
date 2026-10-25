<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Core\Backup_Integration;
use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use EGMail\Repositories\Queue_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour l'intégration de sauvegarde et le snapshot JSON.
 */
final class BackupIntegrationTest extends TestCase {

	protected function tearDown(): void {
		$snapshot_file = Backup_Integration::get_snapshot_path();
		if ( file_exists( $snapshot_file ) ) {
			unlink( $snapshot_file );
		}
		parent::tearDown();
	}

	/**
	 * Vérifie la déclaration de l'emplacement de sauvegarde pour EG Backup & Restore.
	 */
	public function test_register_spool_location(): void {
		$locations = Backup_Integration::register_spool_location( [] );

		$this->assertArrayHasKey( 'eg_mail_spool', $locations );
		$config = $locations['eg_mail_spool'];

		$this->assertSame( 'eg_mail_spool', $config['id'] );
		$this->assertSame( 'EG Mail', $config['plugin_name'] );
		$this->assertSame( 'documents', $config['category'] );
		$this->assertTrue( $config['recommended'] );
		$this->assertContains( '*.tmp', $config['exclude'] );
		$this->assertContains( '*.lock', $config['exclude'] );
	}

	/**
	 * Vérifie l'écriture atomique et la relecture du snapshot JSON.
	 */
	public function test_write_and_read_snapshot(): void {
		$item = new Queue_Item(
			id: 1,
			batch_id: 'batch_snap_123',
			dedup_hash: 'hash_abc',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'dest@example.com',
			subject: 'Test Snapshot',
			message: 'Contenu snapshot',
			headers: [ 'Content-Type: text/plain' ],
			attachments: [],
			context_meta: new Context_Meta( from_name: 'Expéditeur' ),
			created_at: '2026-10-25 12:00:00',
			scheduled_at: '2026-10-25 12:00:00',
		);

		$written = Backup_Integration::write_snapshot( [ $item ] );
		$this->assertTrue( $written );

		$snapshot = Backup_Integration::read_snapshot();
		$this->assertNotNull( $snapshot );
		$this->assertSame( 1, $snapshot['total_items'] );
		$this->assertCount( 1, $snapshot['items'] );
		$this->assertSame( 'batch_snap_123', $snapshot['items'][0]['batch_id'] );
		$this->assertSame( 'dest@example.com', $snapshot['items'][0]['recipient'] );
	}

	/**
	 * Vérifie la réconciliation après restauration : les messages sont réimportés en pause.
	 */
	public function test_reconcile_missing_batches(): void {
		$item = new Queue_Item(
			id: null,
			batch_id: 'batch_restore_999',
			dedup_hash: 'hash_rest',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'restore@example.com',
			subject: 'Message à restaurer',
			message: 'Contenu',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			created_at: '2026-10-25 12:00:00',
			scheduled_at: '2026-10-25 12:00:00',
		);

		Backup_Integration::write_snapshot( [ $item ] );

		$repository = $this->createMock( Queue_Repository::class );
		$repository->expects( $this->once() )
			->method( 'insert' )
			->with(
				$this->callback(
					function ( Queue_Item $inserted ): bool {
						// Doit être réimporté en statut PAUSED par sécurité.
						return Queue_Status::PAUSED === $inserted->status
							&& 'batch_restore_999' === $inserted->batch_id;
					}
				)
			)
			->willReturn( 999 );

		$restored = Backup_Integration::reconcile_missing_batches( $repository );
		$this->assertSame( 1, $restored );
	}
}
