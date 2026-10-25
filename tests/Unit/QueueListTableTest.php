<?php
/**
 * Tests unitaires pour la table d'administration Queue_List_Table.
 */

declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Admin\Queue_List_Table;
use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use EGMail\Repositories\Queue_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Suite de tests pour Queue_List_Table.
 */
final class QueueListTableTest extends TestCase {

	private Queue_Repository $repository;
	private Queue_List_Table $table;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = $this->createMock( Queue_Repository::class );
		$this->table      = new Queue_List_Table( $this->repository );
	}

	/**
	 * Vérifie que les colonnes déclarées sont complètes.
	 */
	public function test_get_columns_returns_expected_structure(): void {
		$columns = $this->table->get_columns();

		$this->assertArrayHasKey( 'id', $columns );
		$this->assertArrayHasKey( 'recipient', $columns );
		$this->assertArrayHasKey( 'subject', $columns );
		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'priority', $columns );
		$this->assertArrayHasKey( 'attempts', $columns );
		$this->assertArrayHasKey( 'scheduled_at', $columns );
		$this->assertArrayHasKey( 'sent_at', $columns );
		$this->assertArrayHasKey( 'last_error', $columns );
	}

	/**
	 * Vérifie que les colonnes triables sont définies.
	 */
	public function test_get_sortable_columns_returns_expected_columns(): void {
		$sortable = $this->table->get_sortable_columns();

		$this->assertArrayHasKey( 'id', $sortable );
		$this->assertArrayHasKey( 'recipient', $sortable );
		$this->assertArrayHasKey( 'scheduled_at', $sortable );
		$this->assertArrayHasKey( 'status', $sortable );
		$this->assertArrayHasKey( 'priority', $sortable );
	}

	/**
	 * Vérifie que prepare_items configure bien _column_headers et peuple les items.
	 */
	public function test_prepare_items_defines_column_headers_and_items(): void {
		$item = new Queue_Item(
			id: 1,
			batch_id: 'test_batch_123',
			dedup_hash: 'hash123',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::NORMAL,
			recipient: 'test@example.com',
			subject: 'Test Subject',
			message: 'Test Message',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			attempts: 0,
			max_attempts: 3,
			last_error: null,
			created_at: '2026-10-25 20:00:00',
			scheduled_at: '2026-10-25 20:00:00',
			sent_at: null,
		);

		$this->repository->expects( $this->once() )
			->method( 'search_queue' )
			->willReturn(
				[
					'items' => [ $item ],
					'total' => 1,
				]
			);

		$this->table->prepare_items();

		$column_info = $this->table->get_column_info();
		$columns     = $column_info[0];

		// Vérifie que les colonnes ne sont pas vides (cause du bogue précédent).
		$this->assertNotEmpty( $columns );
		$this->assertCount( 9, $columns );
		$this->assertCount( 1, $this->table->items );
		$this->assertSame( $item, $this->table->items[0] );
	}

	/**
	 * Vérifie le rendu des colonnes spécialisées.
	 */
	public function test_column_renders(): void {
		$item = new Queue_Item(
			id: 42,
			batch_id: 'batch_xyz',
			dedup_hash: 'hash_xyz',
			status: Queue_Status::PENDING,
			priority: Queue_Priority::CRITICAL,
			recipient: 'dest@example.com',
			subject: 'Mon Sujet',
			message: 'Contenu',
			headers: [],
			attachments: [],
			context_meta: new Context_Meta(),
			attempts: 1,
			max_attempts: 3,
			last_error: 'Erreur SMTP temporaire',
			created_at: '2026-10-25 10:00:00',
			scheduled_at: '2026-10-25 10:05:00',
			sent_at: null,
		);

		$recipient_html = $this->table->column_recipient( $item );
		$this->assertStringContainsString( 'dest@example.com', $recipient_html );
		$this->assertStringContainsString( 'batch_xyz', $recipient_html );

		$status_html = $this->table->column_status( $item );
		$this->assertStringContainsString( 'eg-mail-badge--pending', $status_html );

		$priority_html = $this->table->column_priority( $item );
		$this->assertStringContainsString( 'eg-mail-badge--priority-1', $priority_html );

		$id_str = $this->table->column_default( $item, 'id' );
		$this->assertSame( '42', $id_str );

		$attempts_str = $this->table->column_default( $item, 'attempts' );
		$this->assertSame( '1 / 3', $attempts_str );

		$error_html = $this->table->column_default( $item, 'last_error' );
		$this->assertStringContainsString( 'Erreur SMTP temporaire', $error_html );
	}
}
