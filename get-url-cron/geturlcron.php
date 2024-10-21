<?php
/*
Plugin Name: Cron Setup and Monitor - Get URL Cron
Plugin URI: https://json-content-importer.com/geturlcron
Description: Manage cron jobs, monitor tasks, retry failures, and send email updates
Version: 1.5.3
Author: Bernhard Kux
Author URI: http://www.kux.de/
Text Domain: get-url-cron
Domain Path: /languages
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/* block direct requests */

defined('ABSPATH') OR exit;
if ( !function_exists( 'add_action' )) {
	echo 'Hello, this is a plugin: You must not call me directly.';
	exit;
}
define( 'GETURLCRON_VERSION', '1.5.3' );  // current version number


if (!defined('DISABLE_WP_CRON')) {
	define('DISABLE_WP_CRON',false);
}

function geturlcron_init() {
	$pd = dirname(
		plugin_basename(__FILE__)
	).'/languages/';
	load_plugin_textdomain('get-url-cron', false, $pd);
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
	echo "<h1>";
	esc_html_e('Basic Settings', 'get-url-cron');
	echo "</h1>";
	echo '<form method="post" action="admin.php?page=geturlcrondetailsettingslug">';
    wp_nonce_field( "geturlcron_nc", "geturlcron_nc" );
	submit_button();
	echo '<table class="widefat striped">';
	echo '<input type="hidden" name="subaction" value="settings">';
	settings_fields( 'geturlcron-options-details' ); 
	do_settings_sections( 'geturlcron-options-details' ); 

	echo "<tr><td>";
	echo "<h2>";
	esc_html_e("E-Mailadress for Statusmessages: separate multiple by space or , or ;","get-url-cron");
	echo "</h2>";
	$mailadr = trim(get_option('geturlcron-emailadr'));
	$mailcheckArr = $this->geturlcron_check_mailadress_list($mailadr);
	$anzmailadr = count($mailcheckArr["color"]);
	for ($i = 0; $i < ($anzmailadr); $i++) {
		#echo $mailcheckArr["color"][$i] . "<br>";
		#echo $mailcheckArr["message"][$i] . "<br>";
		echo "<font color=".esc_attr($mailcheckArr["color"][$i]).">".esc_attr(stripslashes($mailcheckArr["message"][$i]))."</font><br>";
	}
	#echo json_encode($mailcheckArr)."<hr>";
	echo '<input type=text size=200 name=geturlcron-emailadr value="'.esc_attr(stripslashes($mailadr)).'">';
	echo "</td></tr>";

	echo "<tr><td>";
	echo "<h2>";
	esc_html_e("E-Mail only for failed Jobs","get-url-cron");
	echo "</h2>";
    esc_html_e("In the default setting, emails are sent regardless of the outcome of the cron jobs. If the following checkbox is active, emails are only sent when a cron jobs fails.","get-url-cron");
	echo "<br>";
	$checkmailonlyfail = "";
	$mailonlyfailopt = get_option('geturlcron-mailonlyfail') ?? 0;
	if ($mailonlyfailopt == 1) {
		$checkmailonlyfail = "checked=checked";
	}
    echo '<input type="checkbox" name="geturlcron-mailonlyfail" value="1" '.esc_attr($checkmailonlyfail).' /> emails only sent when cron jobs fails';
	echo "</td></tr>";
	echo "<tr><td>";

	echo "<tr><td>";
	echo "<h2>";
	esc_html_e("Set timeout","get-url-cron");
	echo "</h2>";
	esc_html_e("Set the timeout for the http-requests (default 60 sec):","get-url-cron");
	echo "<br>";
	$timeout = (int) trim(get_option('geturlcron-timeout'));
	if (!($timeout>0)) {
		$timeout = "60";
	}
	echo '<input type=text size=5 name=geturlcron-timeout value="'.esc_attr($timeout).'">';
	echo "</td></tr>";
	echo "<tr><td>";
	echo "<h2>";
	esc_html_e("Max. age of logentries","get-url-cron");
	echo "</h2>";
	$logfile = $this->geturlcron_getlogfile();
	esc_html_e("Logfile","get-url-cron");
	echo ": ";
	echo esc_html($logfile);
	echo "<br>";
	

    esc_html_e("Delete Logfile-Entires older than days:","get-url-cron");
	echo "<ul><li>";
	esc_html_e("-1 : delete logfile and do not log","get-url-cron");
	echo "</li><li>";
	esc_html_e("0 : do not log but keep existing log","get-url-cron");
	echo "</li><li>";
	esc_html_e("any number : max. age in days of the logfile-entries, default is 20 days","get-url-cron");
	echo "</li></ul>";

	$deldays = (int) trim(get_option('geturlcron-dellog-days'));
	echo '<input type=text size=5 name=geturlcron-dellog-days value="'.esc_attr($deldays).'">';
	echo "</td></tr>";
	echo "<tr><td>";
	
	echo "<h2>";
	esc_html_e("Max. number of Cronjobs (default and minimal: 15)","get-url-cron");
	echo "</h2>";
	$geturlcronmaxnocronjobs = (int) trim(get_option('geturlcron-maxno-cronjobs'));
	echo '<input type=text size=5 name=geturlcron-maxno-cronjobs value="'.esc_attr($geturlcronmaxnocronjobs).'">';
	echo "</td></tr>";

	echo "<tr><td>";
	
	echo "<h2>";
	esc_html_e("Complete delete when uninstalling?","get-url-cron");
	echo "</h2>";
    esc_html_e("On default, not all data of this plugin is deleted:","get-url-cron");
	echo "<br>";
    esc_html_e("Only if the following checkbox is activated, also templates and the above option-data are deleted","get-url-cron");
	echo "<br>";
	$checkeddelall = "";
	if (get_option('geturlcron-uninstall-deleteall') == 1) {
		$checkeddelall = "checked=checked";
	}
	
    echo '<input type="checkbox" name="geturlcron-uninstall-deleteall" value="1" '.esc_attr($checkeddelall).' /> delete all, incl. logfiles';

	echo "</td></tr>";
	echo "<tr><td>";

	echo "<h2>";
	esc_html_e("Example","get-url-cron");
	echo "</h2>";
    esc_html_e("For trying the plugin you might use a URL like this one:","get-url-cron");
	echo "<br>";
	$exampleurl = "http://worldtimeapi.org/api/timezone/Europe/Berlin";
	echo '<a href="'.esc_attr($exampleurl).'" target="_blank">'.esc_attr($exampleurl).'</a><br>';	
	echo "<ul>";
	echo "<li>1. ";
	esc_html_e("Select JSON as requiredformat and 'timezone' as requiredjsonfield","get-url-cron")."</li>";
	echo "<li>2. ";
	esc_html_e("Save Settings","get-url-cron")."</li>";
	echo "<li>3. ";
	esc_html_e("Then executing the CronJob by clicking 'Execute Job'","get-url-cron");
	echo "</li>";
	echo "<li>4. ";
	esc_html_e("Switching to 'Show Logs' should show you the results","get-url-cron");
	echo "</li></ul>";
	
	echo "</td></tr>";
	echo "</table>";
	submit_button();
	echo "</form>";
}

