<?php
/*
Plugin Name: Cron Setup and Monitor - Get URL Cron
Plugin URI: https://json-content-importer.com/geturlcron
Description: Manage cron jobs, monitor tasks, retry failures, and send email updates
Version: 2.0.0
Requires at least: 6.2
Requires PHP: 7.4
Author: Bernhard Kux
Author URI: http://www.kux.de/
Text Domain: get-url-cron
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/* block direct requests */

defined('ABSPATH') OR exit;
if ( !function_exists( 'add_action' )) {
	echo 'Hello, this is a plugin: You must not call me directly.';
	exit;
}
define( 'GETURLCRON_VERSION', '2.0.0' );  // current version number



function geturlcron_init() {
	GetUrlCron::initclass();
}
add_action('plugins_loaded', 'geturlcron_init');


class GetUrlCron {	
	private $urlSettingsArr = array();
	private $nooffields = 15;
	private static $logfile = "";
	private $subaction = "";
	private $action = "";
	private $gmt_offset_add = 0;
	public static $fi = array(
			"url" => 1,
			"interval" => 2,
			"startdate" => 3,
			"retries" => 4,
			"requiredformat" => 5,
			"requiredjsonfield" => 10,
			"sendmail" => 8,
			);

	public static $fi_size = array(
			"url" => 80,
			"interval" => 20,
			"startdate" => 25,
			"retries" => 2,
			"requiredformat" => 5,
			"requiredjsonfield" => 15,
			"sendmail" => 1,
			);
			
	public static $reqformatArr = array(
			"any" => "string",
			"json" => "json",
		);



