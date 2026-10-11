<?php
/**
 * Custom (user-defined) rules — finally wires the `{prefix}devdbotp_rules` table.
 * The user can block/log/allow by user-agent, path, IP/CIDR, country, or ASN.
 *
 * Perf: the table is the source of truth (admin CRUD), but the ENGINE reads the
 * enabled rules from a cached `bp_custom_rules` setting that is rebuilt on every
 * save — so it rides the settings blob already loaded once per request (no extra
 * query on the hot path, same model as the threat feeds).
 */

defined('ABSPATH') || exit;

function devdbotp_rules_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdbotp_rules';
}

/** Fields a custom rule can match on, with the operators each allows. */
function devdbotp_rule_field_ops()
{
    return array(
        'user_agent' => array('contains', 'equals', 'starts_with', 'regex'),
        'path'       => array('contains', 'equals', 'starts_with', 'regex'),
        'ip'         => array('equals', 'cidr'),
        'country'    => array('equals'),
        'asn'        => array('equals'),
    );
}

function devdbotp_rule_actions_list()
{
    return array('allow', 'log', 'block');
}

/** Enabled custom rules, read from the cached setting (hot path — no query). */
function devdbotp_custom_rules()
{
    $r = devdbotp_get_setting('bp_custom_rules', array());
    return is_array($r) ? $r : array();
}

/** All custom rules incl. disabled, straight from the table (admin list only). */
function devdbotp_all_custom_rules()
{
    global $wpdb;
    $t = devdbotp_rules_table();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name, no user input.
    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    $rows = $wpdb->get_results("SELECT * FROM $t WHERE rule_type = 'custom' ORDER BY priority ASC, id ASC", ARRAY_A);
    $GLOBALS['devdbotp_rules_read_failed'] = !is_array($rows) || '' !== (string) $wpdb->last_error; // a failed read is never "no rules"
    return is_array($rows) ? $rows : array();
}

/** What the engine's cache and the hot path's defer flag must hold for the table as it is now; null when the table cannot be read. */
function devdbotp_custom_rules_cache_expected()
{
    global $wpdb;
    $t = devdbotp_rules_table();
    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name, no user input.
    $rows = $wpdb->get_results("SELECT id, name, match_field, operator, pattern, action FROM $t WHERE rule_type = 'custom' AND enabled = 1 ORDER BY priority ASC, id ASC", ARRAY_A);
    if (!is_array($rows) || '' !== (string) $wpdb->last_error) {
        return null;
    }
    // An Allow rule the early blocker cannot judge (anything but an IP match) makes it defer to the full engine.
    $defer = 0;
    foreach ($rows as $cr) {
        if ($cr['action'] === 'allow' && $cr['match_field'] !== 'ip') {
            $defer = 1;
            break;
        }
    }
    return array('rows' => $rows, 'defer' => $defer);
}

/** True when the engine's cache and the defer flag equal what the table holds (a fresh read of both). */
function devdbotp_custom_rules_cache_verify()
{
    $exp = devdbotp_custom_rules_cache_expected();
    if ($exp === null) {
        return false;
    }
    unset($GLOBALS['devdbotp_cache']);
    $cached = devdbotp_custom_rules();
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false; // defaults from a failed read are not the cache
    }
    $defer = (int) devdbotp_get_setting('hot_defer', 0); // the same loaded snapshot: the cache is filled now, no second load happens
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false;
    }
    return $cached === $exp['rows'] && $defer === $exp['defer'];
}

/** Rebuild the cached enabled-rules setting the engine reads. Called after every change. Returns whether it matches the table now. */
function devdbotp_rebuild_custom_rules_cache()
{
    $exp = devdbotp_custom_rules_cache_expected();
    if ($exp === null) {
        return false; // the table could not be read: the cache is left as it is, the caller does not report success
    }
    devdbotp_update_setting('bp_custom_rules', $exp['rows']);
    devdbotp_update_setting('hot_defer', $exp['defer']);
    return devdbotp_custom_rules_cache_verify();
}

/**
 * One rule write (add / delete / switch), made safe the same way the dashboard replacement is:
 *  1. the current custom rows are copied into custom_rules_backup and read back (with the key of the intended result);
 *  2. $write runs; false = nothing changed, the copy is dropped;
 *  3. the engine's cache and the hot path's defer flag are rebuilt and verified against the table;
 *  4. when that fails the write is undone in a NEW error window (the guard refuses writes after a failed query, so the
 *     mark is moved first), the table is compared to the copy and the cache verified; only a verified undo drops the
 *     copy. A copy that stays is put back by devdbotp_kb_site_rules_recover() before the next rule write.
 * Returns the write's value on success (the id, true), 0 / false otherwise.
 */