public function geturlcron_menu() {
	add_menu_page(__('Cron Setup and Monitor','get-url-cron'), 'Cron Setup and Monitor', 'administrator', 'unique_geturlcron_menu_slug', array($this, 'geturlcron_settings_page'), 'dashicons-clock');
	add_submenu_page('unique_geturlcron_menu_slug', __('Set CronJobs','get-url-cron'), __('Set CronJobs','get-url-cron'), 'administrator', 'geturlcronsettingspage', array($this, 'geturlcron_settings_page'));
	add_submenu_page('unique_geturlcron_menu_slug', __('Show CronJobs','get-url-cron'), __('Show CronJobs','get-url-cron'), 'administrator', 'geturlcronjobslistdslug', array($this, 'geturlcron_cronjobs_page'));
	add_submenu_page('unique_geturlcron_menu_slug', __('Show Logs','get-url-cron'), __('Show Logs','get-url-cron'), 'administrator', 'geturlcronlogslug', array($this, 'geturlcron_logs_page'));
	add_submenu_page('unique_geturlcron_menu_slug', __('Basic Settings','get-url-cron'), __('Basic Settings','get-url-cron'), 'administrator', 'geturlcrondetailsettingslug', array($this, 'geturlcron_detailsettings_page'));
	remove_submenu_page('unique_geturlcron_menu_slug', 'unique_geturlcron_menu_slug');
	add_action( 'admin_init', array($this, 'register_geturlcronsettings' ));
}	


private function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remainingSeconds = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $remainingSeconds);
}

