<?php
/**
 * Admin: menu, scoped enqueue, save handlers, and the tabbed dashboard.
 * Tabs: Overview (the verdict hero), Activity (with the top threats lists), Rules, Known Bots (AI crawlers are a group
 * inside it), Settings. Rendered on the shared `.dd-app` design system to the
 * DEVDOME WORDPRESS PLUGIN DESIGN.md standard (sections 14 to 21, Malware Scanner shape).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

const DEVDBOTP_PAGE = 'devdome-bot-protection';

function devdbotp_menu()
{
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        'DevDome Bot Protection',
        'Bot Protection',
        'manage_options',
        DEVDBOTP_PAGE,
        'devdbotp_render_page',
        5
    );
}
add_action('admin_menu', 'devdbotp_menu');

function devdbotp_enqueue_assets($hook)
{
    if (strpos($hook, DEVDBOTP_PAGE) === false) {
        return;
    }
    $css = DEVDBOTP_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css) ? filemtime($css) : DEVDBOTP_VERSION;
    wp_enqueue_style('devdbotp-ui', DEVDBOTP_URL . 'assets/devdome-tools-tw.css', array(), $ver);
    wp_add_inline_style('devdbotp-ui', devdbotp_inline_css());
    wp_enqueue_style('dashicons');
    // Known Bots tab: the DevDome dashboard's Known Bots view, 1:1 (its data rides on the tab markup, data-kb).
    $kcss = DEVDBOTP_DIR . 'assets/bp-knownbots.css';
    wp_enqueue_style('devdbotp-knownbots', DEVDBOTP_URL . 'assets/bp-knownbots.css', array('devdbotp-ui'), file_exists($kcss) ? filemtime($kcss) : DEVDBOTP_VERSION);

    // Activity chart = the DevDome dashboard's chart, 1:1: Chart.js 4.5.1 (MIT, UMD build from the official release), shipped with the plugin.
    wp_enqueue_script('devdbotp-chartjs', DEVDBOTP_URL . 'assets/chart-4.5.1.umd.min.js', array(), '4.5.1', true); // not under a vendor/ folder: the packager drops those
    $js = DEVDBOTP_DIR . 'assets/bp-admin.js';
    $jver = file_exists($js) ? filemtime($js) : DEVDBOTP_VERSION;
    wp_enqueue_script('devdbotp-admin', DEVDBOTP_URL . 'assets/bp-admin.js', array('devdbotp-chartjs'), $jver, true);
    if (function_exists('devdcorev1_error_report_assets')) {
        devdcorev1_error_report_assets(); // "Report this error" on the error banners (DESIGN.md 26)
    }
    $kjs = DEVDBOTP_DIR . 'assets/bp-knownbots.js';
    wp_enqueue_script('devdbotp-knownbots', DEVDBOTP_URL . 'assets/bp-knownbots.js', array(), file_exists($kjs) ? filemtime($kjs) : DEVDBOTP_VERSION, true);
}
add_action('admin_enqueue_scripts', 'devdbotp_enqueue_assets');

/** Info icon with the long explanation in a hover box; the visible hint stays one line (DESIGN.md 21.6). */
function devdbotp_tip($text)
{
    echo '<span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box">' . esc_html($text) . '</span></span>';
}

/** The section-16 save button: save icon first, indigo, identical in every plugin. */
function devdbotp_save_button($name, $label = 'Save Settings', $id = '')
{
    ?>
    <button type="submit" name="<?php echo esc_attr($name); ?>" value="1" class="dd-btn-primary" style="gap:8px;"<?php echo $id !== '' ? ' id="' . esc_attr($id) . '"' : ''; ?>>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
        <?php echo esc_html($label); ?>
    </button>
    <?php
}

/**
 * The tags and attributes this plugin's own markup fragments use (inline SVG icons, the autosave mark, the error
 * report button): the allow-list for wp_kses() wherever such a fragment is printed from a variable.
 */
