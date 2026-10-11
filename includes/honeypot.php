<?php
/**
 * Honeypot + ASN discovery — catches rotating-proxy crawlers that defeat every
 * per-request signal (fresh IP each hit, varied UA, no dwell, no usable referrer).
 *
 * The one thing such a bot can't avoid: it follows EVERY link, a human only clicks
 * what they can see. So we print a hidden trap link (invisible to humans, Disallowed
 * in robots.txt so verified crawlers skip it). Anything that requests it is a crawler.
 * Its IPs rotate, but its ASN is stable — so we flag the ASN, not the IP, ONLY once
 * >= K distinct IPs from that ASN have hit the trap (a stray real visitor can never
 * reach K, so its ISP is never flagged). See _bot-protection-docs/HONEYPOT-ASN-DISCOVERY.md.
 *
 * Perf: trap link is static (cache-safe). Recording happens only on a trap hit (rare).
 * The engine reads a cached `bp_honeypot_asns` map (no per-request query), rebuilt <=5 min.
 */

defined('ABSPATH') || exit;

/** Major consumer/eyeball ISP ASNs — NEVER ASN-blocked even if K IPs hit the trap (a
 *  residential-proxy riding a real ISP would otherwise nuke real users; flag those by IP only).
 *  Starter list of the big eyeball networks; extend over time / via the fleet feed. */
function devdbotp_honeypot_isp_safelist()
{
    // Comcast, AT&T, Verizon, Charter/Spectrum, T-Mobile, Cox, CenturyLink/Lumen, Frontier,
    // Telus, Bell, Rogers, Virgin/Liberty, BT, Sky, Deutsche Telekom, Vodafone, Orange,
    // Free/Iliad, Jio, Airtel, Telefonica, Comcast-bus, Charter-bus, Cogent-eyeball.
    $base = array(
        7922, 7018, 701, 702, 20115, 21928, 22773, 209, 5650,
        852, 577, 812, 5089, 5607, 3320, 3209, 3215, 12322, 55836, 24560,
        12876, 6739, 3352, 6147, 4837, 9829, 9498, 3269, 12389, 6805, 1267,
    );
    // Owner-added safelist (the "Never flag this network" admin action): a false-positive eyeball
    // net the owner cleared must stay cleared across rebuilds, so it lives in the safelist gate too.
    $extra = devdbotp_get_setting('honeypot_isp_extra', array());
    if (is_array($extra) && !empty($extra)) {
        foreach ($extra as $a) {
            $a = (int) $a;
            if ($a > 0) {
                $base[] = $a;
            }
        }
    }
    return $base;
}

/** Owner action: permanently safelist an ASN ("Never flag this network") so a confirmed
 *  false-positive eyeball net is never re-flagged on a future rebuild. Persists honeypot_isp_extra. */
function devdbotp_honeypot_isp_safelist_add($asn)
{
    $asn = (int) $asn;
    if ($asn <= 0) {
        return false;
    }
    $extra = devdbotp_get_setting('honeypot_isp_extra', array());
    if (!is_array($extra)) {
        $extra = array();
    }
    if (!in_array($asn, array_map('intval', $extra), true)) {
        if (!devdbotp_honeypot_unflag($asn)) { // "never flag" = not flagged NOW either; done FIRST so a failed safelist write leaves nothing half done
            return false;
        }
        $extra[] = $asn;
        if (!devdbotp_update_setting('honeypot_isp_extra', array_values($extra))) {
            return false;
        }
        unset($GLOBALS['devdbotp_cache']);
        $stored = devdbotp_get_setting('honeypot_isp_extra', array());
        if (!is_array($stored) || !in_array($asn, array_map('intval', $stored), true)) {
            return false;
        }
    }
    return true;
}

/** The per-site trap slug (random, stable). Generated on first use; bots can't fingerprint it. */
function devdbotp_honeypot_slug()
{
    $s = devdbotp_get_setting('honeypot_slug', '');
    if (!is_string($s) || strlen($s) < 6) {
        $s = 'bpt-' . substr(md5(wp_generate_password(20, false, false) . home_url()), 0, 10);
        devdbotp_update_setting('honeypot_slug', $s);
    }
    return $s;
}

