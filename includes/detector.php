<?php
/**
 * Deterministic request scorer (BOT PROTECTION.md §7–§9). No ML, no per-request
 * network. Order matters: the SEO-safe verified-bot allowlist sits ABOVE every
 * AUTOMATIC block rule so detection can never block a search crawler (the
 * cardinal rule). Two deliberate owner choices sit above the allowlist: the AI
 * hard-block toggle and an explicit per-bot Known Bots block (blocking e.g.
 * Bing must work when the owner asks for it) — public copy must say so.
 *
 * devdbotp_evaluate($ctx) is PURE (feeds passed in / read once) so it can be
 * unit-tested. It returns: category, score, labels[], action, reason.
 */

defined('ABSPATH') || exit;

/** Scanner / exploit paths (substring match against the request path). §7 */
function devdbotp_scanner_paths()
{
    return array(
        '/.env', '/.git', '/.svn', '/.hg', '/.ds_store', '/.aws/credentials',
        '/config.php', '/config.json', '/config.yml', '/backup', '/backup.zip',
        '/db.sql', '/database.sql', '/dump.sql', '/wp-config.php.bak',
        '/wp-config.php.save', '/phpinfo.php', '/info.php', '/adminer.php',
        '/manager/html', '/vendor/phpunit', '/cgi-bin', '/wp-admin/install.php',
    );
}

/** Known scanner / exploit tool UAs (substring match, lowercased). §7 */
function devdbotp_scanner_uas()
{
    return array(
        'sqlmap', 'nikto', 'nuclei', 'zgrab', 'masscan', 'acunetix', 'nessus',
        'openvas', 'wpscan', 'dirbuster', 'gobuster', 'feroxbuster', 'ffuf',
        'whatweb', 'jaeles', 'libwww-perl',
    );
    // NB: curl/wget/python-requests/Go-http-client/Scrapy are flagged (suspicious)
    // not hard-blocked — they have legitimate uses; see devdbotp_suspicious_uas().
}

/** Generic automation UAs — suspicious (points) but not an instant block. */
function devdbotp_suspicious_uas()
{
    return array('python-requests', 'go-http-client', 'aiohttp', 'scrapy', 'httpx', 'curl/', 'wget/');
}

/** Classify the endpoint to pick a threshold band. §8.3 */
function devdbotp_endpoint_type($path)
{
    $p = strtolower($path);
    if (strpos($p, '/wp-login.php') !== false || strpos($p, '/wp-admin') !== false || strpos($p, '/xmlrpc.php') !== false) {
        return 'wp';
    }
    if (strpos($p, '/wp-json') !== false || strpos($p, 'wc-api') !== false || strpos($p, '/?rest_route') !== false) {
        return 'sensitive';
    }
    return 'public';
}

/**
 * Built-in detection rules the user can tune on the Rules tab. The single source
 * of truth for rule keys + default actions (used by the engine) and group / label
 * / description (used by the admin UI). `labels` are the detector signal labels
 * that satisfy each rule.
 */
function devdbotp_rules_catalog()
{
    return array(
        array('key' => 'scanner_path', 'group' => 'Scanners & Exploits', 'default' => 'block',
              'label' => 'Scanner & exploit paths',
              'desc'  => 'Requests probing for /.env, /.git, backups, config files and other exploit paths.',
              'labels' => array('scanner_path')),
        array('key' => 'scanner_ua', 'group' => 'Scanners & Exploits', 'default' => 'block',
              'label' => 'Known scanner tools',
              'desc'  => 'Security scanners by user-agent — sqlmap, Nikto, WPScan, Nuclei and similar.',
              'labels' => array('scanner_ua')),
        array('key' => 'spamhaus', 'group' => 'Bad IPs & Networks', 'default' => 'block',
              'label' => 'Malicious IPs (Spamhaus DROP)',
              'desc'  => 'IPs on the Spamhaus DROP list — hijacked or criminal networks.',
              'labels' => array('spamhaus_drop')),
        array('key' => 'datacenter', 'group' => 'Bad IPs & Networks', 'default' => 'log',
              'label' => 'Datacenter & hosting traffic',
              'desc'  => 'Traffic from hosting / cloud ASNs — rarely real visitors, especially on login & REST.',
              'labels' => array('datacenter_asn', 'datacenter_asn_sensitive')),
        array('key' => 'empty_ua', 'group' => 'Suspicious Clients', 'default' => 'log',
              'label' => 'Empty user-agent',
              'desc'  => 'Requests with no user-agent string. Browsers always send one, some scripts and integrations do not, so record by default.',
              'labels' => array('empty_ua')),
        array('key' => 'suspicious_ua', 'group' => 'Suspicious Clients', 'default' => 'log',
              'label' => 'Automation tools',
              'desc'  => 'Generic HTTP libraries — curl, wget, python-requests, Scrapy. Legit uses exist, so flag by default.',
              'labels' => array('suspicious_ua')),
    );
}

