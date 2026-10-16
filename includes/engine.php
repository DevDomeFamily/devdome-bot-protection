<?php
/**
 * Runtime: evaluate each front-end request, record bot/blocked events, bump
 * counters, and enforce ONLY in Live mode. Watching (the default) logs what
 * WOULD be blocked without touching real visitors. Guards (verified-bot,
 * pinned-admin-IP, Safe Mode, level=off) make it SEO-safe and lockout-proof.
 *
 * Runs after WordPress loads (init, priority 1). The Cloudflare rules on the Settings tab are generated separately.
 */

defined('ABSPATH') || exit;

add_action('init', 'devdbotp_run', 1);

function devdbotp_run()
{
    if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
        return;
    }

    // Pin the admin's IP the first time a logged-in admin is seen (anti-lockout, §7).
    // ONLY pin a PUBLIC IP — a loopback/private/NAT/proxy IP is shared by many
    // visitors, so pinning it would whitelist everyone (and breaks on localhost).
    if (is_user_logged_in() && current_user_can('manage_options')) {
        $cur = devdbotp_client_ip();
        if ($cur !== ''
            && filter_var($cur, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && devdbotp_get_setting('admin_ip_pinned', '') !== $cur) {
            devdbotp_update_setting('admin_ip_pinned', $cur);
        }
        return; // never police a logged-in admin
    }

    // Admin screens: trusted, skip (front-end + wp-login are where bots hit).
    if (is_admin()) {
        return;
    }

    $level = devdbotp_get_setting('protection_level', 'recommended');
    if ($level === 'off') {
        return;
    }
    if ((int) devdbotp_get_setting('safe_mode_until', 0) > time()) {
        return; // Safe Mode: protection paused
    }

    $ip = devdbotp_client_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $path = isset($_SERVER['REQUEST_URI']) ? (string) strtok(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), '?') : '/'; // the path only: a reset token or key in the query string is never stored
    $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
    $cf  = devdbotp_cf_signals();
    $asn = $cf['asn'];

    $result = devdbotp_evaluate(array(
        'ua' => $ua, 'ip' => $ip, 'path' => $path, 'method' => $method, 'asn' => $asn,
        'country' => isset($cf['country']) ? $cf['country'] : '',
        'custom_rules' => function_exists('devdbotp_custom_rules') ? devdbotp_custom_rules() : array(),
        'ip_repeat_offender' => function_exists('devdbotp_ip_is_repeat_offender') ? devdbotp_ip_is_repeat_offender($ip) : false,
        'repeat_offender_action' => devdbotp_get_setting('repeat_offender_action', 'block'),
        'ip_behavior' => function_exists('devdbotp_ip_behavior_flagged') ? devdbotp_ip_behavior_flagged($ip) : false,
        'behavior_action' => devdbotp_get_setting('behavior_action', 'log'),
        'ip_fleet' => function_exists('devdbotp_ip_in_fleet') ? devdbotp_ip_in_fleet($ip) : false,
        'asn_fleet' => function_exists('devdbotp_asn_in_fleet') ? devdbotp_asn_in_fleet($asn) : false,
        'fleet_action' => devdbotp_get_setting('fleet_action', 'log'),
        'honeypot_asn' => function_exists('devdbotp_asn_honeypot_flagged') ? devdbotp_asn_honeypot_flagged($asn) : false,
        'honeypot_action' => devdbotp_get_setting('honeypot_action', 'log'),
    ));

    // Rate limiting (stateful — kept out of the pure scorer). Never throttles verified
    // bots or public pages; catches floods on the login/XML-RPC + REST endpoints.
    $rl_action = devdbotp_get_setting('rate_limit_action', 'log');
    if ($rl_action !== 'off' && $result['category'] !== 'verified_bot' && empty($result['exempt'])) {
        $cls = devdbotp_endpoint_type($path);
        if ($cls === 'wp' || $cls === 'sensitive') {
            list($limit, $window) = devdbotp_rate_config($cls);
            if (devdbotp_rate_hit($ip, $cls, $limit, $window)) {
                $result['labels'][] = 'rate_limit';
                if ($result['category'] === 'human') {
                    $result['category'] = 'rate_limit';
                    $result['is_bot'] = true;
                }
                if ($rl_action === 'block' && $result['action'] !== 'block') {
                    $result['action'] = 'block';
                } elseif ($rl_action === 'log' && $result['action'] === 'allow') {
                    $result['action'] = 'log';
                }
                $result['reason'] = trim($result['reason'] . '; rate limit exceeded (' . $cls . ')', '; ');
            }
        }
    }

    // Request velocity (stateful behavioral signal — FLAG-FIRST). Counts front-end PAGE hits
    // per IP (public pages only, so AJAX/REST never inflate it); an unusually fast burst is a
    // soft bot/scraper signal. Default action is Log, so it never blocks a real visitor unless
    // you raise it. Verified crawlers are exempt.
    $vel_action = devdbotp_get_setting('velocity_action', 'log');
    if ($vel_action !== 'off' && $result['category'] !== 'verified_bot' && empty($result['exempt']) && devdbotp_endpoint_type($path) === 'public') {
        list($vlimit, $vwindow) = devdbotp_velocity_config();
        if (devdbotp_rate_hit($ip, 'velocity', $vlimit, $vwindow)) {
            $result['labels'][] = 'velocity';
            if ($result['category'] === 'human') {
                $result['category'] = 'velocity';
                $result['is_bot'] = true;
            }
            if ($vel_action === 'block' && $result['action'] !== 'block') {
                $result['action'] = 'block';
            } elseif ($vel_action === 'log' && $result['action'] === 'allow') {
                $result['action'] = 'log';
            }
            $result['reason'] = trim($result['reason'] . '; unusual request velocity', '; ');
        }
    }

    // Detection/logging always run. Enforcement (403) is suppressed for the pinned
    // admin IP so an admin can never lock themselves out — but it's still logged.
    $pinned = devdbotp_get_setting('admin_ip_pinned', '');
    $is_admin_ip = ($pinned !== '' && $pinned === $ip);
    $mode = devdbotp_get_setting('mode', 'watching');
    $enforce = ($mode === 'live' && $result['action'] === 'block' && !$is_admin_ip);

    // ---- Counters (atomic UPDATE +1; cheap single write) + daily buckets (sparkline) ----
    $day  = devdbotp_days_back(1)[0];
    $hour = devdbotp_hours_back(1)[0]; // the hour buckets feed the 24-hour chart; the same write carries them
    if ($result['is_bot']) {
        devdbotp_bump(array('stat_bots_seen', 'stat_day_' . $day . '_bots', 'stat_hour_' . $hour . '_bots'));
        if ($result['action'] === 'block') {
            devdbotp_bump_blocked_today();
            devdbotp_bump(array('stat_day_' . $day . '_blocked', 'stat_hour_' . $hour . '_blocked'));
        }
        // Full-log only non-human events (keeps the table small).
        devdbotp_log_event($ip, $ua, $path, $method, $result, $enforce, $cf);
        // IP reputation memory (bots only): count the DECISION so the repeat-offender
        // list builds during Watch soak too, ready the moment you flip to Live.
        if (function_exists('devdbotp_record_reputation')) {
            devdbotp_record_reputation($ip, $result, $cf, ($result['action'] === 'block'));
        }
    } elseif (!devdbotp_is_beacon_request($path)) { // the library's own beacon is not a page view
        devdbotp_bump(array('stat_humans_seen', 'stat_day_' . $day . '_humans', 'stat_hour_' . $hour . '_humans')); // one write per human page view
    }

    // ---- Enforce (Live mode only) ----
    if ($enforce) {
        status_header(403);
        nocache_headers();
        header('X-DevDome-Bot-Protection: blocked');
        wp_die('Access denied.', 'Forbidden', array('response' => 403));
    }
}

