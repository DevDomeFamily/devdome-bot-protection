<?php
/**
 * Activation / deactivation lifecycle: create the custom tables (settings + the
 * blueprint's events / ip_reputation / sessions / rules, §12) and seed defaults.
 * Deactivation is non-destructive; full removal happens in uninstall.php.
 */

defined('ABSPATH') || exit;

function devdbotp_activate()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $p = $wpdb->prefix . 'devdbotp_';


    // name/value settings store
    require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // dbDelta
    dbDelta("CREATE TABLE {$p}settings (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_name varchar(191) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_name (setting_name)
    ) $charset_collate;");

    // events log (blueprint §12.1)
    dbDelta("CREATE TABLE {$p}events (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        request_id varchar(64) NULL,
        ip varbinary(16) NOT NULL,
        ip_text varchar(45) NOT NULL,
        country char(2) NULL,
        asn int unsigned NULL,
        asn_org varchar(255) NULL,
        is_datacenter tinyint(1) DEFAULT 0,
        method varchar(10) NOT NULL,
        path text NOT NULL,
        query_hash char(64) NULL,
        status_code smallint unsigned NULL,
        user_agent text NULL,
        referer text NULL,
        cf_ray varchar(64) NULL,
        cf_bot_score tinyint unsigned NULL,
        cf_verified_bot tinyint(1) DEFAULT 0,
        ja3_hash varchar(64) NULL,
        ja4 varchar(255) NULL,
        detector_labels text NULL,
        bot_category varchar(64) NULL,
        risk_score smallint NOT NULL DEFAULT 0,
        action varchar(32) NOT NULL,
        reason text NULL,
        PRIMARY KEY  (id),
        KEY idx_created_at (created_at),
        KEY idx_ip_text (ip_text),
        KEY idx_bot_category (bot_category),
        KEY idx_action (action),
        KEY idx_risk_score (risk_score)
    ) $charset_collate;");

    // ip reputation cache (blueprint §12.2)
    dbDelta("CREATE TABLE {$p}ip_reputation (
        ip varbinary(16) NOT NULL,
        ip_text varchar(45) NOT NULL,
        first_seen datetime NOT NULL,
        last_seen datetime NOT NULL,
        country char(2) NULL,
        asn int unsigned NULL,
        asn_org varchar(255) NULL,
        is_datacenter tinyint(1) DEFAULT 0,
        is_proxy tinyint(1) DEFAULT 0,
        is_vpn tinyint(1) DEFAULT 0,
        is_tor tinyint(1) DEFAULT 0,
        spamhaus_drop tinyint(1) DEFAULT 0,
        spamhaus_asn_drop tinyint(1) DEFAULT 0,
        greynoise_classification varchar(64) NULL,
        abuse_score tinyint unsigned NULL,
        total_requests bigint unsigned DEFAULT 0,
        total_blocks bigint unsigned DEFAULT 0,
        total_challenges bigint unsigned DEFAULT 0,
        last_checked_at datetime NULL,
        expires_at datetime NULL,
        PRIMARY KEY  (ip),
        KEY idx_ip_text (ip_text),
        KEY idx_asn (asn),
        KEY idx_expires_at (expires_at)
    ) $charset_collate;");

    // sessions (blueprint §12.3)
    dbDelta("CREATE TABLE {$p}sessions (
        session_id char(64) NOT NULL,
        first_seen datetime NOT NULL,
        last_seen datetime NOT NULL,
        ip_text varchar(45) NOT NULL,
        country char(2) NULL,
        asn int unsigned NULL,
        user_agent_hash char(64) NULL,
        pageviews int unsigned DEFAULT 0,
        unique_paths int unsigned DEFAULT 0,
        js_seen tinyint(1) DEFAULT 0,
        cookie_seen tinyint(1) DEFAULT 0,
        asset_seen tinyint(1) DEFAULT 0,
        scroll_seen tinyint(1) DEFAULT 0,
        mouse_seen tinyint(1) DEFAULT 0,
        touch_seen tinyint(1) DEFAULT 0,
        form_seen tinyint(1) DEFAULT 0,
        login_attempts int unsigned DEFAULT 0,
        signup_attempts int unsigned DEFAULT 0,
        api_requests int unsigned DEFAULT 0,
        tool_requests int unsigned DEFAULT 0,
        not_found_count int unsigned DEFAULT 0,
        risk_score smallint DEFAULT 0,
        classification varchar(64) NULL,
        PRIMARY KEY  (session_id),
        KEY idx_last_seen (last_seen),
        KEY idx_ip_text (ip_text),
        KEY idx_classification (classification)
    ) $charset_collate;");

    // rules (blueprint §12.4)
    dbDelta("CREATE TABLE {$p}rules (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        enabled tinyint(1) DEFAULT 1,
        priority int DEFAULT 100,
        rule_type varchar(64) NOT NULL,
        name varchar(255) NOT NULL,
        match_field varchar(64) NOT NULL,
        operator varchar(32) NOT NULL,
        pattern text NOT NULL,
        action varchar(32) NOT NULL,
        risk_points smallint DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_enabled_priority (enabled, priority),
        KEY idx_rule_type (rule_type)
    ) $charset_collate;");

    // Seed default settings only when missing (reactivation/upgrade never wipes).
    $defaults = array(
        'protection_level'      => 'recommended', // off | recommended (= on)
        'mode'                  => 'watching',    // watching (Log-Only, default) | live
        'safe_mode_until'       => 0,             // unix ts; >now = protection paused
        'anonymize_ips'         => 0,             // mask logged IPs (v4 -> /24, v6 -> /48) for privacy
        'outside_lookups'       => 0,             // opt-in (off): bot list downloads + country / network lookups outside this site
        'rate_limit_action'     => 'log',         // off | log | block — per-IP throttle on sensitive endpoints (log: shared IPs and REST integrations trip it)
        'velocity_action'       => 'log',         // off | log | block — flag-first per-IP request-velocity signal (public pages)
        'fleet_enabled'         => 0,             // opt-in: join the community reputation network (report + consume the shared blocklist)
        'fleet_action'          => 'log',         // off | log | block — action for IPs on the fleet community blocklist
        'behavior_beacon'       => 1,             // front-end JS beacon: confirm human sessions (JS + cookie + interaction)
        'repeat_offender_action' => 'block',      // off | log | block — auto-action for repeat-offender IPs
        'repeat_offender_threshold' => 10,        // blocked hits in the retention window before an IP is auto-actioned
        'behavior_action'       => 'log',         // off | log | block — automated-browser (navigator.webdriver) sessions (log: flags a whole IP for 7 days)
        'honeypot_enabled'      => 1,             // print the hidden trap link + catch crawlers that follow it
        'honeypot_action'       => 'log',         // off | log | block — action for ASNs caught crawling the honeypot (flag-first)
        'honeypot_asn_min_ips'  => 3,             // flag an ASN only after >= this many distinct IPs from it hit the trap (safety gate)
        // headline counters (rolled up; engine bumps these later)
        'stat_blocked_today'    => 0,
        'stat_bots_seen'        => 0,
        'stat_humans_seen'      => 0,
        'admin_ip_pinned'       => '',
    );
    $table = $wpdb->prefix . 'devdbotp_settings';
    foreach ($defaults as $name => $value) {
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (setting_name, setting_value) VALUES (%s, %s)",
            $name,
            is_array($value) ? serialize($value) : $value
        ));
    }

    unset($GLOBALS['devdbotp_cache']);

    // Daily maintenance (prune, and the reference feeds once Outside Lookups is on). Nothing is downloaded on
    // activation: no request leaves the site until the owner turns Outside Lookups on (wp.org review 2026-09-29).
    if (!wp_next_scheduled('devdbotp_refresh_feeds')) {
        wp_schedule_event(time() + 3600, 'daily', 'devdbotp_refresh_feeds');
    }
    // Known Bots dashboard sync (hourly pull; no-op when analytics isn't connected).
    if (!wp_next_scheduled('devdbotp_kb_sync')) {
        wp_schedule_event(time() + 300, 'hourly', 'devdbotp_kb_sync');
    }

    devdbotp_remove_old_hot_files();
}

