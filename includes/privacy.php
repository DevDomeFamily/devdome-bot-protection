<?php
/**
 * WordPress privacy tools: personal data exporter + eraser for the events table, and the suggested
 * privacy policy text.
 *
 * Events carry no user id. WordPress asks by e-mail address, and the only addresses WordPress itself
 * knows for a person are the comment author IPs stored with that e-mail (or that user's comments).
 * Events are matched on ip_text IN those addresses, 500 per call. No comment IPs = nothing to do.
 */

defined('ABSPATH') || exit;

define('DEVDBOTP_PRIVACY_PAGE_SIZE', 500);

add_filter('wp_privacy_personal_data_exporters', 'devdbotp_privacy_register_exporter');
function devdbotp_privacy_register_exporter($exporters)
{
    $exporters['devdome-bot-protection'] = array(
        'exporter_friendly_name' => __('DevDome Bot Protection', 'devdome-bot-protection'),
        'callback'               => 'devdbotp_privacy_exporter',
    );
    return $exporters;
}

add_filter('wp_privacy_personal_data_erasers', 'devdbotp_privacy_register_eraser');
function devdbotp_privacy_register_eraser($erasers)
{
    $erasers['devdome-bot-protection'] = array(
        'eraser_friendly_name' => __('DevDome Bot Protection', 'devdome-bot-protection'),
        'callback'             => 'devdbotp_privacy_eraser',
    );
    return $erasers;
}

/** The IP addresses WordPress knows for this e-mail address: comment author IPs. */
function devdbotp_privacy_ips_for_email($email)
{
    global $wpdb;
    $email = trim((string) $email);
    if ('' === $email) {
        return array();
    }
    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    $user    = get_user_by('email', $email);
    if (devdbotp_db_failed()) {
        return null; // the user lookup failed: "no such user" is not known, the caller reports it
    }
    $user_id = $user ? (int) $user->ID : 0;
    if ($user_id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off privacy request.
        $ips = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT comment_author_IP FROM {$wpdb->comments} WHERE (comment_author_email = %s OR user_id = %d) AND comment_author_IP <> ''", $email, $user_id));
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off privacy request.
        $ips = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT comment_author_IP FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_author_IP <> ''", $email));
    }
    if (!is_array($ips) || (isset($wpdb->last_error) && '' !== (string) $wpdb->last_error)) {
        return null; // the lookup failed: the caller reports it instead of finishing with nothing
    }
    $out = array();
    foreach ((array) $ips as $ip) {
        $ip = trim((string) $ip);
        if ('' !== $ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            // Exact addresses only: a row shortened by Anonymize IPs (a /24 or /48) covers several visitors and is never one
            // person's export. An address that IS its own prefix (x.y.z.0, a bare /48) cannot be told from such a row, so it
            // is left out too: the rare visitor on a .0 host gets no export rather than another visitor's (Codex 1.0.4 rounds 1-2).
            if (function_exists('devdbotp_anonymize_ip') && devdbotp_anonymize_ip($ip) === $ip) {
                continue;
            }
            $out[] = $ip;
        }
    }
    return array_values(array_unique($out));
}

function devdbotp_privacy_exporter($email_address, $page = 1)
{
    global $wpdb;
    $ips = devdbotp_privacy_ips_for_email($email_address);
    if ($ips === null) {
        return array('data' => array(), 'done' => false); // WordPress retries; a failed read is never "nothing to export"
    }
    if (!$ips) {
        return array('data' => array(), 'done' => true);
    }
    $page   = max(1, (int) $page);
    $size   = DEVDBOTP_PRIVACY_PAGE_SIZE;
    $t      = $wpdb->prefix . 'devdbotp_events';
    $marks  = implode(',', array_fill(0, count($ips), '%s'));
    $args   = array_merge($ips, array($size, ($page - 1) * $size));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; the placeholders are built from the count of addresses.
    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, created_at, ip_text, path, user_agent, action, reason FROM $t WHERE ip_text IN ($marks) ORDER BY id ASC LIMIT %d OFFSET %d", $args), ARRAY_A);
    if (!is_array($rows) || (isset($wpdb->last_error) && '' !== (string) $wpdb->last_error)) {
        return array('data' => array(), 'done' => false); // the read failed: try again later
    }
    $data = array();
    foreach ((array) $rows as $r) {
        $data[] = array(
            'group_id'          => 'devdome-bot-protection',
            'group_label'       => __('DevDome Bot Protection events', 'devdome-bot-protection'),
            'group_description' => __('Requests from your IP address that the bot protection logged.', 'devdome-bot-protection'),
            'item_id'           => 'devdome-bp-event-' . (int) $r['id'],
            'data'              => array(
                array('name' => __('Time (UTC)', 'devdome-bot-protection'), 'value' => (string) $r['created_at']),
                array('name' => __('IP address', 'devdome-bot-protection'), 'value' => (string) $r['ip_text']),
                array('name' => __('Path', 'devdome-bot-protection'), 'value' => (string) $r['path']),
                array('name' => __('User agent', 'devdome-bot-protection'), 'value' => (string) $r['user_agent']),
                array('name' => __('Action', 'devdome-bot-protection'), 'value' => (string) $r['action']),
                array('name' => __('Reason', 'devdome-bot-protection'), 'value' => (string) $r['reason']),
            ),
        );
    }
    return array('data' => $data, 'done' => count((array) $rows) < $size);
}

