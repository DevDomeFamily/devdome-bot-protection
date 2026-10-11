<?php
/**
 * Fleet reputation network (opt-in) — the moat. When enabled, this site:
 *   - REPORTS the bad IPs it already blocked (repeat-offenders + automated, PUBLIC IPs only)
 *     to api.devdome.com, and
 *   - CONSUMES the community blocklist (IPs confirmed bad by >= N distinct sites), matched
 *     LOCALLY like the DROP list (no per-request call). A fleet-listed IP is acted on per the
 *     configurable fleet_action (default Log — flag-first). The SEO-safe verified-bot
 *     allowlist always wins, so even a poisoned good-bot IP is never blocked.
 *
 * OFF by default. Only attacker IPs (already adversarial + public) and a random site id are
 * shared — never any visitor data. Both sync + report ride the existing daily feed cron.
 */

defined('ABSPATH') || exit;

if (!defined('DEVDBOTP_FLEET_ENDPOINT')) {
    define('DEVDBOTP_FLEET_ENDPOINT', 'https://api.devdome.com/bot-protection/fleet');
}

/** Is the network turned on for this site? */
function devdbotp_fleet_on()
{
    return (int) devdbotp_get_setting('fleet_enabled', 0) === 1;
}

/** Stable per-site random id for reports (NOT PII — just a server-side dedup key). */
function devdbotp_fleet_site_id()
{
    $id = devdbotp_get_setting('fleet_site_id', '');
    if (!is_string($id) || strlen($id) < 16) {
        $id = bin2hex(random_bytes(16));
        devdbotp_update_setting('fleet_site_id', $id);
    }
    return $id;
}

/** Is this IP on the synced community blocklist? Local O(log n) match — no per-request call. */
function devdbotp_ip_in_fleet($ip, $feeds = null)
{
    if (!devdbotp_fleet_on() || !function_exists('devdcorev1_ip_in_cidr_index')) {
        return false;
    }
    if ($feeds === null) {
        $feeds = function_exists('devdbotp_feeds') ? devdbotp_feeds() : devdbotp_get_setting('bp_feeds', array());
    }
    return devdcorev1_ip_in_cidr_index(
        $ip,
        isset($feeds['fleet_v4']) ? $feeds['fleet_v4'] : array(),
        isset($feeds['fleet_v6']) ? $feeds['fleet_v6'] : array()
    );
}

/** Sync the community blocklist into $feeds (called from the feed refresh). Opting out drops it. */
function devdbotp_fleet_sync(&$feeds)
{
    $on = devdbotp_fleet_on();
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1;
        return; // whether Bot Radar is on could not be read: the synced lists stay as they are
    }
    if (!$on) {
        unset($feeds['fleet_v4'], $feeds['fleet_v6']);
        devdbotp_update_setting('fleet_geo', array());
        devdbotp_update_setting('fleet_asns', array());
        return;
    }
    $r = wp_remote_get(DEVDBOTP_FLEET_ENDPOINT, array('timeout' => 10, 'user-agent' => 'DevDome-Bot-Protection/' . DEVDBOTP_VERSION)); // never WordPress's default agent: it names the site
    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
        $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1;
        return; // keep the previous list on failure
    }
    $d = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($d) || !isset($d['cidrs']) || !is_array($d['cidrs'])) {
        $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1; // a 200 that is not the list = a failed download
        return;
    }
    if (is_array($d) && isset($d['cidrs']) && is_array($d['cidrs'])) {
        $idx = devdbotp_build_drop_index(array_values(array_filter(array_map('trim', $d['cidrs']))));
        $feeds['fleet_v4'] = $idx['v4'];
        $feeds['fleet_v6'] = $idx['v6'];
        // Capture the server's geo enrichment (country histogram) for the dashboard. Display-
        // only — not part of matching — so it's stored as its own setting, sanitized to ISO-2.
        $geo = (isset($d['geo']) && is_array($d['geo'])) ? $d['geo'] : array();
        $clean = array();
        foreach ($geo as $cc => $n) {
            $cc = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $cc));
            if (strlen($cc) === 2) {
                $clean[$cc] = max(0, (int) $n);
            }
        }
        arsort($clean);
        if (!devdbotp_update_setting('fleet_geo', $clean)) { $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1; } // a write that failed is a failed refresh
    }
    // Bot Radar: community-confirmed bad crawler ASNs (the honeypot moat). One site's trap catches a
    // rotating-proxy network → every site blocks its ASN. Stored as an O(1) map (no IP ranges).
    if (is_array($d) && isset($d['asns']) && is_array($d['asns'])) {
        $amap = array();
        foreach ($d['asns'] as $a) {
            $a = (int) $a;
            if ($a > 0) {
                $amap[$a] = 1;
            }
        }
        if (!devdbotp_update_setting('fleet_asns', $amap)) { $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1; } // a write that failed is a failed refresh
    }
}

/** Is this request's ASN on the synced Bot Radar (community) bad-ASN list? O(1) map read — no call. */
function devdbotp_asn_in_fleet($asn)
{
    $asn = (int) $asn;
    if ($asn <= 0 || !devdbotp_fleet_on()) {
        return false;
    }
    $map = devdbotp_get_setting('fleet_asns', array());
    return is_array($map) && isset($map[$asn]);
}

