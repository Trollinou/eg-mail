<?php
declare(strict_types=1);

namespace EGMail\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test de vérification de l'environnement de test et des constantes du plugin.
 */
final class BootstrapTest extends TestCase {

	/**
	 * Vérifie que la constante de version est bien définie.
	 */
	public function test_plugin_version_constant_is_defined(): void {
		$this->assertTrue( defined( 'EG_MAIL_VERSION' ) );
		$this->assertSame( '1.0.0', EG_MAIL_VERSION );
	}
}