function devdbotp_honeypot_enabled()
{
    return (int) devdbotp_get_setting('honeypot_enabled', 1) === 1;
}

/* ---- 1. The trap (bait) ------------------------------------------------------ */

/** Print the hidden trap link in the footer of public pages (cache-safe — identical for all). */
add_action('wp_footer', 'devdbotp_honeypot_print_trap', 99);
function devdbotp_honeypot_print_trap()
{
    if (!devdbotp_honeypot_enabled() || is_admin() || is_feed() || is_user_logged_in()) {
        return;
    }
    $url = home_url('/' . devdbotp_honeypot_slug() . '/' . substr(md5(home_url()), 0, 8) . '/');
    // Invisible + unfocusable + nofollow. A human cannot see it or tab to it; a crawler reads the DOM.
    printf(
        '<a href="%s" rel="nofollow noindex" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">%s</a>' . "\n",
        esc_url($url),
        esc_html__('Members area', 'devdome-bot-protection')
    );
}

/** The real, host-root-relative trap path used in BOTH the trap link and the robots.txt
 *  Disallow line. On a subdirectory install (site at /blog) the trap is /blog/{slug}/ — the
 *  Disallow must carry that same /blog prefix or polite crawlers are told to skip the wrong path. */
function devdbotp_honeypot_path_prefix()
{
    $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
    return '/' . trim($home_path, '/');
}

/** Disallow the trap in robots.txt so verified/polite crawlers (Googlebot…) never follow it. */
add_filter('robots_txt', 'devdbotp_honeypot_robots', 8, 2);
function devdbotp_honeypot_robots($output, $public)
{
    if (devdbotp_honeypot_enabled()) {
        // Match the real trap path (home_url-based) so subdirectory installs Disallow the right path.
        $prefix = rtrim(devdbotp_honeypot_path_prefix(), '/');
        $output .= "\n# DevDome Bot Protection\nUser-agent: *\nDisallow: " . $prefix . '/' . devdbotp_honeypot_slug() . "/\n";
    }
    return $output;
}

/* ---- 2. The catcher (detection) --------------------------------------------- */

/** Intercept a hit to the trap path BEFORE the engine (init prio 0). Record + 404. */
add_action('init', 'devdbotp_honeypot_catch', 0);
function devdbotp_honeypot_catch()
{
    if (!devdbotp_honeypot_enabled() || is_admin() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    $uri  = isset($_SERVER['REQUEST_URI']) ? (string) filter_var(wp_unslash($_SERVER['REQUEST_URI']), FILTER_SANITIZE_URL) : ''; // keeps %XX, drops control bytes
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    $slug = devdbotp_honeypot_slug();
    if (strpos($path, '/' . $slug . '/') === false && rtrim($path, '/') !== '/' . $slug) {
        return; // not the trap
    }

    $ip = devdbotp_client_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

    // Verified-bot allowlist FIRST — a real Googlebot/Bingbot that somehow followed it is never flagged.
    $feeds = function_exists('devdbotp_feeds') ? devdbotp_feeds() : array();
    if (function_exists('devdbotp_ua_is_verified_bot') && devdbotp_ua_is_verified_bot($ua, $ip, $feeds)) {
        status_header(404);
        nocache_headers();
        exit;
    }

    // Public IPs only — never let a shared/NAT/private IP become a flagged "offender".
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $cf = function_exists('devdbotp_cf_signals') ? devdbotp_cf_signals() : array('asn' => 0, 'country' => '');
        // A trap hit is always worth flagging — resolve the ASN directly if no header gave one (rare,
        // so the lookup cost is negligible; this is how the FIRST discovery seeds the flagged list).
        if (empty($cf['asn']) && function_exists('devdbotp_resolve_asn')) {
            $cf['asn'] = devdbotp_resolve_asn($ip);
        }
        devdbotp_honeypot_record($ip, $ua, $path, $cf);
    }

    status_header(404);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    exit; // never reveal it was a trap
}

