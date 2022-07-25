<?php
/**
 * Plugin Name: PSAA - Audit Letter Importer
 * Plugin URI: http://www.helpfultechnology.com
 * Description: Imports Audit Letters for PSAA's Audited Bodies from a specially-formatted CSV file to the custom post type. Files must be uploaded to the server first.
 * Author: Phil Banks & Steph Gray
 * Version: 0.2
 * Author URI: http://www.helpfultechnology.com
 *
 * @package psaa-audit-letter-importer
 */


/**
 * Create plugin menu pages.
 */
function ht_psaa_audit_letter_importer_menu() {
	global $ht_psaa_audit_letter_importer_hook;
	$ht_psaa_audit_letter_importer_hook = add_submenu_page( 'tools.php','PSAA - Audit Letter Importer', 'PSAA - Audit Letter Importer', 'manage_options', 'psaa-audit-letter-importer', 'ht_psaa_audit_letter_importer_options' );
}
add_action( 'admin_menu', 'ht_psaa_audit_letter_importer_menu' );


/**
 * Generate plugin options / action page content.
 */
function ht_psaa_audit_letter_importer_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
	}

	$files_directory = wp_upload_dir();
	$files_directory = trailingslashit( $files_directory['basedir'] ) . 'AAL/' . trailingslashit( date( 'Y' ) );

	echo '<div class="wrap">';
	echo '<h2>' . esc_html__( ' PSAA - Audit Letter Importer' ) . '</h2>';

	if ( empty( $action = filter_input( INPUT_POST, 'action' ) ) ) {

		echo '<p>Instructions...<br/>- Files must be placed in <code>' . $files_directory . '</code><br/>- Files must be named bodyid_filename.extension, e.g. 78254_A Council Letter.pdf</p>
		<form method="post">
			<p><label for="year">Data year for file: </label><input type="text" id="year" name="year"> <em>If left blank ' . date( 'Y', strtotime( '-1 year' ) ) . '-' . date( 'y' ) . ' will be used</em>.</p>
			<p><input type="submit" value="Import files" class="button-primary" /></p>
			<input type="hidden" name="action" value="import" />
			' . wp_nonce_field( 'ht-psaa-ali', 'ht-psaa-ali-nonce' ) . '
		</form><br />';
		echo '</div>';

	} else {

		if ( 'import' !== $action || empty( $_POST['ht-psaa-ali-nonce'] ) || ! wp_verify_nonce( $_POST['ht-psaa-ali-nonce'], 'ht-psaa-ali' ) ) {
			die( 'Something looks wrong here.' );
		}

		// Year to attach to the entry.
		if ( empty( $data_year = trim( filter_input( INPUT_POST, 'year' ) ) ) ) {
			$data_year = date( 'Y', strtotime( '-1 year' ) ) . '-' . date( 'y' );
		}

		// Get files for upload.
		$files_to_upload = scandir( $files_directory ); // Get list of files in dir.

		$files_to_upload = array_filter( $files_to_upload, function( $filename ){ // Remove files starting with '.'.
			return ! ( '.' === substr( $filename, 0, 1) || '.pdf' !== substr( $filename, -4) );
		});

		// Get existing Audited Bodies and their Body IDs.
		global $wpdb;
		//$meta_key = 'body_id';
		$meta_key = 'new_body_id';
		$post_type = 'auditedbody';
		$post_status = 'publish';
		$the_audited_bodies = $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT post_id, meta_value
					FROM $wpdb->postmeta
					WHERE meta_key = %s
					AND post_id IN (
						SELECT ID
						FROM $wpdb->posts
						WHERE post_type = %s
						AND post_status = %s
					)
				",
				$meta_key,
				$post_type,
				$post_status
			), ARRAY_A
		);
		// Turn data into flat array using Body ID as the keys and post ID as values;
		foreach ( $the_audited_bodies as $audited_body ) {
			$lc_id = strtolower($audited_body['meta_value']);
			$audited_bodies[ $lc_id ] = $audited_body['post_id'];
		}

		// Loop over found files and process.
		foreach ( $files_to_upload as $file_to_upload ) {

			$body_id = explode( '_', $file_to_upload, 2 );
			// Bail if body_id can't be extracted from filename.
			if ( 2 !== count( $body_id ) ) {
				echo '<p><strong>' . $file_to_upload . ' could not be imported. Failed to find Body ID in filename.</strong></p>';
				continue;
			}
			$body_id = $body_id[0];

			// Bail if body_id doesn't exist in the database.
			if ( ! array_key_exists( $body_id, $audited_bodies ) ) {
				echo '<p><strong>' . $file_to_upload . ' could not be imported. Matching body not found.</strong></p>';
				continue;
			}

			// Import the file to the media library.
			$file_path = $files_directory . $file_to_upload;
			//$file_path = str_replace( ' ', '\ ', $file_path );
			$cli_command = 'wp media import ' . escapeshellarg( $file_path ) . ' --skip-copy --porcelain 2>&1';
			$imported_file_id = system( $cli_command, $exit_code );
			if ( 1 === $exit_code ) { // The command exited with an error code.
				echo '<p><strong>' . $file_to_upload . ' could not be imported. Have you checked the file permissions?</strong></p>';
				continue;
			}

			// Build data array.
			$this_years_data = array(
				'field_593e3bd14212c' => $imported_file_id, // File ID.
				'field_593e564c5422e' => '', // Title.
				'field_593e3bfe4212d' => $data_year, // Year.
			);
			//add_row( 'field_593e3b3b4212a', $this_years_data, $audited_bodies[ $body_id ] );
			// Get existing data - we can't add_row as it needs to be at the start.
			$existing_audit_letters = get_field( 'audit_letters', $audited_bodies[ $body_id ] );
			if ( ! is_array( $existing_audit_letters ) ) {
				$existing_audit_letters = array();
			}
			array_unshift( $existing_audit_letters, $this_years_data );
			// Save ACF data.
			update_field( 'field_593e3b3b4212a', $existing_audit_letters, $audited_bodies[ $body_id ] );

			echo '<p>' . $file_to_upload . ' imported to ' . $audited_bodies[ $body_id ] . '.</p>';
		}


	}
}
