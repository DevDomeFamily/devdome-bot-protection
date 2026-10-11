<?php
/**
 * IP reputation — finally wires the `{prefix}devdbotp_ip_reputation` table so the
 * engine has MEMORY. On every bot/blocked event we upsert one row (first/last seen,
 * total_requests/blocks/challenges, datacenter + Spamhaus flags). A "repeat offender"
 * (>= threshold blocks) is then auto-blocked even on an otherwise-clean request.
 *
 * Perf: reputation is WRITTEN only for bots (rare), never for human traffic. The
 * repeat-offender lookup the engine does per request reads a cached `bp_bad_ips` map
 * from the settings blob (no per-request query); that map is rebuilt at most every
 * 5 minutes and only while bots are actually hitting.
 */

defined('ABSPATH') || exit;

function devdbotp_reputation_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdbotp_ip_reputation';
}

/** Upsert reputation for one bot/blocked request. Cheap single-row PK write. */
function devdbotp_record_reputation($ip, $result, $cf = array(), $blocked = false)
{
    if ($ip === '') {
        return;
    }
    // Only track PUBLIC IPs — a shared/private/loopback IP (NAT, localhost) must never
    // accumulate blocks and become a "repeat offender" that whitelist-blocks everyone.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return;
    }
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return;
    }

    $labels  = isset($result['labels']) && is_array($result['labels']) ? $result['labels'] : array();
    $is_dc   = (in_array('datacenter_asn', $labels, true) || in_array('datacenter_asn_sensitive', $labels, true)) ? 1 : 0;
    $drop    = in_array('spamhaus_drop', $labels, true) ? 1 : 0;
    $country = !empty($cf['country']) ? substr($cf['country'], 0, 2) : null;
    $asn     = !empty($cf['asn']) ? (int) $cf['asn'] : null;
    $blk     = $blocked ? 1 : 0;
    $chl     = (isset($result['action']) && $result['action'] === 'challenge') ? 1 : 0;
    $now     = current_time('mysql', true);

    global $wpdb;
    $t = devdbotp_reputation_table();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; all values prepared.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $t
            (ip, ip_text, first_seen, last_seen, country, asn, is_datacenter, spamhaus_drop,
             total_requests, total_blocks, total_challenges, last_checked_at)
         VALUES (%s, %s, %s, %s, %s, %d, %d, %d, 1, %d, %d, %s)
         ON DUPLICATE KEY UPDATE
            last_seen        = VALUES(last_seen),
            country          = COALESCE(VALUES(country), country),
            asn              = COALESCE(VALUES(asn), asn),
            is_datacenter    = GREATEST(is_datacenter, VALUES(is_datacenter)),
            spamhaus_drop    = GREATEST(spamhaus_drop, VALUES(spamhaus_drop)),
            total_requests   = total_requests + 1,
            total_blocks     = total_blocks + VALUES(total_blocks),
            total_challenges = total_challenges + VALUES(total_challenges),
            last_checked_at  = VALUES(last_checked_at)",
        $packed,
        $ip,
        $now,
        $now,
        $country,
        $asn,
        $is_dc,
        $drop,
        $blk,
        $chl,
        $now
    ));

    devdbotp_maybe_refresh_bad_ips();
}

/** Rebuild the cached repeat-offender map (>= threshold blocks) at most every 5 min. */
function devdbotp_maybe_refresh_bad_ips()
{
    $last = (int) devdbotp_get_setting('bad_ips_built', 0);
    if ((time() - $last) < 300) {
        return;
    }
    devdbotp_rebuild_bad_ips();
}

function devdbotp_rebuild_bad_ips()
{
    global $wpdb;
    $threshold = max(2, (int) devdbotp_get_setting('repeat_offender_threshold', 10));
    $t = devdbotp_reputation_table();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; threshold prepared.
    devdbotp_db_reset_error();
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT ip_text FROM $t WHERE total_blocks >= %d AND ip_text <> '' ORDER BY total_blocks DESC, last_seen DESC LIMIT 2000",
        $threshold
    ));
    if (!is_array($rows) || devdbotp_db_failed()) {
        return false; // the read failed: the previous map and its stamp stay (an empty map would unblock every offender)
    }
    $map = array();
    foreach ((array) $rows as $r) {
        $map[$r] = 1;
    }
    if (!devdbotp_update_setting('bp_bad_ips', $map)) {
        return false; // the map did not land: the stamp is not moved either
    }
    devdbotp_update_setting('bad_ips_built', time());
    unset($GLOBALS['devdbotp_cache']);
    return devdbotp_get_setting('bp_bad_ips', null) == $map; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- ints vs numeric strings
}

/** Is this IP a known repeat offender? (Reads the cached map — no query.) */
function devdbotp_ip_is_repeat_offender($ip)
{
    if ($ip === '') {
        return false;
    }
    $map = devdbotp_get_setting('bp_bad_ips', array());
    return is_array($map) && isset($map[$ip]);
}

/** Count of IPs currently tracked in the reputation table (for the dashboard). */
function devdbotp_reputation_count()
{
    global $wpdb;
    $t = devdbotp_reputation_table();
    devdbotp_db_reset_error();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name, no user input.
    $n = $wpdb->get_var("SELECT COUNT(*) FROM $t");
    return ($n === null || devdbotp_db_failed()) ? null : (int) $n; // null = the count could not be read (the screen shows a dash, never 0)
}

/** Prune reputation rows not seen within the retention window (hooked to the daily cron). */
function devdbotp_prune_reputation()
{
    global $wpdb;
    $retention = devdbotp_retention_days();
    if ($retention < 1) {
        $retention = 90;
    }
    $t = devdbotp_reputation_table();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; cutoff prepared.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $t WHERE last_seen < %s",
        gmdate('Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS)
    ));
    devdbotp_rebuild_bad_ips();
}
add_action('devdbotp_refresh_feeds', 'devdbotp_prune_reputation');
