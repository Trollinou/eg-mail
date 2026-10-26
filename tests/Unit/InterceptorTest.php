<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Interceptor;
use EGMail\Repositories\Queue_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour l'intercepteur Interceptor.
 */
final class InterceptorTest extends TestCase {

	private Queue_Repository $repository;
	private Attachment_Spooler $spooler;
	private Interceptor $interceptor;

	protected function setUp(): void {
		parent::setUp();
		$this->repository  = $this->createMock( Queue_Repository::class );
		$this->spooler     = $this->createMock( Attachment_Spooler::class );
		$this->interceptor = new Interceptor( $this->repository, $this->spooler );
		Interceptor::$is_worker_sending = false;
	}

	/**
	 * Vérifie l'extraction et le nettoyage des destinataires multiples.
	 */
	public function test_extract_recipients(): void {
		// Destinataires séparés par virgule.
		$comma_string = 'adherent1@example.com, adherent2@example.com, invalid-email, ';
		$extracted    = $this->interceptor->extract_recipients( $comma_string );

		$this->assertCount( 2, $extracted );
		$this->assertSame( [ 'adherent1@example.com', 'adherent2@example.com' ], $extracted );

		// Destinataires passés sous forme de tableau.
		$array_to  = [ 'user1@test.com', 'user2@test.com, user3@test.com' ];
		$extracted = $this->interceptor->extract_recipients( $array_to );

		$this->assertCount( 3, $extracted );
		$this->assertSame( [ 'user1@test.com', 'user2@test.com', 'user3@test.com' ], $extracted );
	}

	/**
	 * Vérifie le parsing des en-têtes personnalisés X-Queue-*.
	 */
	public function test_parse_custom_headers(): void {
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'X-Queue-Priority: 1',
			'X-Queue-Batch: convocation-ag-2026',
			'X-Custom-Header: value',
		];

		$parsed = $this->interceptor->parse_custom_headers( $headers );

		$this->assertSame( Queue_Priority::CRITICAL, $parsed['priority'] );
		$this->assertSame( 'convocation-ag-2026', $parsed['batch_id'] );
		$this->assertFalse( $parsed['is_bypass'] );

		// Vérifie que les en-têtes X-Queue-* ont été retirés de clean_headers.
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $parsed['clean_headers'] );
		$this->assertContains( 'X-Custom-Header: value', $parsed['clean_headers'] );
		$this->assertCount( 2, $parsed['clean_headers'] );
	}

	/**
	 * Vérifie la détection du bypass immédiat par en-tête.
	 */
	public function test_bypass_detection_via_header(): void {
		$atts = [
			'to'      => 'admin@example.com',
			'subject' => 'Test Bypass',
			'message' => 'Contenu synchrone',
			'headers' => [ 'X-Queue-Priority: 0' ],
		];

		$result = $this->interceptor->intercept( null, $atts );
		$this->assertNull( $result ); // N'intercepte pas, laisse passer à PHPMailer.
	}

	/**
	 * Vérifie la détection du bypass lorsque le Worker est en cours d'envoi.
	 */
	public function test_bypass_detection_when_worker_sending(): void {
		Interceptor::$is_worker_sending = true;

		$atts = [
			'to'      => 'user@example.com',
			'subject' => 'Message du Worker',
			'message' => 'Contenu',
		];

		$result = $this->interceptor->intercept( null, $atts );
		$this->assertNull( $result );
	}

	/**
	 * Vérifie l'interception standard : insertion en base et retour true.
	 */
	public function test_standard_interception_queues_email(): void {
		$this->spooler->expects( $this->once() )
			->method( 'spool_attachments' )
			->willReturn( [] );

		$this->repository->expects( $this->once() )
			->method( 'insert' )
			->with( $this->isInstanceOf( Queue_Item::class ) )
			->willReturn( 123 );

		$atts = [
			'to'      => 'member@example.com',
			'subject' => 'Convocation AG',
			'message' => 'Bonjour',
		];

		$result = $this->interceptor->intercept( null, $atts );
		$this->assertTrue( $result ); // Intercepté !
	}

	/**
	 * Vérifie la génération automatique du batch_id en l'absence d'en-tête explicite.
	 */
	public function test_automatic_batch_id_generation(): void {
		$subject  = 'Rappel cotisation';
		$batch_id = $this->interceptor->generate_batch_id( $subject );

		$this->assertSame( 32, strlen( $batch_id ) );
		$expected = md5( $subject . '|' . floor( time() / 60 ) );
		$this->assertSame( $expected, $batch_id );
	}

	/**
	 * Vérifie l'analyse de l'en-tête d'envoi différé X-Queue-Scheduled-At (formats explicites, relatifs et Unix).
	 */
	public function test_parse_scheduled_at_header(): void {
		$future_ts    = time() + 7200;
		$expected_str = wp_date( 'Y-m-d H:i:s', $future_ts );

		// 1. Format explicite MySQL Y-m-d H:i:s dans le futur.
		$headers_mysql = [
			'Content-Type: text/html; charset=UTF-8',
			'X-Queue-Scheduled-At: ' . $expected_str,
		];
		$parsed_mysql  = $this->interceptor->parse_custom_headers( $headers_mysql );
		$this->assertSame( $expected_str, $parsed_mysql['scheduled_at'] );
		$this->assertCount( 1, $parsed_mysql['clean_headers'] );

		// 2. Format Timestamp Unix dans le futur.
		$headers_ts = [ 'X-Queue-Scheduled-At: ' . $future_ts ];
		$parsed_ts  = $this->interceptor->parse_custom_headers( $headers_ts );
		$this->assertSame( $expected_str, $parsed_ts['scheduled_at'] );

		// 3. Expression relative (+3 hours).
		$parsed_rel = $this->interceptor->parse_scheduled_at_header( '+3 hours' );
		$this->assertNotNull( $parsed_rel );
		$this->assertGreaterThan( time() + 10000, strtotime( $parsed_rel ) );

		// 4. Date dans le passé ou invalide -> repli sécurisé (null).
		$past_str = wp_date( 'Y-m-d H:i:s', time() - 3600 );
		$this->assertNull( $this->interceptor->parse_scheduled_at_header( $past_str ) );
		$this->assertNull( $this->interceptor->parse_scheduled_at_header( 'not-a-valid-date-string' ) );
		$this->assertNull( $this->interceptor->parse_scheduled_at_header( '' ) );
	}

	/**
	 * Vérifie que calculate_scheduled_at respecte la date différée explicite lorsqu'elle est fournie.
	 */
	public function test_calculate_scheduled_at_with_custom_header(): void {
		$custom_future = wp_date( 'Y-m-d H:i:s', time() + 86400 );

		// Même en priorité normale (10), la date différée est appliquée.
		$result_normal = $this->interceptor->calculate_scheduled_at( Queue_Priority::NORMAL, $custom_future );
		$this->assertSame( $custom_future, $result_normal );

		// En priorité basse (20), la date différée d'éligibilité initiale est également conservée.
		$result_low = $this->interceptor->calculate_scheduled_at( Queue_Priority::LOW, $custom_future );
		$this->assertSame( $custom_future, $result_low );
	}
}