function devdbotp_custom_rule_write($intended_rows, $write, $undo)
{
    global $wpdb;
    if (function_exists('devdbotp_kb_lock') && !devdbotp_kb_lock(2)) {
        return false; // another request is syncing: nothing written, the caller reports it
    }
    try {
        if (function_exists('devdbotp_kb_site_rules_recover') && !devdbotp_kb_site_rules_recover()) {
            return false; // an earlier failure's copy is not back yet
        }
        $t = devdbotp_rules_table();
        devdbotp_db_reset_error();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
        $before = $wpdb->get_results("SELECT * FROM {$t} WHERE rule_type = 'custom' ORDER BY id", ARRAY_A);
        if (!is_array($before) || devdbotp_db_failed()) {
            return false;
        }
        $new_key = function_exists('devdbotp_kb_rows_key') ? devdbotp_kb_rows_key($intended_rows($before)) : '';
        if (!function_exists('devdbotp_kb_backup_store') || !devdbotp_kb_backup_store($before, $new_key)) {
            return false; // no verified copy = nothing is written
        }
        $result = $write();
        if (!$result) {
            devdbotp_kb_backup_clear();
            return false; // the write itself did not happen
        }
        if (devdbotp_rebuild_custom_rules_cache()) {
            if (function_exists('devdbotp_site_rules_changed')) {
                devdbotp_site_rules_changed();
            }
            devdbotp_kb_backup_clear(); // a copy that could not be dropped is harmless: its new_key equals this result, recover() drops it next time
            return $result; // the write is in place and verified: the caller (an import) must know its id
        }
        // The cache does not match the table: the write is undone, checked, in its own error window.
        devdbotp_db_guard_rebase();
        $undo($result);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
        $now = $wpdb->get_results("SELECT * FROM {$t} WHERE rule_type = 'custom' ORDER BY id", ARRAY_A);
        if (is_array($now) && $now === $before && devdbotp_rebuild_custom_rules_cache()) {
            devdbotp_kb_backup_clear(); // table, cache and defer flag are back together; if this drop fails, recover() sees the copy equals the table and drops it
        }
        return false; // the copy stays until the undo is verified
    } finally {
        if (function_exists('devdbotp_kb_unlock')) {
            devdbotp_kb_unlock();
        }
    }
}