function devdbotp_kses_allowed()
{
    return array(
        'svg'    => array('width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true),
        'path'   => array('d' => true),
        'circle' => array('cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true),
        'rect'   => array('x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true),
        'span'   => array('class' => true, 'style' => true, 'data-bp-rules-save' => true),
        'button' => array('type' => true, 'class' => true, 'data-ddc-report' => true),
    );
}

/** The plugin's own mark: a friendly robot head (antenna, ears, two eyes, a smile; stroke icon drawn for this plugin). The header badge uses 20 px, the Overview status circle 84 px. */
function devdbotp_icon_svg($size = 20)
{
    $size = (int) $size;
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.6v2.9"/><circle cx="12" cy="2.9" r="1.15" fill="currentColor" stroke="none"/><rect x="4.4" y="6.5" width="15.2" height="12.4" rx="3.6"/><path d="M2.4 11.2v3.2"/><path d="M21.6 11.2v3.2"/><circle cx="9.1" cy="11.7" r="1.45" fill="currentColor" stroke="none"/><circle cx="14.9" cy="11.7" r="1.45" fill="currentColor" stroke="none"/><path d="M9.4 15.4c1.6 1.1 3.6 1.1 5.2 0"/></svg>';
}

/**
 * The suite's sticky Save footer (DESIGN.md 16 + 16.1, Admin Cleaner devdadcl_save_footer 1:1): hidden until a field
 * differs from what is stored or right after a save; "Saved." and "N changes not saved" sit next to the button.
 */
function devdbotp_save_footer($name, $saved)
{
    ?>
    <footer class="dd-footer" data-dd-autoshow="1"<?php if (!$saved) : ?> hidden<?php endif; ?>>
        <div class="dd-footer-inner">
            <div class="dd-footer-actions">
                <?php devdbotp_save_button($name); ?>
                <?php if ($saved) : ?><span class="dd-saved" style="color:#059669;font-weight:600;font-size:13px;">Saved.</span><?php endif; ?>
                <span class="dd-pending" hidden style="color:#b91c1c;font-weight:600;font-size:13px;" data-one="1 change not saved" data-many="%d changes not saved"></span>
            </div>
        </div>
    </footer>
    <?php
}

/** Server-verified account state from the shared core: {ok, account_id, email, plan}. */
function devdbotp_account_state()
{
    if (function_exists('devdcorev1_connection_state')) {
        $s = devdcorev1_connection_state();
        return is_array($s) ? $s : array('ok' => 0);
    }
    return array('ok' => 0, 'account_id' => '', 'email' => '', 'plan' => '');
}

/** Section-18 badge: one word, one colour. Every echo of it goes through wp_kses(..., devdbotp_badge_allowed()). */
function devdbotp_badge($cls, $text)
{
    return '<span class="bp-badge bp-badge-' . esc_attr($cls) . '">' . esc_html($text) . '</span>';
}

/** What a badge may contain: a span with a class and nothing else. */
function devdbotp_badge_allowed()
{
    return array('span' => array('class' => array()));
}

/** Page-scoped CSS on top of the compiled bundle (DESIGN.md sections 14, 16, 18, 19, 20, 21). */
function devdbotp_inline_css()
{
    return <<<'CSS'
.dd-app .dd-tabpanel{display:none}.dd-app .dd-tabpanel.is-active{display:block}
.dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
.dd-app .dd-btn .dashicons, .dd-app .dd-btn-primary .dashicons { margin-right:5px; }
.dd-app .py-6 { padding-top:1.5rem; padding-bottom:1.5rem; }
/* Typed-word confirm (DESIGN.md 23, Country Blocker dc-typed 1:1). */
.dd-app .bp-typed-confirm { margin-top:8px; border-top:1px solid #f3f4f6; padding-top:14px; }
.dd-app .bp-typed-warn { margin:0 0 8px; font-weight:600; color:#374151; }
.dd-app .bp-typed-ask { margin:0 0 8px; color:#374151; }
.dd-app .bp-typed-input { max-width:240px; margin-bottom:10px; }
.dd-app .bp-typed-btns { display:flex; gap:10px; }
/* Autosave pill on Overview (Known Bots .bp-autosave 1:1). */
.dd-app .bp-autosave { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; color:#6b7280; }
.dd-app .bp-autosave.saved { color:#059669; }
.dd-app .bp-autosave.error { color:#b91c1c; }
.dd-app .bp-autosave svg { width:14px; height:14px; }
.dd-app .bp-autosave .bp-spin { display:inline-block; width:12px; height:12px; border:2px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:bpkspin .7s linear infinite; }
/* Known Bots actions in the header: only while that tab is open. */
.dd-app .bp-kb-headbtns, .dd-app .bp-set-headbtns, .dd-app .bp-rules-headbtns { display:none; align-items:center; gap:10px; }
.dd-app[data-bp-tab="knownbots"] .bp-kb-headbtns, .dd-app[data-bp-tab="settings"] .bp-set-headbtns, .dd-app[data-bp-tab="rules"] .bp-rules-headbtns { display:inline-flex; }
.dd-app .dd-modal-box.is-compact { width:min(420px, calc(100vw - 32px)); }
.dd-app .bp-kb-headbtns .bp-round { border:1px solid #dadce0; color:#5f6368; border-radius:50px; } /* the bug button's exact ring (bp-knownbots.css sets the ring from a variable that only exists inside the tab) */
.dd-app .bp-kb-headbtns .bp-round:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
/* Header bug-report button: exact twin of the DevDome dashboard button. */
.dd-app .bp-bug-btn { width:36px; height:36px; border-radius:50px; border:1px solid #dadce0; background:#fff; display:grid; place-items:center; cursor:pointer; color:#5f6368; transition:all .2s; text-decoration:none; }
.dd-app .bp-bug-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
.dd-app .bp-bug-btn svg { width:16px; height:16px; }
.dd-app .bp-bug-btn:focus { outline:none; box-shadow:none; }
.dd-app .bp-head-btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 14px; border-radius:50px; border:1px solid #dadce0; background:#fff; color:#5f6368; font-size:12.5px; font-weight:600; text-decoration:none; cursor:pointer; font-family:inherit; }
.dd-app .bp-head-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
.dd-app .bp-head-btn .dashicons { font-size:15px; width:15px; height:15px; }
.dd-app .dd-pill-crit { color:#b91c1c; background:#fef2f2; border-color:#fecaca; }
.dd-app .dd-pill-info { color:#3730a3; background:#eef2ff; border-color:#c7d2fe; }
.dd-app .dd-banner { border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; font-size:13px; margin:0 0 16px; display:flex; align-items:center; gap:10px; }
.dd-app .dd-banner .dashicons { font-size:16px; width:16px; height:16px; flex:none; }
.dd-app .dd-banner-ok { border-color:#a7f3d0; background:#ecfdf5; color:#065f46; }
.dd-app .dd-banner-err { border-color:#fecaca; background:#fef2f2; color:#b91c1c; }
.dd-app .dd-banner-watch { border-color:#c7d2fe; background:#eef2ff; color:#3730a3; }
/* Overview hero = the Malware Scanner card: ring left, verdict right, action row, tiles below. */
.dd-app .bp-verdict { display:flex; flex-wrap:wrap; align-items:center; gap:24px; }
.dd-app .bp-verdict-txt { flex:1; min-width:260px; }
.dd-app .bp-verdict-h { display:block; margin:0 0 4px; font-size:1.125rem; line-height:1.75rem; font-weight:600; color:#1f2937; }
.dd-app .bp-verdict-p { display:block; font-size:13px; color:#374151; margin:0 0 14px; }
.dd-app .bp-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:16px; }
.dd-app .bp-actions { flex-wrap:wrap; width:100%; margin:0; }
.dd-app .bp-actions > .dd-btn, .dd-app .bp-actions > .dd-btn-primary, .dd-app .bp-actions > .bp-live-btn { flex:0 0 auto; min-width:180px; justify-content:center; font-size:12.5px; line-height:1.25rem; padding-left:14px; padding-right:14px; white-space:nowrap; }
.dd-app .bp-live-btn { display:inline-flex; align-items:center; gap:8px; background:#10b981; color:#fff; padding:10px 22px; border-radius:8px; font-size:14px; font-weight:700; border:0; cursor:pointer; box-shadow:0 10px 15px -3px rgba(16,185,129,.3), 0 4px 6px -4px rgba(16,185,129,.3); }
.dd-app .bp-live-btn:hover { background:#059669; }
.dd-app .bp-live-btn .dashicons { font-size:16px; width:16px; height:16px; }
.dd-app .dd-btn:disabled, .dd-app .dd-btn-primary:disabled { opacity:.45; cursor:not-allowed; }
.dd-app .dd-stat-sub { font-size:11px; color:#374151; margin-top:2px; }
.dd-app .bp-stats-5 { grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin-top:18px; }
.dd-app .bp-stats-5 .dd-stat { padding:14px 12px; }
.dd-app .bp-stats-5 .dd-stat-num { font-size:1.5rem; }
@media (max-width:1100px) { .dd-app .bp-stats-5 { grid-template-columns:repeat(auto-fit,minmax(118px,1fr)); } }
.dd-app .bp-check-list { margin:18px 0 0; padding:0; list-style:none; border-top:1px solid #f3f4f6; }
.dd-app .bp-check-list li { display:flex; gap:12px; align-items:flex-start; padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:13px; color:#111827; }
.dd-app .bp-check-list li:last-child { border-bottom:0; }
.dd-app .bp-check-dot { width:10px; height:10px; border-radius:50%; margin-top:5px; flex:none; background:#10b981; }
.dd-app .bp-check-dot.is-todo { background:#f59e0b; }
.dd-app .bp-check-dot.is-bad { background:#ef4444; }
.dd-app .bp-check-list .dd-btn { flex:none; margin-left:auto; align-self:center; }
/* Filter pills = Safe Media Cleaner .mc-pill-btn twin (DESIGN.md section 19). */
.dd-app .bp-pills { display:inline-flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
.dd-app .bp-pill { display:inline-flex; align-items:center; gap:7px; border:1px solid #e5e7eb; background:#fff; padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:600; color:#374151; cursor:pointer; white-space:nowrap; font-family:inherit; line-height:1.4; text-decoration:none; }
.dd-app .bp-pill:hover { border-color:#c7d2fe; color:#4338ca; }
.dd-app .bp-pill.is-on { background:#eef2ff; border-color:#c7d2fe; color:#4338ca; }
.dd-app .bp-pill:focus { outline:none; box-shadow:none; }
.dd-app .bp-pill-num { font-weight:700; color:#4b5563; }
.dd-app .bp-pill.is-on .bp-pill-num { color:#4f46e5; }
.dd-app .bp-toolbar-row { margin:0 0 12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.dd-app .bp-toolbar-row .bp-pills { margin:0; }
.dd-app .bp-search { width:240px; height:34px; font-size:13px; margin-left:auto; }
/* Data lists = DESIGN.md section 19 (.dd-list): the Analytics dashboard table, 1:1. */
.dd-app .dd-list-wrap { overflow-x:auto; border-top:1px solid #e5e7eb; }
.dd-app .dd-list { width:100%; border-collapse:separate; border-spacing:0; }
.dd-app .dd-list thead th { width:auto; background:#f9fafb; padding:8px 12px; text-align:left; font-size:12px; line-height:16px; font-weight:500; color:#374151; letter-spacing:.05em; white-space:nowrap; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; text-transform:none; }
.dd-app .dd-list tbody td { padding:10px 12px; font-size:13px; line-height:18px; color:#374151; vertical-align:middle; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
.dd-app .dd-list th:last-child, .dd-app .dd-list td:last-child { border-right:0; }
.dd-app .dd-list tbody tr:last-child td { border-bottom:0; }
.dd-app .dd-list tbody tr:hover td { background:#f8fbff; }
.dd-app .dd-list code, .dd-app .bp-mono { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; color:#4b5563; word-break:break-all; white-space:normal; background:transparent; border:0; padding:0; margin:0; }
.dd-app .bp-title { display:block; font-weight:600; color:#111827; }
.dd-app .bp-meta { display:block; margin-top:3px; font-size:12px; color:#374151; white-space:normal; }
.dd-app .bp-col-pill { width:1%; white-space:nowrap; padding-right:10px; vertical-align:top; }
.dd-app .bp-col-pill code { white-space:nowrap; word-break:normal; }
.dd-app .bp-col-num { width:1%; white-space:nowrap; font-weight:700; color:#111827; }
.dd-app .bp-list-card { padding:0; overflow:hidden; }
.dd-app .bp-list-card .dd-list-wrap { border-top:0; }
.dd-app .bp-act-row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
.dd-app .bp-act-row form { margin:0; }
/* Badges: section-18 colours, 10px bold uppercase pills. */
.dd-app .bp-badge { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; white-space:nowrap; }
.dd-app .bp-badge-bad { background:#fef2f2; color:#dc2626; }
.dd-app .bp-badge-warn { background:#fffbeb; color:#b45309; }
.dd-app .bp-badge-ok { background:#ecfdf5; color:#059669; }
.dd-app .bp-badge-info { background:#eef2ff; color:#4338ca; }
.dd-app .bp-badge-muted { background:#f3f4f6; color:#374151; }
.dd-app .bp-badge-purple { background:#f5f3ff; color:#6d28d9; }
/* Info icon with a hover box (Safe Media Cleaner twin). */
.dd-app .dd-tip { position:relative; display:inline-flex; align-items:center; vertical-align:middle; margin-left:4px; }
.dd-app .dd-tip .dashicons { cursor:help; color:#818cf8; font-size:15px; width:15px; height:15px; line-height:15px; }
.dd-app .dd-tip:hover .dashicons { color:#4f46e5; }
.dd-app .dd-tip-box { pointer-events:none; visibility:hidden; opacity:0; position:absolute; bottom:100%; left:50%; z-index:9999; margin-bottom:.5rem; width:max-content; max-width:280px; transform:translateX(-50%); border-radius:.5rem; border:1px solid #e0e7ff; background:#eef2ff; padding:.625rem; text-align:left; font-size:.75rem; line-height:1.625; font-weight:400; color:#3730a3; box-shadow:0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1); transition:opacity .15s; }
.dd-app .dd-tip:hover .dd-tip-box { visibility:visible; opacity:1; }
/* A hidden tooltip must not widen the page (sideways scroll on narrow screens): out of the layout until hovered. */
.dd-app .dd-tip .dd-tip-box { display:none; }
.dd-app .dd-tabs { overflow-x:auto; flex-wrap:nowrap; scrollbar-width:thin; } /* the tab bar scrolls inside itself, the page never does */
.dd-app .dd-tabs .dd-tab { white-space:nowrap; flex:none; }
.dd-app .dd-tip:hover .dd-tip-box { display:block; }
/* Activity = the DevDome dashboard, 1:1 (dashboard.html: metrics-grid + metric-card + axis dots + chart-box + date pills below). Owner 2026-09-28. */
.dd-app .metrics-grid { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; } /* 3 cards: content-sized like the dashboard's, they wrap, never stretch or scroll */
.dd-app .metric-card { background-color:#fff; padding:10px 12px; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,.08); transition:all .2s; border:2px solid transparent; display:flex; flex-direction:column; justify-content:center; position:relative; min-width:108px; }
.dd-app .metric-label { font-size:12px; font-weight:500; color:#5f6368; white-space:nowrap; line-height:1.25; padding-right:8px; min-height:0; display:flex; align-items:flex-start; padding-top:24px; margin:0; }
.dd-app .metric-value { font-size:20px; font-weight:600; white-space:nowrap; margin:0; }
.dd-app .axis-controls { position:absolute; top:5px; right:4px; display:flex; gap:4px; background:#f8f9fa; border-radius:12px; padding:2px 4px; border:1px solid #e0e0e0; z-index:10; }
.dd-app .axis-radio { appearance:none; -webkit-appearance:none; width:10px; height:10px; min-width:0; border-radius:50%; border:1.5px solid #9aa0a6; background-color:#fff; cursor:pointer; outline:none; transition:all .1s; position:relative; margin:0; box-shadow:none; }
.dd-app .axis-controls .axis-radio:checked::before, .dd-app .axis-controls .axis-radio::before { display:none; content:none; } /* WP core paints a white inner dot on checked radios */
.dd-app .axis-radio::after { content:""; position:absolute; width:24px; height:24px; top:50%; margin-top:-12px; }
.dd-app .axis-radio[name="bp_axis1"]::after { right:50%; margin-right:-7px; }
.dd-app .axis-radio[name="bp_axis2"]::after { left:50%; margin-left:-7px; }
.dd-app .axis-radio:hover { border-color:#5f6368; transform:scale(1.1); }
.dd-app .axis-radio:checked { border-color:var(--active-color); background-color:var(--active-color); }
.dd-app .chart-box { background:#fff; padding:16px; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,.08); margin-bottom:12px; position:relative; }
.dd-app .bp-chart-wrap { position:relative; height:320px; width:100%; }
.dd-app .bp-chart-tip { opacity:0; position:absolute; background:rgba(255,255,255,0.68); color:#3c4043; border:1px solid #dadce0; border-radius:4px; pointer-events:none; transform:translate(-50%,0); transition:opacity 0.1s ease; font-family:'Roboto',sans-serif; font-size:13px; white-space:nowrap; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,.1); padding:12px 16px; display:flex; flex-direction:column; gap:8px; min-width:180px; top:0; left:0; }
.dd-app .bp-tip-date { font-weight:400; color:#202124; font-size:16px; margin-bottom:4px; display:block; }
.dd-app .bp-tip-row { display:flex; justify-content:space-between; align-items:center; font-size:13px; color:#3c4043; gap:16px; }
.dd-app .bp-tip-label { display:flex; align-items:center; gap:8px; }
.dd-app .bp-tip-swatch { width:10px; height:10px; border-radius:2px; display:block; }
.dd-app .bp-tip-value { font-weight:600; color:#202124; }
.dd-app select.dd-input { padding-right:28px; } /* WP paints the arrow at the right edge; .dd-input's own padding ran the text under it */
/* Bots By Country rows = the dashboard's Countries table (side-table): flag + English name, numbers right-aligned tabular. */
.dd-app .bp-country-row td { font-size:13px; color:#3c4043; }
.dd-app .bp-country-row .flag-mini { display:inline-block; width:20px; height:15px; border-radius:2px; vertical-align:-2px; margin-right:8px; }
.dd-app .bp-country-row .country-name { font-weight:500; color:#1f1f1f; }
.dd-app .bp-country-row td.num { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; white-space:nowrap; }
/* Bots By Type: the same cards, 4 across (2 on a phone), no white wrapper, no dots so the title sits at the top. */
.dd-app .bp-types-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); }
.dd-app .bp-types-grid .metric-label { padding-top:0; }
@media (max-width:640px) { .dd-app .metric-card { padding:8px 10px; min-width:90px; } .dd-app .bp-types-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
/* Pagination bar, Link Monitor 1:1. */
.dd-app .bp-pagination { display:flex; flex-wrap:nowrap; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-top:1px solid #e5e7eb; }
.dd-app .bp-pagination:empty { display:none; }
.dd-app .bp-pg-count { white-space:nowrap; font-size:12.5px; color:#3c4043; }
.dd-app .bp-pg-right { display:flex; flex-wrap:nowrap; align-items:center; gap:12px; flex:none; }
.dd-app .bp-pg-per { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#3c4043; }
.dd-app .bp-pg-btns { display:flex; align-items:center; gap:4px; }
.dd-app .page-btn { min-width:30px; height:30px; padding:0 8px; border-radius:6px; border:1px solid #dadce0; background:#fff; color:#3c4043; font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .12s, border-color .12s, color .12s; }
.dd-app .page-btn:hover:not(:disabled) { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
.dd-app .page-btn.active { background:#1967d2; border-color:#1967d2; color:#fff; }
.dd-app .page-btn:disabled { color:#bdc1c6; cursor:not-allowed; }
.dd-app .page-btn:focus { outline:none; box-shadow:none; }
.dd-app .page-gap { color:#94a3b8; padding:0 4px; font-size:13px; user-select:none; }
.dd-app .page-jump-wrap { position:relative; display:block; }
.dd-app .page-jump-menu { position:absolute; bottom:calc(100% + 6px); right:0; width:78px; max-height:336px; overflow-y:auto; overscroll-behavior:contain; background:#fff; border:1px solid #dadce0; border-radius:10px; box-shadow:0 12px 32px rgba(15,23,42,.14), 0 2px 8px rgba(15,23,42,.06); padding:4px; z-index:40; display:flex; flex-direction:column; }
.dd-app .page-jump-menu.hidden { display:none; }
.dd-app .pjm-item { border:0; background:none; font-family:inherit; font-size:13px; font-weight:500; padding:8px 10px; border-radius:6px; cursor:pointer; color:#3c4043; text-align:center; flex:none; }
.dd-app .pjm-item:hover { background:#f8fafc; }
.dd-app .pjm-item.active { background:#e8f0fe; color:#1967d2; font-weight:700; }
/* Compact note box. */
.dd-app .bp-note { border:1px solid #e0e7ff; background:#f5f7ff; border-radius:10px; padding:12px 14px; }
.dd-app .bp-note-h { display:flex; align-items:flex-start; gap:8px; font-size:13px; font-weight:600; color:#1f2937; line-height:1.4; }
.dd-app .bp-note-h .dashicons { color:#4f46e5; font-size:18px; width:18px; height:18px; flex-shrink:0; margin-top:1px; }
.dd-app .bp-note-p { margin:6px 0 0 26px; font-size:12px; line-height:1.5; color:#4b5563; }
/* Setting rows: one rule per row, controls right (Rules, Bots and AI). */
.dd-app .bp-rule { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; padding:16px 0; border-top:1px solid #f3f4f6; }
.dd-app .bp-rule:first-child { border-top:0; padding-top:0; }
.dd-app .bp-rule-txt { flex:1; min-width:240px; }
.dd-app .bp-rule-name { display:block; font-size:13px; font-weight:700; color:#111827; }
.dd-app .bp-rule-count { display:inline-flex; align-items:center; gap:4px; margin-top:6px; font-size:12px; color:#111827; }
.dd-app .bp-rule-count b { font-weight:700; }
.dd-app .bp-rule-count.is-hot { color:#111827; } /* black text, the number bold (owner 2026-09-28): no orange */
.dd-app .bp-rule-count .dashicons { font-size:13px; width:13px; height:13px; }
.dd-app .bp-copy-row { display:flex; gap:8px; align-items:flex-start; margin-top:8px; }
.dd-app .bp-copy-row .dd-textarea, .dd-app .bp-copy-row .dd-input { flex:1; font-size:12px; }
.dd-app .bp-kv { display:flex; flex-wrap:wrap; gap:6px 14px; font-size:12.5px; color:#374151; }
.dd-app .bp-kv b { color:#111827; }
/* Readability (owner 2026-08-29): same sizes, darker text. */
.dd-app .text-gray-500, .dd-app .text-gray-600, .dd-app .text-gray-700, .dd-app .dd-td { color:#1f2937; }
.dd-app .text-gray-400 { color:#374151; }
.dd-app .dd-hint, .dd-app .description, .dd-app .dd-stat-lbl, .dd-app .dd-empty, .dd-app .bp-meta, .dd-app .dd-list thead th, .dd-app .dd-stat-sub, .dd-app .dd-donut-lbl, .dd-app .dd-preset-desc, .dd-app .dd-bar-lbl, .dd-app .dd-fstat-lbl { color:#374151; }
.dd-app .dd-th { color:#1f2937; }
.dd-app .dd-tab { color:#374151; }
.dd-app .dd-tab.is-active { color:#4338ca; }
.dd-app code { background:transparent; border:0; padding:0; margin:0; border-radius:0; font-size:12px; }
/* Sticky save footer = Safe Media Cleaner .dd-footer, 1:1. */
.dd-app .dd-footer { position:sticky; bottom:0; z-index:100; margin-top:2rem; border:1px solid #e5e7eb; border-radius:.75rem; background:#fff; box-shadow:0 1px 2px 0 rgba(0,0,0,.05); }
.dd-app .dd-footer-inner { display:flex; align-items:center; gap:1rem; padding:.75rem 1.25rem; }
.dd-app .dd-footer-actions { display:flex; flex-shrink:0; flex-wrap:wrap; align-items:center; gap:.625rem; }
/* Account gate strip = DESIGN.md section 15 (blue connect button, never the indigo save). */
.dd-app .dd-gate-strip { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin:0 0 16px; }
.dd-app .dd-cgo-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; font-size:14px; font-weight:600; border-radius:8px; padding:10px 20px; text-decoration:none; cursor:pointer; line-height:1; white-space:nowrap; transition:.12s; color:#fff; background:#2563eb; border:1px solid #2563eb; box-shadow:0 4px 10px -3px rgba(37,99,235,.5); }
.dd-app .dd-cgo-btn:hover { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.dd-app .dd-gate-note { font-size:13px; color:#374151; }
.dd-app .bp-frozen { opacity:.5; pointer-events:none; }
/* Dialog = DESIGN.md section 20 (.dd-modal). Above the admin bar. */
.dd-app .dd-modal { position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; }
.dd-app .dd-modal[hidden] { display:none; }
.dd-app .dd-modal-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.45); }
.dd-app .dd-modal-box { position:relative; width:min(560px, calc(100vw - 32px)); background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,.2), 0 8px 10px -6px rgba(0,0,0,.1); }
.dd-app .dd-modal-head { display:flex; align-items:center; gap:10px; padding:16px 20px; border-bottom:1px solid #f3f4f6; }
.dd-app .dd-modal-head h3 { margin:0; font-size:16px; font-weight:700; color:#1f2937; }
.dd-app .dd-modal-x { margin-left:auto; border:0; background:transparent; font-size:22px; line-height:1; color:#4b5563; cursor:pointer; padding:0 2px; }
.dd-app .dd-modal-x:hover { color:#374151; }
.dd-app .dd-modal-x:focus { outline:none; box-shadow:none; }
.dd-app .dd-modal-body { padding:20px; font-size:13px; line-height:1.6; color:#374151; }
.dd-app .dd-modal-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid #f3f4f6; }
/* DevDome account card = the DevDome Tools dashboard Connect card (core hub.php .ddh-conn), same values. */
.dd-app .bp-conn { display:flex; align-items:center; gap:16px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; margin-bottom:12px; box-shadow:0 1px 2px rgba(0,0,0,.05); flex-wrap:wrap; }
.dd-app .bp-conn-ci { display:inline-flex; align-items:center; justify-content:center; width:46px; height:46px; border-radius:12px; background:linear-gradient(150deg,#3b82f6,#2563eb 55%,#1d4ed8); box-shadow:0 7px 15px -6px rgba(37,99,235,.6); flex:none; color:#fff; font-weight:800; letter-spacing:-1px; font-size:18px; }
.dd-app .bp-conn-ci.is-ok { background:#10b981; box-shadow:none; }
.dd-app .bp-conn-body { flex:1 1 320px; min-width:0; }
.dd-app .bp-conn-body strong { display:block; font-size:14.5px; font-weight:700; color:#0f172a; }
.dd-app .bp-conn-body span { font-size:12.5px; color:#475569; line-height:1.45; }
.dd-app .bp-conn-body a { color:#2563eb; text-decoration:underline; }
.dd-app .bp-conn-ctl { display:flex; flex-direction:column; gap:8px; flex:0 1 272px; }
.dd-app .bp-conn-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; font-size:14px; font-weight:600; border-radius:8px; padding:10px 20px; text-decoration:none; cursor:pointer; line-height:1; border:1px solid transparent; white-space:nowrap; transition:.12s; }
.dd-app .bp-conn-solid { width:100%; padding:9px 14px; color:#fff; background:#2563eb; border-color:#2563eb; box-shadow:0 4px 10px -3px rgba(37,99,235,.5); }
.dd-app .bp-conn-solid:hover { background:#1d4ed8; border-color:#1d4ed8; }
.dd-app .bp-conn-ghost { color:#2563eb; background:#eaf1ff; border-color:#cfe0ff; }
.dd-app .bp-conn-ghost:hover { background:#2563eb; color:#fff; border-color:#2563eb; }
.dd-app .bp-conn-hint { font-size:11px; color:#8a94a6; text-align:center; margin:0; }
.dd-app .bp-conn-pill { display:inline-flex; align-items:center; gap:7px; flex:none; margin-left:auto; font-size:13px; font-weight:600; line-height:1; border-radius:999px; color:#15803d; background:#ecfdf5; border:1px solid #bbe7d4; padding:8px 14px 8px 12px; }
.dd-app .bp-conn-pill svg { width:14px; height:14px; color:#15a34a; }
/* Overview hero: a status symbol instead of a score, one state control, the emergency link folded away. */
/* Same outer size as the suite's score ring (.dd-donut, 160 px with a 12 px stroke). */
.dd-app .bp-status { width:160px; height:160px; border-radius:50%; display:grid; place-items:center; flex:none; border:12px solid #e5e7eb; box-sizing:border-box; transition:background-color .15s, border-color .15s; }
.dd-app .bp-status.is-ok { border-color:#a7f3d0; background:#10b981; color:#fff; }
.dd-app .bp-status.is-warn { border-color:#fde68a; background:#f59e0b; color:#fff; }
.dd-app .bp-status.is-bad { border-color:#fecaca; background:#ef4444; color:#fff; }
.dd-app .dd-footer[hidden], .dd-app .dd-saved[hidden], .dd-app .dd-pending[hidden] { display:none !important; }
.dd-app .bp-choices { display:flex; flex-wrap:wrap; gap:10px; }
.dd-app .bp-choice { display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; min-width:180px; padding:10px 14px; border-radius:8px; border:1px solid #d1d5db; background:#fff; color:#374151; font-size:12.5px; line-height:1.25rem; font-weight:600; cursor:pointer; white-space:nowrap; transition:all .15s; }
.dd-app .bp-choice:hover { border-color:#4f46e5; color:#4f46e5; }
.dd-app .bp-choice.is-on { background:#4f46e5; border-color:#4f46e5; color:#fff; box-shadow:0 4px 10px -3px rgba(79,70,229,.5); }
.dd-app .bp-choice input { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.dd-app .bp-choice:focus-within { outline:2px solid #c7d2fe; outline-offset:2px; }
.dd-app .dd-pending { font-size:13px; font-weight:600; color:#b45309; }
.dd-app #bp-verdict .dd-stats { margin-top:22px; }
.dd-app .bp-attention { margin-top:18px; padding-top:14px; border-top:1px solid #f3f4f6; }
.dd-app .bp-attention .bp-check-list { border-top:0; }
.dd-app .bp-crawl-group { margin-top:14px; }
.dd-app .bp-crawl-group .bp-check-list { border-top:0; }
.dd-app .bp-crawl-group .bp-check-list li { padding:7px 0; }
.dd-app .bp-intro { max-width:66%; margin-top:0; }
@media (max-width:782px) { .dd-app .bp-intro { max-width:100%; } }
.dd-app .bp-tech > summary { cursor:pointer; font-size:1.0625rem; font-weight:600; color:#1f2937; padding:6px 0; width:max-content; }
.dd-app .bp-tech[open] > summary { margin-bottom:10px; }
.dd-app .view-bp a.date-button { text-decoration:none; display:inline-block; }
.dd-app .view-bp a.date-button:focus { outline:none; box-shadow:none; }
/* Phones: nothing may widen the page (DESIGN.md 22.5). Last, so it wins over the rules above. */
@media (max-width:600px) { .dd-app .dd-footer-actions { flex-shrink:1; min-width:0; } .dd-app .dd-footer-actions .dd-hint { white-space:normal; min-width:0; } .dd-app .dd-seg { flex-wrap:wrap; max-width:100%; } .dd-app .dd-table .dd-th, .dd-app .dd-table .dd-td { display:block; width:auto; } }
CSS;
}

/* ----------------------------- page shell ------------------------------- */

/**
 * DESIGN.md 24: the page is built inside a guard window. A query that failed while it was built prints a red
 * banner (with "Report this error") instead of passing stale or empty data off as the real state. The page is
 * printed directly (never buffered and echoed), so the banner comes after it and bp-admin.js lifts it to the top.
 */
function devdbotp_render_page()
{
    devdbotp_db_guard_begin();
    devdbotp_render_page_inner();
    if (devdbotp_db_guard_failed()) : ?>
        <div class="dd-banner dd-banner-err" id="bp-guard-banner" style="margin:16px 24px 0;" role="alert"><span>A database query failed while this page was built, so what it shows may be incomplete or stale. Reload the page; if it keeps happening, check the database with your host.</span><?php if (function_exists('devdcorev1_error_report_button')) { echo wp_kses(devdcorev1_error_report_button('devdome-bot-protection', DEVDBOTP_VERSION, 'Bot Protection page built over a failed database query: ' . devdbotp_redact(devdbotp_db_guard_error()), 'Bot Protection page'), devdbotp_kses_allowed()); } ?></div>
    <?php endif;
    devdbotp_db_guard_end();
}

/**
 * Every Bot Protection form POST runs inside a guard window (DESIGN.md 24): devdbotp_update_setting()
 * refuses once a query failed, and the redirect that would say "saved" says "database error" instead.
 */
function devdbotp_guard_post_window()
{
    if (!empty($_POST) && isset($_GET['page']) && DEVDBOTP_PAGE === sanitize_key(wp_unslash($_GET['page']))) { // phpcs:ignore WordPress.Security.NonceVerification -- opens a read-only guard window; every handler verifies its own nonce
        devdbotp_db_guard_begin();
        add_filter('wp_redirect', 'devdbotp_guard_redirect', 1);
    }
}
add_action('admin_init', 'devdbotp_guard_post_window', 0);

function devdbotp_guard_redirect($location)
{
    if (!devdbotp_db_guard_active()) {
        return $location;
    }
    return add_query_arg('bp_dberr', '1', remove_query_arg(array('bp_saved', 'bp_cf', 'bp_imported', 'bp_imported_local', 'bp_feeds_refreshed', 'bp_feeds_failed'), $location));
}

function devdbotp_render_page_inner()
{
    $tab = isset($_GET['bp_tab']) ? sanitize_key($_GET['bp_tab']) : 'overview';
    if ($tab === 'protection') {
        $tab = 'overview'; // old links
    } elseif ($tab === 'threats') {
        $tab = 'activity'; // Threats became the lower half of Activity (0.4.1)
    } elseif ($tab === 'bots') {
        $tab = 'knownbots'; // the AI Crawlers tab became the AI Crawlers group of Known Bots (0.4.0)
    }
    if (!in_array($tab, array('overview', 'activity', 'rules', 'knownbots', 'settings'), true)) {
        $tab = 'overview';
    }
    $safe_until = (int) devdbotp_get_setting('safe_mode_until', 0);
    $safe_on    = $safe_until > time();
    $mode  = (string) devdbotp_get_setting('mode', 'watching');
    $level = (string) devdbotp_get_setting('protection_level', 'recommended');
    $acct  = devdbotp_account_state();
    $connected = !empty($acct['ok']);
    $dash_url  = 'https://analytics.devdome.com/';
    $blocked_today = (int) devdbotp_get_setting('stat_day_' . devdbotp_days_back(1)[0] . '_blocked', 0);

    $bp_tab_defs = array(
        'overview'   => array('label' => 'Overview',    'icon' => 'dashicons-dashboard',     'fn' => 'devdbotp_render_overview_tab'),
        'activity'   => array('label' => 'Activity',    'icon' => 'dashicons-chart-bar',     'fn' => 'devdbotp_render_activity_tab'),
        'rules'      => array('label' => 'Rules',       'icon' => 'dashicons-list-view',     'fn' => 'devdbotp_render_rules_tab'),
        'knownbots'  => array('label' => 'Known Bots',  'icon' => 'dashicons-visibility',    'fn' => 'devdbotp_render_knownbots_tab'),
        'settings'   => array('label' => 'Settings',    'icon' => 'dashicons-admin-generic', 'fn' => 'devdbotp_render_settings_tab'),
    );
    ?>
    <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]" data-bp-tab="<?php echo esc_attr($tab); ?>">
        <?php if (function_exists('devdcorev1_analytics_card')) { devdcorev1_analytics_card(); } ?>
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-5xl px-6 py-4 flex items-center gap-3" style="flex-wrap:wrap;">
                <div class="p-1.5 rounded text-white inline-flex" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><?php echo wp_kses(devdbotp_icon_svg(), devdbotp_kses_allowed()); ?></div>
                <h1 class="text-xl font-bold text-gray-800 m-0">Bot Protection</h1>
                <div style="margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <?php
                    // Header pills (DESIGN.md 21.1): one per thing the owner acts on.
                    if ($safe_on) : ?>
                        <span class="dd-pill dd-pill-safe"><span class="dashicons dashicons-clock" style="font-size:14px;width:14px;height:14px;"></span> Paused, <span id="bp-safe-countdown" data-remaining="<?php echo (int) max(0, $safe_until - time()); ?>">resumes soon</span></span>
                    <?php elseif ($level === 'off') : ?>
                        <span class="dd-pill dd-pill-crit"><span class="dashicons dashicons-dismiss" style="font-size:14px;width:14px;height:14px;"></span> Turned Off</span>
                    <?php elseif ($mode === 'live') : ?>
                        <span class="dd-pill dd-pill-ok"><span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span> Blocking Bad Bots<?php echo $blocked_today > 0 ? ', ' . (int) $blocked_today . ' blocked today' : ''; ?></span>
                    <?php endif; ?>
                    <?php if ($connected) : ?>
                        <a class="dd-btn-primary" href="<?php echo esc_url($dash_url); ?>" target="_blank" rel="noopener" style="padding:8px 16px;">View in DevDome</a>
                    <?php endif; ?>
                    <?php if ($safe_on) : ?>
                        <form method="post" style="margin:0;">
                            <?php wp_nonce_field('devdbotp_safe', '_bpsn'); ?>
                            <button type="submit" name="devdbotp_safe_action" value="resume" class="bp-head-btn"><span class="dashicons dashicons-controls-play"></span>Resume Now</button>
                        </form>
                    <?php endif; ?>
                    <a class="bp-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-bot-protection&v=' . DEVDBOTP_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="Report a bug" aria-label="Report a bug">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                    </a>
                    <?php if (function_exists('devdbotp_kb_head_buttons')) { devdbotp_kb_head_buttons(); } ?>
                    <span class="bp-rules-headbtns">
                        <button type="button" class="bp-bug-btn" id="bp-rules-rec" title="Use recommended: every rule back to its recommended action. Thresholds and switches stay as they are." aria-label="Use recommended rules"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg></button>
                    </span>
                    <span class="bp-set-headbtns">
                        <button type="button" class="bp-bug-btn" id="bp-set-export" title="Export settings" aria-label="Export settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg></button>
                        <button type="button" class="bp-bug-btn" id="bp-set-import" title="Import settings" aria-label="Import settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg></button>
                    </span>
                </div>
            </div>
            <div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist">
                <?php foreach ($bp_tab_defs as $slug => $def) : ?>
                    <a class="dd-tab <?php echo $tab === $slug ? 'is-active' : ''; ?>" href="#<?php echo esc_attr($slug); ?>" role="tab" data-dd-tab="<?php echo esc_attr($slug); ?>"><span class="dashicons <?php echo esc_attr($def['icon']); ?>"></span> <?php echo esc_html($def['label']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (!empty($_GET['bp_dberr'])) : $bp_dberr = 'A database query failed during this action. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.'; ?>
            <div class="dd-banner dd-banner-err" data-bp-flash="1" style="margin:16px 24px 0;" role="alert"><span><?php echo esc_html($bp_dberr); ?></span><?php if (function_exists('devdcorev1_error_report_button')) { echo wp_kses(devdcorev1_error_report_button('devdome-bot-protection', DEVDBOTP_VERSION, 'Database error during a Bot Protection action: ' . $bp_dberr, 'Bot Protection ' . $tab), devdbotp_kses_allowed()); } ?></div>
        <?php endif; ?>
        <main>
            <?php foreach ($bp_tab_defs as $slug => $def) : // Every tab renders up front: switching is instant, like the rest of the suite. ?>
                <div class="dd-tabpanel<?php echo $slug === $tab ? ' is-active' : ''; ?>" data-dd-panel="<?php echo esc_attr($slug); ?>" role="tabpanel">
                    <?php call_user_func($def['fn']); ?>
                </div>
            <?php endforeach; ?>
        </main>
        <div class="dd-modal" id="bp-modal" hidden role="dialog" aria-modal="true" aria-labelledby="bp-modal-title">
            <div class="dd-modal-backdrop" data-bp-modal-close></div>
            <div class="dd-modal-box">
                <div class="dd-modal-head"><h3 id="bp-modal-title">Please Confirm</h3><button type="button" class="dd-modal-x" data-bp-modal-close aria-label="Close">&times;</button></div>
                <div class="dd-modal-body" id="bp-modal-text"></div>
                <div class="dd-modal-foot"><button type="button" class="dd-btn" id="bp-modal-cancel" data-bp-modal-close>Cancel</button><button type="button" class="dd-btn-primary" id="bp-modal-ok">Confirm</button></div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Several settings as ONE save: the earlier values are read first (a failed read = nothing written), every write is
 * checked and read back, and on any failure the earlier values go back in a new error window. Returns whether every
 * value is stored as requested.
 */
function devdbotp_settings_batch($pairs)
{
    global $wpdb;
    if (!devdbotp_settings_batch_recover()) {
        return false; // an earlier batch's restore is not done yet
    }
    unset($GLOBALS['devdbotp_cache']);
    $before = array();
    foreach ($pairs as $name => $v) {
        $before[$name] = devdbotp_get_setting($name, null);
    }
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false;
    }
    // The before-values (and the after-values) are stored and read back BEFORE the first write, so a failed restore
    // is finished later, and a record found next to the after-values is known to be a committed batch.
    $record = array('before' => $before, 'after' => $pairs);
    devdbotp_update_setting('settings_batch_undo', $record);
    unset($GLOBALS['devdbotp_cache']);
    if (devdbotp_get_setting('settings_batch_undo', null) !== $record) {
        return false;
    }
    $ok = true;
    foreach ($pairs as $name => $v) {
        if (!devdbotp_update_setting($name, $v)) {
            $ok = false;
            break;
        }
    }
    unset($GLOBALS['devdbotp_cache']);
    foreach ($ok ? $pairs : array() as $name => $v) {
        if ((string) devdbotp_get_setting($name, '') !== (string) $v) {
            $ok = false;
        }
    }
    if (!$ok) {
        devdbotp_db_guard_rebase();
        devdbotp_settings_batch_recover(); // verified; the record stays when a value could not be put back
        return false;
    }
    devdbotp_update_setting('settings_batch_undo', ''); // done: the record goes; if this fails, recover() finds the after-values in place and drops it
    return true;
}

/** Put back the before-values of a batch that failed; the record goes only when every value reads back. */
function devdbotp_settings_batch_recover()
{
    global $wpdb;
    unset($GLOBALS['devdbotp_cache']);
    $record = devdbotp_get_setting('settings_batch_undo', '');
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false;
    }
    if (!is_array($record) || !isset($record['before'], $record['after']) || !is_array($record['before']) || !is_array($record['after'])) {
        return true; // nothing pending
    }
    $before    = $record['before'];
    $committed = true;
    foreach ($record['after'] as $name => $v) {
        if ((string) devdbotp_get_setting($name, '') !== (string) $v) {
            $committed = false;
        }
    }
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false;
    }
    if ($committed) { // the batch went through, only its record stayed: never put the old values over it
        devdbotp_update_setting('settings_batch_undo', '');
        unset($GLOBALS['devdbotp_cache']);
        return '' === (string) devdbotp_get_setting('settings_batch_undo', '');
    }
    foreach ($before as $name => $v) {
        if ($v === null) {
            $wpdb->delete(devdbotp_settings_table(), array('setting_name' => $name)); // it did not exist before
        } else {
            devdbotp_update_setting($name, $v);
        }
    }
    unset($GLOBALS['devdbotp_cache']);
    foreach ($before as $name => $v) {
        if (devdbotp_get_setting($name, null) !== $v) {
            return false; // not back yet: the record stays
        }
    }
    devdbotp_update_setting('settings_batch_undo', '');
    unset($GLOBALS['devdbotp_cache']);
    return '' === (string) devdbotp_get_setting('settings_batch_undo', '');
}

/** Save handler for the Protection tab (self-POST, nonce + cap guarded). */
function devdbotp_handle_save()
{
    if (empty($_POST['devdbotp_save']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('devdbotp_save', '_bpn');

    // off | watch | block. Off keeps the stored mode, so switching back on returns to where it was.
    $state = isset($_POST['bp_state']) ? sanitize_key(wp_unslash($_POST['bp_state'])) : '';
    if (!in_array($state, array('off', 'watch', 'block'), true)) {
        if (!empty($_POST['bp_ajax'])) {
            wp_send_json(array('success' => false, 'error' => 'no state')); // a request without a choice never counts as saved
        }
        wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE), admin_url('admin.php')));
        exit;
    }
    if ($state === 'off') {
        $ok = false !== devdbotp_set_site_state(array('on' => false));
    } else {
        $ok = false !== devdbotp_set_site_state(array('on' => true, 'mode' => $state === 'block' ? 'live' : 'watching'));
    }
    // Saved means the stored state IS the requested one: the setter's refusal (another request held the lock) and the guard's
    // failed writes both count, and the values are read back.
    unset($GLOBALS['devdbotp_cache']);
    $off_now = devdbotp_get_setting('protection_level', 'recommended') === 'off';
    $ok = $ok && !devdbotp_db_guard_failed() && ($state === 'off' ? $off_now : (!$off_now && devdbotp_get_setting('mode', 'watching') === ($state === 'block' ? 'live' : 'watching')));

    if (!empty($_POST['bp_ajax'])) { // the Overview saves the choice the moment it is clicked
        wp_send_json(array('success' => $ok));
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, $ok ? 'bp_saved' : 'bp_dberr' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdbotp_handle_save');

/** Safe Mode kill-switch: pause protection for 30 min, or resume now (nonce + cap). */
function devdbotp_handle_safemode()
{
    if (empty($_POST['devdbotp_safe_action']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('devdbotp_safe', '_bpsn');
    $action = sanitize_text_field(wp_unslash($_POST['devdbotp_safe_action']));
    $ok = false;
    if ($action === 'activate') {
        $until = time() + 1800;
        $ok    = false !== devdbotp_set_site_state(array('pause_until' => $until));
        unset($GLOBALS['devdbotp_cache']);
        $ok = $ok && (int) devdbotp_get_setting('safe_mode_until', 0) === $until;
    } elseif ($action === 'resume') {
        $ok = false !== devdbotp_set_site_state(array('pause_until' => 0));
        unset($GLOBALS['devdbotp_cache']);
        $ok = $ok && (int) devdbotp_get_setting('safe_mode_until', 0) === 0;
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, $ok ? 'bp_saved' : 'bp_dberr' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdbotp_handle_safemode');


/** Rules tab: store each built-in rule's chosen action (nonce + cap). */
function devdbotp_handle_rules_save()
{
    if (empty($_POST['devdbotp_rules_save']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('devdbotp_rules', '_bprn');

    $valid = array('allow', 'log', 'block');
    $actions = array();
    foreach (devdbotp_rules_catalog() as $rule) {
        $field = 'bp_rule_' . $rule['key'];
        $v = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : $rule['default'];
        $actions[$rule['key']] = in_array($v, $valid, true) ? $v : $rule['default'];
    }
    $actions_before = devdbotp_kb_rule_actions_out();
    $ten = array('rate_limit_action', 'velocity_action', 'repeat_offender_action', 'repeat_offender_threshold', 'behavior_action', 'fleet_enabled', 'fleet_action', 'honeypot_action', 'honeypot_enabled', 'honeypot_asn_min_ips');
    $ten_before = array();
    foreach ($ten as $name) {
        $ten_before[$name] = devdbotp_get_setting($name, null);
    }
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        if (!empty($_POST['bp_ajax'])) {
            wp_send_json(array('success' => false)); // the values to fall back to could not be read: nothing is written
        }
        wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'rules', 'bp_dberr' => '1'), admin_url('admin.php')));
        exit;
    }
    $ok = false !== devdbotp_set_site_state(array('rule_actions' => $actions)); // saved and sent to the dashboard in one place

    $rl  = isset($_POST['bp_rate_action']) ? sanitize_text_field(wp_unslash($_POST['bp_rate_action'])) : 'block';
    $vel = isset($_POST['bp_velocity_action']) ? sanitize_text_field(wp_unslash($_POST['bp_velocity_action'])) : 'log';
    $ro  = isset($_POST['bp_repeat_action']) ? sanitize_text_field(wp_unslash($_POST['bp_repeat_action'])) : 'block';
    $rt  = isset($_POST['bp_repeat_threshold']) ? (int) $_POST['bp_repeat_threshold'] : 10;
    $beh = isset($_POST['bp_behavior_action']) ? sanitize_text_field(wp_unslash($_POST['bp_behavior_action'])) : 'block';
    $fa  = isset($_POST['bp_fleet_action']) ? sanitize_text_field(wp_unslash($_POST['bp_fleet_action'])) : 'log';
    $hpa = isset($_POST['bp_honeypot_action']) ? sanitize_text_field(wp_unslash($_POST['bp_honeypot_action'])) : 'log';
    $hk  = isset($_POST['bp_honeypot_min_ips']) ? (int) $_POST['bp_honeypot_min_ips'] : 3;
    $three = array('off', 'log', 'block');
    // The ten plain settings as ONE save: written, read back, and put back on any failure (devdbotp_settings_batch).
    $ok = $ok && devdbotp_settings_batch(array(
        'rate_limit_action'        => in_array($rl, $three, true) ? $rl : 'block',
        'velocity_action'          => in_array($vel, $three, true) ? $vel : 'log',
        'repeat_offender_action'   => in_array($ro, $three, true) ? $ro : 'block',
        'repeat_offender_threshold' => max(2, min(1000, $rt)),
        'behavior_action'          => in_array($beh, $three, true) ? $beh : 'block',
        'fleet_enabled'            => isset($_POST['bp_fleet_enabled']) ? 1 : 0,
        'fleet_action'             => in_array($fa, $three, true) ? $fa : 'log',
        'honeypot_action'          => in_array($hpa, $three, true) ? $hpa : 'log',
        'honeypot_enabled'         => isset($_POST['bp_honeypot_enabled']) ? 1 : 0,
        'honeypot_asn_min_ips'     => max(2, min(50, $hk)),
    ));
    $batched = $ok;
    if ($ok && function_exists('devdbotp_rebuild_bad_ips')) {
        $ok = devdbotp_rebuild_bad_ips();
    }
    if (!$ok) {
        devdbotp_db_guard_rebase();
        if ($batched) {
            devdbotp_settings_batch(array_filter($ten_before, function ($v) { return $v !== null; })); // the ten go back when only the rebuild failed
        }
        devdbotp_set_site_state(array('rule_actions' => $actions_before)); // the six actions go back with the rest
    }

    unset($GLOBALS['devdbotp_cache']);
    $stored = function_exists('devdbotp_kb_rule_actions_out') ? devdbotp_kb_rule_actions_out() : devdbotp_rule_actions();
    foreach ($actions as $key => $v) {
        if (!isset($stored[$key]) || $stored[$key] !== $v) {
            $ok = false; // the six rule actions must read back as sent
        }
    }
    $ok = $ok && !devdbotp_db_guard_failed(); // the accumulated guard (DESIGN.md 24), not the last query only
    if (!empty($_POST['bp_ajax'])) { // the Rules tab autosaves (Known Bots style)
        wp_send_json(array('success' => $ok));
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'rules', $ok ? 'bp_saved' : 'bp_dberr' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdbotp_handle_rules_save');

/** Custom-rule add / delete / toggle (own nonce; redirects back to the Rules tab). */
function devdbotp_handle_custom_rule()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $do = false;
    $ok = true;
    if (!empty($_POST['devdbotp_custom_add'])) {
        check_admin_referer('devdbotp_custom', '_bpcn');
        $ok = 0 < (int) devdbotp_add_custom_rule(
            isset($_POST['bp_cr_field']) ? sanitize_text_field(wp_unslash($_POST['bp_cr_field'])) : '',
            isset($_POST['bp_cr_op']) ? sanitize_text_field(wp_unslash($_POST['bp_cr_op'])) : '',
            isset($_POST['bp_cr_pattern']) ? sanitize_text_field(wp_unslash($_POST['bp_cr_pattern'])) : '',
            isset($_POST['bp_cr_action']) ? sanitize_text_field(wp_unslash($_POST['bp_cr_action'])) : ''
        );
        $do = true;
    } elseif (!empty($_POST['devdbotp_custom_delete'])) {
        check_admin_referer('devdbotp_custom', '_bpcn');
        $ok = devdbotp_delete_custom_rule(isset($_POST['bp_cr_id']) ? (int) $_POST['bp_cr_id'] : 0);
        $do = true;
    } elseif (!empty($_POST['devdbotp_custom_toggle'])) {
        check_admin_referer('devdbotp_custom', '_bpcn');
        $ok = devdbotp_toggle_custom_rule(
            isset($_POST['bp_cr_id']) ? (int) $_POST['bp_cr_id'] : 0,
            !empty($_POST['bp_cr_enabled'])
        );
        $do = true;
    }
    if ($do) {
        wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'rules', $ok ? 'bp_saved' : 'bp_dberr' => '1'), admin_url('admin.php')));
        exit;
    }
}
add_action('admin_init', 'devdbotp_handle_custom_rule');

/**
 * Honeypot: unflag one network (per-row "Unflag" / "Never flag this network") (own nonce + cap).
 * Its own unique nonce action/field pair — NOT the rules-save nonce — per the security contract.
 */
function devdbotp_handle_honeypot_unflag()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (empty($_POST['bp_honeypot_unflag']) && empty($_POST['bp_honeypot_safelist'])) {
        return;
    }
    check_admin_referer('devdbotp_honeypot', '_bphn');

    $ok = false;
    if (!empty($_POST['bp_honeypot_safelist'])) {
        $asn = isset($_POST['bp_honeypot_safelist']) ? (int) sanitize_text_field(wp_unslash($_POST['bp_honeypot_safelist'])) : 0;
        $ok  = function_exists('devdbotp_honeypot_isp_safelist_add') && devdbotp_honeypot_isp_safelist_add($asn); // safelists AND unflags, both read back inside
    } else {
        $asn = isset($_POST['bp_honeypot_unflag']) ? (int) sanitize_text_field(wp_unslash($_POST['bp_honeypot_unflag'])) : 0;
        $ok  = function_exists('devdbotp_honeypot_unflag') && false !== devdbotp_honeypot_unflag($asn);
    }
    unset($GLOBALS['devdbotp_cache']);
    $flagged = function_exists('devdbotp_asn_honeypot_flagged') && devdbotp_asn_honeypot_flagged($asn);
    $ok = $ok && !$flagged && !devdbotp_db_guard_failed(); // Saved = the network is not flagged any more

    wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'rules', $ok ? 'bp_saved' : 'bp_dberr' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdbotp_handle_honeypot_unflag');

/** The portable config keys for Import/Export (excludes stats, feeds, secrets, runtime). */
function devdbotp_config_keys()
{
    return array('protection_level', 'mode', 'rule_actions', 'known_bots_blocked', 'anonymize_ips', 'timezone',
        'rate_limit_action', 'velocity_action', 'repeat_offender_action', 'repeat_offender_threshold', 'behavior_action',
        'honeypot_enabled', 'honeypot_action', 'honeypot_asn_min_ips', 'fleet_action', 'custom_rules'); // Outside Lookups and Bot Radar are consent: never imported
}

/** The undo set of a running import: the earlier values and the rule ids added so far, stored and read back. */
function devdbotp_import_undo_store($before, $added)
{
    $set = array('at' => time(), 'before' => $before, 'added' => array_values(array_map('intval', $added)));
    devdbotp_update_setting('import_undo', $set);
    unset($GLOBALS['devdbotp_cache']);
    $kept = devdbotp_get_setting('import_undo', null);
    return is_array($kept) && isset($kept['before'], $kept['added']) && $kept['before'] === $set['before'] && $kept['added'] === $set['added'];
}

function devdbotp_import_undo_clear()
{
    devdbotp_update_setting('import_undo', '');
    unset($GLOBALS['devdbotp_cache']);
    return '' === (string) devdbotp_get_setting('import_undo', '');
}

/**
 * Put back what a failed import changed: every earlier value through apply_config (each read back), every rule it
 * added deleted (each checked). Only a fully verified undo drops the set; otherwise it stays for the next attempt.
 */
function devdbotp_import_recover()
{
    $kept = devdbotp_get_setting('import_undo', null);
    if (!is_array($kept) || !isset($kept['before']) || !is_array($kept['before'])) {
        return empty($GLOBALS['devdbotp_settings_read_failed']) && !devdbotp_db_guard_failed(); // nothing to undo, unless the read itself failed
    }
    $done = true;
    $kept['added'] = array_values(array_unique(array_merge((array) ($kept['added'] ?? array()), (array) ($GLOBALS['devdbotp_import_added'] ?? array())))); // this request's own additions too
    foreach ($kept['before'] as $k => $v) {
        if (!in_array($k, devdbotp_config_keys(), true)) {
            continue; // a key an older build exported (the consent switches) is not portable any more: nothing to put back
        }
        if (!devdbotp_import_valid($k, $v) || !devdbotp_apply_config($k, $v)) {
            $done = false;
            continue;
        }
        unset($GLOBALS['devdbotp_cache']);
        if (devdbotp_config_current($k) != $v) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- ints vs numeric strings of the same value
            $done = false;
        }
    }
    // Every custom rule the import added goes: the recorded ids, and (the record can be short when a write failed
    // mid-way) any rule that did not exist before the import started.
    $rows = devdbotp_all_custom_rules();
    if (!empty($GLOBALS['devdbotp_rules_read_failed'])) {
        return false; // cannot tell what is there: the set stays
    }
    $added = array_map('intval', (array) ($kept['added'] ?? array())); // every id the import added is recorded the moment it is added; nothing else is touched
    foreach (array_unique($added) as $rid) {
        $still = false;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $rid) { $still = true; }
        }
        if ($still && !devdbotp_delete_custom_rule($rid)) {
            $done = false;
        }
    }
    return $done && !devdbotp_db_guard_failed() && devdbotp_import_undo_clear();
}

/** Is this imported value one apply_config would store as it is? No writes. */
function devdbotp_import_valid($key, $val)
{
    switch ($key) {
        case 'protection_level': return in_array($val, array('off', 'recommended'), true);
        case 'mode':             return in_array($val, array('watching', 'live'), true);
        case 'anonymize_ips':
        case 'honeypot_enabled': return is_bool($val) || $val === 0 || $val === 1 || $val === '0' || $val === '1';
        case 'rate_limit_action':
        case 'velocity_action':
        case 'repeat_offender_action':
        case 'behavior_action':
        case 'honeypot_action':
        case 'fleet_action':     return in_array($val, array('off', 'log', 'block'), true);
        case 'repeat_offender_threshold': return is_numeric($val) && (int) $val >= 2 && (int) $val <= 1000;
        case 'honeypot_asn_min_ips':      return is_numeric($val) && (int) $val >= 2 && (int) $val <= 50; // the same range the Rules tab enforces
        case 'timezone':         return is_string($val) && ($val === '' || in_array($val, timezone_identifiers_list(), true));
        case 'rule_actions':
            if (!is_array($val)) { return false; }
            $keys = array();
            foreach (devdbotp_rules_catalog() as $rule) { $keys[$rule['key']] = 1; }
            foreach ($val as $rk => $rv) { if (!isset($keys[$rk]) || !in_array($rv, array('allow', 'log', 'block'), true)) { return false; } }
            return true;
        case 'known_bots_blocked':
            if (!is_array($val)) { return false; }
            return count(array_intersect(array_map('strval', $val), devdbotp_kb_all_ids())) === count($val);
        case 'custom_rules':
            if (!is_array($val) || count($val) > 200 || !function_exists('devdbotp_rule_field_ops')) { return false; }
            $field_ops = devdbotp_rule_field_ops();
            foreach ($val as $cr) {
                if (!is_array($cr) || empty($cr['field']) || empty($cr['operator']) || !isset($cr['pattern']) || empty($cr['action'])) { return false; }
                $f = sanitize_key($cr['field']); $o = sanitize_key($cr['operator']); $p = trim(sanitize_text_field((string) $cr['pattern']));
                if (!isset($field_ops[$f]) || !in_array($o, $field_ops[$f], true) || !in_array(sanitize_key($cr['action']), devdbotp_rule_actions_list(), true) || $p === '' || strlen($p) > 255) { return false; }
                if ($o === 'regex' && @preg_match('~' . str_replace('~', '\~', $p) . '~i', '') === false) { return false; }
            }
            return true;
    }
    return false;
}

/** Does the stored value equal what the import asked for? (scalar keys compared through the same normalisation) */
function devdbotp_import_matches($key, $val, $rules_too = false)
{
    unset($GLOBALS['devdbotp_cache']);
    $now = devdbotp_config_current($key);
    if ($key === 'custom_rules' && $rules_too) {
        // Every imported rule must still be there with the on / off state it was imported with (a 409 pull may have
        // replaced the list with the dashboard's).
        $have = array();
        foreach (devdbotp_all_custom_rules() as $r) {
            $have[sanitize_key($r['match_field']) . '|' . sanitize_key($r['operator']) . '|' . (string) $r['pattern'] . '|' . sanitize_key($r['action'])] = (int) $r['enabled'];
        }
        if (!empty($GLOBALS['devdbotp_rules_read_failed'])) {
            return false;
        }
        foreach ((array) $val as $cr) {
            if (!is_array($cr) || empty($cr['field'])) { continue; }
            $sig = sanitize_key($cr['field']) . '|' . sanitize_key($cr['operator']) . '|' . trim(sanitize_text_field((string) $cr['pattern'])) . '|' . sanitize_key($cr['action']);
            $want_on = !isset($cr['enabled']) || $cr['enabled'] ? 1 : 0;
            if (!isset($have[$sig]) || $have[$sig] !== $want_on) {
                return false;
            }
        }
        return true;
    }
    switch ($key) {
        case 'anonymize_ips':
        case 'honeypot_enabled':          return (int) $now === (empty($val) ? 0 : 1);
        case 'repeat_offender_threshold':
        case 'honeypot_asn_min_ips':      return (int) $now === (int) $val;
        case 'rule_actions':              foreach ((array) $val as $rk => $rv) { if (!isset($now[$rk]) || $now[$rk] !== $rv) { return false; } } return true;
        case 'known_bots_blocked':        return array_values((array) $now) === array_values(array_map('strval', (array) $val));
        case 'custom_rules':              return true; // added rules are checked one by one as they are added
        default:                          return (string) $now === (string) $val;
    }
}

/** The value a config key holds now, in the import shape (for the undo of a failed import). */
function devdbotp_config_current($key)
{
    switch ($key) {
        case 'protection_level':  return devdbotp_get_setting('protection_level', 'recommended') === 'off' ? 'off' : 'recommended';
        case 'mode':              return devdbotp_get_setting('mode', 'watching') === 'live' ? 'live' : 'watching';
        case 'rule_actions':      return devdbotp_kb_rule_actions_out();
        case 'known_bots_blocked': return array_values(devdbotp_kb_blocked());
        case 'anonymize_ips':
        case 'honeypot_enabled':  return (int) devdbotp_get_setting($key, 0) === 1 ? 1 : 0;
        case 'repeat_offender_threshold': return max(2, min(1000, (int) devdbotp_get_setting($key, 10)));
        case 'honeypot_asn_min_ips':      return max(2, min(50, (int) devdbotp_get_setting($key, 3)));
        case 'timezone':          return (string) devdbotp_get_setting('timezone', '');
        case 'fleet_enabled':     return (int) devdbotp_get_setting('fleet_enabled', 0) === 1 ? 1 : 0;
        case 'velocity_action':
        case 'honeypot_action':
        case 'fleet_action':      return (string) devdbotp_get_setting($key, 'log');   // the same defaults the Rules tab uses
        default:                  return (string) devdbotp_get_setting($key, 'block');
    }
}

/** Validate + store one imported config value (mirrors the per-tab save validation). */
function devdbotp_apply_config($key, $val)
{
    $ok = true;
    switch ($key) {
        case 'protection_level':
            if (in_array($val, array('off', 'recommended'), true)) { $ok = false !== devdbotp_set_site_state(array('on' => $val !== 'off')); } else { $ok = false; } // the synchronised setter: the dashboard learns it; false = refused
            break;
        case 'mode':
            if (in_array($val, array('watching', 'live'), true)) { $ok = false !== devdbotp_set_site_state(array('mode' => $val)); } else { $ok = false; } // an unknown value is not "imported"
            break;
        case 'anonymize_ips':
        case 'honeypot_enabled':
            if (is_bool($val) || $val === 0 || $val === 1 || $val === '0' || $val === '1') { $ok = devdbotp_update_setting($key, empty($val) ? 0 : 1); } else { $ok = false; }
            break;
        case 'rate_limit_action':
        case 'velocity_action':
        case 'repeat_offender_action':
        case 'behavior_action':
        case 'honeypot_action':
        case 'fleet_action':
            if (in_array($val, array('off', 'log', 'block'), true)) { $ok = devdbotp_update_setting($key, $val); } else { $ok = false; }
            break;
        case 'repeat_offender_threshold':
            if (is_numeric($val) && (int) $val >= 2 && (int) $val <= 1000) { $ok = devdbotp_update_setting($key, (int) $val); } else { $ok = false; }
            break;
        case 'honeypot_asn_min_ips':
            if (is_numeric($val) && (int) $val >= 2 && (int) $val <= 50) { $ok = devdbotp_update_setting($key, (int) $val); } else { $ok = false; } // the Rules tab's range
            break;
        case 'timezone':
            if (is_string($val) && ($val === '' || in_array($val, timezone_identifiers_list(), true))) { $ok = false !== devdbotp_set_site_state(array('timezone' => $val)); } else { $ok = false; }
            break;
        case 'custom_rules': // added, never replaced: an import adds the rules it does not have yet (same field, operator, pattern, action)
            if (!is_array($val) || count($val) > 200) { $ok = false; break; } // more than the plugin holds: refused as a whole, nothing half-imported
            if (function_exists('devdbotp_add_custom_rule')) {
                $have = array();
                foreach (devdbotp_all_custom_rules() as $r) {
                    $have[$r['match_field'] . '|' . $r['operator'] . '|' . $r['pattern'] . '|' . $r['action']] = (int) $r['id'];
                }
                foreach ($val as $cr) {
                    if (!is_array($cr) || empty($cr['field']) || empty($cr['operator']) || !isset($cr['pattern']) || empty($cr['action'])) { $ok = false; continue; } // a malformed rule = not "imported"

                    $sig = sanitize_key($cr['field']) . '|' . sanitize_key($cr['operator']) . '|' . sanitize_text_field((string) $cr['pattern']) . '|' . sanitize_key($cr['action']);
                    if (isset($have[$sig])) { continue; }
                    $id = devdbotp_add_custom_rule(sanitize_key($cr['field']), sanitize_key($cr['operator']), sanitize_text_field((string) $cr['pattern']), sanitize_key($cr['action']), isset($cr['name']) ? sanitize_text_field((string) $cr['name']) : '');
                    if (!$id) { $ok = false; continue; } // invalid or refused (another request held the lock)
                    $GLOBALS['devdbotp_import_added'][] = (int) $id; // for the undo of a failed import: on record NOW
                    if (isset($GLOBALS['devdbotp_import_before']) && !devdbotp_import_undo_store($GLOBALS['devdbotp_import_before'], (array) $GLOBALS['devdbotp_import_added'])) {
                        devdbotp_delete_custom_rule((int) $id); // not recorded = not kept
                        $ok = false;
                        break;
                    }
                    if (isset($cr['enabled']) && !$cr['enabled'] && function_exists('devdbotp_toggle_custom_rule') && !devdbotp_toggle_custom_rule((int) $id, 0)) { $ok = false; }
                }
            }
            break;
        case 'rule_actions':
            if (is_array($val)) {
                $valid = array('allow', 'log', 'block');
                $out = array();
                foreach (devdbotp_rules_catalog() as $rule) {
                    if (isset($val[$rule['key']]) && in_array($val[$rule['key']], $valid, true)) {
                        $out[$rule['key']] = $val[$rule['key']];
                    }
                }
                if (count($out) !== count($val)) { $ok = false; } // an unknown rule or value = not "imported"
                if ($out) { $ok = (false !== devdbotp_set_site_state(array('rule_actions' => array_merge(devdbotp_kb_rule_actions_out(), $out)))) && $ok; } // the synchronised setter (reentrant under the import's lock)
            } else { $ok = false; }
            break;
        case 'known_bots_blocked':
            if (is_array($val)) {
                $ids = array_values(array_intersect(array_map('strval', $val), devdbotp_kb_all_ids()));
                if (count($ids) !== count($val)) { $ok = false; }
                devdbotp_kb_set_local($ids); // under the import's lock; marks pending + queues the push
                unset($GLOBALS['devdbotp_cache']);
                if (array_values(devdbotp_kb_blocked()) !== $ids) { $ok = false; }
            } else { $ok = false; }
            break;
    }
    return $ok;
}

function devdbotp_handle_clear_stats()
{
    if (empty($_POST['devdbotp_clear_stats'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to do this.', 'devdome-bot-protection'));
    }
    check_admin_referer('devdbotp_clear_stats', '_bpcl');
    $word = isset($_POST['bp_word']) && is_string($_POST['bp_word']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['bp_word'])))) : '';
    $back = function ($args) {
        wp_safe_redirect(add_query_arg(array_merge(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'settings'), $args), admin_url('admin.php')));
        exit;
    };
    if ('CLEAR' !== $word) {
        $back(array('bp_error' => 'confirm'));
    }
    devdbotp_db_guard_begin();
    $ok = devdbotp_clear_statistics();
    devdbotp_db_guard_end();
    $back($ok ? array('bp_cleared' => '1') : array('bp_error' => 'clear'));
}
add_action('admin_init', 'devdbotp_handle_clear_stats');

/** Settings tab: save privacy and the Outside Lookups consent, refresh feeds now, or import a config JSON. */
function devdbotp_handle_settings()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $back = function ($args) {
        wp_safe_redirect(add_query_arg(array_merge(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'settings'), $args), admin_url('admin.php')));
        exit;
    };

    if (!empty($_POST['devdbotp_settings_save'])) {
        check_admin_referer('devdbotp_settings', '_bpset');
        $tz = isset($_POST['bp_timezone']) ? sanitize_text_field(wp_unslash($_POST['bp_timezone'])) : '';
        $tz = in_array($tz, timezone_identifiers_list(), true) ? $tz : '';
        $tz_before = (string) devdbotp_get_setting('timezone', '');
        $ok = false !== devdbotp_set_site_state(array('timezone' => $tz)); // the dashboard learns it; false = refused (another request held the lock)
        unset($GLOBALS['devdbotp_cache']);
        $ok = $ok && !devdbotp_db_guard_failed() && (string) devdbotp_get_setting('timezone', '') === $tz;
        // The two flags as ONE save after the setter: written, read back, put back on any failure.
        $ok = $ok && devdbotp_settings_batch(array('anonymize_ips' => empty($_POST['bp_anonymize']) ? 0 : 1, 'outside_lookups' => empty($_POST['bp_outside_lookups']) ? 0 : 1));
        if (!$ok) {
            devdbotp_db_guard_rebase();
            devdbotp_set_site_state(array('timezone' => $tz_before)); // nothing of this save stays
        }
        $back(array($ok ? 'bp_saved' : 'bp_dberr' => '1'));
    }

    if (!empty($_POST['devdbotp_feed_refresh'])) {
        check_admin_referer('devdbotp_settings', '_bpset');
        $ok = devdbotp_outside_lookups_on() && function_exists('devdbotp_refresh_feeds') && devdbotp_refresh_feeds(); // a stale form while the switch is off: no download, no 'refreshed'
        $back(array($ok ? 'bp_feeds_refreshed' : 'bp_feeds_failed' => '1'));
    }

    if (!empty($_POST['devdbotp_import'])) {
        check_admin_referer('devdbotp_settings', '_bpset');
        $json = isset($_POST['bp_import_json']) && is_string($_POST['bp_import_json']) ? sanitize_textarea_field(wp_unslash($_POST['bp_import_json'])) : ''; // JSON: the textarea sanitizer keeps quotes, braces and newlines
        $data = json_decode((string) $json, true);
        $known = is_array($data) ? count(array_intersect(array_keys($data), devdbotp_config_keys())) : 0;
        if (is_array($data) && $known > 0 && array_keys($data) !== range(0, count($data) - 1)) { // an object with at least one known key; "{}" or a list is not an export
            // 1. Every value is validated BEFORE anything is written: one bad value = nothing imported.
            foreach (devdbotp_config_keys() as $k) {
                if (array_key_exists($k, $data) && !devdbotp_import_valid($k, $data[$k])) {
                    $back(array('bp_import_err' => '1'));
                }
            }
            if (!function_exists('devdbotp_kb_lock') || !devdbotp_kb_lock(2)) {
                $back(array('bp_dberr' => '1')); // another request is syncing right now: nothing imported
            }
            $GLOBALS['devdbotp_push_deferred'] = true; // the setter marks pending instead of pushing per key
            $GLOBALS['devdbotp_import_added'] = array();
            $pushed = true;
            try {
                if (!devdbotp_import_recover()) {
                    $ok = false; // an earlier import's undo is not done yet: nothing new on top of it
                } else {
                // 2. The values as they are now, for the undo: STORED and read back before the first write, so they
                //    survive whatever fails later (the copy is dropped only after a verified import or a verified undo).
                $before = array();
                foreach (devdbotp_config_keys() as $k) {
                    if ($k !== 'custom_rules' && array_key_exists($k, $data)) {
                        $before[$k] = devdbotp_config_current($k);
                    }
                }
                $GLOBALS['devdbotp_import_before'] = $before;
                $ok = devdbotp_import_undo_store($before, array());
                foreach ($ok ? devdbotp_config_keys() : array() as $k) {
                    if (!array_key_exists($k, $data)) {
                        continue;
                    }
                    $applied = devdbotp_apply_config($k, $data[$k]);
                    if ($k === 'custom_rules' && !devdbotp_import_undo_store($before, (array) $GLOBALS['devdbotp_import_added'])) {
                        $ok = false; // the ids this import added (even by a half-done key) must be on record before anything else
                        break;
                    }
                    if (!$applied || devdbotp_db_guard_failed() || !devdbotp_import_matches($k, $data[$k])) {
                        $ok = false;
                        break; // a refused, failed or unverified write: stop here, undo below
                    }
                }
                if ($ok) {
                    // 3. One push for the whole import, BEFORE the undo set goes.
                    unset($GLOBALS['devdbotp_push_deferred']);
                    $pushed = (int) devdbotp_get_setting('kb_push_pending', 0) !== 1 || devdbotp_kb_push(); // false = imported here, the dashboard gets it on the next check
                }
                if ($ok && !devdbotp_import_undo_clear()) {
                    // Imported, but the undo set could not be dropped: left as it is, the NEXT import would undo this one.
                    // So this one is undone now, and the screen says it did not go through.
                    $ok = false;
                }
                if (!$ok) {
                    // 4. Undo, checked, in its own error window (the guard refuses writes after the failed query).
                    devdbotp_db_guard_rebase();
                    devdbotp_import_recover();
                }
                }
            } finally {
                unset($GLOBALS['devdbotp_push_deferred']);
                if (!$ok && (int) devdbotp_get_setting('kb_push_pending', 0) === 1) {
                    devdbotp_kb_push(); // the undo goes up too
                }
                devdbotp_kb_unlock();
            }
            $back(array($ok ? ($pushed ? 'bp_imported' : 'bp_imported_local') : 'bp_dberr' => '1'));
        }
        $back(array('bp_import_err' => '1'));
    }
}
add_action('admin_init', 'devdbotp_handle_settings');

/* ----------------------------- data helpers ----------------------------- */

/** Per-day {date, humans, bots, blocked} for the last $days (oldest -> newest). */
function devdbotp_daily_series($days = 7)
{
    $out = array();
    if ($days === 1) { // 24 hourly points, oldest first
        foreach (array_reverse(devdbotp_hours_back(24)) as $h) {
            $out[] = array(
                'date'    => $h,
                'hour'    => true,
                'humans'  => (int) devdbotp_get_setting('stat_hour_' . $h . '_humans', 0),
                'bots'    => (int) devdbotp_get_setting('stat_hour_' . $h . '_bots', 0),
                'blocked' => (int) devdbotp_get_setting('stat_hour_' . $h . '_blocked', 0),
            );
        }
        return $out;
    }
    foreach (array_reverse(devdbotp_days_back($days)) as $d) {
        $out[] = array(
            'date'    => $d,
            'humans'  => (int) devdbotp_get_setting('stat_day_' . $d . '_humans', 0),
            'bots'    => (int) devdbotp_get_setting('stat_day_' . $d . '_bots', 0),
            'blocked' => (int) devdbotp_get_setting('stat_day_' . $d . '_blocked', 0),
        );
    }
    return $out;
}

/** Recent bot/blocked events (humans aren't logged). */
function devdbotp_recent_events($limit = 100, $since = '1970-01-01 00:00:00')
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    devdbotp_db_reset_error();
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $t is an internal table name (not user input); $limit prepared.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT created_at, ip_text, user_agent, bot_category, action, reason, risk_score
         FROM $t WHERE created_at >= %s ORDER BY id DESC LIMIT %d",
        $since,
        $limit
    ));
    return (!is_array($rows) || devdbotp_db_failed()) ? null : $rows; // null = could not be read (the screen says so, never "nothing yet")
}

/** Threat counts by category over the last $days. */
function devdbotp_category_counts($days = 7, $since = null)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    $since = $since !== null ? $since : gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    devdbotp_db_reset_error();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT bot_category, COUNT(*) c FROM $t WHERE created_at >= %s GROUP BY bot_category ORDER BY c DESC",
        $since
    ));
    if (!is_array($rows) || devdbotp_db_failed()) {
        return null; // could not be read
    }
    $out = array();
    foreach ((array) $rows as $r) {
        $out[$r->bot_category] = (int) $r->c;
    }
    return $out;
}

/**
 * The ONE state of the plugin, as the owner sees it: off | watch | block. Stored as before
 * (protection_level off|recommended + mode watching|live) so the dashboard's Live switch keeps working.
 */
function devdbotp_state()
{
    if (devdbotp_get_setting('protection_level', 'recommended') === 'off') {
        return 'off';
    }
    return devdbotp_get_setting('mode', 'watching') === 'live' ? 'block' : 'watch';
}

/**
 * What still needs the owner: only OPEN items, each with the place that fixes it. Empty = nothing to show.
 * (This replaced the 0 to 100 score: a number that said 80 while nothing was blocked told the owner nothing.)
 */
function devdbotp_attention()
{
    $items = array();
    $base  = admin_url('admin.php?page=' . DEVDBOTP_PAGE);
    $feeds = devdbotp_feeds();
    if (!is_array($feeds) || empty($feeds['patterns'])) {
        $items[] = array('text' => devdbotp_outside_lookups_on() ? 'The bot lists have not arrived yet. Detection is weaker until they do.' : 'Outside Lookups is off, so the bot lists are not downloaded. Detection is weaker until you turn it on.', 'label' => 'Open Settings', 'href' => add_query_arg('bp_tab', 'settings', $base));
    }
    return $items;
}

/**
 * "Crawler Access": sample requests of well known crawlers run through the REAL rules, grouped by what the
 * crawler is for. It says what the rules DO with each one (allowed / would be blocked). It never says
 * "verified": a sample has no address, and identity is proven per real request. 'proof' is how a real visit
 * of that crawler is proven here (published IP range, reverse DNS, or not at all).
 */
function devdbotp_crawler_access()
{
    $groups = array(
        'search' => array('title' => 'Search Engines', 'rows' => array(
            array('Googlebot', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'IP range'),
            array('Bingbot', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'IP range'),
            array('DuckDuckBot', 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', 'reverse DNS'),
            array('YandexBot', 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', 'reverse DNS'),
            array('Applebot', 'Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)', 'IP range'),
            array('Yahoo Slurp', 'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)', 'reverse DNS'),
        )),
        'ads' => array('title' => 'Advertising', 'rows' => array(
            array('Google AdsBot', 'AdsBot-Google (+http://www.google.com/adsbot.html)', 'IP range'),
            array('Google AdSense', 'Mediapartners-Google', 'IP range'),
        )),
        'ai' => array('title' => 'AI Access', 'rows' => array(
            array('ChatGPT Search', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot', 'IP range'),
            array('ChatGPT User Visits', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'IP range'),
        )),
        'preview' => array('title' => 'Link Previews', 'rows' => array(
            array('Facebook', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', ''),
        )),
    );

    $search_ok      = true;
    $search_blocked = array(); // by a rule: a fault
    $search_by_you  = array(); // switched off on Known Bots: the owner's choice, still said out loud
    foreach ($groups as $gk => $g) {
        foreach ($g['rows'] as $i => $row) {
            $r = devdbotp_evaluate(array('ua' => $row[1], 'ip' => '', 'path' => '/', 'method' => 'GET', 'asn' => 0));
            $blocked = ($r['action'] === 'block');
            $groups[$gk]['rows'][$i] = array(
                'name'    => $row[0],
                'proof'   => $row[2],
                'blocked' => $blocked,
                'by_you'  => $blocked && $r['category'] === 'known_bot', // switched off on Known Bots: the owner's choice, not a fault
            );
            if ($gk === 'search' && $blocked) {
                if ($r['category'] === 'known_bot') {
                    $search_by_you[] = $row[0];
                } else {
                    $search_ok        = false;
                    $search_blocked[] = $row[0];
                }
            }
        }
    }

    // Control: a known attack tool MUST be caught (proves the rules are not allow-all).
    $ctrl  = devdbotp_evaluate(array('ua' => 'sqlmap/1.7', 'ip' => '', 'path' => '/wp-login.php', 'method' => 'GET', 'asn' => 0));
    $feeds = devdbotp_feeds();

    return array(
        'groups'       => $groups,
        'search_ok'    => $search_ok,
        'search_blocked' => $search_blocked,
        'search_by_you'  => $search_by_you,
        'attack_seen'  => ($ctrl['action'] === 'block'),
        'feeds_synced' => is_array($feeds) && !empty($feeds['patterns']),
    );
}

/* ------------------------------ Overview tab ---------------------------- */

function devdbotp_render_overview_tab()
{
    $state      = devdbotp_state();
    $safe_until = (int) devdbotp_get_setting('safe_mode_until', 0);
    $safe_on    = $safe_until > time();
    $attention  = devdbotp_attention();
    $access     = devdbotp_crawler_access();

    // The three tiles cover ONE period, the one chosen on the range bar (whole days in the site's time zone, from the day buckets: no
    // query, 3 small numbers per day, 90 days kept). They used to mix today's blocks with lifetime totals.
    list($days, , $ranges) = devdbotp_activity_range();
    $stats = array('blocked' => 0, 'bots' => 0, 'humans' => 0);
    foreach (devdbotp_daily_series($days) as $d) {
        $stats['blocked'] += (int) $d['blocked'];
        $stats['bots']    += (int) $d['bots'];
        $stats['humans']  += (int) $d['humans'];
    }
    $period = $days === 1 ? 'Today' : 'Last ' . $ranges[$days];

    // The verdict (DESIGN.md 21.1): the title says what is happening, the sentence says what it means.
    if ($safe_on) {
        $sym  = array('warn', 'dashicons-clock');
        $head = 'Blocking Is Paused';
        $sub  = 'Checking and blocking are paused. Your setting comes back by itself when the pause ends. Press Resume Now in the top bar to end it sooner.';
    } elseif ($state === 'off') {
        $sym  = array('bad', 'dashicons-dismiss');
        $head = 'Bot Protection Is Off';
        $sub  = 'The plugin is not checking visits and not blocking bots. Choose Record Bots to look at your traffic first, or Block Bad Bots to start blocking.';
    } elseif ($state === 'watch') {
        $sym  = array('warn', 'dashicons-visibility');
        $head = 'Recording Bots Only';
        $sub  = 'Bots are recorded, but nobody is blocked. Look at the Activity tab, then choose Block Bad Bots when you are ready.';
    } else {
        $sym  = array('ok', 'dashicons-shield');
        $head = 'Blocking Bad Bots';
        $sub  = 'Your rules are blocking unwanted bots. Open the Activity tab to see what was stopped.';
    }
    $states = array(
        'block' => array('Block Bad Bots', 'Stops the bots your rules mark for blocking. Everyone else visits as usual.'),
        'watch' => array('Record Bots', 'Finds bots and lists them on the Activity tab. Everyone can still visit.'),
        'off'   => array('Turned Off', 'The plugin does nothing.'),
    );
    ?>
    <form method="post" action="" id="bp-overview-form">
        <?php wp_nonce_field('devdbotp_save', '_bpn'); ?>

        <div class="max-w-5xl px-6 py-6 space-y-8">
            <section>
                <div class="dd-card" id="bp-verdict" data-state="<?php echo esc_attr($safe_on ? 'paused' : $state); ?>">
                    <div class="bp-verdict">
                        <div class="bp-status is-<?php echo esc_attr($sym[0]); ?>" aria-hidden="true"><?php echo wp_kses(devdbotp_icon_svg(84), devdbotp_kses_allowed()); ?></div>
                        <div class="bp-verdict-txt">
                            <span class="bp-verdict-h"><?php echo esc_html($head); ?></span>
                            <span class="bp-verdict-p"><?php echo esc_html($sub); ?></span>
                            <div class="bp-toolbar bp-actions bp-choices" id="bp-choices" role="radiogroup" aria-label="Protection">
                                <?php foreach ($states as $k => $st) : ?>
                                    <label class="bp-choice <?php echo $state === $k ? 'is-on' : ''; ?>" data-hint="<?php echo esc_attr($st[1]); ?>">
                                        <input type="radio" name="bp_state" value="<?php echo esc_attr($k); ?>" <?php checked($state, $k); ?>>
                                        <?php echo esc_html($st[0]); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="dd-hint" id="bp-state-hint" style="margin:8px 0 0;"><?php echo esc_html($states[$state][1]); ?></p>
                            <p class="dd-hint" style="margin:4px 0 0;">Verified search crawlers are never blocked. <span class="bp-autosave" data-bp-ov-save></span></p>
                        </div>
                    </div>
                    <div class="dd-stats">
                        <div class="dd-stat"><div class="dd-stat-num" style="color:#ef4444;"><?php echo esc_html(number_format_i18n($stats['blocked'])); ?></div><div class="dd-stat-lbl">Caught By Your Rules</div></div>
                        <div class="dd-stat"><div class="dd-stat-num" style="color:#be123c;"><?php echo esc_html(number_format_i18n($stats['bots'])); ?></div><div class="dd-stat-lbl">Visits From Bots</div></div>
                        <div class="dd-stat"><div class="dd-stat-num" style="color:#1967d2;"><?php echo esc_html(number_format_i18n($stats['humans'])); ?></div><div class="dd-stat-lbl">Visits From People</div></div>
                    </div>
                    <p class="dd-hint" style="margin:10px 0 0;"><?php echo esc_html($period); ?>, whole days in your time zone (<?php echo esc_html(devdbotp_tz()->getName()); ?>). Every page request counts, so one bot can count many times. Caught By Your Rules is the part of the bot visits your rules act on: stopped while Block Bad Bots is on, only recorded while Record Bots is on.</p>
                    <?php if (!empty($attention)) : ?>
                        <div class="bp-attention">
                            <span class="bp-title">Needs Attention</span>
                            <ul class="bp-check-list" style="margin-top:6px;">
                                <?php foreach ($attention as $it) : ?>
                                    <li><span class="bp-check-dot is-todo"></span><span><?php echo esc_html($it['text']); ?></span><?php if ($it['href'] !== '') : ?><a class="dd-btn dd-btn-sm" href="<?php echo esc_url($it['href']); ?>"><?php echo esc_html($it['label']); ?></a><?php endif; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php devdbotp_range_bar('overview', $days, $ranges); ?>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-search dd-ico"></span>
                    <h2 class="dd-h2">Crawler Access</h2>
                </div>
                <div class="dd-card">
                    <?php if (!$access['search_ok']) : ?>
                        <div class="dd-banner dd-banner-err"><span class="dashicons dashicons-warning"></span><span>Your rules would block a sample visit from <?php echo esc_html(implode(', ', $access['search_blocked'])); ?>. Check the Rules tab before you block visitors. This checks access, not search rankings.</span></div>
                    <?php elseif (!$access['feeds_synced']) : ?>
                        <div class="dd-banner dd-banner-watch"><span class="dashicons dashicons-info"></span><span>The bot lists have not downloaded yet, so these checks are incomplete. Press Run Checks Again in a few minutes.</span></div>
                    <?php elseif (!empty($access['search_by_you'])) : ?>
                        <div class="dd-banner dd-banner-watch"><span class="dashicons dashicons-info"></span><span>You switched off <?php echo esc_html(implode(', ', $access['search_by_you'])); ?> on Known Bots, so it is blocked. Every other search engine below is allowed. This checks access, not search rankings.</span></div>
                    <?php else : ?>
                        <div class="dd-banner dd-banner-ok"><span class="dashicons dashicons-yes-alt"></span><span>Your rules allow a sample visit from every search engine below. This checks access, not search rankings. <?php devdbotp_tip('A sample carries the crawler name only. On a real visit the address is checked too, against the ranges the operator publishes or by reverse DNS, so a fake Googlebot does not get through on its name.'); ?></span></div>
                    <?php endif; ?>
                    <?php foreach ($access['groups'] as $g) : ?>
                        <div class="bp-crawl-group">
                            <span class="bp-title"><?php echo esc_html($g['title']); ?></span>
                            <ul class="bp-check-list" style="margin-top:4px;">
                                <?php foreach ($g['rows'] as $row) : ?>
                                    <li>
                                        <span class="bp-check-dot <?php echo $row['blocked'] ? ($row['by_you'] ? 'is-todo' : 'is-bad') : ''; ?>"></span>
                                        <span><?php echo esc_html($row['name']); ?>: <?php echo esc_html($row['blocked'] ? ($row['by_you'] ? 'blocked, your choice on Known Bots' : 'would be blocked') : 'allowed'); ?><span class="bp-meta" style="display:inline;margin:0 0 0 8px;"><?php echo esc_html($row['proof'] !== '' ? 'real visits proven by ' . $row['proof'] : 'real visits cannot be proven, treated as a normal visitor'); ?></span></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <div class="bp-crawl-group">
                        <span class="bp-title">Blocking Check</span>
                        <ul class="bp-check-list" style="margin-top:4px;">
                            <li><span class="bp-check-dot <?php echo $access['attack_seen'] ? '' : 'is-bad'; ?>"></span><span>Sample attack tool (sqlmap): <?php echo $access['attack_seen'] ? 'detected' : 'not detected'; ?></span></li>
                        </ul>
                    </div>
                    <div style="margin-top:14px;"><button type="button" class="dd-btn dd-btn-sm" data-bp-reload><span class="dashicons dashicons-update"></span>Run Checks Again</button></div>
                </div>
            </section>

        </div>
    </form>
    <?php
}

/* ---------------------------- Activity tab ------------------------------ */

/**
 * The Activity tab's ONE time range: whole days in the site's time zone (devdbotp_tz()), so the day buckets (people, bots, caught) and the event lists
 * cover exactly the same period. "Today" is today since midnight, not a rolling 24 hours.
 * @return array{0:int,1:string,2:array<int,string>} days, since (UTC), options
 */
function devdbotp_activity_range()
{
    $ranges = array(1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'); // the site keeps 90 days (devdbotp_retention_days); longer ranges are on the dashboard
    $days   = isset($_GET['bp_range']) ? (int) $_GET['bp_range'] : 7; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filter
    if (!isset($ranges[$days])) {
        $days = 7;
    }
    if ($days === 1) { // Today = the last 24 hours, as the dashboard's 24-hour range
        return array(1, gmdate('Y-m-d H:00:00', time() - 23 * HOUR_IN_SECONDS), $ranges); // the 24 hour buckets the chart draws
    }
    // The event log is stored in UTC: the range starts at midnight of its first day in the site's zone, given as UTC.
    $first = devdbotp_days_back($days);
    $start = new DateTime(end($first) . ' 00:00:00', devdbotp_tz());
    return array($days, $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $ranges);
}

/** The range bar itself (the dashboard's date buttons, as on Known Bots). Overview and Activity share bp_range. */
function devdbotp_range_bar($tab, $days, $ranges, $after = '')
{
    $base = admin_url('admin.php?page=' . DEVDBOTP_PAGE . '&bp_tab=' . $tab);
    ?>
    <div class="view-bp">
        <div class="controls-bar bp-date-filter" role="group" aria-label="Time range">
            <div class="date-buttons">
                <?php $i = 0; foreach ($ranges as $n => $lbl) : $i++; ?>
                    <a class="date-button<?php echo $days === $n ? ' active' : ''; ?><?php echo $i === count($ranges) ? ' pl-last' : ''; ?>" data-range="<?php echo $n === 1 ? '24h' : esc_attr($n . 'd'); ?>" href="<?php echo esc_url(add_query_arg('bp_range', $n, $base)); ?>"<?php echo $days === $n ? ' aria-current="true"' : ''; ?>><?php echo esc_html($lbl); ?></a>
                <?php endforeach; ?>
            </div>
            <?php echo wp_kses($after, devdbotp_kses_allowed()); ?>
        </div>
    </div>
    <?php
}

function devdbotp_render_activity_tab()
{
    list($days, $since, $ranges) = devdbotp_activity_range();
    $range_lbl = $days === 1 ? 'Today' : 'Last ' . $ranges[$days];
    $series    = devdbotp_daily_series($days);
    $cats      = devdbotp_category_counts($days, $since);
    $events    = devdbotp_recent_events(200, $since);

    $tot_h = 0; $tot_b = 0; $tot_c = 0;
    foreach ($series as $d) {
        $tot_h += $d['humans']; $tot_b += $d['bots']; $tot_c += $d['blocked'];
    }

    $cat_label = devdbotp_category_labels();
    $cat_counts = array();
    foreach ((array) $events as $e) {
        $cat_counts[$e->bot_category] = (isset($cat_counts[$e->bot_category]) ? $cat_counts[$e->bot_category] : 0) + 1;
    }
    arsort($cat_counts);
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6 space-y-8">

        <?php devdbotp_range_bar('activity', $days, $ranges); ?>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-chart-bar dd-ico"></span>
                <h2 class="dd-h2">Traffic, <?php echo esc_html($range_lbl); ?></h2>
            </div>
            <?php
            // The dashboard chart's data, per day: short axis label ("Sep 21"), the tooltip's full date, three series.
            $chart = array('labels' => array(), 'full' => array(), 'people' => array(), 'bots' => array(), 'caught' => array());
            foreach ($series as $d) {
                if (!empty($d['hour'])) { // 24-hour view: the dashboard's hourly labels
                    $dt = DateTime::createFromFormat('Y-m-d-H', $d['date'], devdbotp_tz());
                    $chart['labels'][] = $dt ? $dt->format('H:00') : $d['date'];
                    $chart['full'][]   = $dt ? $dt->format('H:00 | D, j M Y') : $d['date'];
                    $chart['people'][] = (int) $d['humans'];
                    $chart['bots'][]   = (int) $d['bots'];
                    $chart['caught'][] = (int) $d['blocked'];
                    continue;
                }
                $ts = strtotime($d['date'] . ' 12:00:00');
                $chart['labels'][] = date_i18n('M j', $ts);
                $chart['full'][]   = date_i18n('D, j M Y', $ts);
                $chart['people'][] = (int) $d['humans'];
                $chart['bots'][]   = (int) $d['bots'];
                $chart['caught'][] = (int) $d['blocked'];
            }
            // The dashboard's KPI cards: two dots per card pick the left and right line (2 of 3).
            $cards = array(
                array('caught', 'Caught By Your Rules', '#ef4444', $tot_c),
                array('bots',   'Visits From Bots',     '#be123c', $tot_b),
                array('people', 'Visits From People',   '#1967d2', $tot_h),
            );
            ?>
            <div class="bp-activity" data-bp-chart="<?php echo esc_attr(wp_json_encode($chart)); ?>">
                <div class="metrics-grid">
                    <?php foreach ($cards as $c) : ?>
                        <div class="metric-card" data-metric="<?php echo esc_attr($c[0]); ?>">
                            <div class="axis-controls">
                                <input type="radio" name="bp_axis1" value="<?php echo esc_attr($c[0]); ?>" class="axis-radio" style="--active-color: <?php echo esc_attr($c[2]); ?>;" title="Left Axis"<?php checked($c[0], 'people'); ?>>
                                <input type="radio" name="bp_axis2" value="<?php echo esc_attr($c[0]); ?>" class="axis-radio" style="--active-color: <?php echo esc_attr($c[2]); ?>;" title="Right Axis"<?php checked($c[0], 'bots'); ?>>
                            </div>
                            <h3 class="metric-label"><?php echo esc_html($c[1]); ?></h3>
                            <p class="metric-value" style="color:<?php echo esc_attr($c[2]); ?>;"><?php echo esc_html(number_format_i18n($c[3])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-box">
                    <div class="bp-chart-tip" role="tooltip" aria-hidden="true"></div>
                    <?php if ($tot_h + $tot_b === 0) : ?>
                        <p class="dd-hint" style="margin:0;">No traffic recorded yet. The chart fills in as visitors and bots arrive.</p>
                    <?php else : ?>
                        <div class="bp-chart-wrap"><canvas class="bp-chart-canvas" aria-label="Traffic per day"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-shield dd-ico"></span>
                <h2 class="dd-h2">Bots By Type, <?php echo esc_html($range_lbl); ?></h2>
            </div>
            <?php if (null === $cats) : ?>
                <div class="dd-card"><div class="dd-empty">The bot counts could not be read from the database. Reload the page; if it stays, check the database.</div></div>
            <?php elseif (empty($cats)) : ?>
                <div class="dd-card"><div class="dd-empty">No bots recorded in this period.</div></div>
            <?php else : ?>
                <div class="metrics-grid bp-types-grid">
                    <?php foreach ($cats as $cat => $n) : ?>
                        <div class="metric-card">
                            <h3 class="metric-label"><?php echo esc_html(isset($cat_label[$cat]) ? $cat_label[$cat] : $cat); ?></h3>
                            <p class="metric-value" style="color:#be123c;"><?php echo esc_html(number_format_i18n($n)); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-admin-site dd-ico"></span>
                <h2 class="dd-h2">Bots By Country, <?php echo esc_html($range_lbl); ?></h2>
            </div>
            <?php $countries = devdbotp_bot_countries($since); ?>
            <?php if (null === $countries) : ?>
                <div class="dd-card"><div class="dd-empty">The country counts could not be read from the database. Reload the page; if it stays, check the database.</div></div>
            <?php elseif (empty($countries)) : ?>
                <div class="dd-card"><div class="dd-empty">No country data yet. It comes from Cloudflare request signals, or turn on attacker geolocation under Settings for sites not behind Cloudflare.</div></div>
            <?php else : ?>
                <div class="dd-card bp-list-card">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" data-bp-paged="20">
                        <thead><tr><th>Country</th><th class="bp-col-num">Bot hits</th></tr></thead>
                        <tbody>
                        <?php foreach ($countries as $row) : $code = strtolower(preg_replace('/[^a-z]/i', '', $row->country)); ?>
                            <tr class="bp-country-row">
                                <td><?php if ($code !== '' && file_exists(DEVDBOTP_DIR . 'assets/flags/' . $code . '.png')) : ?><img class="flag-mini" loading="lazy" decoding="async" width="20" height="15" src="<?php echo esc_url(DEVDBOTP_URL . 'assets/flags/' . $code . '.png'); ?>" alt=""><?php endif; ?><span class="country-name" data-code="<?php echo esc_attr(strtoupper($code)); ?>"><?php echo esc_html(strtoupper($code)); ?></span></td>
                                <td class="bp-col-num num"><span style="color:#be123c;"><?php echo esc_html(number_format_i18n($row->hits)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-list-view dd-ico"></span>
                <h2 class="dd-h2">Recent Activity</h2>
            </div>
            <?php if (null === $events) : ?>
                <div class="dd-card"><div class="dd-empty">The recent events could not be read from the database. Reload the page; if it stays, check the database.</div></div>
            <?php elseif (empty($events)) : ?>
                <div class="dd-card"><div class="dd-empty">No bot events logged yet. Humans are never logged, only flagged bots.</div></div>
            <?php else : ?>
                <div class="bp-toolbar-row">
                    <div class="bp-pills" id="bp-evt-filter" role="group" aria-label="Filter by type">
                        <button type="button" class="bp-pill is-on" data-filter="">All <span class="bp-pill-num"><?php echo (int) count($events); ?></span></button>
                        <?php foreach ($cat_counts as $cat => $n) : ?>
                            <button type="button" class="bp-pill" data-filter="<?php echo esc_attr($cat); ?>"><?php echo esc_html(isset($cat_label[$cat]) ? $cat_label[$cat] : $cat); ?> <span class="bp-pill-num"><?php echo (int) $n; ?></span></button>
                        <?php endforeach; ?>
                    </div>
                    <label class="screen-reader-text" for="bp-evt-search">Search</label>
                    <input type="search" id="bp-evt-search" class="dd-input bp-search" placeholder="Search IP, reason or user agent..." data-bp-search="bp-evt-table">
                </div>
                <?php
                // "Allow This Visitor" = a custom rule "IP equals ..., Allow". Not offered while IPs are masked in the
                // log (a masked address is not the visitor's), and a visitor that already has the rule shows Allowed.
                $bp_can_allow   = !(int) devdbotp_get_setting('anonymize_ips', 0);
                $bp_allowed_ips = array();
                foreach (devdbotp_custom_rules() as $bp_cr) {
                    if (isset($bp_cr['action'], $bp_cr['match_field'], $bp_cr['operator']) && $bp_cr['action'] === 'allow' && $bp_cr['match_field'] === 'ip' && $bp_cr['operator'] === 'equals') {
                        $bp_allowed_ips[(string) $bp_cr['pattern']] = 1;
                    }
                }
                ?>
                <div class="dd-card bp-list-card">
                    <div class="dd-list-wrap">
                        <table class="dd-table dd-list" id="bp-evt-table" data-bp-paged="20">
                            <thead><tr>
                                <th class="bp-col-pill">When</th><th class="bp-col-pill">Type</th><th class="bp-col-pill">Action</th><th>Request</th><th class="bp-col-pill"></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($events as $e) :
                                $cat = (string) $e->bot_category;
                                $act = (string) $e->action; ?>
                                <tr data-cat="<?php echo esc_attr($cat); ?>">
                                    <td class="bp-col-pill"><code><?php echo esc_html(get_date_from_gmt($e->created_at, 'M j, H:i')); ?></code></td>
                                    <td class="bp-col-pill"><?php echo wp_kses(devdbotp_badge(devdbotp_category_badge($cat), isset($cat_label[$cat]) ? $cat_label[$cat] : $cat), devdbotp_badge_allowed()); ?></td>
                                    <td class="bp-col-pill"><?php echo wp_kses(devdbotp_badge(devdbotp_action_badge($act), devdbotp_action_label($act)), devdbotp_badge_allowed()); ?></td>
                                    <td>
                                        <span class="bp-title"><code><?php echo esc_html($e->ip_text); ?></code><?php if ((int) $e->risk_score > 0) : ?> <span class="bp-meta" style="display:inline;margin:0 0 0 8px;">risk <?php echo (int) $e->risk_score; ?></span><?php endif; ?></span>
                                        <?php if ((string) $e->reason !== '') : ?><span class="bp-meta"><b>Reason:</b> <?php echo esc_html($e->reason); ?></span><?php endif; ?>
                                        <?php if ((string) $e->user_agent !== '') : ?><span class="bp-meta"><b>Agent:</b> <?php echo esc_html($e->user_agent); ?></span><?php endif; ?>
                                    </td>
                                    <td class="bp-col-pill">
                                        <?php if (isset($bp_allowed_ips[(string) $e->ip_text])) : ?>
                                            <?php echo wp_kses(devdbotp_badge('ok', 'Allowed'), devdbotp_badge_allowed()); ?>
                                        <?php elseif ($bp_can_allow && filter_var((string) $e->ip_text, FILTER_VALIDATE_IP)) : ?>
                                            <form method="post" action="" style="margin:0;">
                                                <?php wp_nonce_field('devdbotp_custom', '_bpcn'); ?>
                                                <input type="hidden" name="bp_cr_field" value="ip"><input type="hidden" name="bp_cr_op" value="equals"><input type="hidden" name="bp_cr_action" value="allow">
                                                <input type="hidden" name="bp_cr_pattern" value="<?php echo esc_attr($e->ip_text); ?>">
                                                <button type="submit" name="devdbotp_custom_add" value="1" class="dd-btn dd-btn-sm" style="white-space:nowrap;">Allow This Visitor</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php
    devdbotp_render_threats_tab($days, $since);
}

/** One label per bot category, used by every tab. */
function devdbotp_category_labels()
{
    return array(
        'scanner' => 'Scanner', 'malicious_ip' => 'Blocklisted IP', 'datacenter' => 'Datacenter', 'suspicious' => 'Suspicious',
        'rate_limit' => 'Rate limit', 'velocity' => 'High velocity', 'fleet' => 'Bot Radar', 'honeypot' => 'Honeypot',
        'ai_blocked' => 'AI crawler', 'known_bot' => 'Known bot', 'country_blocked' => 'Blocked country', 'custom' => 'Custom rule',
        'automated' => 'Automated browser', 'verified_bot' => 'Verified crawler', 'human' => 'Human',
    );
}

/** Section-18 colour for a category badge. */
function devdbotp_category_badge($cat)
{
    $map = array('scanner' => 'bad', 'malicious_ip' => 'bad', 'datacenter' => 'warn', 'suspicious' => 'warn', 'rate_limit' => 'warn', 'velocity' => 'warn',
        'fleet' => 'info', 'honeypot' => 'bad', 'ai_blocked' => 'purple', 'known_bot' => 'info', 'country_blocked' => 'info', 'custom' => 'info',
        'automated' => 'warn', 'verified_bot' => 'ok', 'human' => 'muted');
    return isset($map[$cat]) ? $map[$cat] : 'muted';
}

function devdbotp_action_badge($act)
{
    $map = array('block' => 'bad', 'would_block' => 'warn', 'challenge' => 'purple', 'log' => 'muted', 'allow' => 'ok');
    return isset($map[$act]) ? $map[$act] : 'muted';
}

function devdbotp_action_label($act)
{
    $map = array('block' => 'Blocked', 'would_block' => 'Would block', 'challenge' => 'Challenge', 'log' => 'Logged', 'allow' => 'Allowed');
    return isset($map[$act]) ? $map[$act] : ucfirst(str_replace('_', ' ', $act));
}

/* ------------------------------ Rules tab ------------------------------- */

/** Per-signal-label event counts over the last $days (for the "caught N" badges). */
function devdbotp_rule_counts($days = 7)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    devdbotp_db_reset_error();
    $rows = $wpdb->get_col($wpdb->prepare("SELECT detector_labels FROM $t WHERE created_at >= %s", $since));
    if (!is_array($rows) || devdbotp_db_failed()) {
        return null; // could not be read: the Rules tab prints ? instead of 0
    }
    $tally = array();
    foreach ((array) $rows as $json) {
        $labels = json_decode((string) $json, true);
        if (is_array($labels)) {
            foreach ($labels as $l) {
                $tally[$l] = (isset($tally[$l]) ? $tally[$l] : 0) + 1;
            }
        }
    }
    return $tally;
}

function devdbotp_render_rules_tab()
{
    $actions = devdbotp_rule_actions();
    list($days, , $ranges) = devdbotp_activity_range();
    $in      = $days === 1 ? 'in the last 24 hours' : 'in the last ' . $ranges[$days];
    $counts  = devdbotp_rule_counts($days);
    $opts    = array('allow' => 'Allow', 'log' => 'Log', 'block' => 'Block');
    $rl      = devdbotp_get_setting('rate_limit_action', 'block');
    $rl_opts = array('off' => 'Off', 'log' => 'Log', 'block' => 'Block');
    $vel     = devdbotp_get_setting('velocity_action', 'log');
    $fleet_on  = (int) devdbotp_get_setting('fleet_enabled', 0);
    $fleet_act = devdbotp_get_setting('fleet_action', 'log');
    $fleet_n   = function_exists('devdbotp_fleet_count') ? devdbotp_fleet_count() : 0;
    $fleet_geo = function_exists('devdbotp_fleet_geo') ? devdbotp_fleet_geo(6) : array();
    $ro        = devdbotp_get_setting('repeat_offender_action', 'block');
    $ro_opts   = array('off' => 'Off', 'log' => 'Log', 'block' => 'Block');
    $ro_threshold = max(2, (int) devdbotp_get_setting('repeat_offender_threshold', 10));
    $rep_count = function_exists('devdbotp_reputation_count') ? devdbotp_reputation_count() : 0; // null = the count could not be read
    $bad_map   = devdbotp_get_setting('bp_bad_ips', array());
    $bad_count = is_array($bad_map) ? count($bad_map) : 0;
    $custom_rules_all = function_exists('devdbotp_all_custom_rules') ? devdbotp_all_custom_rules() : array();
    $beh        = devdbotp_get_setting('behavior_action', 'block');
    $beh_opts   = array('off' => 'Off', 'log' => 'Log', 'block' => 'Block');
    $auto_count = function_exists('devdbotp_automated_session_count') ? devdbotp_automated_session_count($days) : 0;
    $beh_map    = devdbotp_get_setting('bp_behavior_ips', array());
    $beh_flagged = is_array($beh_map) ? count($beh_map) : 0;
    $hp_act     = devdbotp_get_setting('honeypot_action', 'log');
    $hp_map     = function_exists('devdbotp_honeypot_flagged_asns') ? devdbotp_honeypot_flagged_asns() : array();
    $hp_flagged = is_array($hp_map) && empty($GLOBALS['devdbotp_settings_read_failed']) ? count($hp_map) : null; // null = could not be read
    $hp_hits    = function_exists('devdbotp_honeypot_hit_count') ? devdbotp_honeypot_hit_count($days) : 0;
    $hp_slug    = function_exists('devdbotp_honeypot_slug') ? devdbotp_honeypot_slug() : '';
    $hp_prefix  = function_exists('devdbotp_honeypot_path_prefix') ? rtrim(devdbotp_honeypot_path_prefix(), '/') : '';
    $hp_on      = function_exists('devdbotp_honeypot_enabled') ? devdbotp_honeypot_enabled() : true;
    $hp_min_ips = (int) devdbotp_get_setting('honeypot_asn_min_ips', 3);
    $fleet_asn_n = function_exists('devdbotp_fleet_asn_count') ? devdbotp_fleet_asn_count() : 0;

    // Group the catalog, preserving catalog order within each group.
    $groups = array();
    foreach (devdbotp_rules_catalog() as $rule) {
        $groups[$rule['group']][] = $rule;
    }
    $seg = function ($field, $cur, $options) {
        ?>
        <input type="hidden" name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($cur); ?>">
        <div class="dd-seg dd-rule-seg" data-target="<?php echo esc_attr($field); ?>">
            <?php foreach ($options as $val => $lbl) : ?>
                <span class="dd-seg-opt <?php echo $cur === $val ? 'is-active' : ''; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($lbl); ?></span>
            <?php endforeach; ?>
        </div>
        <?php
    };
    ?>
    <?php
    // Use Recommended: the catalog defaults for the built-in rules + the shipped defaults for the behaviour actions (actions only).
    $rec = array('bp_rate_action' => 'log', 'bp_velocity_action' => 'log', 'bp_repeat_action' => 'block', 'bp_behavior_action' => 'log', 'bp_honeypot_action' => 'log', 'bp_fleet_action' => 'log');
    foreach (devdbotp_rules_catalog() as $rule) {
        $rec['bp_rule_' . $rule['key']] = $rule['default'];
    }
    ?>
    <form method="post" action="" id="bp-rules-form" data-bp-rec="<?php echo esc_attr(wp_json_encode($rec)); ?>">
        <?php wp_nonce_field('devdbotp_rules', '_bprn'); ?>
        <div class="max-w-5xl px-6 pt-3 pb-6 space-y-8">
            <?php if (!empty($_GET['bp_saved'])) : ?>
                <div data-bp-flash="1" class="dd-banner dd-banner-ok"><span class="dashicons dashicons-yes-alt"></span><span>Rules saved.</span></div>
            <?php endif; ?>
            <?php devdbotp_range_bar('rules', $days, $ranges, '<span class="bp-autosave" data-bp-rules-save style="margin-left:auto;"></span>'); ?>

            <?php foreach ($groups as $group => $rules) : ?>
                <section>
                    <div class="dd-sec-head">
                        <span class="dashicons dashicons-shield dd-ico"></span>
                        <h2 class="dd-h2"><?php echo esc_html($group); ?></h2>
                    </div>
                    <div class="dd-card">
                        <?php foreach ($rules as $rule) :
                            $key = $rule['key'];
                            $cur = isset($actions[$key]) ? $actions[$key] : $rule['default'];
                            $n = 0;
                            foreach ((array) $counts as $lbl2 => $c2) { if (in_array($lbl2, $rule['labels'], true)) { $n += (int) $c2; } } ?>
                            <div class="bp-rule">
                                <div class="bp-rule-txt">
                                    <span class="bp-rule-name"><?php echo esc_html($rule['label']); ?></span>
                                    <p class="dd-hint" style="margin-top:3px;"><?php echo esc_html(str_replace(array(' — ', ' – ', '—', '–'), array(', ', ', ', ', ', ', '), $rule['desc'])); ?></p>
                                    <span class="bp-rule-count <?php echo $n > 0 ? 'is-hot' : ''; ?>"><span class="dashicons dashicons-chart-bar"></span> Caught <b><?php echo null === $counts ? '?' : (int) $n; ?></b> <?php echo esc_html($in); ?></span>
                                </div>
                                <?php $seg('bp_rule_' . $key, $cur, $opts); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-performance dd-ico"></span>
                    <h2 class="dd-h2">Rate Limits And Behaviour</h2>
                </div>
                <div class="dd-card">
                    <div class="bp-rule">
                        <div class="bp-rule-txt">
                            <span class="bp-rule-name">Per IP request throttle</span>
                            <p class="dd-hint" style="margin-top:3px;">Caps one IP at 20 login or XML-RPC and 60 REST requests a minute. <?php devdbotp_tip('Stops brute force and API hammering. Public pages and verified crawlers are never throttled. Log records floods, Block stops them when Live.'); ?></p>
                        </div>
                        <?php $seg('bp_rate_action', $rl, $rl_opts); ?>
                    </div>
                    <div class="bp-rule">
                        <div class="bp-rule-txt">
                            <span class="bp-rule-name">Rapid page requests</span>
                            <p class="dd-hint" style="margin-top:3px;">Flags an IP that loads more than 30 pages in 10 seconds, a scraper pace no visitor reaches. <?php devdbotp_tip('AJAX and REST calls and verified crawlers are excluded. Log records the burst (recommended), Block stops it when Live.'); ?></p>
                        </div>
                        <?php $seg('bp_velocity_action', $vel, $rl_opts); ?>
                    </div>
                    <div class="bp-rule">
                        <div class="bp-rule-txt">
                            <span class="bp-rule-name">Repeat offenders</span>
                            <p class="dd-hint" style="margin-top:3px;">An IP blocked <?php echo (int) $ro_threshold; ?> or more times gets this action on every request. <?php devdbotp_tip('Built from your own traffic: once an IP keeps getting blocked it is treated as a known bad actor, even on an otherwise clean request. Verified crawlers are never affected.'); ?></p>
                            <span class="bp-rule-count"><span class="dashicons dashicons-database"></span> Tracking <b><?php echo null === $rep_count ? '?' : (int) $rep_count; ?></b> IPs, <b><?php echo (int) $bad_count; ?></b> flagged as repeat offenders</span>
                            <label class="dd-hint" style="margin-top:8px;display:inline-flex;align-items:center;gap:6px;">Threshold <input type="number" name="bp_repeat_threshold" min="2" max="1000" value="<?php echo (int) $ro_threshold; ?>" class="dd-input" style="width:80px;padding:4px 8px;"> blocks</label>
                        </div>
                        <?php $seg('bp_repeat_action', $ro, $ro_opts); ?>
                    </div>
                    <div class="bp-rule">
                        <div class="bp-rule-txt">
                            <span class="bp-rule-name">Automated browsers</span>
                            <p class="dd-hint" style="margin-top:3px;">A session that runs JavaScript but reports the automation flag across 2 or more pageviews gets this action. <?php devdbotp_tip('Selenium, Puppeteer, Playwright and agentic browsers announce themselves through navigator.webdriver. Our on page script confirms real browsers; real people and verified crawlers are never affected.'); ?></p>
                            <span class="bp-rule-count"><span class="dashicons dashicons-chart-bar"></span> <b><?php echo null === $auto_count ? '?' : (int) $auto_count; ?></b> automated sessions <?php echo esc_html($in); ?>, <b><?php echo (int) $beh_flagged; ?></b> IPs flagged</span>
                        </div>
                        <?php $seg('bp_behavior_action', $beh, $beh_opts); ?>
                    </div>
                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-shield dd-ico"></span>
                    <h2 class="dd-h2">Honeypot Trap</h2>
                </div>
                <div class="dd-card">
                    <div class="bp-rule">
                        <div class="bp-rule-txt">
                            <span class="bp-rule-name">Catch rotating proxy crawlers by network</span>
                            <p class="dd-hint" style="margin-top:3px;">A hidden link no human sees is disallowed in robots.txt. Anything that follows it is a bot. <?php devdbotp_tip('These bots rotate a fresh IP on every hit but keep one network (ASN), so the network is flagged, not the IP, and only after several different IPs from it hit the trap. A real visitor is one IP, so their ISP is never flagged. This action then applies to that network.'); ?></p>
                            <span class="bp-rule-count"><span class="dashicons dashicons-chart-bar"></span> <b><?php echo null === $hp_flagged ? '?' : (int) $hp_flagged; ?></b> <?php echo (int) $hp_flagged === 1 ? 'network flagged' : 'networks flagged'; ?>, <b><?php echo null === $hp_hits ? '?' : (int) $hp_hits; ?></b> trap hits <?php echo esc_html($in); ?></span>
                            <label class="dd-opt" style="display:flex;margin-top:10px;align-items:center;gap:8px;">
                                <input type="checkbox" name="bp_honeypot_enabled" value="1" class="dd-check" <?php checked($hp_on, true); ?>>
                                <span>Enable the hidden trap</span>
                            </label>
                            <label class="dd-hint" style="margin-top:8px;display:inline-flex;align-items:center;gap:6px;">Flag a network after <input type="number" name="bp_honeypot_min_ips" min="2" max="50" value="<?php echo (int) $hp_min_ips; ?>" class="dd-input" style="width:72px;padding:4px 8px;"> different IPs hit the trap</label>
                            <?php if ($hp_slug !== '') : ?>
                                <p class="dd-hint" style="margin-top:8px;">Trap path: <code><?php echo esc_html($hp_prefix . '/' . $hp_slug . '/'); ?></code>, disallowed in robots.txt.</p>
                            <?php endif; ?>
                        </div>
                        <?php $seg('bp_honeypot_action', $hp_act, $beh_opts); ?>
                    </div>
                </div>
            </section>

            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-networking dd-ico"></span>
                    <h2 class="dd-h2">Bot Radar</h2>
                </div>
                <div class="dd-card">
                    <div class="bp-rule">
                        <div class="bp-rule-txt">
                            <span class="bp-rule-name">Shared bot intelligence (opt in)</span>
                            <p class="dd-hint" style="margin-top:3px;">This site shares the attacker IPs and networks it blocks and receives the ones other sites flagged. <?php devdbotp_tip('Only adversarial IPs and networks are shared, never visitor data. An IP or network is applied here only after multiple other sites independently flagged it. Verified search crawlers are always exempt.'); ?></p>
                            <label class="dd-opt" style="display:flex;margin-top:10px;align-items:center;gap:8px;">
                                <input type="checkbox" name="bp_fleet_enabled" value="1" class="dd-check" <?php checked($fleet_on, 1); ?>>
                                <span>Join Bot Radar (share and receive bad bots)</span>
                            </label>
                            <?php if ($fleet_on) : ?>
                                <span class="bp-rule-count"><span class="dashicons dashicons-database"></span> <b><?php echo (int) $fleet_n; ?></b> IPs and <b><?php echo (int) $fleet_asn_n; ?></b> bot networks on Bot Radar right now</span>
                                <?php if (!empty($fleet_geo)) : ?>
                                    <p class="dd-hint" style="margin-top:6px;">Top origin countries: <?php $gp = array(); foreach ($fleet_geo as $cc => $n) { $gp[] = '<b>' . esc_html($cc) . '</b> ' . (int) $n; } echo wp_kses_post(implode(', ', $gp)); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php $seg('bp_fleet_action', $fleet_act, $beh_opts); ?>
                    </div>
                </div>
            </section>

        </div>
    </form>

    <div class="max-w-5xl px-6 pb-6 space-y-8">
        <?php if (!empty($hp_map)) :
            $hp_fleet_on = function_exists('devdbotp_fleet_on') && devdbotp_fleet_on();
            $hp_export = wp_json_encode(array(
                'flagged'   => array_map('intval', array_keys($hp_map)),
                'safelist'  => array_values(array_map('intval', (array) devdbotp_get_setting('honeypot_isp_extra', array()))),
            )); ?>
            <section>
                <div class="dd-sec-head">
                    <span class="dashicons dashicons-shield dd-ico"></span>
                    <h2 class="dd-h2">Flagged Networks</h2>
                </div>
                <p class="dd-hint" style="margin:0 0 12px;">Networks caught crawling the hidden trap link. The honeypot action applies to every request from them. Unflag any you recognise as a real network; a false positive consumer ISP can be cleared for good with Never flag.</p>
                <div class="dd-card bp-list-card">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" data-bp-paged="20">
                        <thead><tr>
                            <th>Network</th>
                            <th class="bp-col-num">Distinct IPs</th>
                            <th class="bp-col-num">Trap Hits</th>
                            <?php if ($hp_fleet_on) : ?><th class="bp-col-pill">Bot Radar</th><?php endif; ?>
                            <th class="bp-col-pill">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($hp_map as $asn => $m) :
                            $asn = (int) $asn;
                            $seeded = is_array($m) && !empty($m['seeded']);
                            $ips    = (is_array($m) && isset($m['ips'])) ? (int) $m['ips'] : 0;
                            $hits   = (is_array($m) && isset($m['hits'])) ? (int) $m['hits'] : 0;
                            $label  = function_exists('devdbotp_honeypot_asn_label') ? devdbotp_honeypot_asn_label($asn) : '';
                            $in_fleet = $hp_fleet_on && function_exists('devdbotp_asn_in_fleet') && devdbotp_asn_in_fleet($asn); ?>
                            <tr>
                                <td><span class="bp-title"><code>AS<?php echo (int) $asn; ?></code></span><?php if ($label !== '') : ?><span class="bp-meta"><?php echo esc_html($label); ?></span><?php endif; ?></td>
                                <td class="bp-col-num"><?php echo $seeded ? '<span class="bp-meta" style="margin:0;font-weight:400;">seeded</span>' : (int) $ips; ?></td>
                                <td class="bp-col-num"><?php echo (int) $hits; ?></td>
                                <?php if ($hp_fleet_on) : ?><td class="bp-col-pill"><?php echo wp_kses($in_fleet ? devdbotp_badge('ok', 'Confirmed by other sites') : devdbotp_badge('muted', 'Contributing'), devdbotp_badge_allowed()); ?></td><?php endif; ?>
                                <td class="bp-col-pill">
                                    <div class="bp-act-row">
                                        <form method="post" action="">
                                            <?php wp_nonce_field('devdbotp_honeypot', '_bphn'); ?>
                                            <input type="hidden" name="bp_honeypot_unflag" value="<?php echo (int) $asn; ?>">
                                            <button type="submit" class="dd-btn dd-btn-sm">Unflag</button>
                                        </form>
                                        <form method="post" action="" data-bp-confirm="Never flag this network again, even if it keeps hitting the trap?">
                                            <?php wp_nonce_field('devdbotp_honeypot', '_bphn'); ?>
                                            <input type="hidden" name="bp_honeypot_safelist" value="<?php echo (int) $asn; ?>">
                                            <button type="submit" class="dd-btn dd-btn-sm">Never Flag</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
                <p class="dd-hint" style="margin-top:10px;">Export this list to seed another site: <code><?php echo esc_html($hp_export); ?></code></p>
            </section>
        <?php endif; ?>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-edit dd-ico"></span>
                <h2 class="dd-h2">Custom Rules</h2>
            </div>
            <div class="dd-card">
                <p class="dd-hint" style="margin-top:0;">Block, log or allow traffic by your own criteria: a user agent, a probed path, an IP or CIDR range, a country or a network. <?php devdbotp_tip('Custom rules are checked on every request. The SEO safe crawler allowlist still wins over any custom rule.'); ?></p>
                <form method="post" action="" style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;margin-top:14px;">
                    <?php wp_nonce_field('devdbotp_custom', '_bpcn'); ?>
                    <label class="dd-hint" style="margin:0;">When
                        <select name="bp_cr_field" class="dd-input dd-cr-field" style="display:block;margin-top:3px;">
                            <option value="user_agent">User agent</option>
                            <option value="path">Path or URL</option>
                            <option value="ip">IP or CIDR</option>
                            <option value="country">Country</option>
                            <option value="asn">Network (ASN)</option>
                        </select>
                    </label>
                    <label class="dd-hint" style="margin:0;">&nbsp;
                        <select name="bp_cr_op" class="dd-input dd-cr-op" style="display:block;margin-top:3px;"></select>
                    </label>
                    <label class="dd-hint" style="margin:0;flex:1;min-width:180px;">Value
                        <input type="text" name="bp_cr_pattern" required maxlength="255" placeholder="badbot, /xmlrpc.php, 203.0.113.0/24, RU, 16509" class="dd-input" style="display:block;margin-top:3px;width:100%;">
                    </label>
                    <label class="dd-hint" style="margin:0;">then
                        <select name="bp_cr_action" class="dd-input" style="display:block;margin-top:3px;">
                            <option value="block">Block</option>
                            <option value="log">Log</option>
                            <option value="allow">Allow</option>
                        </select>
                    </label>
                    <button type="submit" name="devdbotp_custom_add" value="1" class="dd-btn-primary">Add Rule</button>
                </form>
            </div>
            <?php if (!empty($GLOBALS['devdbotp_rules_read_failed'])) : ?>
                <div class="dd-card" style="margin-top:12px;"><div class="dd-empty">The custom rules could not be read from the database. Reload the page; if it stays, check the database.</div></div>
            <?php elseif (empty($custom_rules_all)) : ?>
                <div class="dd-card" style="margin-top:12px;"><div class="dd-empty">No custom rules yet. Add one above.</div></div>
            <?php else :
                $cr_act_labels = array('block' => 'Block', 'log' => 'Log', 'challenge' => 'Challenge', 'allow' => 'Allow');
                $cr_act_cls = array('block' => 'bad', 'log' => 'muted', 'challenge' => 'purple', 'allow' => 'ok'); ?>
                <div class="dd-card bp-list-card" style="margin-top:12px;">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" data-bp-paged="20">
                        <thead><tr><th>Rule</th><th class="bp-col-pill">Action</th><th class="bp-col-pill">Status</th><th class="bp-col-pill">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($custom_rules_all as $cr) : $en = !empty($cr['enabled']); $a = (string) $cr['action']; ?>
                            <tr>
                                <td><span class="bp-title"><?php echo esc_html($cr['match_field']); ?> <?php echo esc_html(str_replace('_', ' ', $cr['operator'])); ?> <code><?php echo esc_html($cr['pattern']); ?></code></span></td>
                                <td class="bp-col-pill"><?php echo wp_kses(devdbotp_badge(isset($cr_act_cls[$a]) ? $cr_act_cls[$a] : 'muted', isset($cr_act_labels[$a]) ? $cr_act_labels[$a] : $a), devdbotp_badge_allowed()); ?></td>
                                <td class="bp-col-pill"><?php echo wp_kses($en ? devdbotp_badge('ok', 'Enabled') : devdbotp_badge('muted', 'Disabled'), devdbotp_badge_allowed()); ?></td>
                                <td class="bp-col-pill">
                                    <div class="bp-act-row">
                                        <form method="post" action="">
                                            <?php wp_nonce_field('devdbotp_custom', '_bpcn'); ?>
                                            <input type="hidden" name="bp_cr_id" value="<?php echo (int) $cr['id']; ?>">
                                            <input type="hidden" name="bp_cr_enabled" value="<?php echo $en ? '0' : '1'; ?>">
                                            <button type="submit" name="devdbotp_custom_toggle" value="1" class="dd-btn dd-btn-sm"><?php echo $en ? 'Disable' : 'Enable'; ?></button>
                                        </form>
                                        <form method="post" action="" data-bp-confirm="Delete this rule?">
                                            <?php wp_nonce_field('devdbotp_custom', '_bpcn'); ?>
                                            <input type="hidden" name="bp_cr_id" value="<?php echo (int) $cr['id']; ?>">
                                            <button type="submit" name="devdbotp_custom_delete" value="1" class="dd-btn dd-btn-sm" style="color:#dc2626;">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
            <?php endif; ?>
        </section>    </div>
    <?php
}

/* ------------------------------ Threats tab ----------------------------- */

/** Aggregate threat intel from logged events over $days (verified bots excluded). */
function devdbotp_threats($days = 7, $since = null)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    $since = $since !== null ? $since : gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    $excl = "bot_category NOT IN ('verified_bot','human')"; // static, no user input
    devdbotp_db_reset_error();

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; $excl is a static clause (no user input); $since prepared.
    $summary = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN action IN ('block','would_block') THEN 1 ELSE 0 END) blocked,
                COUNT(DISTINCT ip_text) ips
         FROM $t WHERE created_at >= %s AND $excl",
        $since
    ));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; $excl is a static clause (no user input); $since prepared.
    $ips = $wpdb->get_results($wpdb->prepare(
        "SELECT ip_text, COUNT(*) hits, MAX(created_at) last_seen,
                SUM(CASE WHEN action IN ('block','would_block') THEN 1 ELSE 0 END) blocked,
                GROUP_CONCAT(DISTINCT bot_category ORDER BY bot_category SEPARATOR ', ') cats
         FROM $t WHERE created_at >= %s AND $excl AND ip_text <> ''
         GROUP BY ip_text ORDER BY hits DESC LIMIT 15",
        $since
    ));

    $paths = $wpdb->get_results($wpdb->prepare(
        "SELECT path, COUNT(*) hits FROM $t
         WHERE created_at >= %s AND detector_labels LIKE %s
         GROUP BY path ORDER BY hits DESC LIMIT 15",
        $since,
        '%"scanner_path"%'
    ));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; $excl is a static clause (no user input); $since prepared.
    $uas = $wpdb->get_results($wpdb->prepare(
        "SELECT user_agent, COUNT(*) hits,
                GROUP_CONCAT(DISTINCT bot_category ORDER BY bot_category SEPARATOR ', ') cats
         FROM $t WHERE created_at >= %s AND $excl AND user_agent <> ''
         GROUP BY user_agent ORDER BY hits DESC LIMIT 15",
        $since
    ));

    // Cloudflare-enriched dimensions (Phase 2) — populated only when CF sends them.
    if (devdbotp_db_failed() || !is_array($ips) || !is_array($paths) || !is_array($uas)) {
        return null; // could not be read: the Threats tab says so, never "no threats"
    }
    return compact('summary', 'ips', 'paths', 'uas');
}

/** Bots By Country on Activity: bot hits per country code for the period, every country (the list pages by 20). Verified crawlers left out, as in the technical lists. */
function devdbotp_bot_countries($since)
{
    global $wpdb;
    $t = $wpdb->prefix . 'devdbotp_events';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; static clause, no user input; $since prepared.
    devdbotp_db_reset_error();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT country, COUNT(*) hits FROM $t
         WHERE created_at >= %s AND bot_category NOT IN ('verified_bot','human') AND country IS NOT NULL AND country <> ''
         GROUP BY country ORDER BY hits DESC",
        $since
    ));
    return (!is_array($rows) || devdbotp_db_failed()) ? null : $rows; // null = could not be read
}

function devdbotp_render_threats_tab($days = 7, $since = null)
{
    $d = devdbotp_threats($days, $since);
    if (null === $d) {
        echo '<div class="dd-card"><div class="dd-empty">The threat data could not be read from the database. Reload the page; if it stays, check the database.</div></div>';
        return;
    }
    $cat_label = devdbotp_category_labels();
    $cats_of = function ($csv) use ($cat_label) {
        $out = array();
        foreach (array_filter(array_map('trim', explode(',', (string) $csv))) as $c) {
            $out[] = isset($cat_label[$c]) ? $cat_label[$c] : $c;
        }
        return implode(', ', $out);
    };
    ?>
    <div class="max-w-5xl px-6 pb-6">
    <details class="bp-tech">
        <summary>Technical Details</summary>
        <p class="dd-hint bp-intro">Which addresses, pages and browser strings the recorded bots came from, for the period chosen above. Verified search crawlers are left out.</p>
        <div class="space-y-8">
        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-shield dd-ico"></span>
                <h2 class="dd-h2">Top Offending IPs</h2>
            </div>
            <?php if (empty($d['ips'])) : ?>
                <div class="dd-card"><div class="dd-empty">No threats from any IP in this range.</div></div>
            <?php else : ?>
                <div class="dd-card bp-list-card">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" data-bp-paged="20">
                        <thead><tr><th>IP</th><th class="bp-col-num">Hits</th><th class="bp-col-num">Blocked</th><th class="bp-col-pill">Last Seen</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['ips'] as $row) : ?>
                            <tr>
                                <td><span class="bp-title"><code><?php echo esc_html($row->ip_text); ?></code></span><span class="bp-meta"><b>Types:</b> <?php echo esc_html($cats_of($row->cats)); ?></span></td>
                                <td class="bp-col-num"><?php echo (int) $row->hits; ?></td>
                                <td class="bp-col-num" style="color:#ef4444;"><?php echo (int) $row->blocked; ?></td>
                                <td class="bp-col-pill"><code><?php echo esc_html(get_date_from_gmt($row->last_seen, 'M j, H:i')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-search dd-ico"></span>
                <h2 class="dd-h2">Top Targeted Exploit Paths</h2>
            </div>
            <?php if (empty($d['paths'])) : ?>
                <div class="dd-card"><div class="dd-empty">No exploit path probes recorded in this range.</div></div>
            <?php else : ?>
                <div class="dd-card bp-list-card">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" data-bp-paged="20">
                        <thead><tr><th>Path</th><th class="bp-col-num">Hits</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['paths'] as $row) : ?>
                            <tr><td><code><?php echo esc_html($row->path); ?></code></td><td class="bp-col-num"><?php echo (int) $row->hits; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-id dd-ico"></span>
                <h2 class="dd-h2">Top Offending User Agents</h2>
            </div>
            <?php if (empty($d['uas'])) : ?>
                <div class="dd-card"><div class="dd-empty">No flagged user agents in this range.</div></div>
            <?php else : ?>
                <div class="dd-card bp-list-card">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" data-bp-paged="20">
                        <thead><tr><th>User Agent</th><th class="bp-col-num">Hits</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['uas'] as $row) : ?>
                            <tr><td><span class="bp-title" style="font-weight:400;"><?php echo esc_html($row->user_agent); ?></span><span class="bp-meta"><b>Types:</b> <?php echo esc_html($cats_of($row->cats)); ?></span></td><td class="bp-col-num"><?php echo (int) $row->hits; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="bp-pagination"></div>
                </div>
            <?php endif; ?>
        </section>

        </div>
    </details>
    </div>
    <?php
}

/* ------------------------------ Settings tab ---------------------------- */

function devdbotp_render_settings_tab()
{
    $feeds = devdbotp_feeds();
    $fetched = (is_array($feeds) && !empty($feeds['fetched'])) ? (int) $feeds['fetched'] : 0;
    $np  = (is_array($feeds) && !empty($feeds['patterns'])) ? count($feeds['patterns']) : 0;
    $na  = (is_array($feeds) && !empty($feeds['asns'])) ? count($feeds['asns']) : 0;
    $nd  = (is_array($feeds) && !empty($feeds['drop_v4'])) ? count($feeds['drop_v4']) : 0;
    $nd6 = (is_array($feeds) && !empty($feeds['drop_v6'])) ? count($feeds['drop_v6']) : 0;
    $nbv4 = (is_array($feeds) && !empty($feeds['bot_v4'])) ? count($feeds['bot_v4']) : 0;
    $nbv6 = (is_array($feeds) && !empty($feeds['bot_v6'])) ? count($feeds['bot_v6']) : 0;

    $anonymize = (int) devdbotp_get_setting('anonymize_ips', 0);
    $timezone  = (string) devdbotp_get_setting('timezone', '');
    $outside_lookups = (int) devdbotp_get_setting('outside_lookups', 0);


    $cfg = array();
    foreach (devdbotp_config_keys() as $k) {
        if ($k === 'rule_actions') {
            $cfg[$k] = devdbotp_rule_actions();
        } elseif ($k === 'known_bots_blocked') {
            $cfg[$k] = devdbotp_kb_blocked();
        } else {
            $cfg[$k] = devdbotp_get_setting($k, '');
        }
    }
    if (function_exists('devdbotp_all_custom_rules')) {
        $cfg['custom_rules'] = array();
        foreach (devdbotp_all_custom_rules() as $r) {
            $cfg['custom_rules'][] = array('field' => (string) $r['match_field'], 'operator' => (string) $r['operator'], 'pattern' => (string) $r['pattern'], 'action' => (string) $r['action'], 'name' => (string) $r['name'], 'enabled' => !empty($r['enabled']));
        }
    }
    $export = wp_json_encode($cfg, JSON_PRETTY_PRINT);

    $cf_waf_rules      = function_exists('devdbotp_waf_rules') ? devdbotp_waf_rules() : array();

    $acct      = devdbotp_account_state();
    $connected = !empty($acct['ok']);
    $synced    = (int) devdbotp_get_setting('known_bots_synced_at', 0);
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6 space-y-8">
        <?php if (!empty($_GET['bp_saved'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-ok"><span class="dashicons dashicons-yes-alt"></span><span>Settings saved.</span></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bp_cleared'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-ok"><span class="dashicons dashicons-yes-alt"></span><span>Statistics cleared. New activity is recorded from now on.</span></div>
        <?php endif; ?>
        <?php if (isset($_GET['bp_error']) && in_array($_GET['bp_error'], array('confirm', 'clear'), true)) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-err"><span class="dashicons dashicons-warning"></span><span><?php if ($_GET['bp_error'] === 'confirm') : ?>Type CLEAR to confirm. Nothing was deleted.<?php else : ?>The statistics could not be cleared completely: a database query failed. Try again.<?php endif; ?></span></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bp_feeds_refreshed'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-ok"><span class="dashicons dashicons-yes-alt"></span><span>Threat feeds refreshed.</span></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bp_feeds_failed'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-err"><span class="dashicons dashicons-warning"></span><span>Some feeds could not be downloaded. The previous lists stay in use; try again later.</span></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bp_imported_local'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-watch"><span class="dashicons dashicons-info"></span><span>Configuration imported here. Your DevDome dashboard could not be reached; it gets the change on the next check.</span></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bp_imported'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-ok"><span class="dashicons dashicons-yes-alt"></span><span>Configuration imported.</span></div>
        <?php endif; ?>
        <?php if (!empty($_GET['bp_import_err'])) : ?>
            <div data-bp-flash="1" class="dd-banner dd-banner-err"><span class="dashicons dashicons-warning"></span><span>Import failed. That did not look like valid exported JSON.</span></div>
        <?php endif; ?>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-admin-links dd-ico"></span>
                <h2 class="dd-h2">DevDome Account</h2>
            </div>
            <?php
            // The DevDome Tools dashboard Connect card, one to one (core 1.7.6): same text, same form. The
            // devdcorev1_inventory field is printed only beside that disclosure, so this press carries the permission.
            if (!$connected) : ?>
                <div class="bp-conn">
                    <span class="bp-conn-ci">DD</span>
                    <div class="bp-conn-body">
                        <strong>Connect this site to your DevDome account</strong>
                        <span>Connecting is optional and nothing is sent until you press Connect. It then sends this site's domain and site token, the active DevDome plugins and their versions, and the DevDome library, WordPress and PHP versions to api.devdome.com with the recurring account checks, so your account can show your sites for alerts, support and update notices. Nothing about other plugins, users, email addresses, content or visitors. Disconnecting stops it. <a href="https://devdome.com/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a> &middot; <a href="https://devdome.com/terms-of-service" target="_blank" rel="noopener noreferrer">Terms of Service</a></span>
                    </div>
                    <div class="bp-conn-ctl">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="devdcorev1_connect_go">
                            <input type="hidden" name="devdcorev1_inventory" value="1">
                            <?php wp_nonce_field('devdcorev1_connect_go'); ?>
                            <button type="submit" class="bp-conn-btn bp-conn-solid">Connect your DevDome account</button>
                        </form>
                        <p class="bp-conn-hint">Opens devdome.com to sign in, then links this site.</p>
                    </div>
                </div>
                <div class="dd-banner dd-banner-watch" style="margin-top:12px;max-width:66%;"><span class="dashicons dashicons-info"></span><span>Connecting adds the DevDome dashboard on top: the dashboard view of this site's bot activity and settings. Settings are changed here, on this site; the dashboard shows them. Everything on this page keeps working without an account.</span></div>
            <?php else : ?>
                <div class="bp-conn">
                    <span class="bp-conn-ci is-ok">DD</span>
                    <div class="bp-conn-body">
                        <strong>Connected to your DevDome account</strong>
                        <span><?php echo esc_html((!empty($acct['email']) ? 'Signed in as ' . $acct['email'] . (!empty($acct['account_id']) ? ' (' . $acct['account_id'] . ').' : '.') : 'This site is linked to your DevDome account.') . (!empty($acct['plan']) ? ' ' . ucfirst((string) $acct['plan']) . ' plan.' : '') . ' Known Bots sync: ' . ($synced ? human_time_diff($synced) . ' ago.' : 'not yet.')); ?></span>
                    </div>
                    <span class="bp-conn-pill"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Connected</span>
                    <form method="post" action="" style="margin:0;">
                        <?php wp_nonce_field('devdcorev1_account', '_ddacct'); ?>
                        <button type="submit" name="devdcorev1_disconnect" value="1" class="bp-conn-btn bp-conn-ghost">Disconnect</button>
                    </form>
                </div>
                <div class="bp-act-row">
                    <a class="dd-btn" href="https://analytics.devdome.com/" target="_blank" rel="noopener">Open The DevDome Dashboard</a>
                    <form method="post" action="">
                        <?php wp_nonce_field('devdbotp_kb', '_bpkb'); ?>
                        <button type="submit" name="devdbotp_kb_action" value="pull" class="dd-btn">Sync Now</button>
                    </form>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-cloud dd-ico"></span>
                <h2 class="dd-h2">Threat Feeds</h2>
            </div>
            <div class="dd-card">
                <div class="dd-stats bp-stats-5" style="margin-top:0;">
                    <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) $np; ?></div><div class="dd-stat-lbl">Verified Bot Patterns</div></div>
                    <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) ($nbv4 + $nbv6); ?></div><div class="dd-stat-lbl">Verified Bot IP Ranges</div></div>
                    <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) $na; ?></div><div class="dd-stat-lbl">Datacenter Networks</div></div>
                    <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) ($nd + $nd6); ?></div><div class="dd-stat-lbl">Blocklist Ranges</div></div>
                </div>
                <?php if ($outside_lookups) : ?>
                <form method="post" action="" style="margin-top:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <?php wp_nonce_field('devdbotp_settings', '_bpset'); ?>
                    <button type="submit" name="devdbotp_feed_refresh" value="1" class="dd-btn"><span class="dashicons dashicons-update"></span>Refresh Now</button>
                    <span class="dd-hint" style="margin:0;">Last synced <?php echo $fetched ? esc_html(human_time_diff($fetched) . ' ago') : 'never'; ?>. Feeds sync daily from the DevDome service and are matched locally. <?php devdbotp_tip('Verified bot patterns, the published verified bot IP ranges (Google, Bing, Apple, OpenAI), datacenter networks and the Spamhaus DROP blocklist. Only the lists are downloaded; no visitor data leaves the site.'); ?></span>
                </form>
                <?php else : ?>
                <p class="dd-hint" style="margin:14px 0 0;">The lists are not downloaded while Outside Lookups is off (Privacy, above).</p>
                <?php endif; ?>
            </div>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-shield dd-ico"></span>
                <h2 class="dd-h2">Data And Privacy</h2>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('devdbotp_settings', '_bpset'); ?>
                <div class="dd-card">
                    <table class="dd-table">
                        <tr>
                            <th class="dd-th">Time Zone</th>
                            <td class="dd-td">
                                <select name="bp_timezone" class="dd-input" style="width:100%;max-width:320px;">
                                    <option value="">Same as WordPress (<?php echo esc_html(wp_timezone_string()); ?>)</option>
                                    <?php foreach (timezone_identifiers_list() as $zone) : ?>
                                        <option value="<?php echo esc_attr($zone); ?>" <?php selected($timezone, $zone); ?>><?php echo esc_html(str_replace('_', ' ', $zone)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="dd-hint">The daily totals on the Overview and the Activity tab count each day from midnight to midnight in this time zone. <?php if ($connected) : ?>The DevDome dashboard shows this website in the same time zone. <?php endif; ?>A change applies from now on, days already counted stay as they are.</p>
                            </td>
                        </tr>
                        <tr>
                            <th class="dd-th">Anonymize IPs</th>
                            <td class="dd-td">
                                <label class="dd-opt"><input type="checkbox" name="bp_anonymize" value="1" class="dd-check" <?php checked($anonymize, 1); ?>> Mask visitor IPs in the logs</label>
                                <p class="dd-hint">IPv4 to /24, IPv6 to /48. Detection still works in full. <?php devdbotp_tip('Only the stored value is reduced. Repeat offender tracking and rate limits keep working on the full address in memory.'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th class="dd-th">Outside Lookups</th>
                            <td class="dd-td">
                                <label class="dd-opt"><input type="checkbox" name="bp_outside_lookups" value="1" class="dd-check" <?php checked($outside_lookups, 1); ?>> Download the bot lists and look up attacker networks</label>
                                <p class="dd-hint">Off by default: no list is downloaded and no address is sent to an outside service (Bot Radar on the Rules tab has its own switch; the good-bot check still asks this server's own DNS resolver). On: the bot lists are downloaded daily from DevDome, Google, Bing, Apple and OpenAI, and public IP addresses that hit a trap or match a flagged network are sent to the DevDome geo service to find their country and network. <?php devdbotp_tip('Without the lists, detection relies on the built-in rules only. The country and network lookups can include addresses that are not yet classified as bots. The DevDome account connection is separate and has its own consent.'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="dd-hint" style="margin:12px 0 0;">Bot and blocked events, sessions and offender history are kept <?php echo (int) devdbotp_retention_days(); ?> days and then deleted. Daily totals stay 3 years.</p>
                    <div style="margin-top:14px;"><?php devdbotp_save_button('devdbotp_settings_save'); ?></div>
                </div>
            </form>
            <div class="dd-card" style="margin-top:12px;">
                <span class="bp-title">Clear Statistics</span>
                <p class="dd-hint" style="margin:4px 0 0;">Removes every logged event, the daily and hourly totals, sessions and the learned offender history from this site. Your rules, Known Bots choices, custom rules and DevDome connection stay. The dashboard copy of the totals is cleared with the next sync.</p>
                <?php // DESIGN.md 23: destructive = type the word. bp-admin.js drives form[data-bp-typed]. ?>
                <form method="post" action="" style="margin-top:12px;" data-bp-typed="CLEAR">
                    <input type="hidden" name="devdbotp_clear_stats" value="1">
                    <?php wp_nonce_field('devdbotp_clear_stats', '_bpcl'); ?>
                    <button type="button" class="dd-btn dd-btn-danger" data-bp-typed-open>Clear Statistics</button>
                    <div class="bp-typed-confirm" hidden>
                        <p class="bp-typed-warn">This permanently deletes every event, total, session and learned offender on this site. It cannot be undone.</p>
                        <p class="bp-typed-ask">Type <strong>CLEAR</strong> to confirm.</p>
                        <input type="text" class="dd-input bp-typed-input" autocomplete="off" placeholder="CLEAR" aria-label="Type CLEAR to confirm">
                        <div class="bp-typed-btns">
                            <button type="submit" class="dd-btn dd-btn-danger" disabled>Clear Statistics</button>
                            <button type="button" class="dd-btn" data-bp-typed-cancel>Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section>
            <div class="dd-sec-head">
                <span class="dashicons dashicons-cloud dd-ico"></span>
                <h2 class="dd-h2">Cloudflare Rules</h2>
            </div>
            <div class="dd-card">
                <p class="dd-hint" style="margin-top:0;max-width:66%;">On a site behind Cloudflare these rules block at the edge, before a request reaches WordPress, cached pages included. Paste each one under Security, WAF, Custom rules with the action shown. Cloudflare-verified crawlers are exempt in every rule.</p>
                <?php foreach ($cf_waf_rules as $rule) : ?>
                    <div style="margin-top:14px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="bp-title" style="display:inline;"><?php echo esc_html($rule['name']); ?></span> <?php echo wp_kses(devdbotp_badge('bad', $rule['action']), devdbotp_badge_allowed()); ?></div>
                        <div class="bp-copy-row">
                            <textarea readonly class="dd-textarea" id="bp-cf-<?php echo esc_attr($rule['key']); ?>" rows="2" data-bp-select><?php echo esc_textarea($rule['expr']); ?></textarea>
                            <button type="button" class="dd-btn dd-btn-sm" data-bp-copy="bp-cf-<?php echo esc_attr($rule['key']); ?>">Copy</button>
                        </div>
                        <?php if (!empty($rule['note'])) : ?><p class="dd-hint" style="margin:6px 0 0;"><?php echo esc_html($rule['note']); ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="dd-modal" id="bp-export-modal" hidden role="dialog" aria-modal="true" aria-labelledby="bp-export-title">
            <div class="dd-modal-backdrop" data-bp-dialog-close></div>
            <div class="dd-modal-box">
                <div class="dd-modal-head"><h3 id="bp-export-title">Export Settings</h3><button type="button" class="dd-modal-x" data-bp-dialog-close aria-label="Close">&times;</button></div>
                <div class="dd-modal-body">
                    <div class="bp-copy-row" style="margin-top:0;">
                        <textarea readonly class="dd-textarea" id="bp-export" rows="8" data-bp-select><?php echo esc_textarea($export); ?></textarea>
                        <button type="button" class="dd-btn dd-btn-sm" data-bp-copy="bp-export">Copy</button>
                    </div>
                    <p class="dd-hint">Copy this to clone your protection settings onto another site. <?php devdbotp_tip('Levels, rules, AI controls and Known Bots travel. Stats, synced feeds, the Outside Lookups and Bot Radar consent and secrets never do.'); ?></p>
                </div>
            </div>
        </div>
        <div class="dd-modal" id="bp-import-modal" hidden role="dialog" aria-modal="true" aria-labelledby="bp-import-title">
            <div class="dd-modal-backdrop" data-bp-dialog-close></div>
            <div class="dd-modal-box">
                <div class="dd-modal-head"><h3 id="bp-import-title">Import Settings</h3><button type="button" class="dd-modal-x" data-bp-dialog-close aria-label="Close">&times;</button></div>
                <form method="post" action="" data-bp-confirm="Import these settings? This overwrites your current configuration.">
                    <div class="dd-modal-body">
                        <?php wp_nonce_field('devdbotp_settings', '_bpset'); ?>
                        <textarea name="bp_import_json" class="dd-textarea" rows="6" style="width:100%;" placeholder="Paste exported JSON here..."></textarea>
                        <p class="dd-hint">Only known settings are applied; stats and synced feeds are never touched.</p>
                    </div>
                    <div class="dd-modal-foot"><button type="button" class="dd-btn" data-bp-dialog-close>Cancel</button><button type="submit" name="devdbotp_import" value="1" class="dd-btn-primary">Import Settings</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php
}
