<?php
/**
 * Tests unitaires pour le nettoyeur quotidien Cleaner.
 */

declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Core\Settings;
use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Cleaner;
use EGMail\Repositories\Queue_Repository;
use PHPUnit\Framework\TestCase;

/**
 * Suite de tests pour le cycle de rétention et purge quotidienne.
 */
final class CleanerTest extends TestCase {

	private Queue_Repository $repository;
	private Attachment_Spooler $spooler;
	private Settings $settings;
	private Cleaner $cleaner;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['eg_test_options'] = [];
		$GLOBALS['wp_actions']      = [];

		$this->repository = $this->createMock( Queue_Repository::class );
		$this->spooler    = $this->createMock( Attachment_Spooler::class );
		$this->settings   = new Settings();
		$this->cleaner    = new Cleaner( $this->repository, $this->spooler, $this->settings );
	}

	/**
	 * Vérifie que la méthode init enregistre l'action WP-Cron eg_mail_cron_cleaner.
	 */
	public function test_init_registers_cron_hook(): void {
		$this->cleaner->init();

		$this->assertSame( 10, has_action( 'eg_mail_cron_cleaner', [ $this->cleaner, 'purge_scheduled' ] ) );
	}

	/**
	 * Vérifie la purge avec une rétention personnalisée configurée dans les réglages.
	 */
	public function test_clean_purges_sent_emails_with_custom_retention(): void {
		$this->settings->update( [ 'retention_days' => 30 ] );

		$this->repository->expects( $this->once() )
			->method( 'purge_sent' )
			->with( 30 )
			->willReturn( 42 );

		$this->repository->expects( $this->once() )
			->method( 'get_active_attachment_paths' )
			->willReturn( [ '/spool/file1.pdf' ] );

		$this->spooler->expects( $this->once() )
			->method( 'clean_orphaned_attachments' )
			->with( [ '/spool/file1.pdf' ] )
			->willReturn( 3 );

		$this->repository->expects( $this->once() )
			->method( 'fetch_eligible_batch' )
			->willReturn( [] );

		$result = $this->cleaner->clean();

		$this->assertSame( 42, $result );
	}

	/**
	 * Vérifie que la période de rétention retombe à 15 jours par défaut si la valeur est invalide.
	 */
	public function test_clean_defaults_to_15_days_when_invalid(): void {
		$this->settings->update( [ 'retention_days' => -5 ] );

		$this->repository->expects( $this->once() )
			->method( 'purge_sent' )
			->with( 15 )
			->willReturn( 10 );

		$this->repository->expects( $this->once() )
			->method( 'get_active_attachment_paths' )
			->willReturn( [] );

		$this->spooler->expects( $this->once() )
			->method( 'clean_orphaned_attachments' )
			->with( [] )
			->willReturn( 0 );

		$this->repository->expects( $this->once() )
			->method( 'fetch_eligible_batch' )
			->willReturn( [] );

		$result = $this->cleaner->clean();

		$this->assertSame( 10, $result );
	}
}
