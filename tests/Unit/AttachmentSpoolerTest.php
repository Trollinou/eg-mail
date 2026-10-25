<?php
declare(strict_types=1);

namespace EGMail\Tests;

use EGMail\Queue\Attachment_Spooler;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour Attachment_Spooler.
 */
final class AttachmentSpoolerTest extends TestCase {

	private string $test_tmp_dir;
	private Attachment_Spooler $spooler;

	protected function setUp(): void {
		parent::setUp();
		$this->test_tmp_dir = sys_get_temp_dir() . '/eg-mail-test-spool-' . uniqid();
		mkdir( $this->test_tmp_dir, 0777, true );
		$this->spooler = new Attachment_Spooler();
	}

	protected function tearDown(): void {
		// Nettoyage des fichiers temporaires créés durant le test.
		if ( is_dir( $this->test_tmp_dir ) ) {
			$files = glob( $this->test_tmp_dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
			}
			rmdir( $this->test_tmp_dir );
		}
		parent::tearDown();
	}

	/**
	 * Vérifie que la déduplication fonctionne : deux appels avec le même contenu renvoient le même chemin physique.
	 */
	public function test_spool_deduplication(): void {
		$source_file = $this->test_tmp_dir . '/document.pdf';
		file_put_contents( $source_file, 'Contenu PDF factice pour test' );

		$spooled_1 = $this->spooler->spool_file( $source_file );
		$this->assertNotNull( $spooled_1 );
		$this->assertFileExists( $spooled_1 );

		// Deuxième appel : doit retourner le même chemin sans dupliquer.
		$spooled_2 = $this->spooler->spool_file( $source_file );
		$this->assertSame( $spooled_1, $spooled_2 );

		// Vérifie le format du nom de fichier : hash_nom.ext.
		$expected_hash = md5( 'Contenu PDF factice pour test' );
		$this->assertStringContainsString( $expected_hash . '_document.pdf', $spooled_1 );

		// Nettoyage du fichier dans le spool.
		if ( file_exists( $spooled_1 ) ) {
			unlink( $spooled_1 );
		}
		unlink( $source_file );
	}

	/**
	 * Vérifie le comportement lorsque le fichier source n'existe pas.
	 */
	public function test_spool_non_existent_file(): void {
		$result = $this->spooler->spool_file( '/chemin/inexistant/fichier.txt' );
		$this->assertNull( $result );
	}

	/**
	 * Vérifie le nettoyage des fichiers orphelins par le Garbage Collector.
	 */
	public function test_clean_orphaned_attachments(): void {
		$spool_dir = $this->spooler->get_spool_dir();

		$active_file   = $spool_dir . '/active_file.pdf';
		$orphan_file   = $spool_dir . '/orphan_file.pdf';
		$tmp_file      = $spool_dir . '/write.tmp';
		$htaccess_file = $spool_dir . '/.htaccess';

		file_put_contents( $active_file, 'actif' );
		file_put_contents( $orphan_file, 'orphelin' );
		file_put_contents( $tmp_file, 'en cours' );

		// Nettoie les orphelins en déclarant uniquement $active_file comme actif.
		$deleted_count = $this->spooler->clean_orphaned_attachments( [ $active_file ] );

		$this->assertSame( 1, $deleted_count );
		$this->assertFileExists( $active_file );
		$this->assertFileDoesNotExist( $orphan_file );
		$this->assertFileExists( $tmp_file ); // Fichier .tmp préservé.
		$this->assertFileExists( $htaccess_file ); // .htaccess préservé.

		unlink( $active_file );
		unlink( $tmp_file );
	}
}