function devdbotp_privacy_eraser($email_address, $page = 1)
{
    global $wpdb;
    $result = array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
    $ips    = devdbotp_privacy_ips_for_email($email_address);
    if ($ips === null) {
        $result['items_retained'] = true;
        $result['done']           = false; // WordPress tries again
        $result['messages'][]     = __('DevDome Bot Protection could not look up the addresses for this request: the database query failed.', 'devdome-bot-protection');
        return $result;
    }
    if (!$ips) {
        return $result;
    }
    $size  = DEVDBOTP_PRIVACY_PAGE_SIZE;
    $t     = $wpdb->prefix . 'devdbotp_events';
    $marks = implode(',', array_fill(0, count($ips), '%s'));
    $args  = array_merge($ips, array($size));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; the placeholders are built from the count of addresses.
    $n = $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE ip_text IN ($marks) LIMIT %d", $args));
    if (false === $n) {
        $result['items_retained'] = true;
        $result['messages'][]     = __('DevDome Bot Protection events could not be deleted: the database query failed.', 'devdome-bot-protection');
        $result['done']           = false; // WordPress tries again
        return $result;
    }
    // Reputation rows and sessions carry the same address: gone with the events (small tables, no paging needed).
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names, placeholders built from the list size
    $r1 = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}devdbotp_ip_reputation WHERE ip_text IN ($marks)", $ips));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names, placeholders built from the list size
    $r2 = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}devdbotp_sessions WHERE ip_text IN ($marks)", $ips));
    if (false === $r1 || false === $r2) {
        $result['items_retained'] = true;
        $result['messages'][]     = __('DevDome Bot Protection reputation or session rows could not be deleted: the database query failed.', 'devdome-bot-protection');
    }
    // The cached maps the engine reads (repeat offenders, behavioural flags) carry addresses too: rebuilt / pruned.
    if (function_exists('devdbotp_rebuild_bad_ips') && !devdbotp_rebuild_bad_ips()) {
        $result['items_retained'] = true;
        $result['messages'][]     = __('DevDome Bot Protection could not rebuild its repeat-offender list: the database query failed.', 'devdome-bot-protection');
    }
    $beh = devdbotp_get_setting('bp_behavior_ips', array());
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        $result['items_retained'] = true;
        $result['messages'][]     = __('DevDome Bot Protection could not read its behavioural flag list: the database query failed.', 'devdome-bot-protection');
    } elseif (is_array($beh)) {
        $left = array_diff_key($beh, array_flip($ips));
        if (count($left) !== count($beh) && !devdbotp_update_setting('bp_behavior_ips', $left)) {
            $result['items_retained'] = true;
            $result['messages'][]     = __('DevDome Bot Protection could not update its behavioural flag list: the database query failed.', 'devdome-bot-protection');
        }
    }
    $result['items_removed'] = $n > 0 || (int) $r1 > 0 || (int) $r2 > 0;
    $result['done']          = $n < $size && empty($result['items_retained']); // a full page, or anything retained, means WordPress calls again
    return $result;
}

add_action('admin_init', 'devdbotp_privacy_policy_content');
function devdbotp_privacy_policy_content()
{
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    $days = devdbotp_retention_days();
    $text = '<p>' . esc_html__('This site uses DevDome Bot Protection to detect and block harmful automated traffic. When a request is identified as a bot or is blocked, the plugin stores the IP address, the requested path and the user agent of that request in this site\'s own database. Requests from normal visitors are not logged.', 'devdome-bot-protection') . '</p>'
        /* translators: %d is the number of days events are kept. */
        . '<p>' . esc_html(sprintf(__('These log entries are kept for %d days and are then deleted automatically. The plugin also keeps, for the same period, a per-address record of repeated bad requests (the address, when it was first and last seen, its country and network) and short visit sessions of visitors (a random session id, the address, country, network, page count) used to tell people from automated browsers. Daily and hourly totals contain no addresses.', 'devdome-bot-protection'), $days)) . '</p>'
        . '<p>' . esc_html__('The plugin has an Anonymize IPs option. When it is on, IP addresses are shortened before they are stored (the last part of an IPv4 address is removed, an IPv6 address is cut to its first 48 bits), so a stored address no longer points to one person. The per-address record of repeated bad requests and the enforcement lists can still hold full addresses.', 'devdome-bot-protection') . '</p>';
    wp_add_privacy_policy_content(__('DevDome Bot Protection', 'devdome-bot-protection'), wp_kses_post($text));
}