/** Resolved action per rule key = code defaults overlaid with the saved choices. */
function devdbotp_rule_actions()
{
    $defaults = array();
    foreach (devdbotp_rules_catalog() as $r) {
        $defaults[$r['key']] = $r['default'];
    }
    $saved = devdbotp_get_setting('rule_actions', array());
    if (!is_array($saved)) {
        return $defaults;
    }
    $valid = array('allow', 'off', 'log', 'challenge', 'block');
    foreach ($saved as $k => $v) {
        if (isset($defaults[$k]) && in_array($v, $valid, true)) {
            $defaults[$k] = ($v === 'challenge') ? 'log' : $v; // Challenge was removed in 0.4.1; it always acted like Log
        }
    }
    return $defaults;
}

/**
 * Evaluate a request.
 * $ctx = ['ua'=>'', 'ip'=>'', 'path'=>'', 'method'=>'GET', 'asn'=>int|null, 'feeds'=>array]
 */
function devdbotp_evaluate($ctx)
{
    $ua   = isset($ctx['ua']) ? strtolower((string) $ctx['ua']) : '';
    $ip   = isset($ctx['ip']) ? (string) $ctx['ip'] : '';
    $path = isset($ctx['path']) ? (string) $ctx['path'] : '/';
    $asn  = isset($ctx['asn']) ? (int) $ctx['asn'] : 0;
    $feeds = isset($ctx['feeds']) ? $ctx['feeds'] : devdbotp_feeds();

    // 0. YOUR ALLOW RULES. A custom rule set to Allow is the owner's exception (own monitoring, a partner,
    //    a wrongly blocked visitor): it sits above every block, the Known Bots switches included, and the
    //    runtime skips its rate limits for it ('exempt').
    if (!empty($ctx['custom_rules']) && is_array($ctx['custom_rules']) && function_exists('devdbotp_match_custom_rule')) {
        foreach ($ctx['custom_rules'] as $cr) {
            if (isset($cr['action']) && $cr['action'] === 'allow' && devdbotp_match_custom_rule($cr, $ctx)) {
                $r = devdbotp_result('human', 0, array('custom_allow'), 'allow', 'Allowed by your rule' . (!empty($cr['name']) ? ' (' . $cr['name'] . ')' : ''));
                $r['exempt'] = true;
                return $r;
            }
        }
    }

    // Known Bots switches (dashboard-synced, AI crawlers included). An explicit per-bot block is the
    // user's deliberate choice, so it sits ABOVE the allowlist — blocking e.g. Bing must work.
    if (function_exists('devdbotp_kb_block_match')) {
        $kb = devdbotp_kb_block_match($ua);
        if ($kb !== '') {
            return devdbotp_result('known_bot', 100, array('known_bot'), 'block', 'Blocked known bot (' . $kb . ')');
        }
    }

    // 1. SEO-SAFE ALLOWLIST — above every automatic rule below (the deliberate Known
    //    Bots override above is the only exception). A verified good bot is
    //    never blocked by detection.
    //    UA must be a known good-bot pattern AND the IP must prove it (published range
    //    or cached reverse DNS) — a spoofed good-bot UA from a random IP is NOT trusted.
    if (devdbotp_ua_is_verified_bot($ua, $ip, $feeds)) {
        return devdbotp_result('verified_bot', -100, array('verified_bot'), 'allow', 'Verified search/good bot — SEO-safe allowlist');
    }

    // (Admin-IP trust is handled in the runtime as an ENFORCEMENT guard, not here —
    // it must never disable detection/logging, and a shared IP must never whitelist all.)

    // Per-rule detection (BOT PROTECTION.md §8–§9, made user-configurable on the
    // Rules tab): detect each signal, then apply that rule's chosen action. The
    // request's final action = the most severe ACTIVE rule it matches; rules set to
    // Allow/Off are ignored entirely. (Replaces the old fixed score-summing model so
    // each rule acts independently and predictably — what the per-rule UI implies.)
    $endpoint = devdbotp_endpoint_type($path);
    $actions  = devdbotp_rule_actions();
    $severity = array('allow' => 0, 'off' => 0, 'log' => 1, 'challenge' => 2, 'block' => 3);

    $present = array();
    $pl = strtolower((string) strtok((string) $path, '?'));
    foreach (devdbotp_scanner_paths() as $sp) {
        // A hit only at a segment boundary: /backup, /backup/, /backup.zip match; /backup-guide/ does not.
        if (preg_match('#(^|/)' . preg_quote(ltrim($sp, '/'), '#') . '(/|\.|$)#', $pl)) { $present['scanner_path'] = true; break; }
    }
    foreach (devdbotp_scanner_uas() as $su) {
        if ($su !== '' && strpos($ua, $su) !== false) { $present['scanner_ua'] = true; break; }
    }
    if ($ip !== '' && devdbotp_ip_in_drop($ip, $feeds)) { $present['spamhaus_drop'] = true; }
    if ($ua === '') { $present['empty_ua'] = true; }
    foreach (devdbotp_suspicious_uas() as $su) {
        if (strpos($ua, $su) !== false) { $present['suspicious_ua'] = true; break; }
    }
    if ($asn && devdbotp_is_datacenter_asn($asn, $feeds)) {
        $present[$endpoint === 'public' ? 'datacenter_asn' : 'datacenter_asn_sensitive'] = true;
    }

    $final  = 'allow';
    $labels = array();
    foreach (devdbotp_rules_catalog() as $rule) {
        $hit = false;
        foreach ($rule['labels'] as $lbl) {
            if (isset($present[$lbl])) { $hit = true; break; }
        }
        if (!$hit) { continue; }
        $act = isset($actions[$rule['key']]) ? $actions[$rule['key']] : 'log';
        if ($act === 'allow' || $act === 'off') { continue; } // rule disabled — ignore this signal
        foreach ($rule['labels'] as $lbl) {
            if (isset($present[$lbl])) { $labels[] = $lbl; }
        }
        if ($severity[$act] > $severity[$final]) { $final = $act; }
    }

    // ---- Custom user rules (Rules tab): match by UA / path / IP / country / ASN ----
    $custom = isset($ctx['custom_rules']) && is_array($ctx['custom_rules']) ? $ctx['custom_rules'] : array();
    foreach ($custom as $cr) {
        $act = isset($cr['action']) ? $cr['action'] : 'log';
        if ($act === 'allow' || $act === 'off' || !isset($severity[$act])) { continue; }
        if (function_exists('devdbotp_match_custom_rule') && devdbotp_match_custom_rule($cr, $ctx)) {
            $labels[] = 'custom_rule';
            if ($severity[$act] > $severity[$final]) { $final = $act; }
        }
    }

    // ---- IP reputation: a repeat offender is acted on even on an otherwise-clean request ----
    if (!empty($ctx['ip_repeat_offender'])) {
        $act = isset($ctx['repeat_offender_action']) ? $ctx['repeat_offender_action'] : 'block';
        if ($act !== 'allow' && $act !== 'off' && isset($severity[$act])) {
            $labels[] = 'repeat_offender';
            if ($severity[$act] > $severity[$final]) { $final = $act; }
        }
    }

    // ---- Behavioral: automated browser / abnormal session (from the JS beacon) ----
    if (!empty($ctx['ip_behavior'])) {
        $act = isset($ctx['behavior_action']) ? $ctx['behavior_action'] : 'log';
        if ($act !== 'allow' && $act !== 'off' && isset($severity[$act])) {
            $labels[] = 'behavior';
            if ($severity[$act] > $severity[$final]) { $final = $act; }
        }
    }

    // ---- Fleet / Bot Radar: IP or ASN on the opt-in community blocklist (>= N sites confirmed it) ----
    if (!empty($ctx['ip_fleet']) || !empty($ctx['asn_fleet'])) {
        $act = isset($ctx['fleet_action']) ? $ctx['fleet_action'] : 'log';
        if ($act !== 'allow' && $act !== 'off' && isset($severity[$act])) {
            $labels[] = 'fleet';
            if ($severity[$act] > $severity[$final]) { $final = $act; }
        }
    }

    // ---- Honeypot: this request's ASN was caught crawling the hidden trap link (rotating-proxy
    // crawler). The IPs rotate but the ASN is stable; flagged only after >= K distinct IPs hit it. ----
    if (!empty($ctx['honeypot_asn'])) {
        $act = isset($ctx['honeypot_action']) ? $ctx['honeypot_action'] : 'log';
        if ($act !== 'allow' && $act !== 'off' && isset($severity[$act])) {
            $labels[] = 'honeypot';
            if ($severity[$act] > $severity[$final]) { $final = $act; }
        }
    }

    // ---- Category (for the dashboard breakdown) ----
    if (in_array('scanner_path', $labels, true) || in_array('scanner_ua', $labels, true)) {
        $category = 'scanner';
    } elseif (in_array('spamhaus_drop', $labels, true) || in_array('repeat_offender', $labels, true) || in_array('fleet', $labels, true)) {
        $category = 'malicious_ip';
    } elseif (in_array('custom_rule', $labels, true)) {
        $category = 'custom';
    } elseif (in_array('honeypot', $labels, true)) {
        $category = 'honeypot';
    } elseif (in_array('behavior', $labels, true)) {
        $category = 'automated';
    } elseif (in_array('datacenter_asn', $labels, true) || in_array('datacenter_asn_sensitive', $labels, true)) {
        $category = 'datacenter';
    } elseif (!empty($labels)) {
        $category = 'suspicious';
    } else {
        $category = 'human';
    }

    $score_map = array('allow' => 0, 'off' => 0, 'log' => 40, 'challenge' => 70, 'block' => 100);
    $reason = empty($labels) ? 'No bot signals' : implode(', ', $labels);
    return devdbotp_result($category, $score_map[$final], $labels, $final, $reason);
}

function devdbotp_result($category, $score, $labels, $action, $reason)
{
    return array(
        'category' => $category,
        'score'    => (int) $score,
        'labels'   => $labels,
        'action'   => $action,   // allow | log | block
        'reason'   => $reason,
        'is_bot'   => ($category !== 'human'),
    );
}
