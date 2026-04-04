<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();

geturlcron_uninstall_plugin_options();
function geturlcron_uninstall_plugin_options() {
	if ( 1 === (int) get_option( 'geturlcron-uninstall-deleteall' ) ) {
		geturlcron_UNINSTALL_options();
		$ulp = wp_upload_dir();
		$plugincachepath = $ulp["basedir"]."/geturlcron";
		$files = glob( $plugincachepath . '/*' );
		if ( is_array( $files ) ) {
			array_map( 'wp_delete_file', $files );
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( isset( $wp_filesystem ) && $wp_filesystem->is_dir( $plugincachepath ) ) {
			$wp_filesystem->rmdir( $plugincachepath );
		}
	}
	return;
}

function geturlcron_UNINSTALL_options() {
		global $wpdb;
		delete_option( "geturlcron-emailadr" );
		delete_option( "geturlcron-timeout" );
		delete_option( "geturlcron-dellog-days" );
		delete_option( "geturlcron-mailonlyfail" );
		delete_option( "geturlcron-db-migrated" );
		delete_option( "geturlcron-db-version" );
		$geturlcronmaxnocronjobs = (int) trim(get_option('geturlcron-maxno-cronjobs'));
		delete_option( "geturlcron-maxno-cronjobs" );
		$nooffields = max( 15, $geturlcronmaxnocronjobs );
		for ($r = 1; $r <= $nooffields; $r++) {
			delete_option( 'geturlcron-url-'.$r );
			delete_option( 'geturlcron-interval-'.$r );
			delete_option( 'geturlcron-startdate-'.$r );
			delete_option( 'geturlcron-retries-'.$r );
			delete_option( 'geturlcron-requiredjsonfield-'.$r );
			delete_option( 'geturlcron-requiredformat-'.$r );
			delete_option( 'geturlcron-sendmail-'.$r );
		}
		delete_option('geturlcron-uninstall-deleteall');

		$table = $wpdb->prefix . 'geturlcron_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table )
		);
}