/** The shared library's beacon endpoint, by path or by rest_route (encoded or not). */
function devdbotp_is_beacon_request($path)
{
    if (strpos((string) $path, '/devdome-core/v1/') !== false) {
        return true;
    }
    $route = isset($_GET['rest_route']) && is_string($_GET['rest_route']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['rest_route']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route name
    return strpos($route, '/devdome-core/') === 0;
}

/** Per-endpoint-class rate limit: [max requests, window seconds]. Generous, to avoid false positives. */
function devdbotp_rate_config($cls)
{
    if ($cls === 'wp') {
        return array(20, 60); // login / XML-RPC — 20 per minute per IP
    }
    return array(60, 60);     // sensitive (REST) — 60 per minute per IP
}

/** Request-velocity flag threshold: [max public page hits, window seconds]. Generous — a real
 *  visitor never loads this many pages this fast, so flag-first (Log) is false-positive-safe. */
function devdbotp_velocity_config()
{
    return array(30, 10); // 30 public page hits / 10s per IP
}

/**
 * Count this IP's hits to $cls in the current window; return true once over $limit.
 * Atomic Redis INCR when an object cache is present (the fleet); a transient fallback
 * otherwise (auto-expiring, not autoloaded — no table, no settings-cache pollution).
 */
function devdbotp_rate_hit($ip, $cls, $limit, $window)
{
    if ($ip === '' || $limit <= 0) {
        return false;
    }
    $bucket = (int) floor(time() / $window);

    if (wp_using_ext_object_cache()) {
        $key = 'devdbotp_rl_' . $cls . '_' . substr(md5($ip), 0, 12) . '_' . $bucket;
        if (wp_cache_get($key, 'devdbotp') === false) {
            wp_cache_add($key, 0, 'devdbotp', $window + 5);
        }
        $n = wp_cache_incr($key, 1, 'devdbotp');
        return is_int($n) && $n > $limit;
    }

    // One transient per address and class that resets every window, not one per window (audit 2026-10-16, the same
    // change as the shared library's beacon guard): the old key carried the window number, so every address minted
    // two fresh wp_options rows per window that sat expired until WordPress's daily sweep. A returning address
    // reuses its row, and about one request in fifty removes the expired ones.
    $key = 'devdbotp_rl_' . $cls . '_' . substr(md5($ip), 0, 12);
    $b = get_transient($key);
    if (!is_array($b) || !isset($b['b'], $b['n']) || (int) $b['b'] !== $bucket) {
        $b = array('b' => $bucket, 'n' => 0);
    }
    $b['n'] = (int) $b['n'] + 1;
    set_transient($key, $b, $window + 5);
    if (1 === wp_rand(1, 50)) {
        devdbotp_rate_sweep();
    }
    return $b['n'] > $limit;
}

/** Delete this guard's expired transient pairs (the value row and its timeout row), nothing else. */
function devdbotp_rate_sweep()
{
    global $wpdb;
    $like = $wpdb->esc_like('_transient_timeout_devdbotp_rl_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scoped sweep of this plugin's own expired transients, the way core's delete_expired_transients() works.
    $wpdb->query($wpdb->prepare(
        "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
         WHERE a.option_name LIKE %s
           AND b.option_name = CONCAT('_transient_', SUBSTRING(a.option_name, 20))
           AND a.option_value < %d",
        $like,
        time()
    ));
}

/** Atomic counter increment on the settings table (row-level atomic in InnoDB). One name or a list: a list is ONE write. */
function devdbotp_bump($name, $by = 1)
{
    global $wpdb;
    $table = devdbotp_settings_table();
    $names = (array) $name;
    $args  = array();
    foreach ($names as $n) {
        $args[] = $n;
        $args[] = $by;
    }
    $args[] = $by;
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (setting_name, setting_value) VALUES " . implode(', ', array_fill(0, count($names), '(%s, %d)')) . "
         ON DUPLICATE KEY UPDATE setting_value = setting_value + %d",
        $args
    ));
    unset($GLOBALS['devdbotp_cache']);
}

