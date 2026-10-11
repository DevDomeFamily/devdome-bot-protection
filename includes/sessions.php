<?php
/**
 * Behavioral session signals (BOT PROTECTION.md §12.3). A tiny front-end beacon
 * reports whether the client really ran JS, kept a first-party cookie, and
 * interacted (scroll / mouse / touch / form). Stored once per session; a client
 * that ran JS + kept a cookie + interacted is classified 'human'.
 *
 * v1 finishes the data layer (collect -> store -> surface in Activity). Feeding
 * the session score into live BLOCK decisions is a later phase, so there is NO per-request
 * DB lookup here — the engine's request path stays untouched.
 *
 * Privacy: the shared-core beacon sets a first-party session cookie (ddc_sid); this plugin
 * stores a hashed UA + (optionally anonymized) IP. Nothing is sent off-site. See readme.txt.
 */

defined('ABSPATH') || exit;

/** Respect this plugin's behavior-beacon opt-out at the shared-core beacon level. The beacon
 *  JS + REST endpoint + flood guard now live in devdome-core; this plugin only consumes the
 *  `devdcorev1_beacon` signal (its behavioral storage + blocking stay here). */
add_filter('devdcorev1_beacon_enabled', function ($on) {
    return $on && (int) devdbotp_get_setting('behavior_beacon', 1) === 1;
});

/**
 * Consume one shared-core beacon (fired by devdome-core's `devdcorev1_beacon` action with a
 * pre-parsed, classified $data array): upsert the session row + flag sustained automated
 * browsers. Single cheap PK write. Validation + flood guard already ran in core.
 */
function devdbotp_on_core_beacon($data)
{
    if (!is_array($data) || empty($data['sid'])) {
        return;
    }
    $sid = preg_replace('/[^a-f0-9]/', '', (string) $data['sid']);
    if (strlen($sid) < 16 || strlen($sid) > 64) {
        return;
    }

    $ip_real = isset($data['ip']) ? (string) $data['ip'] : ''; // what enforcement flags
    $ip      = $ip_real;                                         // what gets stored
    $anon = devdbotp_get_setting('anonymize_ips', 0);
    if ($ip !== '' && ($anon || !empty($GLOBALS['devdbotp_settings_read_failed'])) && function_exists('devdbotp_anonymize_ip')) {
        $ip = devdbotp_anonymize_ip($ip); // a setting that could not be read counts as "anonymize": the safe side
    }

    $cookie = !empty($data['cookie']) ? 1 : 0;
    $scroll = !empty($data['scroll']) ? 1 : 0;
    $mouse  = !empty($data['mouse']) ? 1 : 0;
    $touch  = !empty($data['touch']) ? 1 : 0;
    $form   = !empty($data['form']) ? 1 : 0;
    $wd     = !empty($data['webdriver']) ? 1 : 0; // navigator.webdriver — headless/automation
    $class  = isset($data['class']) ? (string) $data['class'] : 'unconfirmed';
    if (!in_array($class, array('automated', 'human', 'unconfirmed'), true)) {
        $class = 'unconfirmed';
    }

    $ua_hash = !empty($data['ua']) ? hash('sha256', (string) $data['ua']) : null;

    global $wpdb;
    $t   = $wpdb->prefix . 'devdbotp_sessions';
    $now = current_time('mysql', true);

    // New session, or merge the new signals into an existing row (one indexed PK write).
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is an internal table name, not user input; values are prepared.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $t
            (session_id, first_seen, last_seen, ip_text, user_agent_hash, pageviews,
             js_seen, cookie_seen, scroll_seen, mouse_seen, touch_seen, form_seen, classification)
         VALUES (%s, %s, %s, %s, %s, 1, 1, %d, %d, %d, %d, %d, %s)
         ON DUPLICATE KEY UPDATE
            last_seen      = VALUES(last_seen),
            pageviews      = pageviews + 1,
            js_seen        = 1,
            cookie_seen    = GREATEST(cookie_seen, VALUES(cookie_seen)),
            scroll_seen    = GREATEST(scroll_seen, VALUES(scroll_seen)),
            mouse_seen     = GREATEST(mouse_seen, VALUES(mouse_seen)),
            touch_seen     = GREATEST(touch_seen, VALUES(touch_seen)),
            form_seen      = GREATEST(form_seen, VALUES(form_seen)),
            classification = CASE
                WHEN VALUES(classification) = 'automated' OR classification = 'automated' THEN 'automated'
                WHEN VALUES(classification) = 'human' OR classification = 'human' THEN 'human'
                ELSE VALUES(classification) END",
        $sid,
        $now,
        $now,
        $ip,
        $ua_hash,
        $cookie,
        $scroll,
        $mouse,
        $touch,
        $form,
        $class
    ));

    // Sustained automated browser (navigator.webdriver across >=2 pageviews from a PUBLIC
    // IP) -> flag the IP so the engine can act on it. Multi-pageview, never a single-request
    // block; the flag helper enforces the public-IP guard.
    if ($wd && $ip !== '') {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table; sid prepared.
        $pv = (int) $wpdb->get_var($wpdb->prepare("SELECT pageviews FROM $t WHERE session_id = %s", $sid));
        if ($pv >= 2) {
            devdbotp_flag_behavior_ip($ip_real); // the real address, or a masked one would never match a request
        }
    }
}
add_action('devdcorev1_beacon', 'devdbotp_on_core_beacon');

