<?php
/**
 * 1.0.5 extras (owner 2026-10-18, from the Bot Traffic Shield comparison):
 *   - the admin-bar counter "Blocked today: N" (or "Would block today" while the plugin only records) with a
 *     dropdown of quick links (Overview, Activity, Rules, Settings) and the count over every kept record;
 *   - the CSV export of the blocked-request log for the last 7 / 14 / 30 days or everything kept, Excel-friendly
 *     (UTF-8 BOM, one header row, spreadsheet formulas neutralised).
 * Both read the plugin's own events table only; the counter is cached for two minutes so the bar never costs a
 * query per page load.
 */

defined('ABSPATH') || exit;

/** The screen's slug. DEVDBOTP_PAGE is defined by admin.php, which the front end (where the bar also shows) never loads. */
function devdbotp_page_slug()
{
    return defined('DEVDBOTP_PAGE') ? DEVDBOTP_PAGE : 'devdome-bot-protection';
}

/**
 * Blocked (or would-block) requests: today in the site's time zone, and over every kept record. null = DB read failed.
 * 1.0.6: two bounded reads instead of one pass over every kept row every two minutes. Today's count walks the
 * created_at index from midnight only (cached 2 min); the all-kept count is a separate read cached 15 min.
 */
function devdbotp_bar_counts()
{
    $today = get_transient('devdbotp_bar_today');
    $kept  = get_transient('devdbotp_bar_kept');
    if (false !== $today && false !== $kept) {
        return array('today' => (int) $today, 'kept' => (int) $kept);
    }
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    devdbotp_db_reset_error();
    if (false === $today) {
        $start = new DateTime('today 00:00:00', devdbotp_tz());
        $since = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; the since value is bound; cached below.
        $today = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE created_at >= %s AND action IN ('block','would_block')", $since));
        if (null === $today || devdbotp_db_failed()) {
            return null;
        }
        set_transient('devdbotp_bar_today', (int) $today, 2 * MINUTE_IN_SECONDS);
    }
    if (false === $kept) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; static clause; cached below.
        $kept = $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action IN ('block','would_block')");
        if (null === $kept || devdbotp_db_failed()) {
            return null;
        }
        set_transient('devdbotp_bar_kept', (int) $kept, 15 * MINUTE_IN_SECONDS);
    }
    return array('today' => (int) $today, 'kept' => (int) $kept);
}

/** The admin-bar node: the counter is the title, the dropdown holds the quick links and the kept-records count. */
function devdbotp_admin_bar($bar)
{
    if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
        return;
    }
    $counts = devdbotp_bar_counts();
    $live   = devdbotp_get_setting('mode', 'watching') === 'live';
    $label  = $live ? __('Blocked today', 'devdome-bot-protection') : __('Would block today', 'devdome-bot-protection');
    $today  = null === $counts ? '?' : number_format_i18n($counts['today']);
    $base   = admin_url('admin.php?page=' . devdbotp_page_slug());
    $bar->add_node(array(
        'id'    => 'devdbotp',
        'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html($label . ': ' . $today) . '</span>',
        'href'  => $base . '&bp_tab=activity',
        'meta'  => array('title' => __('DevDome Bot Protection', 'devdome-bot-protection')),
    ));
    $links = array(
        'overview' => __('Overview', 'devdome-bot-protection'),
        'activity' => __('Activity', 'devdome-bot-protection'),
        'rules'    => __('Rules', 'devdome-bot-protection'),
        'settings' => __('Settings', 'devdome-bot-protection'),
    );
    foreach ($links as $tab => $name) {
        $bar->add_node(array('parent' => 'devdbotp', 'id' => 'devdbotp-' . $tab, 'title' => esc_html($name), 'href' => $base . '&bp_tab=' . $tab));
    }
    $bar->add_node(array(
        'parent' => 'devdbotp',
        'id'     => 'devdbotp-kept',
        'title'  => esc_html(sprintf(
            /* translators: 1: "Blocked" or "Would block", 2: retention days, 3: the count. */
            __('%1$s, all kept records (%2$d days): %3$s', 'devdome-bot-protection'),
            $live ? __('Blocked', 'devdome-bot-protection') : __('Would block', 'devdome-bot-protection'),
            (int) devdbotp_retention_days(),
            null === $counts ? '?' : number_format_i18n($counts['kept'])
        )),
        'href'   => $base . '&bp_tab=activity&bp_range=90',
    ));
}
add_action('admin_bar_menu', 'devdbotp_admin_bar', 95);

/** The shield glyph in the bar (dashicons-shield), attached to the admin-bar stylesheet on both sides. */
function devdbotp_admin_bar_css()
{
    if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
        return;
    }
    wp_add_inline_style('admin-bar', '#wpadminbar #wp-admin-bar-devdbotp .ab-icon:before { content:"\\f332"; top:2px; } #wpadminbar #wp-admin-bar-devdbotp-kept .ab-item { white-space:nowrap; }');
}
add_action('admin_enqueue_scripts', 'devdbotp_admin_bar_css');
add_action('wp_enqueue_scripts', 'devdbotp_admin_bar_css');