/** "Blocked today" with a daily rollover. */
function devdbotp_bump_blocked_today()
{
    $today = devdbotp_days_back(1)[0];
    if (devdbotp_get_setting('stat_blocked_day', '') !== $today) {
        devdbotp_update_setting('stat_blocked_day', $today);
        devdbotp_update_setting('stat_blocked_today', 0);
    }
    devdbotp_bump('stat_blocked_today');
}

/** Daily maintenance (hooked to the feed-refresh cron): prune old day-buckets + events. */
function devdbotp_prune()
{
    global $wpdb;
    // Drop daily-bucket setting rows older than 3 years (the dashboard's longest range).
    $cutoff = gmdate('Y-m-d', time() - 1095 * DAY_IN_SECONDS);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; static LIKE prefix, no user input.
    $rows = $wpdb->get_col("SELECT setting_name FROM " . devdbotp_settings_table() . " WHERE setting_name LIKE 'stat_day_%' OR setting_name LIKE 'stat_hour_%'");
    $hour_cutoff = gmdate('Y-m-d-H', time() - 2 * DAY_IN_SECONDS); // hour buckets only serve the 24-hour view
    foreach ((array) $rows as $name) {
        if (preg_match('/^stat_day_(\d{4}-\d{2}-\d{2})_/', $name, $m) && $m[1] < $cutoff) {
            $wpdb->delete(devdbotp_settings_table(), array('setting_name' => $name));
        } elseif (preg_match('/^stat_hour_(\d{4}-\d{2}-\d{2}-\d{2})_/', $name, $m) && $m[1] < $hour_cutoff) {
            $wpdb->delete(devdbotp_settings_table(), array('setting_name' => $name));
        }
    }
    unset($GLOBALS['devdbotp_cache']);
    // Trim event rows older than the fixed 90-day retention window.
    $retention = devdbotp_retention_days();
    if ($retention < 1) {
        $retention = 90;
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM " . $wpdb->prefix . "devdbotp_events WHERE created_at < %s",
        gmdate('Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS)
    ));
}
add_action('devdbotp_refresh_feeds', 'devdbotp_prune');