/** Add a PUBLIC IP to the cached behavioral-offender set (capped, deduped). */
function devdbotp_flag_behavior_ip($ip)
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return; // PUBLIC IPs only — never flag a shared / NAT / localhost IP
    }
    $map = devdbotp_get_setting('bp_behavior_ips', array());
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return; // the map could not be read: writing a fresh one would drop every existing flag
    }
    if (!is_array($map)) {
        $map = array();
    }
    $cut = time() - 7 * DAY_IN_SECONDS; // expired flags go first, so the cap never evicts an active one for a dead one
    foreach ($map as $k => $t) {
        if ((int) $t < $cut) {
            unset($map[$k]);
        }
    }
    if (isset($map[$ip])) {
        return; // a live flag; an expired one was dropped above and is set afresh below
    }
    if (count($map) >= 2000) {
        asort($map);
        $map = array_slice($map, count($map) - 1999, null, true); // the 1999 newest stay
    }
    $map[$ip] = time();
    devdbotp_update_setting('bp_behavior_ips', $map);
}

/** Is this IP a flagged automated/behavioral offender? (Reads the cached set — no query; 7-day flag.) */
function devdbotp_ip_behavior_flagged($ip)
{
    if ($ip === '') {
        return false;
    }
    $map = devdbotp_get_setting('bp_behavior_ips', array());
    if (!is_array($map) || !isset($map[$ip])) {
        return false;
    }
    return (time() - (int) $map[$ip]) < 7 * DAY_IN_SECONDS;
}

/** Count of automated-classified sessions in the last $days (dashboard). */
function devdbotp_automated_session_count($days = 7)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_sessions';
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table; $since prepared.
    devdbotp_db_reset_error();
    $n = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE classification = 'automated' AND last_seen >= %s",
        $since
    ));
    return ($n === null || devdbotp_db_failed()) ? null : (int) $n; // null = could not be read (the screen shows a dash, never 0)
}

/** Count human-verified sessions (ran JS + kept a cookie + interacted) in the last $days. */
function devdbotp_verified_session_count($days = 7)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_sessions';
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is an internal table name; $since is prepared.
    devdbotp_db_reset_error();
    $n = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE classification = 'human' AND last_seen >= %s",
        $since
    ));
    return ($n === null || devdbotp_db_failed()) ? null : (int) $n; // null = could not be read (the screen shows a dash, never 0)
}

/** Prune session rows past the retention window (hooked alongside the feed/event prune). */
function devdbotp_prune_sessions()
{
    global $wpdb;
    $retention = devdbotp_retention_days();
    if ($retention < 1) {
        $retention = 90;
    }
    $t = $wpdb->prefix . 'devdbotp_sessions';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name; cutoff prepared.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $t WHERE last_seen < %s",
        gmdate('Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS)
    ));
}
add_action('devdbotp_refresh_feeds', 'devdbotp_prune_sessions');