/**
 * Tables and defaults are created by the activation hook, which never runs when the plugin files are copied into
 * place or updated outside the plugin screen (audit 2026-10-16: a site re-provisioned that way had the plugin active
 * with no tables, a failing query on every request and no protection). On every admin load, when the stored version
 * is not this version, run the same setup again: dbDelta and INSERT IGNORE change nothing on a healthy site, and the
 * marker makes this one option read per admin page afterwards.
 */
add_action('admin_init', function () {
    if (get_option('devdbotp_db_version', '') === DEVDBOTP_VERSION) {
        return;
    }
    devdbotp_activate();
    update_option('devdbotp_db_version', DEVDBOTP_VERSION, false);
}, 0);

/** Builds before 1.0.1 wrote an early-block mu-plugin and a Safe Mode flag file; remove them once (never through a link).
 *  Also on admin_init: an update through the plugin screen re-runs no activation hook, and the old file must not keep enforcing. */
add_action('admin_init', function () {
    $mu   = (defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins') . '/devdbotp-hotpath.php';
    $flag = WP_CONTENT_DIR . '/devdome-bp-safe' . (is_multisite() ? '-' . (int) get_current_blog_id() : '') . '.flag';
    if (current_user_can('activate_plugins') && (file_exists($mu) || file_exists($flag))) {
        devdbotp_remove_old_hot_files();
    }
});
function devdbotp_remove_old_hot_files()
{
    $dir   = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    $files = array_merge(array($dir . '/devdbotp-hotpath.php'), (array) glob(WP_CONTENT_DIR . '/devdome-bp-safe*.flag'));
    foreach ($files as $f) {
        if (is_string($f) && file_exists($f) && !is_link($f) && !is_link(dirname($f))) {
            wp_delete_file($f);
        }
    }
}

function devdbotp_deactivate()
{
    if (is_multisite() && function_exists('devdbotp_for_each_site') && !empty($GLOBALS['devdbotp_network_deactivating'])) {
        devdbotp_for_each_site(function () {
            wp_unschedule_hook('devdbotp_refresh_feeds');
            wp_unschedule_hook('devdbotp_kb_sync');
            wp_unschedule_hook('devdbotp_kb_push_event');
        });
    }
    // Non-destructive: settings + stats survive a toggle. Only clear schedules.
    wp_unschedule_hook('devdbotp_refresh_feeds');
    wp_unschedule_hook('devdbotp_kb_sync');
    wp_unschedule_hook('devdbotp_kb_push_event');
    devdbotp_remove_old_hot_files(); // a 1.0.0 early-block file must never enforce while the plugin is off
    wp_cache_flush_group('devdbotp'); // this plugin's own entries only (WP 6.1+; the plugin requires 6.2)
}