/** Log one trap hit as a `honeypot` event + reputation, then maybe rebuild the flagged-ASN map. */
function devdbotp_honeypot_record($ip, $ua, $path, $cf)
{
    $result = array(
        'labels'   => array('honeypot'),
        'category' => 'honeypot',
        'score'    => 100,
        'action'   => 'block',
        'reason'   => 'hit hidden honeypot trap link',
        'is_bot'   => true,
    );
    if (function_exists('devdbotp_log_event')) {
        devdbotp_log_event($ip, $ua, $path, 'GET', $result, false, $cf);
    }
    if (function_exists('devdbotp_record_reputation')) {
        devdbotp_record_reputation($ip, $result, $cf, false);
    }
    devdbotp_maybe_rebuild_honeypot_asns(true);
}

/* ---- 3. ASN flagging (the safety gate) + cached map ------------------------- */

/** Rebuild at most every 5 min; force=true right after a fresh trap hit. */
function devdbotp_maybe_rebuild_honeypot_asns($force = false)
{
    $last = (int) devdbotp_get_setting('honeypot_asns_built', 0);
    if ((time() - $last) < ($force ? 60 : 300)) {
        return; // a trap hit forces at most one rebuild a minute: the GROUP BY over events is not a thing a visitor may run per request
    }
    devdbotp_rebuild_honeypot_asns();
}

/**
 * Flag an ASN only when >= K distinct IPs from it have hit the trap (the proxy signature
 * AND the safety gate — a stray real user is 1 IP, never reaches K). Excludes verified-bot
 * traffic and the consumer-ISP safelist. Reads the events table's honeypot rows.
 */
function devdbotp_rebuild_honeypot_asns()
{
    global $wpdb;
    $k = max(2, (int) devdbotp_get_setting('honeypot_asn_min_ips', 3));
    $t = $wpdb->prefix . 'devdbotp_events';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; values prepared.
    $rows = array();
    for ($offset = 0; $offset < 20000; $offset += 1000) { // pages, so a safelisted or cooled-down first page never hides the rest
        devdbotp_db_reset_error();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; values prepared.
        $page = $wpdb->get_results($wpdb->prepare(
            "SELECT asn, COUNT(DISTINCT ip_text) AS ips, COUNT(*) AS hits
               FROM $t
              WHERE bot_category = 'honeypot' AND asn IS NOT NULL AND asn > 0 AND ip_text <> ''
              GROUP BY asn HAVING ips >= %d ORDER BY ips DESC, asn ASC LIMIT 1000 OFFSET %d",
            $k,
            $offset
        ));
        if (!is_array($page) || devdbotp_db_failed()) {
            return false; // the read failed: the previous map and its stamp stay
        }
        $rows = array_merge($rows, $page);
        if (count($page) < 1000) {
            break;
        }
    }
    $failed = false; // each read is judged right after it
    $safe = array_flip(devdbotp_honeypot_isp_safelist());
    $failed = $failed || !empty($GLOBALS['devdbotp_settings_read_failed']);
    $cooldown = devdbotp_honeypot_unflag_cooldowns(); // [asn => expiry_ts]
    $failed = $failed || !empty($GLOBALS['devdbotp_settings_read_failed']);
    $seeded_now = devdbotp_honeypot_flagged_asns();
    $failed = $failed || !empty($GLOBALS['devdbotp_settings_read_failed']);
    if ($failed) {
        return false; // the safelist / cooldowns / seeded map could not be read: a rebuild on defaults would drop them
    }
    $map = array();
    // Preserve manually-seeded ASNs (analytics miner / fleet bootstrap). The rebuild is driven by
    // trap EVENTS, which seeded ASNs aren't in — so without this they'd be wiped on the next rebuild.
    // Honor the unflag cooldown even for seeded entries (an owner who unflags a seeded ASN means it).
    foreach ($seeded_now as $sa => $sm) {
        $sa = (int) $sa;
        if (is_array($sm) && !empty($sm['seeded']) && !isset($safe[$sa]) && !isset($cooldown[$sa])) {
            $map[$sa] = $sm;
        }
    }
    foreach ((array) $rows as $r) {
        $asn = (int) $r->asn;
        // Skip safelisted ASNs AND any an owner just unflagged (re-add guard / cooldown window) so a
        // stubborn proxy net can't instantly re-flag right after a confirmed clear; genuinely-new abuse
        // is still caught once the window lapses.
        if ($asn > 0 && !isset($safe[$asn]) && !isset($cooldown[$asn])) {
            $map[$asn] = array('ips' => (int) $r->ips, 'hits' => (int) $r->hits);
        }
    }
    if (!devdbotp_update_setting('bp_honeypot_asns', $map)) {
        return false; // the map did not land: the stamp is not moved either, the next rebuild runs on time
    }
    devdbotp_update_setting('honeypot_asns_built', time());
    unset($GLOBALS['devdbotp_cache']);
    return devdbotp_get_setting('bp_honeypot_asns', null) == $map; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- ints vs numeric strings
}

