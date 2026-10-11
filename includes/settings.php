<?php
/**
 * Settings storage — a single name/value table (mirrors the suite's Redirect
 * Manager convention) with a process-level read cache. Arrays are serialized.
 */

defined('ABSPATH') || exit;

function devdbotp_settings_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdbotp_settings';
}

/** Read a setting, falling back to $default when unset. Arrays are unserialized. */
function devdbotp_get_setting($name, $default = '')
{
    global $wpdb;

    if (!isset($GLOBALS['devdbotp_cache']) || !is_array($GLOBALS['devdbotp_cache'])) {
        $table = devdbotp_settings_table();
        // The day buckets (up to 3 years of them) stay out of the load every page view does; see below.
        if (function_exists('devdbotp_db_reset_error')) {
            devdbotp_db_reset_error();
        }
        $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM $table WHERE setting_name NOT LIKE 'stat\_day\_%' AND setting_name NOT LIKE 'stat\_hour\_%'", ARRAY_A);
        if (!is_array($rows) || (isset($wpdb->last_error) && '' !== (string) $wpdb->last_error)) {
            // The load FAILED: nothing is cached (the next read tries again) and the caller gets its default for this
            // one read. The guard records the failure, so any action in a window refuses to write after it.
            $GLOBALS['devdbotp_settings_read_failed'] = true;
            return $default;
        }
        $GLOBALS['devdbotp_settings_read_failed'] = false;
        $GLOBALS['devdbotp_cache'] = array();
        foreach ($rows as $r) {
            $GLOBALS['devdbotp_cache'][$r['setting_name']] = $r['setting_value'];
        }
    }

    // Day buckets load once, the first time a screen or the hourly push asks for one.
    if ((strpos($name, 'stat_day_') === 0 || strpos($name, 'stat_hour_') === 0) && !isset($GLOBALS['devdbotp_cache']["\0days"])) {
        $table = devdbotp_settings_table();
        if (function_exists('devdbotp_db_reset_error')) {
            devdbotp_db_reset_error();
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name, static LIKE prefixes, no user input
        $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM $table WHERE setting_name LIKE 'stat\_day\_%' OR setting_name LIKE 'stat\_hour\_%'", ARRAY_A);
        if (!is_array($rows) || (isset($wpdb->last_error) && '' !== (string) $wpdb->last_error)) {
            $GLOBALS['devdbotp_settings_read_failed'] = true; // the buckets could not be read: not cached, this read gets its default
            return $default;
        }
        $GLOBALS['devdbotp_cache']["\0days"] = 1;
        foreach ($rows as $r) {
            $GLOBALS['devdbotp_cache'][$r['setting_name']] = $r['setting_value'];
        }
    }

    if (!array_key_exists($name, $GLOBALS['devdbotp_cache'])) {
        return $default;
    }

    $value = $GLOBALS['devdbotp_cache'][$name];
    if (!is_string($value) || !is_serialized($value)) {
        return $value; // plain scalars ('watching', '0') never reach unserialize: it warns on them under a debug handler
    }
    $unser = @unserialize($value, array('allowed_classes' => false));
    if ($unser !== false || $value === 'b:0;') {
        return $unser;
    }
    return $value;
}

/**
 * The time zone the day totals are counted in: the Time Zone chosen on the Settings tab (sent to the DevDome
 * dashboard while connected), or WordPress's own zone while nothing was chosen.
 */
function devdbotp_tz()
{
    $name = (string) devdbotp_get_setting('timezone', '');
    if ($name !== '') {
        try {
            return new DateTimeZone($name);
        } catch (Exception $e) {
            return wp_timezone();
        }
    }
    return wp_timezone();
}

/** The last $n day keys (Y-m-d) in that zone, today first. Noon-based, so a clock change never skips or repeats a day. */
function devdbotp_days_back($n)
{
    $d = new DateTime('now', devdbotp_tz());
    $d->setTime(12, 0);
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        $out[] = $d->format('Y-m-d');
        $d->modify('-1 day');
    }
    return $out;
}

/**
 * How long bot and blocked events, sessions and offender history are kept on this site: 90 days for everyone
 * (wp.org review 2026-09-29: local data can never depend on a plan). Longer ranges live on the DevDome dashboard.
 */
function devdbotp_retention_days()
{
    return 90;
}

/** Safe Mode = the safe_mode_until setting, written and read back (no flag file since 1.0.1). */
function devdbotp_set_safe_mode($until)
{
    $until = (int) $until;
    if (!devdbotp_update_setting('safe_mode_until', $until)) {
        return false;
    }
    unset($GLOBALS['devdbotp_cache']);
    return (int) devdbotp_get_setting('safe_mode_until', -1) === $until;
}

