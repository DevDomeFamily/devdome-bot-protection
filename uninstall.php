<?php
/**
 * Full data removal — runs only when the plugin is DELETED (not on deactivate).
 * Drops the custom tables and clears any leftover options/transients. Per-site on multisite.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

function devdbotp_uninstall_site()
{
    global $wpdb;
    $p = $wpdb->prefix . 'devdbotp_';

    foreach (array('settings', 'events', 'ip_reputation', 'sessions', 'rules') as $t) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix + hardcoded table name; DROP TABLE cannot be prepared/cached.
        $wpdb->query("DROP TABLE IF EXISTS {$p}{$t}");
    }

    delete_option('devdbotp_db_version'); // the setup marker (1.0.5)
    wp_unschedule_hook('devdbotp_refresh_feeds');
    wp_unschedule_hook('devdbotp_kb_sync');
    wp_unschedule_hook('devdbotp_kb_push_event');
    // Every transient this plugin writes (their timeout rows too), then the object cache copy of them.
    foreach (array('devdbotp_kb_series2', 'devdbotp_kb_pulled', 'devdbotp_kb_signal', 'devdbotp_kb_series', 'devdbotp_asn_down') as $tr) {
        delete_transient($tr);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep of this plugin's own transients
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        '_transient_devdbotp_%', '_transient_timeout_devdbotp_%', '_transient_devdbotp_%', '_transient_timeout_devdbotp_%'));
    wp_cache_flush_group('devdbotp'); // this plugin's own entries only
}

// Remove the hot-path mu-plugin + every Safe Mode flag file (functions aren't loaded here). Only through plain paths:
// a link where the file or a parent directory should be is left alone, so nothing outside wp-content can be hit.
function devdbotp_uninstall_plain_path($path)
{
    if (is_link($path)) {
        return false;
    }
    $dir  = dirname($path);
    $root = realpath(WP_CONTENT_DIR);
    $real = realpath($dir);
    if ($root === false || $real === false || strpos($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) !== 0) {
        return false; // the real location is outside the content directory: not ours to delete
    }
    // Every directory in the GIVEN path is inspected up to the content dir, (a path whose real location is outside the
    // content directory was already refused above): a link anywhere on the way refuses. Never cut by length.
    while (true) {
        if (is_link($dir)) {
            return false;
        }
        if ($dir === WP_CONTENT_DIR || $dir === $root) {
            break;
        }
        $up = dirname($dir);
        if ($up === $dir) {
            break;
        }
        $dir = $up;
    }
    return true;
}
$devdbotp_mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
$devdbotp_files  = array_merge(array($devdbotp_mu_dir . '/devdbotp-hotpath.php'), (array) glob(WP_CONTENT_DIR . '/devdome-bp-safe*.flag'));
foreach ($devdbotp_files as $devdbotp_f) {
    if (is_string($devdbotp_f) && file_exists($devdbotp_f) && devdbotp_uninstall_plain_path($devdbotp_f)) {
        wp_delete_file($devdbotp_f);
    }
}

// Coordinate shared-core cleanup (removes the core cron/option only if this is the LAST
// DevDome plugin still installed, so the others keep working). Per site on a network: the
// shared state is stored per site.
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
// The suite updater caches its update manifest under a hashed key, per site and (on multisite) network wide.
// Clear both spellings, or the cache outlives the plugin.
$devdbotp_updater_cache = 'devdome_upd_' . md5('devdome-bot-protection');
delete_transient($devdbotp_updater_cache);
if (is_multisite()) {
    delete_site_transient($devdbotp_updater_cache);
}
if (is_multisite()) {
    $devdbotp_offset = 0;
    do {
        $devdbotp_sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $devdbotp_offset));
        foreach ($devdbotp_sites as $devdbotp_blog_id) {
            switch_to_blog((int) $devdbotp_blog_id);
            devdbotp_uninstall_site();
            devdcorev1_uninstall_cleanup('devdome-bot-protection/devdome-bot-protection.php');
            restore_current_blog();
        }
        $devdbotp_offset += 100;
    } while (count($devdbotp_sites) === 100);
} else {
    devdbotp_uninstall_site();
}

// The current site (single site, or the main site of a network).
devdcorev1_uninstall_cleanup('devdome-bot-protection/devdome-bot-protection.php');

wp_cache_flush_group('devdbotp');