/** Is this request's ASN one the honeypot has flagged? (Reads the cached map — no query.) */
function devdbotp_asn_honeypot_flagged($asn)
{
    $asn = (int) $asn;
    if ($asn <= 0) {
        return false;
    }
    $map = devdbotp_get_setting('bp_honeypot_asns', array());
    return is_array($map) && isset($map[$asn]);
}

/** Flagged-network list for the admin UI: [asn => {ips, hits}]. */
function devdbotp_honeypot_flagged_asns()
{
    $map = devdbotp_get_setting('bp_honeypot_asns', array());
    return is_array($map) ? $map : array();
}

/** Count of trap hits in the last N days (dashboard stat). */
function devdbotp_honeypot_hit_count($days = 7)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; cutoff prepared.
    devdbotp_db_reset_error();
    $n = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE bot_category = 'honeypot' AND created_at >= %s",
        gmdate('Y-m-d H:i:s', time() - max(1, (int) $days) * DAY_IN_SECONDS)
    ));
    return ($n === null || devdbotp_db_failed()) ? null : (int) $n; // null = could not be read (the screen shows a dash, never 0)
}

/** Display-only network names for the well-known mined/abusive ASNs (no lookup cost). The geo
 *  service resolves names from a live IP; for seeded ASNs (no IP) this gives the admin a legible
 *  label instead of a bare number. Extend as the fleet discovers more. ISO-2 country in parens. */
function devdbotp_honeypot_asn_label($asn)
{
    $asn = (int) $asn;
    $known = array(
        132203 => 'ACEVILLE PTE.LTD. (SG)',
        203020 => 'Keminet SHPK (AL)',
        399073 => 'Bunny Communications LLC (US)',
        20473  => 'The Constant Company, LLC (US)',
        14061  => 'DigitalOcean, LLC (US)',
        16509  => 'Amazon AWS (US)',
        14618  => 'Amazon AWS (US)',
        15169  => 'Google LLC (US)',
        45102  => 'Alibaba Cloud (CN)',
        9009   => 'M247 Europe (RO)',
        51167  => 'Contabo GmbH (DE)',
    );
    return isset($known[$asn]) ? $known[$asn] : '';
}

/** Owner/fleet seeding: directly flag an ASN discovered from existing analytics data. */
function devdbotp_honeypot_seed_asns($asns)
{
    if (!is_array($asns) || empty($asns)) {
        return 0;
    }
    $failed = false; // each read is judged right after it: a later successful read must not hide an earlier failure
    $safe = array_flip(devdbotp_honeypot_isp_safelist());
    $failed = $failed || !empty($GLOBALS['devdbotp_settings_read_failed']);
    $cooldown = devdbotp_honeypot_unflag_cooldowns();
    $failed = $failed || !empty($GLOBALS['devdbotp_settings_read_failed']);
    $map = devdbotp_honeypot_flagged_asns();
    $failed = $failed || !empty($GLOBALS['devdbotp_settings_read_failed']);
    if ($failed) {
        return 0; // the safelist, cooldowns or map could not be read: seeding onto defaults would flag an exempt network or drop the others
    }
    $n = 0;
    foreach ($asns as $asn) {
        $asn = (int) $asn;
        // Don't re-seed a safelisted ASN, an already-flagged one, or one the owner just unflagged.
        if ($asn > 0 && !isset($safe[$asn]) && !isset($map[$asn]) && !isset($cooldown[$asn])) {
            $map[$asn] = array('ips' => 0, 'hits' => 0, 'seeded' => 1);
            $n++;
        }
    }
    if ($n > 0) {
        if (!devdbotp_update_setting('bp_honeypot_asns', $map)) {
            return 0; // nothing seeded: the map did not land
        }
        unset($GLOBALS['devdbotp_cache']);
        if (devdbotp_get_setting('bp_honeypot_asns', null) != $map) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- ints vs numeric strings
            return 0;
        }
    }
    return $n;
}