/** Count of Bot Radar community ASNs currently synced (for the dashboard). */
function devdbotp_fleet_asn_count()
{
    $map = devdbotp_get_setting('fleet_asns', array());
    return is_array($map) ? count($map) : 0;
}

/** Collect this site's honeypot-flagged ASNs to contribute to Bot Radar (numbers only, no PII). */
function devdbotp_fleet_collect_bad_asns($limit = 500)
{
    $map = function_exists('devdbotp_honeypot_flagged_asns') ? devdbotp_honeypot_flagged_asns() : array();
    $out = array();
    foreach ((array) $map as $asn => $meta) {
        if (count($out) >= $limit) {
            break;
        }
        $asn = (int) $asn;
        if ($asn > 0) {
            $out[] = $asn;
        }
    }
    return $out;
}

/** Raw honeypot (asn, ip) pairs from this site's trap hits (last 14d) — the fleet-wide POOLING signal.
 * An ASN spread ~1 IP per site never crosses the per-site K-IP gate, but pools fleet-wide on the server
 * (>= RAW_IP_THRESHOLD distinct IPs across all sites => asns_review). No PII: ASN + the bot's own IP. */
function devdbotp_fleet_collect_raw_honeypot($limit = 2000)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    // `ip` is packed binary (INET6); `ip_text` holds the readable string — use that.
    devdbotp_db_reset_error();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT asn, ip_text FROM `$t` WHERE bot_category = 'honeypot' AND asn > 0 AND ip_text <> '' "
        . "AND created_at > (NOW() - INTERVAL 14 DAY) LIMIT %d",
        (int) $limit
    ), ARRAY_A);
    if (!is_array($rows) || devdbotp_db_failed()) {
        return null; // the read failed: not an empty list
    }
    $out = array();
    foreach ((array) $rows as $r) {
        $out[] = array('asn' => (int) $r['asn'], 'ip' => (string) $r['ip_text']);
    }
    return $out;
}

/** Top origin countries of the synced community blocklist (ISO2 => count), highest first. */
function devdbotp_fleet_geo($limit = 8)
{
    $geo = devdbotp_get_setting('fleet_geo', array());
    if (!is_array($geo) || empty($geo)) {
        return array();
    }
    arsort($geo);
    return array_slice($geo, 0, max(1, (int) $limit), true);
}

/** Collect PUBLIC bad IPs from this site's reputation memory (capped). */
function devdbotp_fleet_collect_bad_ips($limit = 500)
{
    $out = array();
    $sources = array(
        'bp_bad_ips'      => 'repeat_offender', // {ip: ts}
        'bp_behavior_ips' => 'automated',
    );
    foreach ($sources as $key => $cat) {
        $map = devdbotp_get_setting($key, array());
        if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
            return null; // could not be read: not an empty list
        }
        if (!is_array($map)) {
            continue;
        }
        foreach ($map as $ip => $ts) {
            if (count($out) >= $limit) {
                return $out;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $out[] = array('ip' => (string) $ip, 'cat' => $cat);
            }
        }
    }
    return $out;
}

/** Report this site's recently-blocked bad IPs to the network (opt-in, daily, fire-and-forget). */
function devdbotp_fleet_report()
{
    if (!devdbotp_fleet_on()) {
        return true; // Bot Radar is off: nothing to report is not a failure
    }
    $ips  = devdbotp_fleet_collect_bad_ips(500);
    $asns = devdbotp_fleet_collect_bad_asns(500);
    $hp   = function_exists('devdbotp_fleet_collect_raw_honeypot') ? devdbotp_fleet_collect_raw_honeypot(2000) : array();
    if ($hp === null || $ips === null || $asns === null || !empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false; // something could not be read: the report is not "nothing to send", it is not sent
    }
    if (empty($ips) && empty($asns) && empty($hp)) {
        return true;
    }
    $r = wp_remote_post(DEVDBOTP_FLEET_ENDPOINT . '/report', array(
        'user-agent' => 'DevDome-Bot-Protection/' . DEVDBOTP_VERSION, // the report carries a random site id only
        'timeout'  => 10, // a cron job: waiting 10 s for the answer costs nothing, and only an answer proves the report landed
        'headers'  => array('Content-Type' => 'application/json'),
        'body'     => wp_json_encode(array('site' => devdbotp_fleet_site_id(), 'ips' => $ips, 'asns' => $asns, 'hp' => $hp)),
    ));
    $ok = !is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200;
    devdbotp_update_setting('fleet_report_last', array('at' => time(), 'ok' => $ok ? 1 : 0)); // what the Settings tab can show
    return $ok;
}
add_action('devdbotp_refresh_feeds', 'devdbotp_fleet_report');

/** Count of community-blocklisted IP ranges currently synced (for the dashboard). */
function devdbotp_fleet_count()
{
    $feeds = function_exists('devdbotp_feeds') ? devdbotp_feeds() : devdbotp_get_setting('bp_feeds', array());
    $n = 0;
    if (!empty($feeds['fleet_v4'])) { $n += count($feeds['fleet_v4']); }
    if (!empty($feeds['fleet_v6'])) { $n += count($feeds['fleet_v6']); }
    return $n;
}
