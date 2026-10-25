<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Enums\Queue_Priority;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour l'énumération Queue_Priority.
 */
final class QueuePriorityTest extends TestCase {

	/**
	 * Vérifie la détection des priorités nocturnes.
	 */
	public function test_is_nocturnal(): void {
		$this->assertTrue( Queue_Priority::LOW->is_nocturnal() );
		$this->assertFalse( Queue_Priority::NORMAL->is_nocturnal() );
		$this->assertFalse( Queue_Priority::CRITICAL->is_nocturnal() );
		$this->assertFalse( Queue_Priority::BYPASS->is_nocturnal() );
	}

	/**
	 * Vérifie la détection de la priorité immédiate bypass.
	 */
	public function test_is_bypass(): void {
		$this->assertTrue( Queue_Priority::BYPASS->is_bypass() );
		$this->assertFalse( Queue_Priority::CRITICAL->is_bypass() );
		$this->assertFalse( Queue_Priority::NORMAL->is_bypass() );
		$this->assertFalse( Queue_Priority::LOW->is_bypass() );
	}
}

