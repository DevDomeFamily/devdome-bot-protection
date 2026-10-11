<?php
/**
 * Geo / ASN enrichment (opt-in) — fills the Threats tab's "Top countries" and "Top
 * networks (ASN)" for sites that are NOT behind Cloudflare, where the CF-IPCountry /
 * CF-ASN request headers are absent (those are the only geo source today).
 *
 * Off the request path entirely. A daily, bounded, batched cron pass resolves the country
 * + ASN of recently-logged THREAT IPs (already adversarial, PUBLIC IPs only) via the
 * DevDome geo-resolve service (MaxMind GeoLite2) and back-fills the events rows. The per-request
 * lookup below (honeypot hits, flagged-network matching) can include addresses not yet classified
 * as bots. Both run ONLY while Outside Lookups (Settings tab, off by default) is on.
 */

defined('ABSPATH') || exit;

if (!defined('DEVDBOTP_GEO_ENDPOINT')) {
    define('DEVDBOTP_GEO_ENDPOINT', 'https://api.devdome.com/geo-resolve/classify');
}

/** Country / network enrichment runs only while Outside Lookups (Settings tab) is on. */
function devdbotp_geo_enrich_on()
{
    return devdbotp_outside_lookups_on();
}

/**
 * Daily (rides the feed-refresh cron): resolve country + ASN for recent threat IPs that
 * still have no geo, in ONE batched call, and back-fill the events table. Bounded (<=100
 * IPs/run) and per-IP cached so an unresolvable IP isn't re-queried for a week.
 */
function devdbotp_geo_enrich()
{
    global $wpdb;
    if (!devdbotp_geo_enrich_on()) {
        return;
    }
    $t = $wpdb->prefix . 'devdbotp_events';
    $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);

    // Pages of 500, oldest-first inside the week, until 100 eligible addresses are found or the candidates run out:
    // a burst of recent already-tried or private addresses can no longer hide older eligible ones.
    $ips = array();
    for ($offset = 0; count($ips) < 100; $offset += 500) { // ends when a page comes back short
        devdbotp_db_reset_error();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own events table
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ip_text FROM $t
             WHERE created_at >= %s AND ip_text <> '' AND (country IS NULL OR country = '')
               AND bot_category NOT IN ('verified_bot','human')
             ORDER BY created_at DESC LIMIT 500 OFFSET %d",
            $since,
            $offset
        ));
        if (!is_array($candidates) || devdbotp_db_failed()) {
            return false; // the read failed: reported, not "nothing to resolve"
        }
        if (!$candidates) {
            break;
        }
        foreach ($candidates as $ip) {
            if (count($ips) >= 100) {
                break;
            }
            // Public IPs only (never resolve a private/shared/localhost address), and skip any
            // we already attempted recently so a MaxMind miss isn't re-sent every day.
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }
            if (get_transient('devdbotp_geotry_' . md5($ip))) {
                continue;
            }
            $ips[] = $ip;
        }
        if (count($candidates) < 500) {
            break;
        }
    }
    if (empty($ips)) {
        return;
    }

    $r = wp_remote_post(DEVDBOTP_GEO_ENDPOINT, array(
        'timeout' => 15,
        'user-agent' => 'DevDome-Bot-Protection/' . DEVDBOTP_VERSION, // never WordPress's default agent: it names the site
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('ips' => $ips)),
    ));

    // Mark every sent IP as attempted regardless of outcome (don't hammer on failure).
    foreach ($ips as $ip) {
        set_transient('devdbotp_geotry_' . md5($ip), 1, 7 * DAY_IN_SECONDS);
    }

    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
        return;
    }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($d) || empty($d['results']) || !is_array($d['results'])) {
        return;
    }

    foreach ($d['results'] as $ip => $info) {
        if (!is_array($info)) {
            continue;
        }
        $country = isset($info['country'])
            ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $info['country']), 0, 2))
            : '';
        $asn = isset($info['asn']) ? (int) $info['asn'] : 0;
        if ($country === '' && $asn <= 0) {
            continue;
        }
        // Back-fill only rows that still lack a country (never clobber Cloudflare data).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; all values prepared.
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET country = %s, asn = %d WHERE ip_text = %s AND (country IS NULL OR country = '')",
            $country,
            max(0, $asn),
            (string) $ip
        ));
    }
}
add_action('devdbotp_refresh_feeds', 'devdbotp_geo_enrich');

/**
 * Real-time IP -> ASN for the per-request honeypot / Bot Radar checks (distinct from the cron
 * enrichment above). Cached per-IP for 24h, so it's a lookup per NEW IP, never per request, and a
 * cached 0 stops re-hammering an unknown IP. Fail-open with a 2-minute circuit breaker so a slow or
 * down geo-resolve never stalls a page. Public IPs only. Universal — works with or without Cloudflare.
 */
function devdbotp_resolve_asn($ip)
{
    $info = devdbotp_resolve_asn_info($ip);
    return (int) $info['asn'];
}

/** Real-time IP -> ['asn' => int, 'org' => string] via geo-resolve (MaxMind ASN). One cached call
 *  serves both the number and the network name; fail-open with the shared circuit breaker. */
function devdbotp_resolve_asn_info($ip)
{
    $empty = array('asn' => 0, 'org' => '');
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $empty;
    }
    if (!devdbotp_outside_lookups_on()) {
        return $empty; // no address leaves the site while Outside Lookups is off
    }
    $key = 'devdbotp_asninfo_' . substr(md5($ip), 0, 16);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    if (get_transient('devdbotp_asn_down') !== false) {
        return $empty; // geo-resolve recently failed -> back off, don't pile up timeouts
    }
    $r = wp_remote_post(DEVDBOTP_GEO_ENDPOINT, array(
        'timeout' => 2,
        'user-agent' => 'DevDome-Bot-Protection/' . DEVDBOTP_VERSION, // never WordPress's default agent: it names the site
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('ips' => array($ip))),
    ));
    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
        set_transient('devdbotp_asn_down', 1, 120);
        return $empty;
    }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    $row = (is_array($d) && isset($d['results'][$ip]) && is_array($d['results'][$ip])) ? $d['results'][$ip] : array();
    $info = array(
        'asn' => isset($row['asn']) ? max(0, (int) $row['asn']) : 0,
        'org' => isset($row['asn_org']) ? (string) $row['asn_org'] : '',
    );
    set_transient($key, $info, DAY_IN_SECONDS); // cache even unknowns so we don't re-lookup
    return $info;
}

/** Is there any flagged-ASN list to match against? Gates the per-request lookup — no list, no lookup. */
function devdbotp_asn_matching_active()
{
    $hp = devdbotp_get_setting('bp_honeypot_asns', array());
    if (is_array($hp) && !empty($hp)) {
        return true;
    }
    $fl = devdbotp_get_setting('fleet_asns', array());
    return is_array($fl) && !empty($fl);
}