/**
 * Outside Lookups (Settings tab, off by default): the one switch that allows every request to a server outside
 * this site that is not the connected DevDome account: the bot list downloads and the country / network lookups.
 */
function devdbotp_outside_lookups_on()
{
    return (int) devdbotp_get_setting('outside_lookups', 0) === 1;
}

/** Multisite: a blog switch drops the cache so the next read is the new blog's table. */
add_action('switch_blog', 'devdbotp_forget_cache');
function devdbotp_forget_cache()
{
    unset($GLOBALS['devdbotp_cache']);
}

/** The last $n hour keys (Y-m-d-H, site time zone), newest first. 'Today' on every tab = the last 24 hours, as the dashboard's 24-hour range (owner 2026-09-28). */
function devdbotp_hours_back($n)
{
    $d = new DateTime('now', devdbotp_tz());
    $d->setTime((int) $d->format('G'), 0);
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        $out[] = $d->format('Y-m-d-H');
        $d->modify('-1 hour');
    }
    return $out;
}

/** Write a setting (insert or update). Arrays are serialized. Invalidates the cache. */
function devdbotp_update_setting($name, $value)
{
    if (function_exists('devdbotp_db_guard_active') && devdbotp_db_guard_active()) {
        return false; // DESIGN.md 24: no write after a failed read inside an action window
    }
    global $wpdb;
    $table = devdbotp_settings_table();
    $stored = is_array($value) ? serialize($value) : $value;

    $r = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (setting_name, setting_value) VALUES (%s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $name,
        $stored
    ));

    unset($GLOBALS['devdbotp_cache']);
    return false !== $r; // a failed write is a failed write; callers that read back still do
}

/**
 * Clear Statistics (typed-word CLEAR, DESIGN.md 23; the server checks the word too). Wipes every event, session, learned
 * offender, the day and hour buckets and the lifetime counters; keeps every choice, the custom rules and the account link.
 * The dashboard's copy of the day totals is replaced on the next push (the push carries a clear flag once).
 */
function devdbotp_clear_statistics()
{
    global $wpdb;
    $p = $wpdb->prefix . 'devdbotp_';
    $t = devdbotp_settings_table();
    // One transaction around the deletes (InnoDB): a failure part way leaves everything as it was.
    if (false === $wpdb->query('START TRANSACTION')) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction boundary
        return false;
    }
    $fail = function () use ($wpdb) {
        $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction boundary
        return false;
    };
    foreach (array('events', 'sessions', 'ip_reputation') as $tbl) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
        if (false === $wpdb->query("DELETE FROM {$p}{$tbl}")) {
            return $fail(); // one table not cleared = not cleared; the screen says so
        }
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name, static LIKE prefixes
    if (false === $wpdb->query("DELETE FROM {$t} WHERE setting_name LIKE 'stat\\_day\\_%' OR setting_name LIKE 'stat\\_hour\\_%'")) {
        return $fail();
    }
    foreach (array('bp_bad_ips', 'bad_ips_built', 'bp_behavior_ips', 'bp_honeypot_asns', 'honeypot_asns_built', 'kb_days_sent') as $name) {
        if (false === $wpdb->delete($t, array('setting_name' => $name))) {
            return $fail();
        }
    }
    unset($GLOBALS['devdbotp_cache']);
    foreach (array('stat_blocked_today' => 0, 'stat_blocked_day' => '', 'stat_bots_seen' => 0, 'stat_humans_seen' => 0) as $name => $v) {
        if (!devdbotp_update_setting($name, $v)) {
            return $fail();
        }
    }

    delete_transient('devdbotp_kb_series2');
    // The per-IP rate, geolocation and DNS caches carry visitor-derived keys: sweep them (their timeout rows too).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off sweep of this plugin's own transients
    if (false === $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_devdbotp_%', '_transient_timeout_devdbotp_%'))) {
        return $fail(); // still inside the transaction: nothing of the clear stays
    }
    if (false === $wpdb->query('COMMIT')) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction boundary
        return $fail();
    }
    foreach (array('devdbotp_kb_series2') as $tr) {
        wp_cache_delete($tr, 'transient'); // only our own keys, never the whole group
    }
    devdbotp_update_setting('kb_clear_pending', 1); // the dashboard drops its stored days for this site; stays pending until it answers 200
    if (function_exists('devdbotp_kb_send_days')) {
        devdbotp_kb_send_days(true);
    }
    return !devdbotp_db_guard_failed();
}
