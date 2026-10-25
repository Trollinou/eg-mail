<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour le DTO Queue_Item.
 */
final class QueueItemTest extends TestCase {

	/**
	 * Vérifie l'hydratation depuis une ligne SQL et la conversion vers un tableau DB.
	 */
	public function test_hydration_and_export(): void {
		$row = (object) [
			'id'           => '42',
			'batch_id'     => 'batch_abc123',
			'dedup_hash'   => 'hash_xyz789',
			'status'       => 'pending',
			'priority'     => '10',
			'recipient'    => 'user@example.com',
			'subject'      => 'Test Sujet',
			'message'      => '<p>Test Message</p>',
			'headers'      => json_encode( [ 'Content-Type: text/html' ] ),
			'attachments'  => json_encode( [ '/path/to/file.pdf' ] ),
			'context_meta' => json_encode( [ 'from_name' => 'Admin' ] ),
			'attempts'     => '1',
			'max_attempts' => '3',
			'last_error'   => null,
			'created_at'   => '2026-10-25 10:00:00',
			'scheduled_at' => '2026-10-25 10:00:00',
			'sent_at'      => null,
		];

		$item = Queue_Item::from_db_row( $row );

		$this->assertSame( 42, $item->id );
		$this->assertSame( 'batch_abc123', $item->batch_id );
		$this->assertSame( Queue_Status::PENDING, $item->status );
		$this->assertSame( Queue_Priority::NORMAL, $item->priority );
		$this->assertSame( 'user@example.com', $item->recipient );
		$this->assertSame( [ 'Content-Type: text/html' ], $item->headers );
		$this->assertSame( [ '/path/to/file.pdf' ], $item->attachments );
		$this->assertSame( 'Admin', $item->context_meta->from_name );
		$this->assertSame( 1, $item->attempts );

		$exported = $item->to_db_array();
		$this->assertSame( 'batch_abc123', $exported['batch_id'] );
		$this->assertSame( 'pending', $exported['status'] );
		$this->assertSame( 10, $exported['priority'] );
		$this->assertSame( 'user@example.com', $exported['recipient'] );
	}
}