/* ---- 4. Owner unflag (admin "Unflag" control) + re-add guard -------------------- */

/** How long (seconds) an owner-unflagged ASN is kept out of the flagged map even if fresh trap
 *  events arrive. Prevents a stubborn proxy net re-flagging the instant a false positive is cleared. */
function devdbotp_honeypot_unflag_cooldown_window()
{
    return 7 * DAY_IN_SECONDS;
}

/** Active unflag cooldowns: [asn => expiry_ts], expired entries pruned + persisted on read. */
function devdbotp_honeypot_unflag_cooldowns()
{
    $cd = devdbotp_get_setting('honeypot_unflagged', array());
    if (!is_array($cd) || empty($cd)) {
        return array();
    }
    $now = time();
    $live = array();
    $changed = false;
    foreach ($cd as $asn => $exp) {
        $asn = (int) $asn;
        $exp = (int) $exp;
        if ($asn > 0 && $exp > $now) {
            $live[$asn] = $exp;
        } else {
            $changed = true; // expired or invalid — drop it (genuinely-new abuse can flag it again)
        }
    }
    if ($changed) {
        devdbotp_update_setting('honeypot_unflagged', $live);
    }
    return $live;
}

/**
 * Owner action: unflag one ASN (event-discovered OR seeded). Removes it from the cached map,
 * records a cooldown so the next <=5-min rebuild does not instantly re-add a seeded/just-caught
 * entry, and stamps honeypot_asns_built so the map is treated as fresh. Returns true if removed.
 */
function devdbotp_honeypot_unflag($asn)
{
    $asn = (int) $asn;
    if ($asn <= 0) {
        return false;
    }
    // The cooldown FIRST (covers a fresh-events ASN that isn't in the cached map yet but would re-appear on the very
    // next rebuild; genuinely-new abuse re-flags after the window lapses), then the map: a map changed without a
    // cooldown would be re-flagged by the next rebuild, the other way round is harmless.
    $cd = devdbotp_get_setting('honeypot_unflagged', array());
    $failed = !empty($GLOBALS['devdbotp_settings_read_failed']);
    if (!is_array($cd)) {
        $cd = array();
    }
    $map = devdbotp_honeypot_flagged_asns();
    if ($failed || !empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false; // cooldowns / map could not be read: a write on defaults would drop the others
    }
    $cd[$asn] = time() + devdbotp_honeypot_unflag_cooldown_window();
    if (!devdbotp_update_setting('honeypot_unflagged', $cd)) {
        return false;
    }
    $was = isset($map[$asn]);
    if ($was) {
        unset($map[$asn]);
        if (!devdbotp_update_setting('bp_honeypot_asns', $map)) {
            return false;
        }
    }
    // Treat the map as freshly built so an immediate page load can't trigger a 5-min rebuild that
    // (still respecting the cooldown above) churns the entry — purely a perf/no-op-churn guard.
    devdbotp_update_setting('honeypot_asns_built', time());
    // Read back: unflagged means the map no longer carries it AND the cooldown is stored.
    unset($GLOBALS['devdbotp_cache']);
    $cd_now = devdbotp_get_setting('honeypot_unflagged', array());
    return !devdbotp_asn_honeypot_flagged($asn) && is_array($cd_now) && isset($cd_now[$asn]);
}
