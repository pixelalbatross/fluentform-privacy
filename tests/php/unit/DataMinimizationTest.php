<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\DataMinimization;
use WP_UnitTestCase;

/**
 * A minimal-data form stores no IP address, country, browser or device, and its uploaded photos
 * lose their metadata.
 *
 * @covers \Outstand\WP\FluentForm\Addons\Privacy\DataMinimization
 */
class DataMinimizationTest extends WP_UnitTestCase {

	/**
	 * ID of the minimal-data form in these tests.
	 *
	 * @var int
	 */
	protected const MINIMAL_FORM_ID = 7;

	/**
	 * ID of a form that keeps the default data.
	 *
	 * @var int
	 */
	protected const OTHER_FORM_ID = 8;

	/**
	 * Module under test.
	 *
	 * @var DataMinimization
	 */
	protected DataMinimization $module;

	/**
	 * Files that the tests write.
	 *
	 * @var array<int, string>
	 */
	protected array $files = [];

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {

		parent::set_up();

		$this->module = new DataMinimization();

		add_filter( 'outstand_fluentform_privacy_minimal_data', [ $this, 'minimal_data' ], 10, 2 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		remove_filter( 'outstand_fluentform_privacy_minimal_data', [ $this, 'minimal_data' ] );
		remove_filter( 'wp_handle_upload', [ $this->module, 'strip_image_metadata' ], 5 );

		parent::tear_down();
	}

	/**
	 * Turns on data minimization for the minimal-data form only.
	 *
	 * @param  bool $minimal Whether the form is a minimal-data form.
	 * @param  int  $form_id The form ID.
	 * @return bool
	 */
	public function minimal_data( $minimal, $form_id ): bool {
		return $minimal || self::MINIMAL_FORM_ID === $form_id;
	}

	/**
	 * Data minimization is off for every form when no site turns it on.
	 *
	 * @return void
	 */
	public function test_is_off_by_default(): void {

		remove_filter( 'outstand_fluentform_privacy_minimal_data', [ $this, 'minimal_data' ] );

		$this->assertFalse( DataMinimization::applies_to( self::MINIMAL_FORM_ID ) );
		$this->assertFalse( $this->module->disable_ip_logging( false, self::MINIMAL_FORM_ID ) );
	}

	/**
	 * A form ID that is not positive never turns on data minimization.
	 *
	 * @return void
	 */
	public function test_does_not_apply_to_an_invalid_form_id(): void {

		add_filter( 'outstand_fluentform_privacy_minimal_data', '__return_true' );

		$this->assertFalse( DataMinimization::applies_to( 0 ) );

		remove_filter( 'outstand_fluentform_privacy_minimal_data', '__return_true' );
	}

	/**
	 * The minimal-data form stores no IP address and no country; other forms keep the Fluent Forms
	 * value.
	 *
	 * @return void
	 */
	public function test_minimal_form_stores_no_ip_or_country(): void {

		$this->assertTrue( $this->module->disable_ip_logging( false, self::MINIMAL_FORM_ID ) );
		$this->assertTrue( $this->module->disable_country_detection( false, self::MINIMAL_FORM_ID ) );
		$this->assertFalse( $this->module->disable_ip_logging( false, self::OTHER_FORM_ID ) );
		$this->assertFalse( $this->module->disable_country_detection( false, self::OTHER_FORM_ID ) );
		$this->assertTrue( $this->module->disable_ip_logging( true, self::OTHER_FORM_ID ) );
	}

	/**
	 * The minimal-data form entry loses its IP address, browser and device; other entries keep them.
	 *
	 * @return void
	 */
	public function test_minimal_form_entry_has_no_device_data(): void {

		$entry = [
			'form_id' => self::MINIMAL_FORM_ID,
			'ip'      => '192.0.2.1',
			'browser' => 'Firefox',
			'device'  => 'Linux',
		];

		$minimal = $this->module->remove_device_data( $entry );

		$this->assertNull( $minimal['ip'] );
		$this->assertNull( $minimal['browser'] );
		$this->assertNull( $minimal['device'] );

		$entry['form_id'] = self::OTHER_FORM_ID;

		$this->assertSame( $entry, $this->module->remove_device_data( $entry ) );
	}

	/**
	 * Only an upload to the minimal-data form removes photo metadata.
	 *
	 * @return void
	 */
	public function test_only_minimal_form_uploads_are_watched(): void {

		$this->module->watch_upload( [], (object) [ 'id' => self::OTHER_FORM_ID ] );

		$this->assertFalse( has_filter( 'wp_handle_upload', [ $this->module, 'strip_image_metadata' ] ) );

		$this->module->watch_upload( [], (object) [ 'id' => self::MINIMAL_FORM_ID ] );

		$this->assertSame( 5, has_filter( 'wp_handle_upload', [ $this->module, 'strip_image_metadata' ] ) );
	}

	/**
	 * A JPEG photo loses its GPS position and stays a valid image of the same size.
	 *
	 * @return void
	 */
	public function test_jpeg_loses_its_metadata(): void {

		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'exif_read_data' ) ) {
			$this->markTestSkipped( 'GD and the EXIF extension are required.' );
		}

