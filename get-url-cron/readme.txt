=== Cron Setup and Monitor - Get URL Cron ===
Contributors: berkux
Tags: cron,scheduler,monitor,check,alarm
Requires at least: 3.0
Tested up to: 6.6.1
Requires PHP: 7.4
Stable tag: 1.5.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Manage cron jobs, monitor tasks, retry failures, and send email updates

== Description ==

Effortlessly define and manage cron jobs with execution URLs and WP-Shortcodes. The plugin monitors cron jobs, retries failed executions as needed, and sends status updates via email.

With "Cron Setup and Monitor - Get URL Cron" you can:
* Add, edit, and delete cron jobs to request HTTP URLs or WordPress shortcodes at defined times with various intervals.
* Verify the retrieved result by checking for a required string or JSON field to ensure the HTTP URL or shortcode request was successful.
* Retry the HTTP URL or shortcode request multiple times in case of failures.
*  Display all cron jobs in the WordPress installation, including those independent of this plugin.
* Manually execute cron jobs.
* Log requests and show OK or FAIL status: The first log entry records what should happen ("try"). The second log entry shows the success of the request.
* Send emails for each HTTP URL or shortcode request, including the start of the attempt and the result of the request.
* Option to send emails only on failure.

= Usage =
1. Go to 'Basic Settings' in the plugin menu to set basic settings (like E-Mailadress for Statusmessages) 
2. Go to 'Set CronJobs' to manage the cron events: Set URL or Wordpress-Shortcode, interval, startdate etc.
3. Store the defined CronJobs
4. Manually execute a Cronjob by clicking on "execute job"
5. Check plugin-menu 'Show CronJobs': There the scheduled CronJobs "geturlcron_event-" should be listed 
6. Check plugin-menu 'Show Logs': There should be at least one entry for the "try". And if the CronJob has been finished a entry for the result ("FAIL" or "OK")
7. If a E-Mailadress is defined, two E-Mails are sent for trying and result.  

== Frequently Asked Questions ==

= What's the use of the plugin? =
* Monitor websites / URLs on other Servers to check if the service is ok
* Cron-Execute Wordpress-Shortcodes 
* Generate Custom Post Types with the Plugin JSON Content Importer

= How to Start a Job at a Defined Time? =
When setting up a Cron job, you specify a "first run date and time" along with a recurrence interval. The plugin then calculates subsequent execution times starting from this "first run date and time" by adding the recurrence interval.
For example, if the "first run date and time" is set for today at 6:00 AM and the recurrence interval is 15 minutes, the Cron job will execute at 6:00 AM, 6:15 AM, 6:30 AM, and so on.


= How to Receive Email Notifications Only for Failed Jobs? =
To receive email notifications only for failed jobs, select the "Email only for failed jobs" option in the basic settings of the plugin.

= What is the PluginCheckPlugin-Status? =
* No errors found

== Screenshots ==

1. New cron events can be added, modified, deleted, and executed. Plus: Setup for monitoring.
2. Overview of all running Cronjobs
3. Basic settings for E-Mail-Notification, Timeout. Logfile and uninstall
4. Logfile: See what's going on - try and success / failure
                                                                      
== Installation ==
Basis installation: For detailed installation instructions, please read the [standard installation procedure for WordPress plugins](http://codex.wordpress.org/Managing_Plugins#Installing_Plugins).

Configure "Cron Setup and Monitor - Get URL Cron": Select "Basic Settings" and set E-Mailadress for Statusmessages, Timeout for the http-URL- / Shortcode-requests and the expiration time of logfile-entries. Then define a cron job.

== Changelog ==
= 1.5.2 =
* Additional bug fixes related to the logfile
* Plugin ok with "Plugin Check 1.1.0"

= 1.5.1 =
* Bugfix on Page "Set Cronjobs"
* OK with WordPress 6.6.1

= 1.5.0 =
* Rename Plugin to "Cron Setup and Monitor - Get URL Cron"
* OK with WordPress 6.6
* PluginCheckPlugin-Status: No Errors
* CHANGED - Display of Time: In the backend, the UTC Unix timestamp is always used. In the frontend, the timezone settings of WordPress are now used. Keep this in mind when viewing the execution times!
* Added Feature: Option to send emails only for failed cronjobs
* Improved: Log-Evaluation - see latest executed Cronjobs and their Status
* Improved: Design of backend with Logs, Setup etc.

= 1.4.8 =
* Fixed security issue: Rio D. discovered a security issue. Thank you Rio! For utilize this you need Wordpress-Backend-Access and the affected Page is in the Wordpress-Adminarea only. Nevertheless: Update your JCI-Plugin, please!

= 1.4.7 =
* Improved display of CronJobs
* PHP8.1 fixes
* Minor Bugfixes 

= 1.4.6 =
* Display current Servertime on several pages
* Set DISABLE_WP_CRON to false if not set before
* Minor Preparations for PHP8-usage

= 1.4.5 =
* Bugfix: Translation settings
* Minor Improvement if no Cronjob is defined  

= 1.4.4 =
* Plugin ok for Translations: POT-File available, MO-File for German included 
* Set Cronjob, startdate: Placeholder shows current servertime
* Set Cronjob, interval: Additional intervals 5, 10 15 minutes and option "disable" 
* Bugfix: Chronological Sorting of Logfiles
* Plugin is ok with WP 5.8.3

= 1.4.3 =
* Bugfix: More than 15 Cronjobs now really possible... 
* Plugin is ok with WP 5.8.2

= 1.4.2 =
* Minor Bugfix: No more "PHP Notice"-Messages at Logfile-Display  
* Plugin is ok with WP 5.8

= 1.4.1 =
* "Basic Settings": You can increase the no of cronjobs 15+n  
* Plugin is ok with WP 5.7.1

= 1.4 =
* Bugfix displaying next execution time 
* Plugin is ok with WP 5.6 

= 1.3 =
* Plugin is ok with WP 5.4. and PHP 7.4 

= 1.2 =
Cronjob-Wordpress-Shortcode: Insert Shortcodes which will be executed

= 1.1 =
Relative Cronjob-URL: If a Cronjob-URL starts with "/" the domain is added ("home_url()")

= 1.0 =
Initial release on WordPress.org. Any comments and feature-requests are welcome!

== Upgrade Notice ==
= 1.5.2 =
* Additional bug fixes related to the logfile
* Plugin ok with "Plugin Check 1.1.0"