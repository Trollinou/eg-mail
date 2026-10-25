<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Core\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour la gestion des réglages et le calcul des quotas.
 */
final class SettingsTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['eg_test_options'] = [];
		$this->settings             = new Settings();
	}

	/**
	 * Vérifie les valeurs par défaut.
	 */
	public function test_default_settings(): void {
		$this->assertFalse( $this->settings->get( 'smtp_force' ) );
		$this->assertSame( 10, $this->settings->get( 'quota_minute_day' ) );
		$this->assertSame( 25, $this->settings->get( 'quota_minute_night' ) );
		$this->assertSame( 0, $this->settings->get( 'quota_hour' ) );
		$this->assertSame( '22:00', $this->settings->get( 'night_start' ) );
		$this->assertSame( '06:00', $this->settings->get( 'night_end' ) );
		$this->assertSame( 15, $this->settings->get( 'retention_days' ) );
	}

	/**
	 * Vérifie la mise à jour des options.
	 */
	public function test_update_settings(): void {
		$updated = $this->settings->update(
			[
				'quota_minute_day' => 15,
				'quota_hour'       => 150,
			]
		);

		$this->assertTrue( $updated );
		$this->assertSame( 15, $this->settings->get( 'quota_minute_day' ) );
		$this->assertSame( 150, $this->settings->get( 'quota_hour' ) );
		// Les autres restent aux valeurs par défaut.
		$this->assertSame( 25, $this->settings->get( 'quota_minute_night' ) );
	}

	/**
	 * Vérifie la détection de la plage nocturne chevauchant minuit (22:00 - 06:00).
	 */
	public function test_is_night_time_detection(): void {
		// 14h30 UTC -> Jour.
		$day_time = strtotime( '2026-10-25 14:30:00' );
		$this->assertFalse( $this->settings->is_night_time( $day_time ) );

		// 23h15 UTC -> Nuit.
		$night_time_1 = strtotime( '2026-10-25 23:15:00' );
		$this->assertTrue( $this->settings->is_night_time( $night_time_1 ) );

		// 04h00 UTC -> Nuit.
		$night_time_2 = strtotime( '2026-10-25 04:00:00' );
		$this->assertTrue( $this->settings->is_night_time( $night_time_2 ) );

		// 07h00 UTC -> Jour.
		$day_time_2 = strtotime( '2026-10-25 07:00:00' );
		$this->assertFalse( $this->settings->is_night_time( $day_time_2 ) );
	}

	/**
	 * Vérifie le calcul du quota effectif (minute vs reliquat horaire).
	 */
	public function test_calculate_effective_quota(): void {
		$day_time   = strtotime( '2026-10-25 14:00:00' );
		$night_time = strtotime( '2026-10-25 23:00:00' );

		// Sans limite horaire (quota_hour = 0) :
		$this->assertSame( 10, $this->settings->calculate_effective_quota( 50, $day_time ) );
		$this->assertSame( 25, $this->settings->calculate_effective_quota( 50, $night_time ) );

		// Avec limite horaire configurée à 100 mails/heure :
		$this->settings->update( [ 'quota_hour' => 100 ] );

		// 1. Déjà 95 envoyés sur l'heure -> il ne reste que 5 slots autorisés (5 < 10).
		$effective = $this->settings->calculate_effective_quota( 95, $day_time );
		$this->assertSame( 5, $effective );

		// 2. Déjà 100 envoyés sur l'heure -> quota horaire saturé -> 0.
		$effective_zero = $this->settings->calculate_effective_quota( 100, $day_time );
		$this->assertSame( 0, $effective_zero );

		// 3. Déjà 105 envoyés (dépassement) -> 0.
		$effective_overflow = $this->settings->calculate_effective_quota( 105, $day_time );
		$this->assertSame( 0, $effective_overflow );

		// 4. Seulement 20 envoyés sur l'heure -> il reste 80 slots (mais limité à 10 par minute le jour).
		$effective_normal = $this->settings->calculate_effective_quota( 20, $day_time );
		$this->assertSame( 10, $effective_normal );
	}

	/**
	 * Vérifie les paliers de backoff exponentiel.
	 */
	public function test_calculate_backoff_delay(): void {
		$this->assertSame( 300, Settings::calculate_backoff_delay( 1 ) ); // 5 min.
		$this->assertSame( 1200, Settings::calculate_backoff_delay( 2 ) ); // 20 min.
		$this->assertSame( 3600, Settings::calculate_backoff_delay( 3 ) ); // 60 min.
		$this->assertSame( 3600, Settings::calculate_backoff_delay( 4 ) ); // 60 min.
	}
}