		$file = $this->jpeg_with_gps();

		$this->assertSame( 'N', $this->exif( $file )['GPSLatitudeRef'] ?? null );

		$upload = [
			'file' => $file,
			'url'  => 'https://example.org/photo.jpg',
			'type' => 'image/jpeg',
		];

		$this->assertSame( $upload, $this->module->strip_image_metadata( $upload ) );
		$this->assertArrayNotHasKey( 'GPSLatitudeRef', $this->exif( $file ) );

		$size = wp_getimagesize( $file );

		$this->assertSame( [ 40, 30 ], [ $size[0], $size[1] ] );
	}

	/**
	 * A map from JPEG to another output type does not write a new file next to the upload.
	 *
	 * @return void
	 */
	public function test_jpeg_is_replaced_when_an_output_format_is_mapped(): void {

		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'exif_read_data' ) ) {
			$this->markTestSkipped( 'GD and the EXIF extension are required.' );
		}

		$file = $this->jpeg_with_gps();
		$png  = preg_replace( '/\.jpg$/', '.png', $file );

		$this->files[] = $png;

		add_filter( 'image_editor_output_format', [ $this, 'map_jpeg_to_png' ] );

		$this->module->strip_image_metadata(
			[
				'file' => $file,
				'type' => 'image/jpeg',
			]
		);

		remove_filter( 'image_editor_output_format', [ $this, 'map_jpeg_to_png' ] );

		$this->assertArrayNotHasKey( 'GPSLatitudeRef', $this->exif( $file ) );
		$this->assertFileDoesNotExist( $png );
	}

	/**
	 * Output map from JPEG to PNG, as a plugin for modern image formats sets it.
	 *
	 * @param  array<string, string> $formats Output MIME type by source MIME type.
	 * @return array<string, string>
	 */
	public function map_jpeg_to_png( $formats ): array {
		return array_merge( (array) $formats, [ 'image/jpeg' => 'image/png' ] );
	}

	/**
	 * A file that is not a JPEG or PNG photo stays as it was uploaded.
	 *
	 * @return void
	 */
	public function test_other_files_are_untouched(): void {

		$file = wp_tempnam( 'upload.gif' );

		$this->files[] = $file;

		file_put_contents( $file, 'GIF89a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->module->strip_image_metadata(
			[
				'file' => $file,
				'type' => 'image/gif',
			]
		);

		$this->assertSame( 'GIF89a', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A JPEG file with an EXIF block that holds a GPS latitude reference.
	 *
	 * @return string The file path.
	 */
	protected function jpeg_with_gps(): string {

		$image = imagecreatetruecolor( 40, 30 );

		ob_start();
		imagejpeg( $image );
		$jpeg = (string) ob_get_clean();

		// TIFF header, IFD0 with the GPS IFD pointer, and the GPS IFD with GPSLatitudeRef "N".
		$tiff  = 'II' . pack( 'v', 42 ) . pack( 'V', 8 );
		$tiff .= pack( 'v', 1 ) . pack( 'vvVV', 0x8825, 4, 1, 26 ) . pack( 'V', 0 );
		$tiff .= pack( 'v', 1 ) . pack( 'vvV', 0x0001, 2, 2 ) . "N\0\0\0" . pack( 'V', 0 );

		$exif = "Exif\0\0" . $tiff;
		$app1 = "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif;
		$file = get_temp_dir() . wp_unique_filename( get_temp_dir(), 'upload.jpg' );

		$this->files[] = $file;

		file_put_contents( $file, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $file;
	}

	/**
	 * The EXIF data of a file.
	 *
	 * @param  string $file The file path.
	 * @return array<string, mixed>
	 */
	protected function exif( string $file ): array {

		$data = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return is_array( $data ) ? $data : [];
	}
}