public function geturlcron_logs_page() {
	$logfile = $this->geturlcron_getlogfile();
	$deldays = (int) trim(get_option('geturlcron-dellog-days'));
#	if ($deldays==-1) {
	if ($deldays<0) {
		@unlink($logfile);
		echo '<h1>';
		esc_html_e("Logfile deleted!",'get-url-cron');
		echo '</h1><h2>';
		esc_html_e('See settings and check "Delete Logfile-Entires older than": "-1" means delete logfile','get-url-cron');
		echo "</h2>";
		return TRUE;
	}
	
	if (file_exists($logfile)) {
		$size = (int) (filesize($logfile)/1024/1024);
		$memory_limit = (int) ini_get('memory_limit');
		if ($size>($memory_limit)) {
			echo '<h1>';
			esc_html_e('The size of the logfile is too big','get-url-cron');
			echo '</h1>';
			esc_html_e('Size of Logfile','get-url-cron');
			echo ": ";
			echo esc_html($size);
			echo " MB, ";
			esc_html_e('PHP Memory limit','get-url-cron');
			echo ": ";
			echo esc_html($memory_limit);
			echo " MB";
			echo "<p>";
			esc_html_e('Options:','get-url-cron');
			echo "<br>";
			esc_html_e('1. Manually delete or alter name of the Logfile','get-url-cron');
			echo " ";
			echo esc_html($logfile);
			echo "<br>";
			esc_html_e('2. Set "Max. age of logentries" at "Basic Settings" to -1','get-url-cron');
			echo " ";
			echo esc_html($logfile);
			echo "<br>";
			esc_html_e('3. Increase the memory limit at php.ini or the settings of your Hoster','get-url-cron');
			echo "<br>";
			return TRUE;
		}
	}	

	
	echo "<h1>";
	esc_html_e("Cron Setup and Monitor - Get URL Cron: Logs",'get-url-cron');
	

	echo ", ";
	esc_html_e("Current Servertime",'get-url-cron');
	echo ": ".esc_html(current_time("Y-m-d, H:i:s"));

	$timezone_string = get_option('timezone_string') ?? '';
	if (!empty($timezone_string)) {
		echo ", ";
		esc_html_e("Timezone",'get-url-cron');
		echo ": ".esc_html(get_option('timezone_string'));
	}
	$gmt_offset = get_option('gmt_offset') ?? '';
		if (!empty($gmt_offset)) {
			echo ", ";
			esc_html_e("UTC-Offset",'get-url-cron');
			echo ": ".esc_html(get_option('gmt_offset'))." ";
			esc_html_e("hours",'get-url-cron');
	}
	echo "</h1>";
	
	# load file
	if ( ! function_exists( 'request_filesystem_credentials' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$url = wp_nonce_url( 'index.php', 'my-nonce_geturlcron-loadfile' );
	$credentials = request_filesystem_credentials( $url );
	if ( ! WP_Filesystem( $credentials ) ) {
		echo "<h2>";
		esc_html_e("Failed to initialize WP_Filesystem",'get-url-cron');
		echo "</h2>";
		return TRUE;
	}
	global $wp_filesystem;

	if ( !$wp_filesystem->exists( $logfile ) ) {
		echo "<h2>";
		esc_html_e("emtpy logfile up to now...",'get-url-cron');
		echo "</h2>";
		return TRUE;
	}
	$logf = $wp_filesystem->get_contents( $logfile );
	#if (!file_exists($logfile)) {
	#	esc_html_e("emtpy logfile up to now...",'get-url-cron');
	#	return TRUE;
	#}
	$separator = " /// ";
	$separator2 = "=";
	
	#echo $logf; return true;
	$logfArr1 = explode("\n",$logf);
	
	$deldays = trim(get_option('geturlcron-dellog-days'));
	$count = 1000;
	$logfArr11 = array();
	$statuscheck = array();
	$deleteentry = FALSE;
	
	$lastexec = array();
	$laststatus = array();
		
	for ($r = count($logfArr1); $r >=0; $r--) {
		if (empty($logfArr1[$r])) {
			continue;
		}
		$logfArr2 = explode($separator , $logfArr1[$r]);
		#echo "<hr>$r  -- ".$logfArr1[$r]."<hr>";
		$id = $logfArr2[0];
		$status = $logfArr2[8] ?? '';
		$statkey = trim($id)."-".trim($status);
		@$statuscheck[$statkey]++;
		#echo $statkey."<br>";
		#echo $statkey."-".strlen($statkey)."<br>";
		$tArr = explode("time=", $logfArr1[$r]);

		$tArr1 = explode(" ", $tArr[1]);
		$timestampoflogentry = $tArr1[0];

		$guno = $logfArr2[9] ?? '';
		if ( ((int)$guno > 0 ) && 
			(!isset($lastexec[$guno])) ) {
			$lastexec[$guno] = $timestampoflogentry;
		}
		if ( ((int)$guno > 0 ) && 
			(!isset($laststatus[$guno])) ) {
			$laststatus[$guno] = $status;
		}

		$deleteentry = FALSE;
		if ($deldays>0) {
			if ($tArr1[0]<10) {
				$deleteentry = TRUE;
			} else {
				$ageofentryindays = (time()-$tArr1[0])/86400;
				if ($ageofentryindays>$deldays) {
					$deleteentry = TRUE;
				}
			}
			if ($deleteentry) {
				#echo "DEL: ".$ageofentryindays." - $deldays <hr>";
			} else {
				#echo "OK:  ".$ageofentryindays." - $deldays <hr>";
				$logfArr11[$timestampoflogentry."-".$count] = $logfArr1[$r];
				$count++;
			}
		} else {
			$logfArr11[$timestampoflogentry."-".$count] = $logfArr1[$r];
			$count++;
		}
	}
	
	echo '<table class="widefat" border=1>';
		echo "<tr bgcolor=yellow>";
		echo "<td><h2>";
		echo esc_html(__("Status last execution",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Cronjob with this Plugin",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Time last execution",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Time since last execution (hrs:min:sec)",'get-url-cron'));
		echo "</h2></td>";
		#echo "<td><h2>";
		#echo esc_html(__("Expected time of next execution (hrs:min:sec)",'get-url-cron'));
		#echo "</h2></td>";
		echo "</tr>";
	foreach($lastexec as $k => $v) {
		$bgcolor = "#9edeaa";
		if ("FAIL"==$laststatus[$k]) {
			$bgcolor = "#ffa099";
		}
		echo "<tr>";
		echo "<td bgcolor=".esc_attr($bgcolor).">";
		echo esc_html($laststatus[$k]);
		echo "</td><td bgcolor=".esc_attr($bgcolor).">";
		echo esc_html('geturlcron_event-'.$k);
		echo "</td><td bgcolor=".esc_attr($bgcolor).">";
		echo esc_html(gmdate("Y-m-d, H:i:s", $v + $this->gmt_offset_add));		
		echo "</td><td bgcolor=".esc_attr($bgcolor).">";
		echo esc_html($this->formatTime(time()- $v));	

		$args = array(((int) $k));				
		$nexttime = wp_next_scheduled('geturlcron_event-'.$k, $args);
		if ($nexttime - $v<0) {
			$bgcolor = "#ffa099";
		}
		#echo "</td><td bgcolor=".esc_attr($bgcolor).">";
		#echo esc_html(gmdate("Y-m-d, H:i:s", $nexttime + $this->gmt_offset_add));
		#echo "k=$k nexttime= $nexttime "."  gmt_offset_add=".$this->gmt_offset_add."<br>";
		#$timetonextrun = $nexttime - time();
		#if ($timetonextrun>0) {
		#	echo " ".esc_html(__("in",'get-url-cron'))." ".esc_html($this->formatTime($timetonextrun));	
		#}
		#echo "</td>";
		echo "</tr>";
	}
	echo "</table>";
	
	echo "<hr><h2>".esc_html(__("Chronological Log Entries",'get-url-cron'))."</h2>";
	
	krsort($logfArr1);
	
	if ($deleteentry) {
		$newlogfile = join("\n", $logfArr11);
		$logf = $wp_filesystem->put_contents( $logfile, $newlogfile."\n" );
		#$fsc = file_put_contents($logfile, $newlogfile."\n");
	}
	

	echo '<table class="widefat" border=1>';
	echo "<tr bgcolor=yellow>";
		echo "<td><h2>";
		echo esc_html(__("ID of Run",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Status",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Log Entry",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Retires",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("JSON Status",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Runtime (sec)",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("URL or WP-Shortcode",'get-url-cron'));
		echo "</h2></td><td><h2>";
		echo esc_html(__("Response",'get-url-cron'));
		echo "</h2></td>";
		echo "</tr>";
	#echo esc_html($outhead);
	#for ($r = count($logfArr11); $r >=0; $r--) {
	foreach($logfArr1 as $key => $val) {	
		if (empty($val)) {
			continue;
		}
		$logfArr2 = explode($separator, $val);
		if (empty($logfArr2[1])) {
			continue;
		}
		$lga3 = explode($separator2, $logfArr2[1],2);
		if (($lga3[1]==0) || count($logfArr2)==0) {
			continue;
		}
		
		$id = trim($logfArr2[0]);
		$status = trim(($logfArr2[8] ?? ''));
		$bgcol = "#ffffff"; 
		$stc_fail = $statuscheck[$id."-FAIL"] ?? 0;
		#echo $stc_fail."<br>";
		$stc_ok = $statuscheck[$id."-OK"] ?? 0;
		$stc_try = $statuscheck[$id."-try"] ?? 0;
		if ($status=="try") {
			if ($stc_fail>0) {
				# try failed
				$bgcol = "#ffa099"; 
			} else if ($stc_ok>0) {
				# try ok
				$bgcol = "#9edeaa"; #green
			} else {
				# try unknown status
				$bgcol = "white"; 
		}
		}
		if ($status=="OK") {
			if ($stc_try>0) {
				# try ok
				$bgcol = "#9edeaa"; #green
				#$bgcol = "#ffa099"; 
			}
		}

		if ($status=="FAIL") {
			$bgcol = "#ffa099"; 
		}

		if ($status=="schedule") {
			$bgcol = "#ffbb00"; #orange
		}

		echo "<tr bgcolor=".esc_attr($bgcol).">";
		echo "<td>";
		echo esc_html($id);
		echo "</td><td>";
		echo esc_html($status);
		echo "</td><td>";
		echo esc_html(gmdate("Y-m-d, H:i:s", ((int) $lga3[1]) + $this->gmt_offset_add));
		echo "</td><td>";
		$lga43 = explode($separator2, $logfArr2[3],2);
		echo esc_html($lga43[1]);
		echo "</td><td>";
		echo esc_html($logfArr2[4]);
		echo "</td><td>";
		$lga43 = explode($separator2, $logfArr2[6],2);
		echo esc_html($lga43[1]);
		echo "</td><td>";
		$lga43 = explode($separator2, $logfArr2[5],2);
		echo esc_html(stripslashes($lga43[1]));
		echo "</td><td>";
		$lga43 = explode($separator2, $logfArr2[7],2);
		$lga43[1] = chunk_split($lga43[1], 200, ' '); # insert blank every 200char for linebreaks
		echo esc_html($lga43[1]);
		echo "</td>";
		echo "</tr>";
	}
	echo "</table>";
	return TRUE;
}

public function geturlcron_cronjobs_page() {
	$cjArr = _get_cron_array();
	if ( empty( $cjArr ) ) {
		$cjArr = array();
	}
	#echo json_encode($cjArr);
	
	$out = "";
	$plugincronjobs = 0;
	$nonplugincronjobs = 0;
	foreach($cjArr as $k => $v) {
		foreach($v as $k1 => $v1) {
			$noofjob = preg_replace("/geturlcron_event-/", "", $k1);
			$showcronjob = TRUE;
			$op = get_option("geturlcron-url-".$noofjob);
			if ($this->is_relative_url($op)) {
				# relative path
				$op = $this->add_domain_to_url($op);
			}
			if (empty($op)) {
				$jobhook = "geturlcron_event-".$noofjob;
				#echo "unsched: $jobhook<br>";
				$args = array($noofjob);
				wp_unschedule_event( "", $jobhook, $args);
				wp_clear_scheduled_hook( $jobhook, $args);
				$showcronjob = FALSE;
			}
			if ($noofjob>=1 && $showcronjob) {
				foreach($v1 as $k2 => $v2) {
					$intv = $v2["schedule"];
					if (empty($intv)) {
						$intv = __("run only once",'get-url-cron');
					}
				}
				$plugincronjobs++;
				$out_kl[] = $k1;

				$opout = trim(stripslashes($op));
				$out_opout[] = $opout;
				$out_opoutlink[] = $op;
				
				$cronschedulesArr = wp_get_schedules();
				$out_cronschedulesArr[] = $cronschedulesArr[$intv]['display'];
				$std = get_option("geturlcron-startdate-".$noofjob);
				$out_std[] = $std;
				
				$args = array(((int) $noofjob));				
				$nexttime = wp_next_scheduled($k1, $args);
				#echo "--".($nexttime-time())."--";
				$nextdate = "";
				$nextdist = "";
				$out_nexttime[] = $nexttime;
				if ($nexttime>0) {
					$nextdate = gmdate("Y-m-d, H:i", $nexttime +  $this->gmt_offset_add);
					$nextdistVal = $nexttime-time();
					$out_nextdistVal[] = $nextdistVal;
					if ($nextdistVal>0) {
						$nextdist_day = floor($nextdistVal/(3600*24));  # from sec to days	
						$remainsec = $nextdistVal-$nextdist_day*(3600*24);			
						$nextdist_hr = floor($remainsec/3600);  # from sec to hrs					
						$remainsec = $remainsec-$nextdist_hr*3600;			
						$nextdist_min = floor($remainsec/60);  # from sec to min					
						$nextdist_sec = $remainsec-$nextdist_min*60;  # remaining sec
						
						$nextdist = "";
						if ($nextdist_day>0) {
							$nextdist .= "$nextdist_day ";			
							if ($nextdist_day>1) {
								$nextdist .= "days ";			
							} else {
								$nextdist .= "day ";			
							}
						}

						if ($nextdist_hr>0) {
							$nextdist .= "$nextdist_hr ";			
							if ($nextdist_hr>1) {
								$nextdist .= "hours ";			
							} else {
								$nextdist .= "hour ";			
							}
						}

						if ($nextdist_min>0) {
							$nextdist .= "$nextdist_min ";			
							if ($nextdist_min>1) {
								$nextdist .= "minutes ";			
							} else {
								$nextdist .= "minute ";			
							}
						}
						$nextdist .= "$nextdist_sec seconds";			
						$out_nextdate[] = $nextdate ?? '';
						$out_nextdist[] = $nextdist ?? '';
					} else {
						$out_else[] = "reload this page please";
					}
				} else {
					$out_nextdate[] = $nextdate;
					$out_nextdist[] = $nextdist;
				}
			} else {
				# other cronjobs
				$recurrence = "";
				$args = "";
				
				foreach($v1 as $k2 => $v2) {
					#$recurrence = __($v2["schedule"],'get-url-cron');
					/* translators: %s is the name of the schedule */
					#$recurrence = sprintf( __('%s', 'get-url-cron'), $v2["schedule"] );
					$recurrence = $v2["schedule"];
					if (empty($v2["args"])) {
						$args = __("none", 'get-url-cron');
					} else {
						$args = json_encode($v2["args"]);
					}					
				}
				if ($recurrence=="") {
					$recurrence = __('Not repeating','get-url-cron');
				}
				
				$cronschedulesArr = wp_get_schedules();
				$recurrence = $cronschedulesArr[$recurrence]["display"] ?? $recurrence;
				#echo print_r($cronschedulesArr[$recurrence]["display"]);exit;

				$eventdetails = wp_get_scheduled_event($k1);
				$nexttime = $eventdetails->timestamp ?? '';
				
				$nonplugincronjobs++;
				$outelse_k1[] = $k1;
				$nextdate = "";
				$nextdist = "";
				if ($nexttime>0) {
					#echo  $nexttime." ". gmdate("d.m.y, H:i:s", $nexttime +2*60*60)."<BR>";
					$nextdate = gmdate("Y-m-d, H:i:s", $nexttime + $this->gmt_offset_add);
					$nextdistVal = $nexttime-time();
					$nextdist_day = floor($nextdistVal/(3600*24));  # from sec to days	
					$remainsec = $nextdistVal-$nextdist_day*(3600*24);			
					$nextdist_hr = floor($remainsec/3600);  # from sec to hrs					
					$remainsec = $remainsec-$nextdist_hr*3600;			
					$nextdist_min = floor($remainsec/60);  # from sec to min					
					$nextdist_sec = $remainsec-$nextdist_min*60;  # remaining sec
					
					#4 days 1 hour
					#23 minutes 25 seconds
					$nextdist = "";
					if ($nextdist_day>0) {
						$nextdist .= "$nextdist_day ";			
						if ($nextdist_day>1) {
							$nextdist .= "days ";			
						} else {
							$nextdist .= "day ";			
						}
						
					}

					if ($nextdist_hr>0) {
						$nextdist .= "$nextdist_hr ";			
						if ($nextdist_hr>1) {
							$nextdist .= "hours ";			
						} else {
							$nextdist .= "hour ";			
						}
						
					}

					if ($nextdist_min>0) {
						$nextdist .= "$nextdist_min ";			
						if ($nextdist_min>1) {
							$nextdist .= "minutes ";			
						} else {
							$nextdist .= "minute ";			
						}
					}
					$nextdist .= " $nextdist_sec seconds";			
				}
				$outelse_nextdate[] = $nextdate;
				$outelse_nextdist[] = $nextdist;
				$outelse_recurrence[] = $recurrence;
				$outelse_args[] = $args;
			}
		}
	}
	
	echo "<h1>Cron Setup and Monitor - Get URL Cron: ";
	if ($plugincronjobs==0) {
		echo esc_html_e('No Cronjob defined by this Plugin','get-url-cron');
	} elseif ($plugincronjobs==1) {
		echo esc_html($plugincronjobs)." ";
		esc_html_e('Cronjob defined by this Plugin','get-url-cron');
	} else {
		echo esc_html($plugincronjobs)." ";
		esc_html_e('Cronjobs defined by this Plugin','get-url-cron');
	}
	echo ' - '.esc_html($nonplugincronjobs).' ';
	esc_html_e("other Cronjobs",'get-url-cron');
	echo '</h1>';
	
	echo "<h2>";
	esc_html_e("All upcoming run times and distances are calculated based on this time setting",'get-url-cron');
	echo " - ";
	esc_html_e("Current Servertime",'get-url-cron');
	echo ": ".esc_html(current_time("Y-m-d, H:i:s"));
	
	$timezone_string = get_option('timezone_string') ?? '';
	if (!empty($timezone_string)) {
		echo ", ";
		esc_html_e("Timezone",'get-url-cron');
		echo ": ".esc_html(get_option('timezone_string'));
	}
	$gmt_offset = get_option('gmt_offset') ?? '';
	if (!empty($gmt_offset)) {
		echo ", ";
		esc_html_e("UTC-Offset",'get-url-cron');
		echo ": ".esc_html(get_option('gmt_offset')). " ";
		esc_html_e("hours",'get-url-cron');
	}
	
	echo "</h2>";

	echo '<table class="widefat striped">';
	#if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
	#	$outhead .= '<h2><font color=red>In case of problems: The Wordpress-Cron is disabled! Check wp_config.php and set <i>define(\'DISABLE_WP_CRON\',false);</i> there, please!</font></h2>';
	#}
	echo "<tr><td bgcolor=yellow><h2>";
	esc_html_e("Cronjob with this Plugin",'get-url-cron');
	echo "</h2></td><td bgcolor=yellow><h2>";
	esc_html_e("Recurrence",'get-url-cron');
	echo "</h2></td><td bgcolor=yellow><h2>";
	esc_html_e("Next Run",'get-url-cron');
	echo "</h2></td><td bgcolor=yellow><h2>";
	esc_html_e("First Run",'get-url-cron');
	echo "</h2></td><td bgcolor=yellow><h2>";
	esc_html_e("URL or WP-Shortcode",'get-url-cron');
	echo "</h2></td>";
	echo "</tr>";

if ($plugincronjobs>0) {
	foreach($out_kl as $k => $v) {
		echo "<tr><td>";
		echo esc_html($v);
		echo "</td><td>";
		echo esc_html($out_cronschedulesArr[$k]);
		echo "</td><td>";
		if ($out_nexttime[$k]>0) {
			$nd = $out_nextdate[$k] ?? '';
			$ndi = $out_nextdist[$k] ?? '';
			$ndiv = $out_nextdistVal[$k] ?? '';
			if ($ndiv>0 && !empty($nd)) {
				echo esc_html($nd);
				echo "<br>";
				echo esc_html($ndi);
				echo "</td><td>";
				echo esc_html($out_std[$k]);
				echo "</td>";
			} else {
				echo "<b>reload this page please</b>";
				echo "</td><td>";
				$oe = $out_else[$k] ?? ''; 
				echo esc_html($oe);
				echo "</td>";
			}
		} else {
			$nd = $out_nextdate[$k] ?? '';
			$ndi = $out_nextdist[$k] ?? '';
			echo esc_html($nd);
			echo "</td><td>";
			echo esc_html($ndi);
			echo "</td>";
		}
		echo "<td>";
		if (preg_match("/^\[/",$out_opout[$k])) {
			esc_html_e("execute Shortcode",'get-url-cron');
			echo ": ".esc_html($out_opout[$k]);
		} else {
			echo "<a href=".esc_attr($out_opoutlink[$k])." target=_blank>".esc_html($out_opout[$k])."</a>";
		}
		echo "</td></tr>";
	}
} else {
	echo "<tr><td colspan=6><h2>";
	echo "No Cronjob defined with this Plugin</h2>";
	echo "</td></tr>";
}
	echo "<tr><td bgcolor=yellow><h2>";
	esc_html_e("Cronjob NOT from this Plugin",'get-url-cron');
	echo "</h2></td><td bgcolor=yellow><h2>";
	esc_html_e("Recurrence",'get-url-cron');
	echo "</h2></td>";
	echo "<td bgcolor=yellow><h2>";
	esc_html_e("Next Run",'get-url-cron');
	echo "</h2></td><td bgcolor=yellow colspan=2><h2>";
	esc_html_e("Arguments",'get-url-cron');
	echo "</h2></td>";
	echo "</tr>";
	foreach($outelse_k1 as $k => $v) {
		echo "<tr><td>";
		echo esc_html($v);
		echo "</td><td>";
		echo esc_html($outelse_recurrence[$k]);
		echo "</td><td>";
		echo esc_html( $outelse_nextdate[$k]);
		echo "<br>";
		echo esc_html( $outelse_nextdist[$k]);
		echo "</td><td colspan=2>";
		echo esc_html($outelse_args[$k]);
		echo "</td></tr>";
		
	}
	echo "</table>";
}


public function geturlcron_settings_page() {
	echo '<h1>';
	esc_html_e("Cron Setup and Monitor - Get URL Cron",'get-url-cron');
	echo ": ";
	esc_html_e("Define Cronjobs with this Plugin",'get-url-cron').": ";
	echo '</h1>';
	echo '<form method="post" action="admin.php?page=geturlcronsettingspage">';
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
		
		echo '<table class="widefat striped">';
		echo "<tr>";
		echo "<td bgcolor=yellow><h2>";
		esc_html_e("No",'get-url-cron');
		echo "</h2></td>";
		foreach($fi as $k => $v) {
			echo "<td bgcolor=yellow><h2>";
			if ($k=="url") {
				esc_html_e("URL or WP-Shortcode: If the URL starts",'get-url-cron');
				echo "<br>";
				esc_html_e("with \"/\", ",'get-url-cron');
				echo esc_url(home_url());
				esc_html_e(" prepended to the URL",'get-url-cron');
			} else {
				echo esc_html($fi_out[$k]);
				if ("startdate"==$k) {
					echo "<br>";
					esc_html_e("Current Servertime", 'get-url-cron');
					echo ": ".esc_html(current_time("Y-m-d H:i:s"));
					$timezone_string = get_option('timezone_string') ?? '';
					if (!empty($timezone_string)) {
						echo "<br>";
						esc_html_e("Timezone",'get-url-cron');
						echo ": ".esc_html(get_option('timezone_string'));
					}
					$gmt_offset = get_option('gmt_offset') ?? '';
					if (!empty($gmt_offset)) {
						echo "<br>";
						esc_html_e("UTC-Offset",'get-url-cron');
						echo ": ".esc_html(get_option('gmt_offset')). " ";
						esc_html_e("hours",'get-url-cron');
					}
				}
			}
			echo "</h2></td>";
		}
		echo "<td bgcolor=yellow><h2>";
		esc_html_e("Execute Job",'get-url-cron');
		echo "</h2></td>";
		echo "</tr>";
		
		for ($r = 1; $r <= $this->nooffields; $r++) {
			echo "<tr>";
			echo "<td>";
			echo esc_html($r);
			echo "</td>";
			foreach($fi as $k => $v) {
				echo "<td>";
				$ki = "geturlcron-".$k."-".$r;
				$op = get_option($ki);
				if ($k=="interval") { 
					$cronschedulesArr = wp_get_schedules();
					#echo print_r($cronschedulesArr);exit;

					if ($op=="") { $op = "daily"; }
					echo "<select name=".esc_attr($ki).">";
					$scArr_display = array();
					$scArr_key = array();
					foreach($cronschedulesArr as $csk => $csv) {
						$scArr_display[$csv["interval"]] = $csv["display"];
						$scArr_key[$csv["interval"]] = $csk;
					}
					ksort($scArr_key, SORT_NUMERIC);


					foreach($scArr_key as $csk => $csv) {
						$csel = "";
						if ($op==$csv) {
							$csel = " selected ";
						}
						echo '<option value="'.esc_attr($csv).'" '.esc_attr($csel).">".esc_html($scArr_display[$csk]);
					}
					echo "</select>";
				} else if ($k=="requiredformat") {
					echo "<select name=".esc_attr($ki).">";
					foreach($reqformatArr as $csk => $csv) {
						$csel = "";
						if ($op==$csk) {
							$csel = " selected ";
						}
						echo '<option value="'.esc_attr($csk).'" '.esc_attr($csel).">".esc_html($csv)." ";
					}
					echo "</select>";
				} else if ($k=="retries") {
					echo "<select name=".esc_attr($ki).">";
					for ($rr = 1; $rr <= 10; $rr++) {
						$csel = "";
						if ($op==$rr) {
							$csel = " selected ";
						}
						echo '<option value="'.esc_attr($rr).'" '.esc_attr($csel).">".esc_html($rr)." ";
					}
					echo "</select>";
				} else if ($k=="sendmail") {
					$sel = "";
					if ($op=="yes" || (!isset($op))) {
						$csel = " checked ";
					}
					echo "<input type=checkbox ".esc_attr($csel)." name=".esc_attr($ki)." value=yes \>";
				} else {
					$placeholder = "";
					$inputtype = "text";
					if ($k=="startdate") {
						$placeholder = gmdate("Y-m-d H:i"); #"YYYY-MM-DD hh:mm:ss";
						$inputtype = "datetime-local";
					}
					if ($k=="url") {
					$placeholder = esc_html(__("http... OR /path... OR [shortcode id...]",'get-url-cron'));
					}
					$opout = stripslashes($op);
					echo ' <input type="'.esc_attr($inputtype).'" placeholder="'.esc_attr($placeholder).'" name="'.esc_attr($ki).'" value="'.esc_attr($opout).'" size='.esc_attr($fi_size[$k]).'>';
					#echo '<input type=text placeholder="'.$placeholder.'" name="'.$ki.'" value="'.$op.'" size='.$fi_size[$k].'>';
				}
				echo "</td>";
			}
			echo "<td>";
			$nonce = wp_create_nonce( 'getcronurl' );
			$url = "?page=unique_geturlcron_menu_slug&action=geturlcron&no=$r&hash=$nonce";
			echo "<a href=".esc_attr($url).">".esc_attr(__("Execute Job",'get-url-cron'))."</a>";
			echo "</td>";
			echo "</tr>";
		}
		echo "</table>";
		submit_button(); 
		echo "</form>";
}
	
public function register_geturlcronsettings() {
	register_setting( 'geturlcron-options-details', 'geturlcron-emailadr' );
	register_setting( 'geturlcron-options-details', 'geturlcron-timeout' );
	register_setting( 'geturlcron-options-details', 'geturlcron-uninstall-deleteall' );
	register_setting( 'geturlcron-options-details', 'geturlcron-dellog-days' );
	register_setting( 'geturlcron-options-details', 'geturlcron-maxno-cronjobs' );
	register_setting( 'geturlcron-options-details', 'geturlcron-mailonlyfail' );
	#$this->nooffields = $this->geturlcron_getnooffields();
	for ($r = 1; $r <= $this->nooffields; $r++) {
		register_setting( 'geturlcron-options', 'geturlcron-url-'.$r );
		register_setting( 'geturlcron-options', 'geturlcron-interval-'.$r );
		register_setting( 'geturlcron-options', 'geturlcron-startdate-'.$r );
		register_setting( 'geturlcron-options', 'geturlcron-retries-'.$r );
		register_setting( 'geturlcron-options', 'geturlcron-requiredjsonfield-'.$r );
		register_setting( 'geturlcron-options', 'geturlcron-requiredformat-'.$r );
		register_setting( 'geturlcron-options', 'geturlcron-sendmail-'.$r );
	}
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
		for ($r = 1; $r <= $this->nooffields; $r++) {
			foreach(self::$fi as $k => $v) {
				$ki = "geturlcron-".$k."-".$r;
				if (isset($_REQUEST['geturlcron_nc'])) { 
					$req_geturlcron_nc = sanitize_text_field(wp_unslash($_REQUEST['geturlcron_nc']));
						#	$req_geturlcron_nc = esc_hmtl($req_geturlcron_nc, ENT_QUOTES, 'UTF-8');
					$nonceCheck = wp_verify_nonce( esc_attr($req_geturlcron_nc), "geturlcron_nc" );
					if ($nonceCheck) {
						$ppin = sanitize_text_field(wp_unslash($_POST[$ki] ?? null));
						$op = update_option($ki, $this->geturlcron_handlePost_input($ki, $ppin));
					}
				}
			}
		}
	}

	public function geturlcron_add_action_cronjob($rt="") {
#	public function geturlcron_add_action_cronjob($rt) {
#	public function geturlcron_add_action_cronjob() {
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
		#$out = "";
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
				#$input_geturlcron_emailadr = sanitize_email($this->geturlcron_handlePost_input("geturlcron-emailadr", $ppin));
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
			$req_geturlcron_nc = sanitize_text_field(wp_unslash($_REQUEST['geturlcron_nc']));
			$nonceCheck = wp_verify_nonce( $req_geturlcron_nc, "geturlcron_nc" );
			if ($nonceCheck) {
				$this->geturlcron_unschedulejobs();
				# create all jobs
				$this->geturlcron_set_cronjoboptions();
				$this->geturlcron_set_urlSettingsarr();
				$this->geturlcron_activatejobs();
			} else {
				return TRUE;
			}
		}
		$ppin = sanitize_text_field(wp_unslash($_GET["action"] ?? null));
		$this->action = $this->geturlcron_handleGet_input("action", $ppin);
		
		if ("geturlcron"==$this->action) {
			$req_hash = "";
			#bjs
			
			$req_hash = "";
			if (isset($_REQUEST["hash"])) {
				$req_hash = sanitize_text_field(wp_unslash($_REQUEST["hash"]));
			}
			$noncecheckok = wp_verify_nonce($req_hash, "getcronurl"); 
			if (!$noncecheckok) {
				return TRUE;
			}
			$noin = sanitize_text_field(wp_unslash($_GET["no"] ?? null));
			if (is_null($noin)) {
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
				# needed format: 2022-12-14 22:25:38
				$ppval = preg_replace("/T/", " ",  $ppval);
			}
			$pp = $ppval;
			#$pp = sanitize_text_field($ppval);
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
			#echo $retVal["timenextexec"]."<br>";
			$datedstr = gmdate("Y-m-d, H:i:s", $retVal["timenextexec"]);
			#$datedstr = gmdate("Y-m-d, H:i:s", $retVal["timenextexec"] + $this->gmt_offset_add);
			if (($retVal["timenextexec"]>0) && ("geturlcron_disable"!=$retVal["sedeuleofurl"])){
				#echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;AC:  $no : ".print_r($retVal, true)."<br>";
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
			} else {
				#echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;NOT: $no : ".print_r($retVal, true)."<br>";
			}
		}
	}	

	private function geturlcron_log($idofrun, $url, $done_retries, $returnvalue, $info, $runtime, $status, $gucno="") {
	#private function geturlcron_log($idofrun, $url, $done_retries, $returnvalue, $info, $runtime, $status, $gucno="") {
		#$gucno = "";
		$separator = "#gucsep#";
		$separator = " /// ";
		$separator2 = "=";
		$datestr = gmdate("Y-m-d, H:i:s", time() +  $this->gmt_offset_add);
		$logline = $idofrun.$separator."time".$separator2.time().$separator."date".$separator2.$datestr.$separator.
				"retries".$separator2.$done_retries.$separator.$info.$separator."url".$separator2.$url.$separator."runtime".$separator2.$runtime.$separator."json".$separator2.substr($returnvalue, 0 ,300).$separator.$status.$separator.$gucno;
		$logline = preg_replace("/\n/", "", $logline);
		$logline = preg_replace("/\r/", "", $logline);
		#echo "logline: $logline<br>";
		return $logline;
	}
	
	
	

	private static function geturlcron_setlogfile() {
		#$plugincachepath = plugin_dir_path(__FILE__) . "logs";
		$ulp = wp_upload_dir();
		$plugincachepath = $ulp["basedir"]."/geturlcron";
		$plugincachepath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $plugincachepath);
		
		########
		# load WP_Filesystem
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$url = wp_nonce_url( 'index.php', 'my-nonce_geturlcron_setlogfile' );
		$credentials = request_filesystem_credentials( $url );
		if ( ! WP_Filesystem( $credentials ) ) {
			return FALSE;#'Failed to initialize WP_Filesystem.';
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
		###########
		self::$logfile = $plugincachepath."/geturlcron-log.cgi";
	}
	public static function geturlcron_getlogfile() {
		return self::$logfile;
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


	private function geturlcron_savelog($logline) {
		$deldays = (int) trim(get_option('geturlcron-dellog-days'));
		if ($deldays<=0) {
			return TRUE;
		}
		
		$logfile = $this->geturlcron_getlogfile();
		# load WP_Filesystem
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$url = wp_nonce_url( 'index.php', 'my-nonce_geturlcron_setlogfile' );
		$credentials = request_filesystem_credentials( $url );
		if ( ! WP_Filesystem( $credentials ) ) {
			return FALSE;#'Failed to initialize WP_Filesystem.';
		}
		global $wp_filesystem;
		
		# read data, append, store
		$new_content = "";
		$existing_content = $wp_filesystem->get_contents($logfile);
		if ($existing_content === false) {
			#return false;
		} else {
			$new_content .= $existing_content;
		}
		$new_content .= $logline . "\n";

		if (!$wp_filesystem->put_contents($logfile, $new_content)) {
			return FALSE;
		}
		#$fsc = file_put_contents($logfile, $logline."\n", FILE_APPEND);
		return TRUE;
	}

	private function geturlcron_getandcheckurl($url, $done_retries, $idofrun, $no) {
		$urlout = trim(stripslashes($url));

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
			$sc = trim(stripslashes($url));
			$returnvalue = do_shortcode($sc);
			$resp = "shortcode"; # do_shortcode does not have an errorlevel
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
				#return $error_message;
			} else {
				$resp = wp_remote_retrieve_response_code($response); # http code
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
#	public function geturlcron_executejob($urlArr, $no, $rt) {
#	public function geturlcron_executejob($urlArr, $no) {
		$url = $urlArr["url"];
		if (empty($url)) {
			return TRUE;
		}
		$urlout = stripslashes($url);
				
		$retries = $urlArr["retries"];
		$overallok = FALSE;
		$done_retries = 1;

		$idofrun = md5(time().$url.wp_rand());

		####
		$retArr = $this->geturlcron_getandcheckurl($url, $done_retries, $idofrun, $no);
		$returnvalue = $retArr["returnvalue"];
		$resp = $retArr["resp"];
		$runtime = $retArr["runtime"];
		$starttime = gmdate("H:i:s", $retArr["starttime"] +  $this->gmt_offset_add);
		$endtime = gmdate("H:i:s", $retArr["endtime"] +  $this->gmt_offset_add);

		#$message = " failedcheck \n";

		$checkArray = $this->geturlcron_checkresponse($urlArr, $returnvalue, $resp);
		if ($checkArray["requestok"]) {
		#if ($checkArray[""]) {
			$overallok = TRUE;
		} else { 
			#$message .= " fail detected \n";
			if ($retries>1) { # try again
				#$message .= " retry due to fail: done_retry is $done_retries \n";
				for ($r = 1; $r <= $retries; $r++) {
					#$message .= " retry run $r \n";
					$checkArray = $this->geturlcron_checkresponse($urlArr, $returnvalue, $resp);
					$done_retries++;
					$retArr = $this->geturlcron_getandcheckurl($url, $done_retries, $idofrun, $no);
					if ($checkArray["requestok"]) { # try ok
						#$message .= " retry run $r OK \n";
						$returnvalue = $retArr["returnvalue"];
						$resp = $retArr["resp"];
						$runtime = $retArr["runtime"];
						$starttime = gmdate("H:i:s", $retArr["starttime"] +  $this->gmt_offset_add);
						$endtime = gmdate("H:i:s", $retArr["endtime"] +  $this->gmt_offset_add);
						$overallok = TRUE;
						break;
					} else {
						#$message .= " retry run $r failed \n";
					}
				}
			} else {
				# no retry
			}
		}
	
		if ($overallok) {
			$status = __("OK",'get-url-cron')." ";
		} else {
			$status = __("FAIL",'get-url-cron');
		}
	
		#$info = $status." ".$checkArray["info"];
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

		$cronname = "$no, ".__("retires:",'get-url-cron')." $done_retries, ".__("url:",'get-url-cron')." $urlout, ".__("result:",'get-url-cron')." ".$logl;


		/*
		$logl = $this->geturlcron_log(
			$idofrun, 
			$urlout, 
			$done_retries, 
			$returnvalue, 
			$info, 
			$runtime, 
			$status);
		*/
		$this->geturlcron_savelog($logl);

		$subject = $status." $done_retries: ".__("get",'get-url-cron')." $urlout, ".__("ID",'get-url-cron')." $idofrun";

		$message = "\n\n-------------\n";
		#$message .= "RT: ".json_encode($rt);  # no of cronjob
		$message .= __("Status",'get-url-cron').": $status\n\n";
		$message .= __("Job",'get-url-cron').": $no\n";
		$message .= __("GET",'get-url-cron').": $urlout\n";
		$message .= __("ID",'get-url-cron').": $idofrun\n";
		$message .= __("Retires so far",'get-url-cron').": $done_retries\n";
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
			$to = trim(get_option('geturlcron-emailadr'));
			$to = preg_replace("/[, ]/", ";", $to);
			if (!empty($to)) {
				$srvh = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
				$site_domain = $senderprefix.__("Admin of",'get-url-cron') . ' ' . $srvh;
				$site_adminmail = get_option('admin_email') ?? '';
				
				$messageout = __('Mail from the WordPress-Plugin "Cron Setup and Monitor - Get URL Cron"','get-url-cron')."\n".__('Installed on','get-url-cron')." ".$srvh;
				$messageout .= __('Report for the execution of a Cron Job','get-url-cron').":";

				$messagefooter = "\n".'-------------'."\n".__("To Disable These Messages",'get-url-cron').': '."\n".__('GGo to the settings of the WordPress Plugin "Cron Setup and Monitor - Get URL Cron" and configure it to send emails only for failed cron jobs or disable email notifications entirely.','get-url-cron')."\n\n";
				$plugins_url_settings = admin_url('admin.php'). '?page=geturlcrondetailsettingslug';
				$messagefooter .= __('Plugin settings','get-url-cron').":\n".$plugins_url_settings."\n\n";
				$plugins_url_logs = admin_url('admin.php'). '?page=geturlcronlogslug';
				$messagefooter .= __('Cron Logs','get-url-cron').":\n".$plugins_url_logs."\n";
				
				$messageout .= $message. $messagefooter;
				
				$headers = 'From: ' . $site_domain .' <'.$site_adminmail.'>' . "\r\n";
				$headers .= 'Reply-To: '. $site_adminmail . "\r\n"; 
				$resmail = mail( $to , $subject , $messageout, $headers);
			}
		} else {
			/*
			$to = trim(get_option('geturlcron-emailadr'));
			$to = preg_replace("/[, ]/", ";", $to);
			if (!empty($to)) {
				$resmail = mail( $to , "do not send" , $message);
			}
			*/
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
							#$info .= __("failed jsonvalue detection",'get-url-cron').": ".$checkOnJSONArr["foundvalue"]." - ";
							$info4mail .= __("Required JSONfield missing",'get-url-cron')."\n";
							$info4mail .= $checkOnJSONArr["foundvalue"]."\n";
							#$info4mail .= __("failed jsonvalue detection",'get-url-cron').":\n".$checkOnJSONArr["foundvalue"]."\n";
							$requestok = FALSE;
						}
					}
				}
			} else {
				# any format welcome, check on requiredfield
				$requiredjsonfield = trim($urlArr["requiredjsonfield"]);
				$info4mail .= __("check on string: requiredjsonfield",'get-url-cron')."\n";
				if (empty($requiredjsonfield)) { 
					$info .= __("no check for required string",'get-url-cron'). " - ";
					$info4mail .= __("no check for required string",'get-url-cron')."\n";
					$requestok = TRUE;
				} else {
					if (preg_match("/$requiredjsonfield/", $returnvalue)) {
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
		wp_unschedule_event( "", $jobhook, $args );
		wp_clear_scheduled_hook( $jobhook, $args );
		#echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; unsch:  $no  $jobhook<br>";
	}


	## scheduling intervals
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
#GetUrlCron::initclass();
?>