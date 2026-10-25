<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Enums\Queue_Status;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour l'énumération Queue_Status.
 */
final class QueueStatusTest extends TestCase {

	/**
	 * Vérifie que les statuts terminaux sont correctement identifiés.
	 */
	public function test_is_terminal(): void {
		$this->assertTrue( Queue_Status::SENT->is_terminal() );
		$this->assertTrue( Queue_Status::FAILED->is_terminal() );
		$this->assertTrue( Queue_Status::CANCELLED->is_terminal() );

		$this->assertFalse( Queue_Status::PENDING->is_terminal() );
		$this->assertFalse( Queue_Status::PROCESSING->is_terminal() );
		$this->assertFalse( Queue_Status::PAUSED->is_terminal() );
	}

	/**
	 * Vérifie les statuts éligibles à l'annulation ou la pause.
	 */
	public function test_can_be_cancelled(): void {
		$this->assertTrue( Queue_Status::PENDING->can_be_cancelled() );
		$this->assertTrue( Queue_Status::PAUSED->can_be_cancelled() );

		$this->assertFalse( Queue_Status::PROCESSING->can_be_cancelled() );
		$this->assertFalse( Queue_Status::SENT->can_be_cancelled() );
		$this->assertFalse( Queue_Status::FAILED->can_be_cancelled() );
		$this->assertFalse( Queue_Status::CANCELLED->can_be_cancelled() );
	}
}
