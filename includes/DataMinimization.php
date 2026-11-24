<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Services\Submission\SubmissionService;

/**
 * Keeps the data that identifies a visitor out of the entries of selected forms.
 *
 * Data minimization is off by default. A site turns it on for a form with the
 * `outstand_fluentform_privacy_minimal_data` filter. For such a form, Fluent Forms stores no IP
 * address, country, browser or device with the entry. Uploaded JPEG and PNG photos are saved again
 * with the GD image editor, which writes no metadata, so the camera, the owner and the GPS position
 * of a photo are not kept. The photo is rotated first, because its EXIF orientation is removed with
 * the other metadata.
 *
 * Fluent Forms limits submissions per visitor by the stored IP address, so that limit does not
 * apply to these forms.
 */
class DataMinimization extends BaseModule {

	/**
	 * Image types that are saved again without metadata. A GIF is not saved again, because GD
	 * keeps only its first frame.
	 *
	 * @var array<int, string>
	 */
	public const STRIPPED_IMAGE_TYPES = [ 'image/jpeg', 'image/png' ];

	/**
	 * Registers nothing unless Fluent Forms is active.
	 *
	 * @return bool
	 */
	public function can_register(): bool {
		return class_exists( SubmissionService::class );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'fluentform/disable_ip_logging', [ $this, 'disable_ip_logging' ], 10, 2 );
		add_filter( 'fluentform/disable_submission_country_detection', [ $this, 'disable_country_detection' ], 10, 2 );
		add_filter( 'fluentform/filter_insert_data', [ $this, 'remove_device_data' ] );
		add_action( 'fluentform/starting_file_upload', [ $this, 'watch_upload' ], 10, 2 );
	}

	/**
	 * Whether a form keeps only the data that the visitor enters.
	 *
	 * @param  int $form_id The form ID.
	 * @return bool
	 */
	public static function applies_to( int $form_id ): bool {

		if ( $form_id <= 0 ) {
			return false;
		}

		return (bool) apply_filters( 'outstand_fluentform_privacy_minimal_data', false, $form_id );
	}

	/**
	 * Stores no IP address with the entries of a minimal-data form.
	 *
	 * @param  bool $disable Whether Fluent Forms already stores no IP address.
	 * @param  int  $form_id The form ID.
	 * @return bool
	 */
	public function disable_ip_logging( $disable, $form_id ): bool {
		return (bool) $disable || self::applies_to( (int) $form_id );
	}

	/**
	 * Stores no country with the entries of a minimal-data form.
	 *
	 * @param  bool $disable Whether Fluent Forms already stores no country.
	 * @param  int  $form_id The form ID.
	 * @return bool
	 */
	public function disable_country_detection( $disable, $form_id ): bool {
		return (bool) $disable || self::applies_to( (int) $form_id );
	}

	/**
	 * Removes the IP address, the browser and the device from the entry of a minimal-data form.
	 *
	 * Fluent Forms writes its "no IP address" value as `false`, so the IP address is set to `null`.
	 *
	 * @param  array<string, mixed> $data The entry that Fluent Forms is about to insert.
	 * @return array<string, mixed>
	 */
	public function remove_device_data( $data ) {

		if ( ! is_array( $data ) || ! self::applies_to( (int) ( $data['form_id'] ?? 0 ) ) ) {
			return $data;
		}

		$data['ip']      = null;
		$data['browser'] = null;
		$data['device']  = null;

		return $data;
	}

	/**
	 * Removes the photo metadata from the next file uploaded to a minimal-data form.
	 *
	 * Fluent Forms uploads one file in each request, so the filter stays for the rest of the
	 * request. It runs before the Fluent Forms filter at priority 10, which keeps only the file
	 * name.
	 *
	 * @param  array<string, mixed> $files The uploaded files.
	 * @param  object|null          $form  The form.
	 * @return void
	 */
	public function watch_upload( $files, $form ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $files is part of the action signature.

		if ( ! is_object( $form ) || ! self::applies_to( (int) ( $form->id ?? 0 ) ) ) {
			return;
		}

		add_filter( 'wp_handle_upload', [ $this, 'strip_image_metadata' ], 5 );
	}

	/**
	 * Saves an uploaded JPEG or PNG photo again without its metadata.
	 *
	 * When GD is not available, the photo is kept as it was uploaded, so the submission still goes
	 * through.
	 *
	 * @param  array<string, mixed> $upload The uploaded file: its path, URL and MIME type.
	 * @return array<string, mixed>
	 */
	public function strip_image_metadata( $upload ) {

		$file = (string) ( $upload['file'] ?? '' );
		$type = (string) ( $upload['type'] ?? '' );

		if ( ! in_array( $type, self::STRIPPED_IMAGE_TYPES, true ) || ! is_file( $file ) ) {
			return $upload;
		}

		add_filter( 'wp_image_editors', [ $this, 'gd_editor' ] );

		$editor = wp_get_image_editor( $file );

		remove_filter( 'wp_image_editors', [ $this, 'gd_editor' ] );

		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		add_filter( 'image_editor_output_format', [ $this, 'keep_output_format' ], PHP_INT_MAX, 3 );

		$editor->maybe_exif_rotate();
		$editor->save( $file, $type );

		remove_filter( 'image_editor_output_format', [ $this, 'keep_output_format' ], PHP_INT_MAX );

		return $upload;
	}

	/**
	 * Keeps the type of the photo that is saved again, so the save replaces the uploaded file.
	 *
	 * A plugin for modern image formats maps JPEG to WebP or AVIF. With that map, the editor writes
	 * a new file next to the upload, and the upload keeps its metadata.
	 *
	 * @param  array<string, string> $formats   Output MIME type by source MIME type.
	 * @param  string                $filename  The file path.
	 * @param  string                $mime_type The source MIME type.
	 * @return array<string, string>
	 */
	public function keep_output_format( $formats, $filename, $mime_type ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $filename is part of the filter signature.

		$formats = (array) $formats;

		unset( $formats[ $mime_type ] );

		return $formats;
	}

	/**
	 * Limits the image editors to GD, which writes no metadata.
	 *
	 * @return array<int, string>
	 */
	public function gd_editor(): array {
		return [ 'WP_Image_Editor_GD' ];
	}
}