/** Insert one row into the events table for a bot/blocked request. */
function devdbotp_log_event($ip, $ua, $path, $method, $result, $enforced, $cf = array())
{
    global $wpdb;
    $anon = devdbotp_get_setting('anonymize_ips', 0);
    if ($ip !== '' && ($anon || !empty($GLOBALS['devdbotp_settings_read_failed']))) {
        $ip = devdbotp_anonymize_ip($ip); // a preference that could not be read counts as "anonymize": the safe side
    }
    $packed = $ip !== '' ? @inet_pton($ip) : false;
    if ($packed === false) {
        $packed = inet_pton('0.0.0.0');
    }
    $row = array(
        'created_at'      => current_time('mysql', true),
        'ip'              => $packed,
        'ip_text'         => $ip,
        'method'          => substr($method, 0, 10),
        'path'            => substr($path, 0, 2000),
        'user_agent'      => substr($ua, 0, 1000),
        'detector_labels' => wp_json_encode($result['labels']),
        'bot_category'    => substr($result['category'], 0, 64),
        'risk_score'      => (int) $result['score'],
        'action'          => $enforced ? 'block' : ($result['action'] === 'block' ? 'would_block' : $result['action']),
        'reason'          => substr($result['reason'], 0, 500),
    );
    // Cloudflare enrichment (Phase 2) — populate only what CF actually sent.
    if (!empty($cf['country'])) { $row['country'] = substr($cf['country'], 0, 2); }
    if (!empty($cf['asn'])) { $row['asn'] = (int) $cf['asn']; }
    if (!empty($cf['ray'])) { $row['cf_ray'] = substr($cf['ray'], 0, 64); }
    if (isset($cf['bot_score']) && $cf['bot_score'] !== null) { $row['cf_bot_score'] = (int) $cf['bot_score']; }

    $wpdb->insert($wpdb->prefix . 'devdbotp_events', $row);
}