/* ------------------------------------ CSV export ------------------------------------ */

/** Nonced URL of the blocked-request export. $days = 7 | 14 | 30 | 0 (everything kept). */
function devdbotp_export_url($days)
{
    return wp_nonce_url(
        add_query_arg(array('page' => devdbotp_page_slug(), 'bp_tab' => 'activity', 'bp_export' => '1', 'bp_days' => (int) $days), admin_url('admin.php')),
        'devdbotp_export',
        '_bpexn'
    );
}

/** A spreadsheet cell: a leading = + - @ or tab/CR would run as a formula in Excel; prefix it with a quote. */
function devdbotp_csv_cell($v)
{
    // Security review 2026-10-19 (B9): the same guard as Activity Log's devdacti_csv_guard: leading spaces, the
    // UTF-8 BOM, Unicode spaces and bidi marks are skipped before the formula check, control bytes are removed,
    // and | counts as a formula start too.
    $v = (string) $v;
    if ('' === $v) {
        return $v;
    }
    $v = preg_replace('~^(?:[\x00-\x20]|\xEF\xBB\xBF|\xC2\xA0|\xE2\x80[\x80-\x8F\xA8-\xAF]|\xE2\x81[\x9F\xA0-\xAF]|\xE3\x80\x80)+~', '', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    if ('' === $v) {
        return $v;
    }
    return preg_match('/^[=+\-@\t\r|]/', $v) ? "'" . $v : $v;
}

/** Stream the blocked-request log as CSV (nonce + capability gated admin download). */
function devdbotp_handle_export()
{
    if (empty($_GET['bp_export']) || !current_user_can('manage_options')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below
        return;
    }
    check_admin_referer('devdbotp_export', '_bpexn');
    $days = isset($_GET['bp_days']) ? (int) $_GET['bp_days'] : 7;
    if (!in_array($days, array(7, 14, 30, 0), true)) {
        $days = 7;
    }
    if ($days > 0) {
        $first = devdbotp_days_back($days);
        $start = new DateTime(end($first) . ' 00:00:00', devdbotp_tz());
        $since = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } else {
        $since = '1970-01-01 00:00:00';
    }
    devdbotp_db_guard_begin(); // DESIGN.md 24: a failed read is not the end of the list; the file says so
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    $cat_label = function_exists('devdbotp_category_labels') ? devdbotp_category_labels() : array();

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="devdome-bot-protection-blocked-' . ($days > 0 ? $days . 'd' : 'all') . '-' . gmdate('Y-m-d') . '.csv"');

    // 1.0.6: a large export used to stop silently at the PHP time limit or inside an output buffer. The limit is
    // lifted for this request where the host allows it, buffers are dropped so rows stream, every chunk is
    // flushed, and the file ends with a trailer row naming the row count: a file without that last row was cut.
    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hosts may forbid it; the export then runs within the default limit.
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV rows to php://output for a nonce + capability gated admin download.
    $out = fopen('php://output', 'w');
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the BOM goes to the same php://output stream as the rows below.
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM: Excel opens it as UTF-8 without the import wizard
    fputcsv($out, array('time', 'ip', 'country', 'action', 'type', 'risk', 'reason', 'method', 'path', 'user_agent', 'referer', 'network', 'datacenter'));

    $last  = 0;
    $total = 0;
    $cut   = false;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked read of the plugin's own table; since / id bound.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, created_at, ip_text, country, action, bot_category, risk_score, reason, method, path, user_agent, referer, asn_org, is_datacenter
             FROM $t WHERE action IN ('block','would_block') AND created_at >= %s AND id > %d ORDER BY id ASC LIMIT %d",
            $since,
            $last,
            500
        ), ARRAY_A);
        if (devdbotp_db_guard_failed()) {
            fputcsv($out, array(devdbotp_db_guard_message()));
            $cut = true;
            break;
        }
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            $total++;
            $cat  = (string) $r['bot_category'];
            fputcsv($out, array_map('devdbotp_csv_cell', array(
                get_date_from_gmt((string) $r['created_at'], 'Y-m-d H:i:s'),
                (string) $r['ip_text'],
                (string) $r['country'],
                'block' === $r['action'] ? 'blocked' : 'would block',
                isset($cat_label[$cat]) ? $cat_label[$cat] : $cat,
                (int) $r['risk_score'],
                (string) $r['reason'],
                (string) $r['method'],
                (string) $r['path'],
                (string) $r['user_agent'],
                (string) $r['referer'],
                (string) $r['asn_org'],
                !empty($r['is_datacenter']) ? 'yes' : 'no',
            )));
        }
        flush();
    } while (count($rows) === 500);
    devdbotp_db_guard_end();
    fputcsv($out, array($cut
        ? sprintf('# export stopped after %d rows (database read failed); the file is incomplete', $total)
        : sprintf('# end of export, %d rows', $total)));
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output
    fclose($out);
    exit;
}
add_action('admin_init', 'devdbotp_handle_export');