	protected function __construct() {
		$this->geturlcron_getnooffields();

		$this->gmt_offset_add = (get_option('gmt_offset') ?? 0) *3600;

		add_action('admin_menu', array( $this, 'geturlcron_menu'));

		$this->geturlcron_setlogfile();
		$this->geturlcron_create_table();
		$this->geturlcron_maybe_upgrade_table();

		add_filter( 'cron_schedules',  array( $this, 'geturlcron_recurrence_interval' ) );
		add_action( 'init', array( $this, 'geturlcron_action_handle' ) );

		register_activation_hook(__FILE__, array($this, 'geturlcron_activatejobs'));
		register_deactivation_hook(__FILE__, array($this, 'geturlcron_unschedulejobs'));
		$this->geturlcron_set_urlSettingsarr();

		for ($no = 1; $no <= count($this->urlSettingsArr); $no++) {
			add_action('geturlcron_event-'.$no, array( $this, 'geturlcron_add_action_cronjob' ) );
		}
	}
	
	
public function geturlcron_detailsettings_page() {
	$mailadr         = trim( get_option('geturlcron-emailadr') );
	$mailcheckArr    = $this->geturlcron_check_mailadress_list( $mailadr );
	$mailonlyfailopt = (int) ( get_option('geturlcron-mailonlyfail') ?? 0 );
	$timeout         = (int) trim( get_option('geturlcron-timeout') );
	if ( ! ( $timeout > 0 ) ) { $timeout = 60; }
	$deldays         = (int) trim( get_option('geturlcron-dellog-days') );
	$maxnocronjobs   = (int) trim( get_option('geturlcron-maxno-cronjobs') );
	if ( $maxnocronjobs < 15 ) { $maxnocronjobs = 15; }
	$logfile         = $this->geturlcron_getlogfile();

	echo '<h1>' . esc_html__( 'Basic Settings', 'get-url-cron' ) . '</h1>';
	echo '<form method="post" action="admin.php?page=geturlcronsettingspage&tab=basicsettings">';
	wp_nonce_field( 'geturlcron_nc', 'geturlcron_nc' );
	echo '<input type="hidden" name="subaction" value="settings">';
	settings_fields( 'geturlcron-options-details' );
	do_settings_sections( 'geturlcron-options-details' );

	/* ---- E-Mail ---- */
	echo '<div class="card" style="max-width:900px;margin-top:16px;">';
	echo '<h2 style="margin-top:0;padding-bottom:10px;border-bottom:1px solid #eee;">&#9993; ';
	esc_html_e( 'E-Mail Notifications', 'get-url-cron' );
	echo '</h2>';
	echo '<table class="form-table"><tbody>';

	echo '<tr>';
	echo '<th scope="row"><label for="geturlcron-emailadr">';
	esc_html_e( 'E-Mailadress for Statusmessages: separate multiple by space or , or ;', 'get-url-cron' );
	echo '</label></th><td>';
	foreach ( $mailcheckArr['color'] as $i => $color ) {
		$icon = ( $color === 'black' ) ? '&#10003;' : '&#9888;';
		echo '<span style="color:' . esc_attr( $color ) . ';">' . esc_attr($icon) . ' ' . esc_html( $mailcheckArr['message'][$i] ) . '</span><br>';
	}
	echo '<input type="text" id="geturlcron-emailadr" name="geturlcron-emailadr" class="large-text" value="' . esc_attr( $mailadr ) . '">';
	echo '</td></tr>';

	echo '<tr>';
	echo '<th scope="row">';
	esc_html_e( 'E-Mail only for failed Jobs', 'get-url-cron' );
	echo '</th><td>';
	echo '<p class="description">';
	esc_html_e( 'In the default setting, emails are sent regardless of the outcome of the cron jobs. If the following checkbox is active, emails are only sent when a cron jobs fails.', 'get-url-cron' );
	echo '</p>';
	echo '<label><input type="checkbox" name="geturlcron-mailonlyfail" value="1" ' . checked( 1, $mailonlyfailopt, false ) . '> ';
	esc_html_e( 'emails only sent when cron jobs fails', 'get-url-cron' );
	echo '</label></td></tr>';

	echo '</tbody></table></div>';

	/* ---- Logging & Performance ---- */
	echo '<div class="card" style="max-width:900px;margin-top:16px;">';
	echo '<h2 style="margin-top:0;padding-bottom:10px;border-bottom:1px solid #eee;">&#128196; ';
	esc_html_e( 'Logging &amp; Performance', 'get-url-cron' );
	echo '</h2>';
	echo '<table class="form-table"><tbody>';

	echo '<tr>';
	echo '<th scope="row"><label for="geturlcron-timeout">';
	esc_html_e( 'Set timeout', 'get-url-cron' );
	echo '</label></th><td>';
	echo '<input type="number" id="geturlcron-timeout" name="geturlcron-timeout" value="' . esc_attr( $timeout ) . '" min="1" max="300" style="width:80px;"> ';
	esc_html_e( 'seconds', 'get-url-cron' );
	echo '<p class="description">';
	esc_html_e( 'Set the timeout for the http-requests (default 60 sec):', 'get-url-cron' );
	echo '</p></td></tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="geturlcron-dellog-days">';
	esc_html_e( 'Max. age of logentries', 'get-url-cron' );
	echo '</label></th><td>';
	echo '<input type="number" id="geturlcron-dellog-days" name="geturlcron-dellog-days" value="' . esc_attr( $deldays ) . '" style="width:80px;"> ';
	esc_html_e( 'days', 'get-url-cron' );
	echo '<p class="description">';
	esc_html_e( 'Logs are stored in the database.', 'get-url-cron' );
	echo '<br>';
	esc_html_e( '-1 : delete all log entries from database and do not log', 'get-url-cron' );
	echo '<br>';
	esc_html_e( '0 : do not log but keep existing entries', 'get-url-cron' );
	echo '<br>';
	esc_html_e( 'any number : max. age in days of the log entries, default is 20 days', 'get-url-cron' );
	echo '</p></td></tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="geturlcron-maxno-cronjobs">';
	esc_html_e( 'Max. number of Cronjobs (default and minimal: 15)', 'get-url-cron' );
	echo '</label></th><td>';
	echo '<input type="number" id="geturlcron-maxno-cronjobs" name="geturlcron-maxno-cronjobs" value="' . esc_attr( $maxnocronjobs ) . '" min="15" style="width:80px;">';
	echo '</td></tr>';

	echo '</tbody></table></div>';

	/* ---- Uninstall ---- */
	echo '<div class="card" style="max-width:900px;margin-top:16px;border-left:4px solid #d63638;">';
	echo '<h2 style="margin-top:0;padding-bottom:10px;border-bottom:1px solid #eee;color:#d63638;">&#9888; ';
	esc_html_e( 'Complete delete when uninstalling?', 'get-url-cron' );
	echo '</h2>';
	echo '<table class="form-table"><tbody>';
	echo '<tr>';
	echo '<th scope="row">';
	esc_html_e( 'Delete all data', 'get-url-cron' );
	echo '</th><td>';
	echo '<p class="description">';
	esc_html_e( 'On default, not all data of this plugin is deleted:', 'get-url-cron' );
	echo '<br>';
	esc_html_e( 'Only if the following checkbox is activated, also templates and the above option-data are deleted', 'get-url-cron' );
	echo '</p>';
	echo '<label><input type="checkbox" name="geturlcron-uninstall-deleteall" value="1" ' . checked( 1, (int) get_option('geturlcron-uninstall-deleteall'), false ) . '> ';
	esc_html_e( 'delete all, incl. logfiles', 'get-url-cron' );
	echo '</label></td></tr>';
	echo '</tbody></table></div>';

	echo '<div style="margin-top:20px;">';
	submit_button();
	echo '</div>';
	echo '</form>';
}

private function geturlcron_admin_styles() {
    echo '<style>
.guc-table{border-collapse:collapse;width:100%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;font-size:13px;}
.guc-table thead th{background:#1d2327;color:#fff;font-weight:600;padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;}
.guc-table tbody td{padding:7px 12px;vertical-align:middle;border-bottom:1px solid #f0f0f1;}
.guc-table tbody tr:nth-child(even) td{background:#f9f9f9;}
.guc-table tbody tr:hover td{background:#eaf2fb!important;}
.guc-table tr.guc-ok  td{background:#f0faf0!important;}
.guc-table tr.guc-fail td{background:#fdf2f2!important;}
.guc-table tr.guc-warn td{background:#fefbec!important;}
.guc-table tr.guc-info td{background:#f0f6ff!important;}
.guc-table tr.guc-schedule td{background:#f8f5ff!important;}
.guc-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.guc-badge-ok    {background:#d1fae5;color:#065f46;}
.guc-badge-fail  {background:#fee2e2;color:#991b1b;}
.guc-badge-warn  {background:#fef3c7;color:#92400e;}
.guc-badge-try   {background:#e0e7ff;color:#3730a3;}
.guc-badge-sched {background:#ede9fe;color:#5b21b6;}
.guc-badge-manual{background:#e0f2fe;color:#075985;}
.guc-badge-sys-ok  {background:#d1fae5;color:#065f46;}
.guc-badge-sys-warn{background:#fef3c7;color:#92400e;}
.guc-badge-sys-fail{background:#fee2e2;color:#991b1b;}
.guc-mono{font-family:monospace;font-size:12px;}
.guc-url {word-break:break-all;font-size:12px;color:#1d6fa9;}
.guc-resp{font-size:11px;color:#555;word-break:break-all;line-height:1.4;}
.guc-id  {font-family:monospace;font-size:11px;color:#888;}
.guc-section{font-size:14px;font-weight:600;color:#1d2327;border-bottom:2px solid #dcdcde;padding-bottom:6px;margin:20px 0 10px;}
.guc-pagination{margin:10px 0;font-size:13px;line-height:2;}
.guc-pagination a{display:inline-block;padding:2px 8px;margin:0 1px;border:1px solid #c3c4c7;border-radius:3px;text-decoration:none;color:#2271b1;}
.guc-pagination a.guc-current{background:#2271b1;color:#fff;border-color:#2271b1;pointer-events:none;}
.guc-pagination .guc-count{color:#666;margin-right:10px;}
</style>';
}

public function geturlcron_main_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, no data modification
	$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
	$tabs = array(
		'settings'      => array( 'icon' => 'dashicons-admin-tools',    'label' => __( 'Set CronJobs',   'get-url-cron' ) ),
		'cronjobs'      => array( 'icon' => 'dashicons-calendar-alt',   'label' => __( 'Show CronJobs',  'get-url-cron' ) ),
		'logs'          => array( 'icon' => 'dashicons-list-view',      'label' => __( 'Show Logs',       'get-url-cron' ) ),
		'basicsettings' => array( 'icon' => 'dashicons-admin-settings', 'label' => __( 'Basic Settings',  'get-url-cron' ) ),
		'systemcheck'   => array( 'icon' => 'dashicons-yes-alt',        'label' => __( 'System Check',    'get-url-cron' ) ),
	);
	echo '<div class="wrap">';
	$this->geturlcron_admin_styles();
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $tab => $tabdata ) {
		$active = ( $current_tab === $tab ) ? ' nav-tab-active' : '';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=geturlcronsettingspage&tab=' . $tab ) ) . '" class="nav-tab' . esc_attr( $active ) . '">';
		echo '<span class="dashicons ' . esc_attr( $tabdata['icon'] ) . '" style="font-size:16px;line-height:1;vertical-align:middle;margin-right:4px;position:relative;top:-1px;"></span>';
		echo esc_html( $tabdata['label'] );
		echo '</a>';
	}
	echo '</nav>';
	switch ( $current_tab ) {
		case 'cronjobs':
			$this->geturlcron_cronjobs_page();
			break;
		case 'logs':
			$this->geturlcron_logs_page();
			break;
		case 'basicsettings':
			$this->geturlcron_detailsettings_page();
			break;
		case 'systemcheck':
			$this->geturlcron_systemcheck_page();
			break;
		default:
			$this->geturlcron_settings_page();
	}
	echo '</div>';
}

public function geturlcron_menu() {
	add_menu_page(
		__( 'Cron Setup and Monitor', 'get-url-cron' ),
		__( 'Cron Setup Monitor', 'get-url-cron' ),
		'manage_options',
		'geturlcronsettingspage',
		array( $this, 'geturlcron_main_page' ),
		'dashicons-clock'
	);
	add_action( 'admin_init', array( $this, 'register_geturlcronsettings' ) );
}

public function geturlcron_systemcheck_page() {
	$checks = array();

	// PHP version
	$php_version = phpversion();
	$php_ok = version_compare( $php_version, '7.4', '>=' );
	$checks[] = array(
		'label'  => __( 'PHP Version', 'get-url-cron' ),
		'status' => $php_ok ? 'ok' : 'fail',
		'detail' => $php_version . ( $php_ok ? '' : ' — ' . __( 'PHP 7.4 or higher required', 'get-url-cron' ) ),
	);

	// WordPress version
	global $wp_version;
	$checks[] = array(
		'label'  => __( 'WordPress Version', 'get-url-cron' ),
		'status' => 'ok',
		'detail' => $wp_version,
	);

	// DISABLE_WP_CRON
	$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$checks[] = array(
		'label'  => 'DISABLE_WP_CRON',
		'status' => $cron_disabled ? 'warn' : 'ok',
		'detail' => $cron_disabled
			? __( 'TRUE — WordPress pseudo-cron will not fire on page visits. A real system cron is required (see instructions below).', 'get-url-cron' )
			: __( 'FALSE — WordPress pseudo-cron is active. Jobs fire on the next page visit after the scheduled time.', 'get-url-cron' ),
	);

	// WP cron system: check via a known core event
	$next_wp_cron = wp_next_scheduled( 'wp_version_check' );
	if ( $next_wp_cron ) {
		$age_days = ( $next_wp_cron - time() ) / DAY_IN_SECONDS;
		$cron_sys_ok = $age_days < 3;
		$checks[] = array(
			'label'  => __( 'WP Cron System', 'get-url-cron' ),
			'status' => $cron_sys_ok ? 'ok' : 'warn',
			'detail' => __( 'Next wp_version_check:', 'get-url-cron' ) . ' '
				. esc_html( gmdate( 'Y-m-d H:i:s', $next_wp_cron + $this->gmt_offset_add ) )
				. ( $cron_sys_ok ? '' : ' — ' . __( 'Overdue by more than 3 days, cron may not be firing.', 'get-url-cron' ) ),
		);
	} else {
		$checks[] = array(
			'label'  => __( 'WP Cron System', 'get-url-cron' ),
			'status' => 'warn',
			'detail' => __( 'No standard WP core events scheduled — WP cron may not be working correctly.', 'get-url-cron' ),
		);
	}

	// Loopback: can the site call itself?
	$loopback = wp_remote_get( home_url(), array( 'timeout' => 7, 'sslverify' => false ) );
	if ( is_wp_error( $loopback ) ) {
		$checks[] = array(
			'label'  => __( 'Loopback Requests', 'get-url-cron' ),
			'status' => 'fail',
			'detail' => __( 'Site cannot call itself — WP pseudo-cron relies on this:', 'get-url-cron' ) . ' ' . $loopback->get_error_message(),
		);
	} else {
		$lb_code = wp_remote_retrieve_response_code( $loopback );
		$lb_ok   = ( $lb_code >= 200 && $lb_code < 400 );
		$checks[] = array(
			'label'  => __( 'Loopback Requests', 'get-url-cron' ),
			'status' => $lb_ok ? 'ok' : 'warn',
			'detail' => __( 'HTTP response code:', 'get-url-cron' ) . ' ' . $lb_code
				. ( $lb_ok ? '' : ' — ' . __( 'Unexpected response, check server configuration.', 'get-url-cron' ) ),
		);
	}

	// Outgoing HTTP: check available transports (instant, no network request)
	$transports = array();
	if ( function_exists( 'curl_version' ) ) {
		$curl_info    = curl_version();
		$transports[] = 'cURL ' . $curl_info['version'];
	}
	if ( ini_get( 'allow_url_fopen' ) ) {
		$transports[] = 'allow_url_fopen';
	}
	$http_ok = ! empty( $transports );
	$checks[] = array(
		'label'  => __( 'Outgoing HTTP Transport', 'get-url-cron' ),
		'status' => $http_ok ? 'ok' : 'fail',
		'detail' => $http_ok
			? implode( ', ', $transports )
			: __( 'Neither cURL nor allow_url_fopen available — wp_remote_get() will fail.', 'get-url-cron' ),
	);

	// SSL support
	$ssl_ok = function_exists( 'curl_version' ) && ( curl_version()['features'] & CURL_VERSION_SSL );
	if ( ! $ssl_ok ) {
		$ssl_ok = extension_loaded( 'openssl' );
	}
	$checks[] = array(
		'label'  => __( 'SSL / HTTPS Support', 'get-url-cron' ),
		'status' => $ssl_ok ? 'ok' : 'warn',
		'detail' => $ssl_ok
			? __( 'SSL available — HTTPS URLs can be called.', 'get-url-cron' )
			: __( 'No SSL support detected — HTTPS URLs may fail. Set sslverify=false or install OpenSSL.', 'get-url-cron' ),
	);

	// Plugin jobs scheduled
	$total      = 0;
	$scheduled  = 0;
	$unscheduled = array();
	for ( $i = 1; $i <= $this->nooffields; $i++ ) {
		$url = get_option( 'geturlcron-url-' . $i );
		if ( ! empty( $url ) ) {
			$total++;
			$args = array( (int) $i );
			if ( wp_next_scheduled( 'geturlcron_event-' . $i, $args ) ) {
				$scheduled++;
			} else {
				$unscheduled[] = $i;
			}
		}
	}
	$jobs_status = ( $total === 0 || $scheduled === $total ) ? 'ok' : 'warn';

	$jobs_detail = $total === 0
		/* translators: Shown when no cron jobs have been configured yet. */
		? __( 'No jobs configured yet.', 'get-url-cron' )
		/* translators: %1$d: number of scheduled jobs, %2$d: total number of configured jobs */
		: sprintf( __( '%1$d of %2$d configured jobs are scheduled.', 'get-url-cron' ), $scheduled, $total );
	if ( ! empty( $unscheduled ) ) {
		$jobs_detail .= ' ' . __( 'Not scheduled: Job', 'get-url-cron' ) . ' ' . implode( ', ', $unscheduled ) . '.';
	}
	$checks[] = array(
		'label'  => __( 'Scheduled Plugin Jobs', 'get-url-cron' ),
		'status' => $jobs_status,
		'detail' => $jobs_detail,
	);

	// Log storage: DB table
	global $wpdb;
	$log_table      = $this->geturlcron_get_table_name();
	$cached_exists = wp_cache_get( 'table_exists', 'geturlcron', false, $cache_found );
	if ( ! $cache_found ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$cached_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) === $log_table );
		wp_cache_set( 'table_exists', $cached_exists, 'geturlcron', 3600 );
	}
	$log_table_exists = (bool) $cached_exists;
	if ( $log_table_exists ) {
		$log_row_count = wp_cache_get( 'syscheck_row_count', 'geturlcron' );
		if ( false === $log_row_count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$log_row_count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $log_table )
			);
			wp_cache_set( 'syscheck_row_count', $log_row_count, 'geturlcron', 300 );
		}
		$log_row_count = (int) $log_row_count;
	} else {
		$log_row_count = 0;
	}
	$checks[] = array(
		'label'  => __( 'Log Storage (Database)', 'get-url-cron' ),
		'status' => $log_table_exists ? 'ok' : 'fail',
		'detail' => $log_table_exists
			/* translators: %1$s: DB table name, %2$d: number of log entries */
			? sprintf( __( 'Table %1$s exists — %2$d log entries', 'get-url-cron' ), $log_table, $log_row_count )
			/* translators: %s: DB table name */
			: sprintf( __( 'Table %s does not exist — deactivate and reactivate the plugin', 'get-url-cron' ), $log_table ),
	);

	// PHP memory limit
	$memory_limit = ini_get( 'memory_limit' );
	$checks[] = array(
		'label'  => __( 'PHP Memory Limit', 'get-url-cron' ),
		'status' => 'ok',
		'detail' => $memory_limit,
	);

	// PHP max_execution_time
	$max_exec = (int) ini_get( 'max_execution_time' );
	$exec_ok  = ( $max_exec === 0 || $max_exec >= 30 );
	$checks[] = array(
		'label'  => __( 'PHP max_execution_time', 'get-url-cron' ),
		'status' => $exec_ok ? 'ok' : 'warn',
		'detail' => ( $max_exec === 0 ? __( 'unlimited', 'get-url-cron' ) : $max_exec . 's' )
			. ( $exec_ok ? '' : ' — ' . __( 'Less than 30s may cause cron jobs to time out.', 'get-url-cron' ) ),
	);

	// Render table
	$label = array(
		'ok'   => __( 'OK',      'get-url-cron' ),
		'warn' => __( 'Warning', 'get-url-cron' ),
		'fail' => __( 'Error',   'get-url-cron' ),
	);

	echo '<h1>' . esc_html__( 'System Check', 'get-url-cron' ) . '</h1>';
	echo '<table class="guc-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Check',   'get-url-cron' ) . '</th>';
	echo '<th style="width:100px;">' . esc_html__( 'Status',  'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Details', 'get-url-cron' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $checks as $check ) {
		$s = $check['status'];
		$badge_class = 'guc-badge guc-badge-sys-' . esc_attr( $s );
		$badge_label = $label[ $s ] ?? $s;
		$row_class   = ( 'ok' === $s ) ? '' : ( 'warn' === $s ? 'guc-warn' : 'guc-fail' );
		echo '<tr' . ( $row_class ? ' class="' . esc_attr( $row_class ) . '"' : '' ) . '>';
		echo '<td><strong>' . esc_html( $check['label'] ) . '</strong></td>';
		echo '<td><span class="' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span></td>';
		echo '<td>' . esc_html( $check['detail'] ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	// System cron instructions
	echo '<h2>' . esc_html__( 'Recommendation: Real System Cron', 'get-url-cron' ) . '</h2>';
	echo '<p>' . esc_html__( 'WordPress pseudo-cron only fires when someone visits the site. For reliable execution, use a real system cron.', 'get-url-cron' ) . '</p>';
	echo '<p><strong>' . esc_html__( '1. Add to wp-config.php:', 'get-url-cron' ) . '</strong></p>';
	echo '<pre>define(\'DISABLE_WP_CRON\', true);</pre>';
	echo '<p><strong>' . esc_html__( '2. Add to crontab (crontab -e) — runs every 5 minutes:', 'get-url-cron' ) . '</strong></p>';
	$wp_cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
	echo '<pre>*/5 * * * * wget -q -O /dev/null "' . esc_html( $wp_cron_url ) . '" &gt;/dev/null 2&gt;&amp;1</pre>';
	echo '<p>' . esc_html__( 'Alternative with curl:', 'get-url-cron' ) . '</p>';
	echo '<pre>*/5 * * * * curl -s -o /dev/null "' . esc_html( $wp_cron_url ) . '"</pre>';
}


private function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remainingSeconds = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $remainingSeconds);
}

private function echo_timezone_info() {
    $timezone_string = get_option( 'timezone_string' ) ?? '';
    if ( ! empty( $timezone_string ) ) {
        echo ', ';
        esc_html_e( 'Timezone', 'get-url-cron' );
        echo ': ' . esc_html( $timezone_string );
    }
    $gmt_offset = get_option( 'gmt_offset' ) ?? '';
    if ( ! empty( $gmt_offset ) ) {
        echo ', ';
        esc_html_e( 'UTC-Offset', 'get-url-cron' );
        echo ': ' . esc_html( $gmt_offset ) . ' ';
        esc_html_e( 'hours', 'get-url-cron' );
    }
}

private function format_time_distance( int $seconds ): string {
    if ( $seconds <= 0 ) {
        return '';
    }
    $days    = (int) floor( $seconds / DAY_IN_SECONDS );
    $remain  = $seconds - $days * DAY_IN_SECONDS;
    $hours   = (int) floor( $remain / HOUR_IN_SECONDS );
    $remain -= $hours * HOUR_IN_SECONDS;
    $minutes = (int) floor( $remain / MINUTE_IN_SECONDS );
    $secs    = $remain - $minutes * MINUTE_IN_SECONDS;
    $parts   = array();
    if ( $days > 0 ) {
        /* translators: %d = number of days */
        $parts[] = sprintf( _n( '%d day', '%d days', $days, 'get-url-cron' ), $days );
    }
    if ( $hours > 0 ) {
        /* translators: %d = number of hours */
        $parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'get-url-cron' ), $hours );
    }
    if ( $minutes > 0 ) {
        /* translators: %d = number of minutes */
        $parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'get-url-cron' ), $minutes );
    }
    /* translators: %d = number of seconds */
    $parts[] = sprintf( _n( '%d second', '%d seconds', $secs, 'get-url-cron' ), $secs );
    return implode( ' ', $parts );
}

public function geturlcron_logs_page() {
	global $wpdb;
	$table   = $this->geturlcron_get_table_name();
	$deldays = (int) trim( get_option( 'geturlcron-dellog-days' ) );

	if ( $deldays < 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		$this->geturlcron_clear_log_cache();
	
		echo '<h1>';
		esc_html_e( 'Logfile deleted!', 'get-url-cron' );
		echo '</h1><h2>';
		esc_html_e( 'See settings and check "Delete Logfile-Entries older than": "-1" means delete logfile', 'get-url-cron' );
		echo '</h2>';
		return true;
	}

	if ( $deldays > 0 ) {
		$cutoff = time() - ( $deldays * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE timestamp < %d', $table, $cutoff ) );
		$this->geturlcron_clear_log_cache();
	}

	$per_page     = 100;
	$current_page = max( 1, absint( wp_unslash( $_GET['logpage'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$offset       = ( $current_page - 1 ) * $per_page;

	echo '<h1>';
	esc_html_e( 'Cron Setup and Monitor - Get URL Cron: Logs', 'get-url-cron' );
	echo ', ';
	esc_html_e( 'Current Servertime', 'get-url-cron' );
	echo ': ' . esc_html( current_time( 'Y-m-d, H:i:s' ) );
	$this->echo_timezone_info();
	echo '</h1>';

	/* ---- Summary: latest execution per job ---- */
	$summary = wp_cache_get( 'log_summary', 'geturlcron' );
	if ( false === $summary ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$summary = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t1.job_no, t1.status AS last_status, t1.timestamp AS last_ts
				 FROM %i t1
				 INNER JOIN (
					 SELECT job_no, MAX(id) AS max_id
					 FROM %i
					 WHERE job_no > 0
					 GROUP BY job_no
				 ) t2 ON t1.id = t2.max_id
				 ORDER BY t1.job_no",
				$table,
				$table
			),
			ARRAY_A
		);
		wp_cache_set( 'log_summary', $summary, 'geturlcron', 300 );
	}

	echo '<table class="guc-table">';
	echo '<thead><tr>';
	echo '<th style="width:90px;">' . esc_html__( 'Status',                        'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Cronjob',                                            'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Last Execution',                                     'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Time since last execution',                          'get-url-cron' ) . '</th>';
	echo '</tr></thead><tbody>';
	if ( ! empty( $summary ) ) {
		foreach ( $summary as $row ) {
			$job_no  = (int) $row['job_no'];
			$last_ts = (int) $row['last_ts'];
			$st      = trim( $row['last_status'] );
			$args    = array( $job_no );
			$next    = wp_next_scheduled( 'geturlcron_event-' . $job_no, $args );
			$overdue = $next && ( $next - $last_ts ) < 0;
			$is_fail = ( 'FAIL' === $st ) || $overdue;
			$row_class   = $is_fail ? 'guc-fail' : 'guc-ok';
			$badge_class = $is_fail ? 'guc-badge guc-badge-fail' : 'guc-badge guc-badge-ok';
			echo '<tr class="' . esc_attr( $row_class ) . '">';
			echo '<td><span class="' . esc_attr( $badge_class ) . '">' . esc_html( $st ) . '</span></td>';
			echo '<td class="guc-mono">geturlcron_event-' . esc_html( $job_no ) . '</td>';
			echo '<td>' . esc_html( gmdate( 'Y-m-d, H:i:s', $last_ts + $this->gmt_offset_add ) ) . '</td>';
			echo '<td>' . esc_html( $this->formatTime( time() - $last_ts ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="4"><em>' . esc_html__( 'No executions logged yet.', 'get-url-cron' ) . '</em></td></tr>';
	}
	echo '</tbody></table>';

	echo '<hr><h2>' . esc_html__( 'Chronological Log Entries', 'get-url-cron' ) . '</h2>';

	/* ---- Status counts for row colouring ---- */
	$sc_raw = wp_cache_get( 'log_sc_raw', 'geturlcron' );
	if ( false === $sc_raw ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$sc_raw = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT idofrun, status, COUNT(*) AS cnt FROM %i GROUP BY idofrun, status',
				$table
			),
			ARRAY_A
		);
		wp_cache_set( 'log_sc_raw', $sc_raw, 'geturlcron', 300 );
	}
	$statuscheck = array();
	foreach ( $sc_raw as $sc ) {
		$statuscheck[ trim( $sc['idofrun'] ) . '-' . trim( $sc['status'] ) ] = (int) $sc['cnt'];
	}

	/* ---- Chronological entries ---- */
	$total_count = wp_cache_get( 'log_total_count', 'geturlcron' );
	if ( false === $total_count ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);
		wp_cache_set( 'log_total_count', $total_count, 'geturlcron', 300 );
	}
	$total_count       = (int) $total_count;
	$entries_cache_key = 'log_entries_' . $current_page;
	$entries           = wp_cache_get( $entries_cache_key, 'geturlcron' );


	if ( false === $entries ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY timestamp DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		wp_cache_set( $entries_cache_key, $entries, 'geturlcron', 300 );
	}

	echo '<table class="guc-table">';
	echo '<thead><tr>';
	echo '<th style="width:80px;">'  . esc_html__( 'Status',          'get-url-cron' ) . '</th>';
	echo '<th style="width:130px;">' . esc_html__( 'Time',            'get-url-cron' ) . '</th>';
	echo '<th style="width:44px;">'  . esc_html__( 'Job',             'get-url-cron' ) . '</th>';
	echo '<th style="width:44px;">'  . esc_html__( 'Tries',           'get-url-cron' ) . '</th>';
	echo '<th style="width:55px;">'  . esc_html__( 'Runtime',         'get-url-cron' ) . '</th>';
	echo '<th style="width:220px;">' . esc_html__( 'URL / Shortcode', 'get-url-cron' ) . '</th>';
	echo '<th style="width:180px;">' . esc_html__( 'Check',           'get-url-cron' ) . '</th>';
	echo '<th>'                      . esc_html__( 'Response',        'get-url-cron' ) . '</th>';
	echo '</tr></thead><tbody>';
	if ( ! empty( $entries ) ) {
		foreach ( $entries as $entry ) {
			$id       = trim( $entry['idofrun'] );
			$status   = trim( $entry['status'] );
			$stc_fail = $statuscheck[ $id . '-FAIL' ] ?? 0;
			$stc_ok   = $statuscheck[ $id . '-OK' ]   ?? 0;
			$stc_try  = $statuscheck[ $id . '-try' ]  ?? 0;
			switch ( $status ) {
				case 'OK':
					$row_class   = 'guc-ok';
					$badge_class = 'guc-badge guc-badge-ok';
					break;
				case 'FAIL':
					$row_class   = 'guc-fail';
					$badge_class = 'guc-badge guc-badge-fail';
					break;
				case 'try':
					$row_class   = $stc_fail > 0 ? 'guc-fail' : ( $stc_ok > 0 ? 'guc-ok' : '' );
					$badge_class = 'guc-badge guc-badge-try';
					break;
				case 'schedule':
					$row_class   = 'guc-schedule';
					$badge_class = 'guc-badge guc-badge-sched';
					break;
				default:
					$row_class   = 'guc-info';
					$badge_class = 'guc-badge guc-badge-manual';
			}
			$response = chunk_split( $entry['response'], 200, ' ' );
			echo '<tr class="' . esc_attr( $row_class ) . '">';
			echo '<td><span class="' . esc_attr( $badge_class ) . '">' . esc_html( $status ) . '</span></td>';
			echo '<td class="guc-mono">' . esc_html( gmdate( 'Y-m-d, H:i:s', (int) $entry['timestamp'] + $this->gmt_offset_add ) ) . '</td>';
			echo '<td style="text-align:center;">' . esc_html( $entry['job_no'] > 0 ? $entry['job_no'] : '—' ) . '</td>';
			echo '<td style="text-align:center;">' . esc_html( $entry['retries'] ) . '</td>';
			echo '<td style="text-align:center;">' . esc_html( $entry['runtime'] > 0 ? $entry['runtime'] . 's' : '—' ) . '</td>';
			echo '<td class="guc-url">' . esc_html( $entry['url'] ) . '</td>';
			echo '<td style="font-size:12px;">' . esc_html( $entry['info'] ) . '</td>';
			echo '<td class="guc-resp">' . esc_html( $response ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="8"><em>' . esc_html__( 'empty logfile up to now...', 'get-url-cron' ) . '</em></td></tr>';
	}
	echo '</tbody></table>';

	$total_pages = (int) ceil( $total_count / $per_page );
	if ( $total_pages > 1 || $total_count > 0 ) {
		$base_url = admin_url( 'admin.php?page=geturlcronsettingspage&tab=logs' );
		echo '<div class="guc-pagination">';
		echo '<span class="guc-count">';
		echo esc_html( sprintf(
			/* translators: 1: first entry number, 2: last entry number, 3: total entries */
			__( '%1$d–%2$d of %3$d entries', 'get-url-cron' ),
			$offset + 1,
			min( $offset + $per_page, $total_count ),
			$total_count
		) );
		echo '</span>';
		for ( $p = 1; $p <= $total_pages; $p++ ) {
			$cls = ( $p === $current_page ) ? 'guc-current' : '';
			echo '<a href="' . esc_url( $base_url . '&logpage=' . $p ) . '"' . ( $cls ? ' class="' . esc_attr( $cls ) . '"' : '' ) . '>' . esc_html( $p ) . '</a>';
		}
		echo '</div>';
	}

	/* ---- Migration card ---- */
	$migrated    = (int) get_option( 'geturlcron-db-migrated' );
	$old_logfile = $this->geturlcron_getlogfile();
	$old_exists  = file_exists( $old_logfile );
	if ( $old_exists || ! $migrated ) {
		$uid        = get_current_user_id();
		$mig_result = get_transient( 'geturlcron_migration_result_' . $uid );
		delete_transient( 'geturlcron_migration_result_' . $uid );

		echo '<div class="card" style="max-width:100%;margin-top:16px;border-left:4px solid #2271b1;">';
		echo '<h2 style="margin-top:0;padding-bottom:10px;border-bottom:1px solid #eee;color:#2271b1;">&#128260; ';
		esc_html_e( 'Log Migration: File → Database', 'get-url-cron' );
		echo '</h2>';

		if ( is_array( $mig_result ) ) {
			if ( $mig_result['status'] === 'ok' ) {
				echo '<div class="notice notice-success inline" style="margin:8px 0;"><p>';
				echo esc_html(
					sprintf(
						/* translators: %d = number of migrated log entries */
						__( 'Migration successful: %d log entries imported into the database. The old log file has been renamed to *.migrated.', 'get-url-cron' ),
						$mig_result['count']
					)
				);
				echo '</p></div>';
			} elseif ( $mig_result['status'] === 'fs_error' ) {
				echo '<div class="notice notice-error inline" style="margin:8px 0;"><p>';
				esc_html_e( 'Automatic migration failed: could not access the filesystem. Please migrate manually.', 'get-url-cron' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error inline" style="margin:8px 0;"><p>';
				esc_html_e( 'Migration failed.', 'get-url-cron' );
				echo ' Status: ' . esc_html( $mig_result['status'] );
				echo '</p></div>';
			}
		}

		if ( $old_exists ) {
			echo '<p>';
			esc_html_e( 'Old log file found:', 'get-url-cron' );
			echo ' <code>' . esc_html( $old_logfile ) . '</code></p>';
			if ( ! is_array( $mig_result ) || $mig_result['status'] !== 'ok' ) {
				echo '<form method="post" action="admin.php?page=geturlcronsettingspage&tab=logs">';
				wp_nonce_field( 'geturlcron_nc', 'geturlcron_nc' );
				echo '<input type="hidden" name="subaction" value="migratelogs">';
				submit_button( __( 'Import old log file into database', 'get-url-cron' ), 'secondary', 'submit', false );
				echo '</form>';
			}
		}
		echo '</div>';
	}
	return true;
}

public function geturlcron_cronjobs_page() {
	$cjArr = _get_cron_array();
	if ( empty( $cjArr ) ) {
		$cjArr = array();
	}

	$cronschedulesArr  = wp_get_schedules();
	$plugincronjobs    = 0;
	$nonplugincronjobs = 0;
	$out_kl            = array();
	$out_opout         = array();
	$out_opoutlink     = array();
	$out_cronschedulesArr = array();
	$out_std           = array();
	$out_nexttime      = array();
	$out_nextdistVal   = array();
	$out_nextdate      = array();
	$out_nextdist      = array();
	$out_else          = array();
	$outelse_k1        = array();
	$outelse_nextdate  = array();
	$outelse_nextdist  = array();
	$outelse_recurrence = array();
	$outelse_args      = array();

	foreach ( $cjArr as $k => $v ) {
		foreach ( $v as $k1 => $v1 ) {
			$noofjob    = preg_replace( "/geturlcron_event-/", "", $k1 );
			$showcronjob = TRUE;
			$op          = get_option( "geturlcron-url-" . $noofjob );
			if ( $this->is_relative_url( $op ) ) {
				$op = $this->add_domain_to_url( $op );
			}
			if ( empty( $op ) ) {
				$jobhook = "geturlcron_event-" . $noofjob;
				$args    = array( $noofjob );
				wp_clear_scheduled_hook( $jobhook, $args );
				$showcronjob = FALSE;
			}
			if ( $noofjob >= 1 && $showcronjob ) {
				foreach ( $v1 as $k2 => $v2 ) {
					$intv = $v2["schedule"];
					if ( empty( $intv ) ) {
						$intv = __( "run only once", 'get-url-cron' );
					}
				}
				$plugincronjobs++;
				$out_kl[] = $k1;

				$opout           = trim( $op );
				$out_opout[]     = $opout;
				$out_opoutlink[] = $op;

				$out_cronschedulesArr[] = $cronschedulesArr[ $intv ]['display'] ?? $intv;
				$std                    = get_option( "geturlcron-startdate-" . $noofjob );
				$out_std[]              = $std;

				$args      = array( (int) $noofjob );
				$nexttime  = wp_next_scheduled( $k1, $args );
				$nextdate  = "";
				$nextdist  = "";
				$out_nexttime[] = $nexttime;
				if ( $nexttime > 0 ) {
					$nextdate    = gmdate( "Y-m-d, H:i", $nexttime + $this->gmt_offset_add );
					$nextdistVal = $nexttime - time();
					$out_nextdistVal[] = $nextdistVal;
					if ( $nextdistVal > 0 ) {
						$nextdist        = $this->format_time_distance( $nextdistVal );
						$out_nextdate[]  = $nextdate;
						$out_nextdist[]  = $nextdist;
					} else {
						$out_else[] = __( 'reload this page please', 'get-url-cron' );
					}
				} else {
					$out_nextdate[] = $nextdate;
					$out_nextdist[] = $nextdist;
				}
			} else {
				$recurrence = "";
				$args       = "";

				foreach ( $v1 as $k2 => $v2 ) {
					$recurrence = $v2["schedule"];
					if ( empty( $v2["args"] ) ) {
						$args = __( "none", 'get-url-cron' );
					} else {
						$args = json_encode( $v2["args"] );
					}
				}
				if ( $recurrence == "" ) {
					$recurrence = __( 'Not repeating', 'get-url-cron' );
				}

				$recurrence = $cronschedulesArr[ $recurrence ]["display"] ?? $recurrence;

				$eventdetails = wp_get_scheduled_event( $k1 );
				$nexttime     = $eventdetails->timestamp ?? '';

				$nonplugincronjobs++;
				$outelse_k1[] = $k1;
				$nextdate      = "";
				$nextdist      = "";
				if ( $nexttime > 0 ) {
					$nextdate    = gmdate( "Y-m-d, H:i:s", $nexttime + $this->gmt_offset_add );
					$nextdistVal = $nexttime - time();
					$nextdist    = $this->format_time_distance( $nextdistVal );
				}
				$outelse_nextdate[]    = $nextdate;
				$outelse_nextdist[]    = $nextdist;
				$outelse_recurrence[]  = $recurrence;
				$outelse_args[]        = $args;
			}
		}
	}

	echo "<h1>Cron Setup and Monitor - Get URL Cron: ";
	if ( $plugincronjobs == 0 ) {
		esc_html_e( 'No Cronjob defined by this Plugin', 'get-url-cron' );
	} elseif ( $plugincronjobs == 1 ) {
		echo esc_html( $plugincronjobs ) . " ";
		esc_html_e( 'Cronjob defined by this Plugin', 'get-url-cron' );
	} else {
		echo esc_html( $plugincronjobs ) . " ";
		esc_html_e( 'Cronjobs defined by this Plugin', 'get-url-cron' );
	}
	echo ' - ' . esc_html( $nonplugincronjobs ) . ' ';
	esc_html_e( "other Cronjobs", 'get-url-cron' );
	echo '</h1>';

	echo "<h2>";
	esc_html_e( "All upcoming run times and distances are calculated based on this time setting", 'get-url-cron' );
	echo " - ";
	esc_html_e( "Current Servertime", 'get-url-cron' );
	echo ": " . esc_html( current_time( "Y-m-d, H:i:s" ) );
	$this->echo_timezone_info();
	echo "</h2>";

	echo '<table class="guc-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Cronjob',        'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Recurrence',     'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Next Run',        'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'First Run',       'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'URL / Shortcode', 'get-url-cron' ) . '</th>';
	echo '</tr></thead><tbody>';
	if ( $plugincronjobs > 0 ) {
		foreach ( $out_kl as $k => $v ) {
			$nd   = $out_nextdate[$k]    ?? '';
			$ndi  = $out_nextdist[$k]    ?? '';
			$ndiv = $out_nextdistVal[$k] ?? 0;
			echo '<tr>';
			echo '<td class="guc-mono">' . esc_html( $v ) . '</td>';
			echo '<td>' . esc_html( $out_cronschedulesArr[$k] ) . '</td>';
			echo '<td>';
			if ( $out_nexttime[$k] > 0 ) {
				if ( $ndiv > 0 && ! empty( $nd ) ) {
					echo esc_html( $nd ) . '<br><small style="color:#666;">' . esc_html( $ndi ) . '</small>';
				} else {
					echo '<em>' . esc_html__( 'reload this page', 'get-url-cron' ) . '</em>';
				}
			}
			echo '</td>';
			echo '<td class="guc-mono">' . esc_html( $out_std[$k] ?? '' ) . '</td>';
			echo '<td class="guc-url">';
			if ( preg_match( '/^\[/', $out_opout[$k] ) ) {
				echo '<em>' . esc_html__( 'Shortcode', 'get-url-cron' ) . ':</em> ' . esc_html( $out_opout[$k] );
			} else {
				echo '<a href="' . esc_url( $out_opoutlink[$k] ) . '" target="_blank">' . esc_html( $out_opout[$k] ) . '</a>';
			}
			echo '</td></tr>';
		}
	} else {
		echo '<tr><td colspan="5"><em>' . esc_html__( 'No Cronjob defined with this Plugin', 'get-url-cron' ) . '</em></td></tr>';
	}
	echo '</tbody></table>';

	echo '<h2 style="margin-top:24px;">' . esc_html__( 'Cronjob NOT from this Plugin', 'get-url-cron' ) . '</h2>';
	echo '<table class="guc-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Hook',       'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Recurrence', 'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Next Run',   'get-url-cron' ) . '</th>';
	echo '<th>' . esc_html__( 'Arguments',  'get-url-cron' ) . '</th>';
	echo '</tr></thead><tbody>';
	if ( ! empty( $outelse_k1 ) ) {
		foreach ( $outelse_k1 as $k => $v ) {
			echo '<tr>';
			echo '<td class="guc-mono">' . esc_html( $v ) . '</td>';
			echo '<td>' . esc_html( $outelse_recurrence[$k] ) . '</td>';
			echo '<td>' . esc_html( $outelse_nextdate[$k] );
			if ( ! empty( $outelse_nextdist[$k] ) ) {
				echo '<br><small style="color:#666;">' . esc_html( $outelse_nextdist[$k] ) . '</small>';
			}
			echo '</td>';
			echo '<td><code class="guc-mono">' . esc_html( $outelse_args[$k] ) . '</code></td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="4"><em>' . esc_html__( 'No other cronjobs found.', 'get-url-cron' ) . '</em></td></tr>';
	}
	echo '</tbody></table>';
}


public function geturlcron_settings_page() {
	echo '<h1>';
	esc_html_e("Cron Setup and Monitor - Get URL Cron",'get-url-cron');
	echo ": ";
	esc_html_e("Define Cronjobs with this Plugin",'get-url-cron').": ";
	echo '</h1>';
	echo '<form method="post" action="admin.php?page=geturlcronsettingspage&tab=settings">';
    wp_nonce_field( "geturlcron_nc", "geturlcron_nc" );
	submit_button();
		echo '<input type="hidden" name="subaction" value="savecronjobs">';
		settings_fields( 'geturlcron-options' ); 
		do_settings_sections( 'geturlcron-options' ); 
		$fi = self::$fi;
		$fi_size = self::$fi_size;

		$fi_out = array(
			"url" => __("URL", 'get-url-cron'),
			"interval" => __("Recurrence", 'get-url-cron'),
			"startdate" => __("First Run (year-mon-day hr:min)", 'get-url-cron'),
			"retries" => __("Retries", 'get-url-cron'),
			"requiredformat" => __("Required format", 'get-url-cron'),
			"requiredjsonfield" => __("Required JSON field or string", 'get-url-cron'),
			"sendmail" => __("Sendmail", 'get-url-cron'),
		);
		
		$reqformatArr = self::$reqformatArr;
		
		echo '<table class="guc-table">';
		echo '<thead><tr>';
		echo '<th style="width:36px;">' . esc_html__( 'No', 'get-url-cron' ) . '</th>';
		foreach ( $fi as $k => $v ) {
			echo '<th>';
			if ( $k === 'url' ) {
				esc_html_e( 'URL or WP-Shortcode: If the URL starts', 'get-url-cron' );
				echo '<br><small>';
				esc_html_e( 'with "/", ', 'get-url-cron' );
				echo esc_url( home_url() );
				esc_html_e( ' prepended to the URL', 'get-url-cron' );
				echo '</small>';
			} else {
				echo esc_html( $fi_out[$k] );
				if ( $k === 'startdate' ) {
					echo '<br><small>';
					esc_html_e( 'Current Servertime', 'get-url-cron' );
					echo ': ' . esc_html( current_time( 'Y-m-d H:i:s' ) );
					$this->echo_timezone_info();
					echo '</small>';
				}
			}
			echo '</th>';
		}
		echo '<th style="width:90px;">' . esc_html__( 'Execute Job', 'get-url-cron' ) . '</th>';
		echo '</tr></thead><tbody>';

		$cronschedulesArr = wp_get_schedules();
		for ( $r = 1; $r <= $this->nooffields; $r++ ) {
			echo '<tr>';
			echo '<td style="text-align:center;font-weight:bold;">' . esc_html( $r ) . '</td>';
			foreach ( $fi as $k => $v ) {
				echo '<td>';
				$ki = 'geturlcron-' . $k . '-' . $r;
				$op = get_option( $ki );
				if ( $k === 'interval' ) {
					if ( $op === '' ) { $op = 'daily'; }
					echo '<select name="' . esc_attr( $ki ) . '">';
					$scArr_display = array();
					$scArr_key     = array();
					foreach ( $cronschedulesArr as $csk => $csv ) {
						$scArr_display[ $csv['interval'] ] = $csv['display'];
						$scArr_key[ $csv['interval'] ]     = $csk;
					}
					ksort( $scArr_key, SORT_NUMERIC );
					foreach ( $scArr_key as $csk => $csv ) {
						echo '<option value="' . esc_attr( $csv ) . '"' . (( $op === $csv ) ? ' selected' : '') . '>' . esc_html( $scArr_display[$csk] );
					}
					echo '</select>';
				} elseif ( $k === 'requiredformat' ) {
					echo '<select name="' . esc_attr( $ki ) . '">';
					foreach ( $reqformatArr as $csk => $csv ) {
						echo '<option value="' . esc_attr( $csk ) . '"' . (( $op === $csk ) ? ' selected' : '') . '>' . esc_html( $csv );
					}
					echo '</select>';
				} elseif ( $k === 'retries' ) {
					echo '<select name="' . esc_attr( $ki ) . '">';
					for ( $rr = 1; $rr <= 10; $rr++ ) {
						echo '<option value="' . esc_attr( $rr ) . '"' . (( $op == $rr ) ? ' selected' : '') . '>' . esc_html( $rr );
					}
					echo '</select>';
				} elseif ( $k === 'sendmail' ) {
					echo '<input type="checkbox" name="' . esc_attr( $ki ) . '" value="yes"' . (( $op === 'yes' || ! isset( $op ) ) ? ' checked' : '') . '>';
				} else {
					$placeholder = '';
					$inputtype   = 'text';
					if ( $k === 'startdate' ) {
						$placeholder = gmdate( 'Y-m-d H:i' );
						$inputtype   = 'datetime-local';
					}
					if ( $k === 'url' ) {
						$placeholder = __( 'http... OR /path... OR [shortcode id...]', 'get-url-cron' );
					}
					$opout = $op;
					echo '<input type="' . esc_attr( $inputtype ) . '" placeholder="' . esc_attr( $placeholder ) . '" name="' . esc_attr( $ki ) . '" value="' . esc_attr( $opout ) . '" size="' . esc_attr( $fi_size[$k] ) . '">';
				}
				echo '</td>';
			}
			echo '<td style="text-align:center;">';
			$nonce = wp_create_nonce( 'getcronurl_' . $r );
			$url   = '?page=geturlcronsettingspage&tab=settings&action=geturlcron&no=' . $r . '&hash=' . $nonce;
			echo '<a href="' . esc_url( $url ) . '" class="button button-small">' . esc_html__( 'Execute Job', 'get-url-cron' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
}
	
public function register_geturlcronsettings() {
	register_setting( 'geturlcron-options-details', 'geturlcron-emailadr',  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	register_setting( 'geturlcron-options-details', 'geturlcron-timeout',  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	register_setting( 'geturlcron-options-details', 'geturlcron-uninstall-deleteall',  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	register_setting( 'geturlcron-options-details', 'geturlcron-dellog-days',  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	register_setting( 'geturlcron-options-details', 'geturlcron-maxno-cronjobs',  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	register_setting( 'geturlcron-options-details', 'geturlcron-mailonlyfail',  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	for ($r = 1; $r <= $this->nooffields; $r++) {
		register_setting( 'geturlcron-options', 'geturlcron-url-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
		register_setting( 'geturlcron-options', 'geturlcron-interval-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
		register_setting( 'geturlcron-options', 'geturlcron-startdate-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
		register_setting( 'geturlcron-options', 'geturlcron-retries-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
		register_setting( 'geturlcron-options', 'geturlcron-requiredjsonfield-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
		register_setting( 'geturlcron-options', 'geturlcron-requiredformat-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
		register_setting( 'geturlcron-options', 'geturlcron-sendmail-'.$r,  ['sanitize_callback' => [ $this, 'sanitize_geturlcron_register_setting'] ] );
	}
}	

	private function sanitize_geturlcron_register_setting( $input ) {
		return sanitize_text_field( $input );
	}

	private function is_relative_url($url) {
		if (preg_match("/^\//", $url)) {
			return TRUE;
		}
		return FALSE;
	}
	
	private function add_domain_to_url($url) {
		return home_url().$url;
	}
	
	private function geturlcron_set_urlSettingsarr() {
		for ($r = 1; $r <= $this->nooffields; $r++) {
			foreach(self::$fi as $k => $v) {
				$ki = "geturlcron-".$k."-".$r;
				$op = get_option($ki);
				if ($k=="url" && $this->is_relative_url($op)) {
					$op = $this->add_domain_to_url($op);
				}
				$this->urlSettingsArr[$r][$k] = $op;
			}
		}
	}

	private function geturlcron_set_cronjoboptions() {
		// Nonce verified once before the loop.
		if ( ! isset( $_REQUEST['geturlcron_nc'] ) ) {
			return;
		}
		$req_geturlcron_nc = sanitize_text_field( wp_unslash( $_REQUEST['geturlcron_nc'] ) );
		if ( ! wp_verify_nonce( $req_geturlcron_nc, 'geturlcron_nc' ) ) {
			return;
		}
		for ($r = 1; $r <= $this->nooffields; $r++) {
			foreach(self::$fi as $k => $v) {
				$ki = "geturlcron-".$k."-".$r;
				$ppin = sanitize_text_field(wp_unslash($_POST[$ki] ?? null));
				update_option($ki, $this->geturlcron_handlePost_input($ki, $ppin));
			}
		}
	}

	public function geturlcron_add_action_cronjob($rt="") {
					$cfArr = explode("-", current_filter());
					$no = trim($cfArr[1]); 
					if (!empty($this->urlSettingsArr[$no])) {
						$urltouse = $this->urlSettingsArr[$no];
						$this->geturlcron_executejob($urltouse, $no, $rt); 
					}
	}

	public function geturlcron_activatejobs() {
		for ($nol = 1; $nol <= count($this->urlSettingsArr); $nol++) {
			if (!empty($this->urlSettingsArr[$nol])) {
				$urltouse = $this->urlSettingsArr[$nol];
				$this->geturlcron_schedule($urltouse, $nol);
			}
		}
	}

	public function geturlcron_check_mailadress_list($mailadress_list) {
		$retcolor = array();
		$retmessage = array();
		$mailadr = preg_replace("/[, ]/", ";", $mailadress_list);
		$mailadrArr = preg_split("/[,; ]/", $mailadr);
		foreach( $mailadrArr as $k => $v) {
			if (filter_var($v, FILTER_VALIDATE_EMAIL)) {
				$retcolor[] = "black";
				$retmessage[] = __("OK:",'get-url-cron')." ".$v;
			} else {
				if (!empty($v)) {
					$retcolor[] = "red";
					$retmessage[] = __("CHECK E-Mailadress please:",'get-url-cron')." ".$v;
				}
			}
		}
		return array('color' => $retcolor, 'message' => $retmessage);
	}
	
	public function geturlcron_action_handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return TRUE;
		}
		$ppin = sanitize_text_field(wp_unslash($_POST["subaction"] ?? null));
		$this->subaction = $this->geturlcron_handlePost_input("subaction", $ppin);
		if ("settings"==$this->subaction) {
			# there must be a valid nonce to save 
			$req_geturlcron_nc = "";
			if (isset($_REQUEST['geturlcron_nc'])) {
				$req_geturlcron_nc = sanitize_text_field(wp_unslash($_REQUEST['geturlcron_nc']));
			}
			$nonceCheck = wp_verify_nonce( $req_geturlcron_nc, "geturlcron_nc" );
			if ($nonceCheck) {
				$ppin = sanitize_text_field(wp_unslash($_POST["geturlcron-timeout"] ?? null));
				$input_geturlcron_timeout = $this->geturlcron_input_integer($this->geturlcron_handlePost_input("geturlcron-timeout", $ppin), 60, TRUE, TRUE, TRUE);
				update_option("geturlcron-timeout", $input_geturlcron_timeout);
			
				$ppin = sanitize_text_field(wp_unslash($_POST["geturlcron-dellog-days"] ?? null));
				$input_geturlcron_dellog_days = $this->geturlcron_input_integer($this->geturlcron_handlePost_input("geturlcron-dellog-days", $ppin), 20, TRUE, FALSE, TRUE);
				update_option("geturlcron-dellog-days", $input_geturlcron_dellog_days);
			
				$ppin = sanitize_text_field(wp_unslash($_POST["geturlcron-maxno-cronjobs"] ?? null));
				$input_geturlcron_maxno_cronjobs = $this->geturlcron_input_integer($this->geturlcron_handlePost_input("geturlcron-maxno-cronjobs", $ppin), 15, TRUE, FALSE, TRUE);
				update_option("geturlcron-maxno-cronjobs", $input_geturlcron_maxno_cronjobs);
						
				$ppin = sanitize_text_field(wp_unslash($_POST["geturlcron-emailadr"] ?? null));
				$input_geturlcron_emailadr = $this->geturlcron_handlePost_input("geturlcron-emailadr", $ppin);
				update_option("geturlcron-emailadr", $input_geturlcron_emailadr);
			
				$ppin = sanitize_text_field(wp_unslash($_POST["geturlcron-uninstall-deleteall"] ?? null));
				$input_geturlcron_uninstall_deleteall = $this->geturlcron_handlePost_input("geturlcron-uninstall-deleteall", $ppin);
				if (1!=$input_geturlcron_uninstall_deleteall) {
					$input_geturlcron_uninstall_deleteall  = 0;
				}
				update_option("geturlcron-uninstall-deleteall", $input_geturlcron_uninstall_deleteall);

				$ppin = sanitize_text_field(wp_unslash($_POST["geturlcron-mailonlyfail"] ?? null));
				$input_geturlcron_mailonlyfail = $this->geturlcron_handlePost_input("geturlcron-mailonlyfail", $ppin);
				if (1!=$input_geturlcron_mailonlyfail) {
					$input_geturlcron_mailonlyfail  = 0;
				}
				update_option("geturlcron-mailonlyfail", $input_geturlcron_mailonlyfail);
			} else {
				return TRUE;
			}
		}
		if ("savecronjobs"==$this->subaction) {
			# there must be a valid nonce to save
			$req_geturlcron_nc = isset($_REQUEST['geturlcron_nc']) ? sanitize_text_field(wp_unslash($_REQUEST['geturlcron_nc'])) : '';
			$nonceCheck = wp_verify_nonce( $req_geturlcron_nc, "geturlcron_nc" );
			if ($nonceCheck) {
				$this->geturlcron_unschedulejobs();
				$this->geturlcron_set_cronjoboptions();
				$this->geturlcron_set_urlSettingsarr();
				$this->geturlcron_activatejobs();
			} else {
				return TRUE;
			}
		}
		if ( "migratelogs" === $this->subaction ) {
			$req_geturlcron_nc = isset( $_REQUEST['geturlcron_nc'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['geturlcron_nc'] ) ) : '';
			if ( ! wp_verify_nonce( $req_geturlcron_nc, 'geturlcron_nc' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'get-url-cron' ) );
			}
			$uid    = get_current_user_id();
			$result = $this->geturlcron_migrate_logfile();
			set_transient( 'geturlcron_migration_result_' . $uid, $result, 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=geturlcronsettingspage&tab=logs' ) );
			exit;
		}
		// Auto-migrate old log file to database on first admin page load.
		if ( is_admin()
			&& ! (int) get_option( 'geturlcron-db-migrated' )
			&& file_exists( $this->geturlcron_getlogfile() )
			&& ! get_transient( 'geturlcron_auto_migrate_tried' ) ) {
			set_transient( 'geturlcron_auto_migrate_tried', 1, 5 * MINUTE_IN_SECONDS );
			$uid    = get_current_user_id();
			$result = $this->geturlcron_migrate_logfile();
			set_transient( 'geturlcron_migration_result_' . $uid, $result, 300 );
		}

		$ppin = sanitize_text_field(wp_unslash($_GET["action"] ?? null));
		$this->action = $this->geturlcron_handleGet_input("action", $ppin);
		
		if ("geturlcron"==$this->action) {
			$req_hash = "";
			if (isset($_REQUEST["hash"])) {
				$req_hash = sanitize_text_field(wp_unslash($_REQUEST["hash"]));
			}
			$noin = sanitize_text_field(wp_unslash($_GET["no"] ?? null));
			if (is_null($noin) || !is_numeric($noin)) {
				return TRUE;
			}
			$noncecheckok = wp_verify_nonce( $req_hash, 'getcronurl_' . (int) $noin );
			if (!$noncecheckok) {
				return TRUE;
			}
			$no = sanitize_text_field($noin);
			if (is_numeric($no) && $no>0) {
				$this->geturlcron_singlerun($no);
			}
		}
		return TRUE;
	}

	private function geturlcron_input_integer($input, $defaultvalue, $isnumeric, $ispositive, $round) {
		if ($isnumeric && !is_numeric($input)) {
			$input = $defaultvalue;
		}
		if ($ispositive && $input<=0) {
			$input = $defaultvalue;
		}
		if ($round && $input!=round($input)) {
			$input = round($input+0.5);
		}
		return $input;
	}
	
	private function geturlcron_handlePost_input($postparm, $ppin = NULL) { 
		if (is_null($ppin)) {
			return "";
		}	
		$pp = "";
		if (isset($ppin)) {
			$ppval = $ppin;
			if (preg_match("/^geturlcron-startdate-/", $postparm)) {
				$ppval = preg_replace("/T/", " ",  $ppval);
			}
			$pp = $ppval;
		}
		return $pp;
	}
	private function geturlcron_handleGet_input($postparm, $ppin = NULL) {
		if (is_null($ppin)) {
			return "";
		}
		$pp = sanitize_text_field($ppin);
		if ("geturlcron"!=$pp) {
			return "";
		}
		return $pp;
	}


	private function geturlcron_getschedule_interval($urlArr, $no) {
		$autoadd = "";
		$retVal = array();
		$schedurl = trim($urlArr["url"]);
		if (empty($schedurl)) {
			return $retVal;
		}
		$sedeuleofurl = $urlArr["interval"];
		$timefirstexec = strtotime($urlArr["startdate"]);
		$curtime = time();
		if (empty($timefirstexec)) {
			$timefirstexec = $curtime;
		}
		if ($curtime>$timefirstexec) {
			# first run in past: set to next
			$schedules = wp_get_schedules();
			$secintv = $schedules[ $sedeuleofurl ]['interval'];  ## interval in seconds
			if ($secintv>0) {
				$numberOfIntervalsTillNextExec = round(((time()-$timefirstexec)/$secintv) + 0.5);
			} else {
				$numberOfIntervalsTillNextExec = 0;
			}
			if ($secintv==-1) {
				return -1;
			}
			$nextExecTime = $timefirstexec + $numberOfIntervalsTillNextExec * $secintv;
			$autoadd .= __("interval in sec:",'get-url-cron')." $secintv, ";
			$autoadd .= __("firstexec:",'get-url-cron')." $timefirstexec (".$urlArr["startdate"]."), ";
			$autoadd .= __("numberOfIntervalsTillNextExec:",'get-url-cron')." $numberOfIntervalsTillNextExec, ";
			$autoadd .= __("nextExecTime:",'get-url-cron')." $nextExecTime (".gmdate("Y-m-d, H:i:s", $nextExecTime + $this->gmt_offset_add)."), ";
		} else {
			# first run in the future
			$nextExecTime = $timefirstexec;
		}
	
		$retVal["sedeuleofurl"] = $sedeuleofurl;
		$retVal["autoadd"] = $autoadd;
		$retVal["timenextexec"] = $nextExecTime;
		
		return $retVal;
		
	}

	private function geturlcron_singlerun($no) {
		$logl = $this->geturlcron_log(
			"", 
			$this->urlSettingsArr[$no]["url"], 
			"", 
			"", 
			"", 
			"", 
			"manually started");
		$this->geturlcron_savelog($logl);
		$args = array($no);
		wp_schedule_single_event( time(), 'geturlcron_event-'.$no, $args);
	}	


	private function geturlcron_schedule($urlArr, $no) {
		$retVal = $this->geturlcron_getschedule_interval($urlArr, $no);
		if ($retVal==-1) {
			return TRUE;
		}
		if (!empty($retVal["sedeuleofurl"])) {
			$retVal["timenextexec"] = (int) $retVal["timenextexec"];
			$datedstr = gmdate("Y-m-d, H:i:s", $retVal["timenextexec"]);
			if (($retVal["timenextexec"]>0) && ("geturlcron_disable"!=$retVal["sedeuleofurl"])){
				$logl = $this->geturlcron_log(
					"geturlcron-$no",
					$urlArr["url"],
					"",
					"interval: ".$retVal["sedeuleofurl"].",".$retVal["autoadd"]." Next Run: ".$datedstr,
					"",
					"",
					"schedule");
				$this->geturlcron_savelog($logl);
				$args = array($no);
				wp_schedule_event( $retVal["timenextexec"], $retVal["sedeuleofurl"], 'geturlcron_event-'.$no, $args);
			}
		}
	}	

	private function geturlcron_log( $idofrun, $url, $done_retries, $returnvalue, $info, $runtime, $status, $gucno = "" ) {
		return array(
			'idofrun'   => (string) $idofrun,
			'timestamp' => time(),
			'job_no'    => (int) $gucno,
			'status'    => trim( (string) $status ),
			'retries'   => (int) $done_retries,
			'runtime'   => (int) $runtime,
			'url'       => (string) $url,
			'info'      => (string) $info,
			'response'  => substr( (string) $returnvalue, 0, 300 ),
		);
	}
	
	
	

	private static function geturlcron_setlogfile() {
		$ulp = wp_upload_dir();
		$plugincachepath = $ulp["basedir"]."/geturlcron";
		$plugincachepath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $plugincachepath);

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$url = wp_nonce_url( 'index.php', 'my-nonce_geturlcron_setlogfile' );
		$credentials = request_filesystem_credentials( $url );
		if ( ! WP_Filesystem( $credentials ) ) {
			return FALSE;
		}
		global $wp_filesystem;
		if ( !$wp_filesystem->is_dir( $plugincachepath ) ) {
			$parts = explode(DIRECTORY_SEPARATOR, $plugincachepath);
			$currentPath = DIRECTORY_SEPARATOR;
			foreach ($parts as $part) {
				if (empty($part)) continue;
				$currentPath .= $part . DIRECTORY_SEPARATOR;
				if (!$wp_filesystem->is_dir( $currentPath )) {
					$wp_filesystem->mkdir($currentPath);
				}
            }
		}
		self::$logfile = $plugincachepath."/geturlcron-log.cgi";
	}
	public static function geturlcron_getlogfile() {
		return self::$logfile;
	}

	private function geturlcron_maybe_upgrade_table(): void {
		if ( (int) get_option( 'geturlcron-db-version' ) >= 2 ) {
			return;
		}
		global $wpdb;
		$table = $this->geturlcron_get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$index = $wpdb->get_row(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s AND Non_unique = 0', $table, 'idofrun_ts' )
		);
		if ( $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX idofrun_ts', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX idx_idofrun (idofrun(32))', $table ) );
		}
		update_option( 'geturlcron-db-version', 2 );
	}

	private function geturlcron_get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'geturlcron_logs';
	}

	private function geturlcron_create_table() {
		global $wpdb;
		$table = $this->geturlcron_get_table_name();
		$cached_exists = wp_cache_get( 'table_exists', 'geturlcron', false, $cache_found );
		if ( ! $cache_found ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$cached_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
			wp_cache_set( 'table_exists', $cached_exists, 'geturlcron', 3600 );
		}
		if ( $cached_exists ) {
			return;
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			idofrun     VARCHAR(32)     NOT NULL DEFAULT '',
			timestamp   INT UNSIGNED    NOT NULL DEFAULT 0,
			job_no      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			status      VARCHAR(20)     NOT NULL DEFAULT '',
			retries     TINYINT UNSIGNED NOT NULL DEFAULT 0,
			runtime     INT UNSIGNED    NOT NULL DEFAULT 0,
			url         TEXT            NOT NULL,
			info        TEXT            NOT NULL,
			response    TEXT            NOT NULL,
			PRIMARY KEY (id),
			KEY job_no  (job_no),
			KEY timestamp (timestamp),
			KEY idx_idofrun (idofrun(32))
		) {$charset_collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		wp_cache_delete( 'table_exists', 'geturlcron' );
	}

	public function geturlcron_migrate_logfile() {
		global $wpdb;
		$logfile = $this->geturlcron_getlogfile();
		if ( ! file_exists( $logfile ) ) {
			return array( 'status' => 'nofile', 'count' => 0 );
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$cred_url   = wp_nonce_url( 'index.php', 'my-nonce_geturlcron_migrate' );
		$credentials = request_filesystem_credentials( $cred_url );
		if ( ! WP_Filesystem( $credentials ) ) {
			return array( 'status' => 'fs_error', 'count' => 0 );
		}
		global $wp_filesystem;
		$content = $wp_filesystem->get_contents( $logfile );
		if ( $content === false ) {
			return array( 'status' => 'read_error', 'count' => 0 );
		}
		$table     = $this->geturlcron_get_table_name();
		$separator = ' /// ';
		$lines     = explode( "\n", $content );
		$count     = 0;

foreach ( $lines as $line ) {
    $line = trim( $line );
    if ( empty( $line ) ) { continue; }
    $parts = explode( $separator, $line );
    if ( count( $parts ) < 8 ) { continue; }
    $idofrun     = trim( $parts[0] );
    $time_parts  = explode( '=', $parts[1], 2 );
    $timestamp   = isset( $time_parts[1] ) ? (int) trim( $time_parts[1] ) : 0;
    if ( $timestamp < 1 ) { continue; }
    $retry_parts = explode( '=', $parts[3], 2 );
    $retries     = isset( $retry_parts[1] ) ? (int) trim( $retry_parts[1] ) : 0;
    $info        = trim( $parts[4] );
    $url_parts   = explode( '=', $parts[5], 2 );
    $url         = isset( $url_parts[1] ) ? trim( $url_parts[1] ) : '';
    $rt_parts    = explode( '=', $parts[6], 2 );
    $runtime     = isset( $rt_parts[1] ) ? (int) trim( $rt_parts[1] ) : 0;
    $resp_parts  = explode( '=', $parts[7], 2 );
    $response    = isset( $resp_parts[1] ) ? trim( $resp_parts[1] ) : '';
    $status      = isset( $parts[8] ) ? trim( $parts[8] ) : '';
    $job_no      = isset( $parts[9] ) ? (int) trim( $parts[9] ) : 0;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            'INSERT IGNORE INTO %i (idofrun, timestamp, job_no, status, retries, runtime, url, info, response)
             VALUES (%s, %d, %d, %s, %d, %d, %s, %s, %s)',
            $table,
            $idofrun, $timestamp, $job_no, $status, $retries, $runtime, $url, $info, $response
        )
    );
    if ( $wpdb->rows_affected > 0 ) {
        $count++;
    }
}
		$wp_filesystem->move( $logfile, $logfile . '.migrated' );
		update_option( 'geturlcron-db-migrated', 1 );
		$this->geturlcron_clear_log_cache();
		return array( 'status' => 'ok', 'count' => $count );
	}


	public function geturlcron_getnooffields() {
		$geturlcronmaxnocronjobs = (int) trim(get_option('geturlcron-maxno-cronjobs'));
		if ($geturlcronmaxnocronjobs < 15) {
			$this->nooffields = 15;
			return FALSE;
		}
		$this->nooffields = $geturlcronmaxnocronjobs;
		return TRUE;# $geturlcronmaxnocronjobs;
	}


	private function geturlcron_savelog( $entry ) {
		global $wpdb;
		$deldays = (int) trim( get_option( 'geturlcron-dellog-days' ) );
		if ( $deldays <= 0 ) {
			return true;
		}
		$table = $this->geturlcron_get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $wpdb->insert(
			$table,
			array(
				'idofrun'   => $entry['idofrun'],
				'timestamp' => $entry['timestamp'],
				'job_no'    => $entry['job_no'],
				'status'    => $entry['status'],
				'retries'   => $entry['retries'],
				'runtime'   => $entry['runtime'],
				'url'       => $entry['url'],
				'info'      => $entry['info'],
				'response'  => $entry['response'],
			),
			array( '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'geturlcron: DB insert failed — ' . $wpdb->last_error );
			return false;
		}
		$this->geturlcron_clear_log_cache();
		return true;
	}

	private function geturlcron_delete_try_entries( string $idofrun ): void {
		if ( empty( $idofrun ) ) {
			return;
		}
		global $wpdb;
		$table = $this->geturlcron_get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array( 'idofrun' => $idofrun, 'status' => 'try' ),
			array( '%s', '%s' )
		);
		$this->geturlcron_clear_log_cache();
	}

	private function geturlcron_clear_log_cache(): void {
		wp_cache_delete( 'log_summary',        'geturlcron' );
		wp_cache_delete( 'log_sc_raw',         'geturlcron' );
		wp_cache_delete( 'log_total_count',    'geturlcron' );
		wp_cache_delete( 'syscheck_row_count', 'geturlcron' );
		for ( $p = 1; $p <= 20; $p++ ) {
			wp_cache_delete( 'log_entries_' . $p, 'geturlcron' );
		}
	}

	private function geturlcron_getandcheckurl($url, $done_retries, $idofrun, $no) {
		$urlout = trim($url);

		$message = "\n\n-------------\n";
		$message .= __("Cron Job Attempted",'get-url-cron')."\n";
		$message .= __("Retries so far",'get-url-cron').": ".$done_retries."\n";
		$message .= __("GET",'get-url-cron').": $urlout\n";
		$message .= __("ID",'get-url-cron')." $idofrun";
		$subject = __("TRY",'get-url-cron')." ".$done_retries.": ".__("get",'get-url-cron')." $url, ".__("ID",'get-url-cron')." $idofrun";

		$this->getcronurl_sendmail($subject, $message, $no);
		$logl = $this->geturlcron_log(
			$idofrun, 
			$urlout, 
			$done_retries,
			__("start trying",'get-url-cron'), 
			"", 
			"", 
			"try");
		$this->geturlcron_savelog($logl);

		$timeout = trim(get_option('geturlcron-timeout'));
		if (!( ((int) $timeout)>0)) {
			$timeout = "60";
		}
		$starttime = time();
		$returnvalue = "";

		if (preg_match("/^\[/",$url)) {
			$sc = trim($url);
			$returnvalue = do_shortcode($sc);
			$resp = "shortcode";
		} else {
			$args = array(
				'timeout'     	=> $timeout,
				'user-agent'	=> 'GetURLCron-Plugin',
				'sslverify' 	=> false
				);
			$response = wp_remote_get($url, $args);
			if ( is_wp_error( $response ) ) {
				$resp = "Error: ".$response->get_error_message();
				$returnvalue = $resp;
			} else {
				$resp = wp_remote_retrieve_response_code($response);
				$returnvalue = wp_remote_retrieve_body($response);
			}
		}
		
		$endtime = time();
		$runtime = $endtime - $starttime;
		return array(
			"starttime" => $starttime,
			"endtime" => $endtime,
			"runtime" => $runtime,
			"returnvalue" => $returnvalue,
			"resp" => $resp,
		);
	}

	public function geturlcron_executejob($urlArr, $no, $rt="") {
		$url = $urlArr["url"];
		if (empty($url)) {
			return TRUE;
		}
		$urlout = $url;
				
		$retries = $urlArr["retries"];
		$overallok = FALSE;
		$done_retries = 1;

		$idofrun = md5(time().$url.wp_rand());

		$retArr = $this->geturlcron_getandcheckurl($url, $done_retries, $idofrun, $no);
		$returnvalue = $retArr["returnvalue"];
		$resp = $retArr["resp"];
		$runtime = $retArr["runtime"];
		$starttime = gmdate("H:i:s", $retArr["starttime"] +  $this->gmt_offset_add);
		$endtime = gmdate("H:i:s", $retArr["endtime"] +  $this->gmt_offset_add);

		$checkArray = $this->geturlcron_checkresponse($urlArr, $returnvalue, $resp);
		if ($checkArray["requestok"]) {
			$overallok = TRUE;
		} else {
			if ($retries>1) {
				for ($r = 1; $r <= $retries; $r++) {
					$done_retries++;
					$retArr = $this->geturlcron_getandcheckurl($url, $done_retries, $idofrun, $no);
					$returnvalue = $retArr["returnvalue"];
					$resp = $retArr["resp"];
					$runtime = $retArr["runtime"];
					$starttime = gmdate("H:i:s", $retArr["starttime"] +  $this->gmt_offset_add);
					$endtime = gmdate("H:i:s", $retArr["endtime"] +  $this->gmt_offset_add);
					$checkArray = $this->geturlcron_checkresponse($urlArr, $returnvalue, $resp);
					if ($checkArray["requestok"]) {
						$overallok = TRUE;
						break;
					}
				}
			}
		}
	
		if ($overallok) {
			$status = __("OK",'get-url-cron')." ";
		} else {
			$status = __("FAIL",'get-url-cron');
		}

		$info = $checkArray["info"];

		$logl = $this->geturlcron_log(
			$idofrun,
			$urlout,
			$done_retries,
			$returnvalue,
			$info,
			$runtime,
			$status,
			$rt);

		$this->geturlcron_savelog($logl);
		$this->geturlcron_delete_try_entries( $idofrun );

		$subject = $status." $done_retries: ".__("get",'get-url-cron')." $urlout, ".__("ID",'get-url-cron')." $idofrun";

		$message = "\n\n-------------\n";
		$message .= __("Status",'get-url-cron').": $status\n\n";
		$message .= __("Job",'get-url-cron').": $no\n";
		$message .= __("GET",'get-url-cron').": $urlout\n";
		$message .= __("ID",'get-url-cron').": $idofrun\n";
		$message .= __("Retries so far",'get-url-cron').": $done_retries\n";
		$info4mail = $checkArray["info4mail"];
		$message .= "$info4mail\n";
		$message .= "-------------\n".__("Runtime of Cron Job",'get-url-cron').": ";
		$message .= $runtime." seconds\n";
		$message .= __("Time Window",'get-url-cron').": $starttime " . __("to",'get-url-cron') . " $endtime\n";
		$this->getcronurl_sendmail($subject, $message, $no);
	}

	private function getcronurl_sendmail($subject, $message, $no) {
		$doSendFlag = $this->urlSettingsArr[$no]["sendmail"];
		if ("yes"==$doSendFlag) {
			$flagmailonlyfail = get_option('geturlcron-mailonlyfail') ?? 0;
			$flagmailonlyfail = trim($flagmailonlyfail);
			if ($flagmailonlyfail==1) {
				# send only FAIL-mails
				if (!preg_match("/^FAIL/", $subject)) {
					return TRUE;
				}
			}

			$isthereafail = FALSE;
			if (preg_match("/^FAIL/", $subject)) {
				$isthereafail = TRUE;
			}
			
			$senderprefix = "";
			if ($isthereafail) {
				$senderprefix = __("FAIL",'get-url-cron'). " - ";
			}
			$to_raw = trim( get_option('geturlcron-emailadr') );
			$to_arr = preg_split( '/[\s,;]+/', $to_raw, -1, PREG_SPLIT_NO_EMPTY );
			$to_arr = array_filter( $to_arr, 'is_email' );
			if ( ! empty( $to_arr ) ) {
				$srvh          = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$site_adminmail = get_option('admin_email') ?? '';

				$messageout  = __('Mail from the WordPress-Plugin "Cron Setup and Monitor - Get URL Cron"','get-url-cron') . "\n";
				$messageout .= __('Installed on','get-url-cron') . ' ' . $srvh . "\n";
				$messageout .= __('Report for the execution of a Cron Job','get-url-cron') . ":";

				$plugins_url_settings = admin_url('admin.php?page=geturlcronsettingspage&tab=basicsettings');
				$plugins_url_logs     = admin_url('admin.php?page=geturlcronsettingspage&tab=logs');
				$messagefooter  = "\n-------------\n";
				$messagefooter .= __("To Disable These Messages",'get-url-cron') . ': ' . "\n";
				$messagefooter .= __('Go to the settings of the WordPress Plugin "Cron Setup and Monitor - Get URL Cron" and configure it to send emails only for failed cron jobs or disable email notifications entirely.','get-url-cron') . "\n\n";
				$messagefooter .= __('Plugin settings','get-url-cron') . ":\n" . $plugins_url_settings . "\n\n";
				$messagefooter .= __('Cron Logs','get-url-cron') . ":\n" . $plugins_url_logs . "\n";

				$messageout .= $message . $messagefooter;

				$headers = array(
					'From: ' . $senderprefix . __("Admin of",'get-url-cron') . ' ' . $srvh . ' <' . $site_adminmail . '>',
					'Reply-To: ' . $site_adminmail,
				);
				wp_mail( $to_arr, $subject, $messageout, $headers );
			}
		}
		return TRUE;
	}
	
	
	private function getJsonValueViaPath($data, $path, $delimiter = '.') {
		$keys = explode($delimiter, $path);
		$current = $data;
		foreach ($keys as $key) {
			if (is_array($current) && isset($current[$key])) {
				$current = $current[$key];
			} elseif (is_object($current) && isset($current->$key)) {
				$current = $current->$key;
			} else {
				return null;
			}
		}
		return $current;
	}
	

	private function geturlcron_checkOnJSONfield($jsonArr, $fieldkeypath) {
		$jsonfieldfound = FALSE;
		$foundvalue = __("Details of JSONfieldcheck",'get-url-cron').":\n";
		$checkresult = $this->getJsonValueViaPath($jsonArr, $fieldkeypath);
		if ($checkresult !== null) {
			$jsonfieldfound = TRUE;
			$foundvalue .= "- ".__("Value found",'get-url-cron').": ".$checkresult."\n";
		} else {
			$foundvalue .= "- ".__("NO value found",'get-url-cron').": ".__("check failed",'get-url-cron')."\n";
		}
		return array(
			"jsonfieldfound" => $jsonfieldfound,
			"foundvalue" => $foundvalue,
		);
	}

	private function geturlcron_checkresponse($urlArr, $returnvalue, $resp) {
		$reqok = FALSE;
		$jsonok = FALSE;
		$requestok = FALSE;
		$info = "";
		$info4mail = "\n--- ".__("Request",'get-url-cron').":\n";
		if (
			(200==$resp) ||  
			("shortcode"==$resp) 
			){
			# server answer: ok
			$reqok = TRUE;
			$info .= __("Request: OK",'get-url-cron').", ".__("http-Code",'get-url-cron').": ".$resp." - ";
			$info4mail .= __("Request: OK",'get-url-cron').", ".__("http-Code",'get-url-cron').": ".$resp."\n";
		} else {
			$info .= __("Request failed",'get-url-cron').", ".__("http-Code",'get-url-cron').": ".$resp." - ";
			$info4mail .= __("Request failed",'get-url-cron').", ".__("http-Code",'get-url-cron').": ".$resp."\n";
		}
		if ($reqok) {
			$requiredformat = $urlArr["requiredformat"];
			if ($requiredformat=="json") { # check on json
				$info4mail .= __("Check on valid JSON",'get-url-cron').": ";
				# json ok?
				$jsonArr = json_decode($returnvalue, TRUE);
				if (is_null($jsonArr)) {
					$info .= __("Invalid JSON",'get-url-cron')." - ";
					$info4mail .= __("Invalid JSON",'get-url-cron')."\n";
				} else {
					$info .= __("Valid JSON",'get-url-cron')." - ";
					$info4mail .= __("Valid JSON",'get-url-cron')."\n";
					$jsonok = TRUE;
					$requiredjsonfield = trim($urlArr["requiredjsonfield"]);
					if (empty($requiredjsonfield)) { 
						$requestok = TRUE;
						$info .= __("No check for required JSONfield",'get-url-cron')." - ";
						$info4mail .= __("No check for required JSONfield",'get-url-cron')."\n";
					} else {
						$info4mail .= __("Check on required JSONfield",'get-url-cron').":\n";
						$checkOnJSONArr = $this->geturlcron_checkOnJSONfield($jsonArr, $requiredjsonfield);
						if ($checkOnJSONArr["jsonfieldfound"]) { 
							$info .= __("Required JSONfield OK",'get-url-cron')." - ";
							$info4mail .= __("Required JSONfield OK",'get-url-cron')."\n";
							$info4mail .= $checkOnJSONArr["foundvalue"]."\n";
							$requestok = TRUE;
						} else {
							$info .= __("Required JSONfield missing",'get-url-cron').": ".$requiredjsonfield." - ";
							$info4mail .= __("Required JSONfield missing",'get-url-cron')."\n";
							$info4mail .= $checkOnJSONArr["foundvalue"]."\n";
							$requestok = FALSE;
						}
					}
				}
			} else {
				$requiredjsonfield = trim($urlArr["requiredjsonfield"]);
				$info4mail .= __("check on string: requiredjsonfield",'get-url-cron')."\n";
				if (empty($requiredjsonfield)) { 
					$info .= __("no check for required string",'get-url-cron'). " - ";
					$info4mail .= __("no check for required string",'get-url-cron')."\n";
					$requestok = TRUE;
				} else {
					if (preg_match("/" . preg_quote($requiredjsonfield, "/") . "/", $returnvalue)) {
						$info .= __("required string ok",'get-url-cron')." - ";
						$info4mail .= __("required string ok",'get-url-cron').": $requiredjsonfield\n";
						$requestok = TRUE;
					} else {
						$info .= __("required string missing",'get-url-cron').": ".$requiredjsonfield." - ";
						$info4mail .= __("required string missing",'get-url-cron').": $requiredjsonfield\n";
						$requestok = FALSE;
					}
				}
			}
		}
		return array(
			"info" => $info,
			"info4mail" => $info4mail,
			"reqok" => $reqok,
			"jsonok" => $jsonok,
			"requestok" => $requestok,
		);
}

	public function geturlcron_unschedulejobs() {
		for ($no = 1; $no <= count($this->urlSettingsArr); $no++) {
			$this->geturlcron_unschedulejob($no);
		}
	}
	
	public function geturlcron_unschedulejob($no) {
		$jobhook = "geturlcron_event-".$no;
		$args = array($no);
		wp_clear_scheduled_hook( $jobhook, $args );
	}


	public function geturlcron_recurrence_interval( $schedules ) {
		$schedules['geturlcron_02_minutes'] = array(
            'interval'  => 60*2,
            'display'   => __('2 Minutes','get-url-cron')
		);
		$schedules['geturlcron_05_minutes'] = array(
            'interval'  => 60*5,
            'display'   => __('5 Minutes','get-url-cron')
		);
		$schedules['geturlcron_10_minutes'] = array(
            'interval'  => 60*10,
            'display'   => __('10 Minutes','get-url-cron')
		);
		$schedules['geturlcron_15_minutes'] = array(
            'interval'  => 60*15,
            'display'   => __('15 Minutes','get-url-cron')
		);
		$schedules['geturlcron_30_minutes'] = array(
            'interval'  => 60*30,
            'display'   => __('30 Minutes','get-url-cron')
		);
		$schedules['geturlcron_6_hours'] = array(
            'interval'  => 60*60*6,
            'display'   => __('6 Hours','get-url-cron')
		);
		$schedules['geturlcron_7_days'] = array(
            'interval'  => 60*60*24*7,
            'display'   => __('7 Days','get-url-cron')
		);
		$schedules['geturlcron_disable'] = array(
            'interval'  => -1,
            'display'   => __('Disable','get-url-cron')
		);
		return $schedules;
	}


	public static function initclass() {
		static $inst = null;
		if ( ! $inst ) {
			$inst = new GetUrlCron();
		}
		return $inst;

	}
}