/** Insert a custom rule (validated). Returns the new id or false. */
function devdbotp_add_custom_rule($field, $operator, $pattern, $action, $name = '')
{
    $field_ops = devdbotp_rule_field_ops();
    if (!isset($field_ops[$field]) || !in_array($operator, $field_ops[$field], true)) {
        return false;
    }
    if (!in_array($action, devdbotp_rule_actions_list(), true)) {
        return false;
    }
    $pattern = trim((string) $pattern);
    if ($pattern === '' || strlen($pattern) > 255) {
        return false;
    }
    if ($operator === 'regex' && @preg_match('~' . str_replace('~', '\~', $pattern) . '~i', '') === false) {
        return false; // reject an invalid regex up front
    }
    if ($field === 'asn') {
        $pattern = (string) (int) preg_replace('/[^0-9]/', '', $pattern);
    }
    if ($field === 'country') {
        $pattern = strtoupper(preg_replace('/[^A-Za-z]/', '', $pattern));
    }
    // What is stored must be a value the matcher can use: a country code, a network number, an address or a range.
    if (($field === 'country' && strlen($pattern) !== 2) || ($field === 'asn' && (int) $pattern <= 0)) {
        return false;
    }
    if ($field === 'ip' && $operator === 'equals' && !filter_var($pattern, FILTER_VALIDATE_IP)) {
        return false;
    }
    if ($field === 'ip' && $operator === 'cidr') {
        $cp = explode('/', $pattern, 2);
        $cmax = filter_var($cp[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
        if (!filter_var($cp[0], FILTER_VALIDATE_IP) || !isset($cp[1]) || !ctype_digit($cp[1]) || (int) $cp[1] > $cmax) {
            return false;
        }
    }
    global $wpdb;
    $row_name = $name !== '' ? substr(sanitize_text_field($name), 0, 255) : ($field . ' ' . $operator . ' ' . $pattern);
    $t = devdbotp_rules_table();
    return (int) devdbotp_custom_rule_write(
        function ($before) use ($field, $operator, $pattern, $action, $row_name) {
            $before[] = array('match_field' => $field, 'operator' => $operator, 'pattern' => $pattern, 'action' => $action, 'enabled' => 1, 'name' => $row_name);
            return $before;
        },
        function () use ($wpdb, $t, $field, $operator, $pattern, $action, $row_name) {
            $now = current_time('mysql', true);
            $n = $wpdb->insert($t, array(
                'enabled' => 1, 'priority' => 100, 'rule_type' => 'custom', 'name' => $row_name,
                'match_field' => $field, 'operator' => $operator, 'pattern' => $pattern, 'action' => $action,
                'risk_points' => 0, 'created_at' => $now, 'updated_at' => $now,
            ));
            return $n ? (int) $wpdb->insert_id : 0;
        },
        function ($id) use ($wpdb, $t) {
            $wpdb->delete($t, array('id' => (int) $id, 'rule_type' => 'custom'));
        }
    );
}

function devdbotp_delete_custom_rule($id)
{
    global $wpdb;
    $id = (int) $id;
    $t  = devdbotp_rules_table();
    $row = null;
    return (bool) devdbotp_custom_rule_write(
        function ($before) use ($id, &$row) {
            $out = array();
            foreach ($before as $r) {
                if ((int) $r['id'] === $id) { $row = $r; continue; }
                $out[] = $r;
            }
            return $out;
        },
        function () use ($wpdb, $t, $id) {
            return 0 < (int) $wpdb->delete($t, array('id' => $id, 'rule_type' => 'custom')); // a row that is not there was not deleted
        },
        function () use ($wpdb, $t, &$row) {
            if (is_array($row)) {
                $wpdb->insert($t, $row); // the row comes back with its id
            }
        }
    );
}

function devdbotp_toggle_custom_rule($id, $enabled)
{
    global $wpdb;
    $id  = (int) $id;
    $t   = devdbotp_rules_table();
    $was = null;
    $row = null;
    return (bool) devdbotp_custom_rule_write(
        function ($before) use ($id, $enabled, &$was, &$row) {
            foreach ($before as $i => $r) {
                if ((int) $r['id'] === $id) { $was = (int) $r['enabled']; $row = $r; $before[$i]['enabled'] = $enabled ? 1 : 0; }
            }
            return $before;
        },
        function () use ($wpdb, $t, $id, $enabled, &$was) {
            if ($was === null) {
                return false; // no such rule
            }
            $n = $wpdb->update($t, array('enabled' => $enabled ? 1 : 0, 'updated_at' => current_time('mysql', true)), array('id' => $id, 'rule_type' => 'custom'));
            return false !== $n && ($n > 0 || $was === ($enabled ? 1 : 0)); // already in that state counts, a vanished row does not
        },
        function () use ($wpdb, $t, $id, &$was, &$row) {
            if ($was !== null) {
                $wpdb->update($t, array('enabled' => $was, 'updated_at' => is_array($row) ? $row['updated_at'] : current_time('mysql', true)), array('id' => $id, 'rule_type' => 'custom')); // the row as it was, stamp included, so the undo compares equal
            }
        }
    );
}

/** Does one custom rule match the request context? (Pure — used inside the scorer.) */
function devdbotp_match_custom_rule($rule, $ctx)
{
    $field = isset($rule['match_field']) ? $rule['match_field'] : '';
    $op    = isset($rule['operator']) ? $rule['operator'] : '';
    $pat   = isset($rule['pattern']) ? (string) $rule['pattern'] : '';
    if ($pat === '') {
        return false;
    }

    switch ($field) {
        case 'user_agent': $hay = (string) (isset($ctx['ua']) ? $ctx['ua'] : ''); break;
        case 'path':       $hay = (string) (isset($ctx['path']) ? $ctx['path'] : ''); break;
        case 'ip':         $hay = (string) (isset($ctx['ip']) ? $ctx['ip'] : ''); break;
        case 'country':    $hay = strtoupper((string) (isset($ctx['country']) ? $ctx['country'] : '')); $pat = strtoupper($pat); break;
        case 'asn':        $hay = (string) (int) (isset($ctx['asn']) ? $ctx['asn'] : 0); break;
        default: return false;
    }

    if ($field === 'ip' && $op === 'cidr') {
        return devdbotp_ip_in_cidr($hay, $pat);
    }
    if (($field === 'user_agent' || $field === 'path') && $op !== 'regex') {
        $hay = strtolower($hay); // plain operators compare without case; a regex keeps its escapes (\D, \W) meaningful and matches with /i
        $pat = strtolower($pat);
    }
    switch ($op) {
        case 'equals':      return $hay !== '' && $hay === $pat;
        case 'contains':    return strpos($hay, $pat) !== false;
        case 'starts_with': return strpos($hay, $pat) === 0;
        case 'regex':       return @preg_match('~' . str_replace('~', '\~', $pat) . '~i', $hay) === 1;
    }
    return false;
}

/** Single IPv4/IPv6 CIDR (or bare IP) containment test. */
function devdbotp_ip_in_cidr($ip, $cidr)
{
    if ($ip === '') {
        return false;
    }
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($net, $bits) = explode('/', $cidr, 2);
    if (!ctype_digit((string) $bits)) {
        return false; // "/not-a-mask" is not /0
    }
    $bits = (int) $bits;
    $ipp  = @inet_pton($ip);
    $netp = @inet_pton($net);
    if ($ipp === false || $netp === false || strlen($ipp) !== strlen($netp)) {
        return false;
    }
    if ($bits < 0 || $bits > strlen($ipp) * 8) {
        return false;
    }
    $whole = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($whole > 0 && strncmp($ipp, $netp, $whole) !== 0) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return (ord($ipp[$whole]) & ord($mask)) === (ord($netp[$whole]) & ord($mask));
}
