=== Cron Setup and Monitor - Get URL Cron ===
Contributors: berkux
Tags: cron,scheduler,monitor,check,alarm
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Manage cron jobs, monitor tasks, retry failures, and send email alerts when something goes wrong.

== Description ==

Effortlessly define and manage cron jobs with execution URLs and WP shortcodes. The plugin monitors cron jobs, retries failed executions as needed, and sends status updates via email.

With "Cron Setup and Monitor - Get URL Cron" you can:

* Add, edit, and delete cron jobs to request HTTP URLs or WordPress shortcodes at defined times with various intervals.
* Verify the retrieved result by checking for a required string or JSON field to ensure the request was successful.
* Retry the HTTP URL or shortcode request multiple times in case of failures.
* Display all cron jobs in the WordPress installation, including those independent of this plugin.
* Manually execute cron jobs.
* Log requests and show OK or FAIL status in a database-backed log.
* Send emails for each cron job execution, including start attempt and result.
* Option to send emails only on failure.
* System Check page: verify PHP version, WordPress cron status, loopback requests, outgoing HTTP, and SSL support.

= Usage =
1. Go to 'Basic Settings' in the plugin menu to set basic settings (e.g. e-mail address for status messages).
2. Go to 'Set CronJobs' to manage cron events: set URL or WordPress shortcode, interval, start date, etc.
3. Save the defined cron jobs.
4. Manually execute a cron job by clicking "Execute Job".
5. Check plugin menu 'Show CronJobs': the scheduled cron jobs "geturlcron_event-" should be listed there.
6. Check plugin menu 'Show Logs': completed jobs show OK or FAIL status.
7. If an e-mail address is defined, e-mails are sent for each attempt and result.

== Frequently Asked Questions ==

= What is the purpose of this plugin? =
* Monitor websites and URLs on other servers to check if a service is running correctly.
* Execute WordPress shortcodes on a schedule.
* Generate Custom Post Types with the plugin JSON Content Importer.

= How do I start a job at a defined time? =
When setting up a cron job, specify a "first run date and time" along with a recurrence interval. The plugin calculates subsequent execution times by adding the recurrence interval to the first run time.

For example: first run at 6:00 AM, interval 15 minutes: jobs run at 6:00, 6:15, 6:30, etc.

= How do I receive email notifications only for failed jobs? =
Select the "Email only for failed jobs" option in the Basic Settings of the plugin.

= What is the minimum WordPress version required? =
WordPress 6.2 or higher is required. The plugin uses the `%i` identifier placeholder in `$wpdb->prepare()`, which was introduced in WordPress 6.2.

= Does the plugin work on multisite installations? =
The plugin uses the `manage_options` capability for access control and should work on multisite. Each site manages its own cron jobs independently.

== Screenshots ==

1. New cron events can be added, modified, deleted, and executed. Includes setup for monitoring with required string or JSON field check.
2. Overview of all running cron jobs with next run time and recurrence interval.
3. Basic settings for e-mail notification, timeout, log retention, and uninstall options.
4. Log view: see executed cron jobs with OK or FAIL status, runtime, URL, and response.
5. System Check: verify PHP version, WordPress cron, loopback requests, outgoing HTTP transport, and SSL support.

== Installation ==
Basic installation: for detailed installation instructions, please read the [standard installation procedure for WordPress plugins](http://codex.wordpress.org/Managing_Plugins#Installing_Plugins).

Configure "Cron Setup and Monitor - Get URL Cron": select "Basic Settings" and set the e-mail address for status messages, timeout for HTTP/shortcode requests, and the expiration time of log entries. Then define a cron job.

== Changelog ==
= 2.0.0 =
* Improved: Design, security and usability of the plugin including basic settings and log viewer
* New: System Check – verifies that the server meets all requirements for this plugin
* Changed: Log storage migrated from file-based to WordPress database. A one-time migration will be triggered automatically!
* Requires at least WordPress 6.2 (set explicitly in plugin header and readme)
* Plugin ok with WordPress 6.9
* Plugin ok with Plugin Check 1.7.0

= 1.5.3 =
* Minor bugfix: unneeded PHP warning removed
* Plugin ok with WordPress 6.6.2
* Plugin ok with Plugin Check 1.2.0

= 1.5.2 =
* Additional bug fixes related to the log
* Plugin ok with Plugin Check 1.1.0

= 1.5.1 =
* Bugfix on page "Set Cronjobs"
* OK with WordPress 6.6.1

= 1.5.0 =
* Rename plugin to "Cron Setup and Monitor - Get URL Cron"
* OK with WordPress 6.6
* Plugin Check status: no errors
* Changed: display of time — UTC Unix timestamp used internally; WordPress timezone settings applied in the frontend
* Added feature: option to send emails only for failed cron jobs
* Improved: log evaluation — see latest executed cron jobs and their status
* Improved: backend design with logs, setup, and system check

= 1.4.8 =
* Fixed security issue: Rio D. discovered a security issue. Thank you Rio! Access requires WordPress backend login; the affected page is in the admin area only. Nevertheless: please update!

= 1.4.7 =
* Improved display of cron jobs
* PHP 8.1 fixes
* Minor bugfixes

= 1.4.6 =
* Display current server time on several pages
* Set DISABLE_WP_CRON to false if not set before
* Minor preparations for PHP 8 usage

= 1.4.5 =
* Bugfix: translation settings
* Minor improvement if no cron job is defined

= 1.4.4 =
* Plugin ready for translations: POT file available, MO file for German included
* Set cron job start date: placeholder shows current server time
* Set cron job interval: additional intervals 5, 10, 15 minutes and option "disable"
* Bugfix: chronological sorting of log entries
* Plugin ok with WordPress 5.8.3

= 1.4.3 =
* Bugfix: more than 15 cron jobs now really possible
* Plugin ok with WordPress 5.8.2

= 1.4.2 =
* Minor bugfix: no more PHP notice messages at log display
* Plugin ok with WordPress 5.8

= 1.4.1 =
* Basic Settings: you can increase the number of cron jobs beyond 15
* Plugin ok with WordPress 5.7.1

= 1.4 =
* Bugfix displaying next execution time
* Plugin ok with WordPress 5.6

= 1.3 =
* Plugin ok with WordPress 5.4 and PHP 7.4

= 1.2 =
* Cron job WordPress shortcode: insert shortcodes which will be executed

= 1.1 =
* Relative cron job URL: if a URL starts with "/" the home URL is prepended

= 1.0 =
* Initial release on WordPress.org

== Upgrade Notice ==
= 2.0.0 =
Usability, security and performance improvements. Log storage migrated to database.

