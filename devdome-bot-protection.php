<?php
/*
Plugin Name: DevDome Bot Protection
Plugin URI: https://devdome.com/
Description: See your real traffic and protect it — block bad bots and fake clicks while never breaking your Google rankings. Part of the DevDome suite.
Version: 1.0.5
Requires at least: 6.2
Requires PHP: 7.4
Author: DevDome
Author URI: https://devdome.com
Text Domain: devdome-bot-protection
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the
// same codebase can switch off self-hosted updates and other non-wp.org behavior.
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDBOTP_VERSION', '1.0.5');
define('DEVDBOTP_DIR', plugin_dir_path(__FILE__));
define('DEVDBOTP_URL', plugin_dir_url(__FILE__));

// Shared bot-detection core (vendored, version-guarded; only the highest copy across
// all installed DevDome plugins actually loads). Provides the unified UA/ASN/DROP feed
// + matchers; this plugin's advanced signals + enforcement stay above it.
require_once DEVDBOTP_DIR . 'lib/devdome-core/loader.php';

// Legacy shared-state copy (devdome_* -> devdcorev1_*) ships only in self-hosted builds:
// wp.org installs are fresh and have no old rows to move.
if (file_exists(DEVDBOTP_DIR . 'includes/bp-core-ids.php')) {
    require_once DEVDBOTP_DIR . 'includes/bp-core-ids.php';
}

require_once DEVDBOTP_DIR . 'includes/db-guard.php';
require_once DEVDBOTP_DIR . 'includes/settings.php';
require_once DEVDBOTP_DIR . 'includes/install.php';
if (file_exists(DEVDBOTP_DIR . 'includes/migrate.php')) {
    require_once DEVDBOTP_DIR . 'includes/migrate.php'; // self-hosted builds only: pre-release data moves to the devdbotp_ names
}
require_once DEVDBOTP_DIR . 'includes/feeds.php';
require_once DEVDBOTP_DIR . 'includes/detector.php';
require_once DEVDBOTP_DIR . 'includes/engine.php';
require_once DEVDBOTP_DIR . 'includes/edge.php';
require_once DEVDBOTP_DIR . 'includes/knownbots.php';
require_once DEVDBOTP_DIR . 'includes/sessions.php';
require_once DEVDBOTP_DIR . 'includes/rules.php';
require_once DEVDBOTP_DIR . 'includes/reputation.php';
require_once DEVDBOTP_DIR . 'includes/honeypot.php';
require_once DEVDBOTP_DIR . 'includes/fleet.php';
require_once DEVDBOTP_DIR . 'includes/geoip.php';
require_once DEVDBOTP_DIR . 'includes/site-health.php';
require_once DEVDBOTP_DIR . 'includes/abilities.php';
require_once DEVDBOTP_DIR . 'includes/privacy.php';
require_once DEVDBOTP_DIR . 'includes/multisite.php';

if (is_admin()) {
    require_once DEVDBOTP_DIR . 'includes/admin.php';
}

add_filter('query', 'devdbotp_db_guard_record', 1);

// "Report this error" (DESIGN.md 26): the report carries the plugin's state, never a visitor address;
// every excerpt passes the plugin's own redactor before it leaves the site.
add_filter('devdcorev1_error_report_log', function ($lines, $plugin) {
    if ($plugin !== 'devdome-bot-protection' || !function_exists('devdbotp_get_setting')) {
        return $lines;
    }
    $feeds = function_exists('devdbotp_feeds') ? devdbotp_feeds() : array();
    return array(
        'level=' . (string) devdbotp_get_setting('protection_level', '?') . ' mode=' . (string) devdbotp_get_setting('mode', '?') . ' safe_mode=' . (int) ((int) devdbotp_get_setting('safe_mode_until', 0) > time()),
        'cloudflare=' . (int) (function_exists('devdbotp_behind_cloudflare') && devdbotp_behind_cloudflare()) . ' object_cache=' . (int) wp_using_ext_object_cache() . ' outside_lookups=' . (int) devdbotp_get_setting('outside_lookups', 0),
        'feeds_patterns=' . (is_array($feeds) && !empty($feeds['patterns']) ? count((array) $feeds['patterns']) : 0),
        'blocked_today=' . (int) devdbotp_get_setting('stat_blocked_today', 0) . ' bots_seen=' . (int) devdbotp_get_setting('stat_bots_seen', 0),
        'cron_feeds=' . (int) wp_next_scheduled('devdbotp_refresh_feeds'),
    );
}, 10, 2);
add_filter('devdcorev1_error_report_redact', function ($text, $plugin) {
    return ($plugin === 'devdome-bot-protection' && function_exists('devdbotp_redact')) ? devdbotp_redact((string) $text) : $text;
}, 10, 2);

// DevDome Tools hub: register this plugin in the suite dashboard (decoupled — devdome-core
// renders the Overview from whatever plugins register here; new plugin = ~10 lines, zero hub edits).
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-bot-protection'] = array(
        'slug'     => 'devdome-bot-protection',
        'name'     => 'Bot Protection',
        'desc'     => 'Block fake clicks &amp; bot traffic so your stats stay real.',
        'icon'     => 'dashicons-shield',
        'version'  => DEVDBOTP_VERSION,
        'page'     => 'devdome-bot-protection',
        'position' => 30,
        'schema'   => 1,
        'tiles'    => function () {
            // Read-only: all values come from the cached settings blob (no query on render).
            if (!function_exists('devdbotp_get_setting')) {
                return array();
            }
            $href    = 'admin.php?page=devdome-bot-protection';
            $blocked = (int) devdbotp_get_setting('stat_day_' . devdbotp_days_back(1)[0] . '_blocked', 0); // the day bucket
            $bots    = (int) devdbotp_get_setting('stat_bots_seen', 0);
            $hp_map  = devdbotp_get_setting('bp_honeypot_asns', array());
            $netw    = is_array($hp_map) ? count($hp_map) : 0;
            if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
                return array(); // the settings could not be read: no tiles rather than zeros
            }
            return array(
                array('label' => 'Blocked today',    'value' => $blocked, 'fmt' => 'int', 'state' => $blocked ? 'good' : 'idle', 'href' => $href),
                array('label' => 'Bots seen',        'value' => $bots,    'fmt' => 'int', 'state' => $bots ? 'warn' : 'idle', 'href' => $href),
                array('label' => 'Networks flagged', 'value' => $netw,    'fmt' => 'int', 'state' => $netw ? 'warn' : 'idle', 'href' => $href . '&bp_tab=rules'),
            );
        },
        'health'   => function () {
            if (!function_exists('devdbotp_get_setting')) {
                return null;
            }
            $href  = admin_url('admin.php?page=devdome-bot-protection');
            $mode  = (string) devdbotp_get_setting('mode', 'watching');
            $level = (string) devdbotp_get_setting('protection_level', 'recommended');
            $safe  = (int) devdbotp_get_setting('safe_mode_until', 0);
            $issues = array();
            if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
                return array(
                    'score'       => 0,
                    'status'      => 'urgent',
                    'scope_label' => 'Protection',
                    'summary'     => 'The settings could not be read.',
                    'issues'      => array(array('problem' => 'Bot Protection could not read its settings.', 'why_it_matters' => 'Its state is unknown until the database answers again.', 'fix' => 'Reload this page; if it stays, check the database.', 'href' => $href, 'level' => 'critical')),
                );
            }
            // Paused, off or watching only are the owner's choices, not problems: the dashboard lists real failures only (owner + Codex 2026-09-30).
            $score = $issues ? ($safe > time() || $level === 'off' ? 0 : 60) : 100;
            return array(
                'score'       => $score,
                'status'      => $score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'urgent'),
                'scope_label' => 'Protection',
                'summary'     => '',
                'issues'      => $issues,
            );
        },
    );
    return $r;
});

// S3: Recent-activity digest section (read-only, from cached settings).
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdbotp_get_setting')) {
        return $s;
    }
    $blocked_today = (int) devdbotp_get_setting('stat_blocked_today', 0);
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        $s[] = array('title' => 'Bot Protection', 'lines' => array('The settings could not be read; state unknown.'));
        return $s;
    }
    $s[] = array('title' => 'Bot Protection', 'lines' => array(
        $blocked_today . ' blocked today',
        (int) devdbotp_get_setting('stat_bots_seen', 0) . ' bots seen',
        (devdbotp_get_setting('protection_level', 'recommended') === 'off' ? 'Protection is off' : (devdbotp_get_setting('mode', 'watching') === 'live' ? 'Blocking threats' : 'Watching only')),
    ));
    return $s;
});

register_activation_hook(__FILE__, 'devdbotp_activate');
register_deactivation_hook(__FILE__, 'devdbotp_deactivate');

/* DevDome suite self-hosted updates (admin/cron only - never on the front end). The wp.org
   build drops this behavior entirely (wp.org delivers updates there) by defining
   DEVDCOREV1_WPORG_BUILD, so the whole block — including the updater require — is skipped. */
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) ) {
	require_once __DIR__ . '/includes/class-devdome-suite-updater.php';
	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		new DevDome_Suite_Updater( __FILE__, 'devdome-bot-protection', 'https://api.devdome.com/plugin-updates/devdome-bot-protection.json' );
	}
}
