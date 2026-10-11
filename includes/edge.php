<?php
/**
 * EDGE tier (BOT-PROTECTION-ARCHITECTURE.md §4a + §10.2). Generates copy-paste
 * Cloudflare custom-rule expressions — the only tier that can enforce on pages
 * served straight from the edge cache, where PHP (HOT + WARM) never runs.
 *
 * Free-plan friendly: copy-paste, no API (the API deploy was removed 2026-09-28 after a Codex review: it held a
 * WAF-edit token in the settings table and was never verified against a zone). Every rule carries
 * `not cf.client.bot`, so Cloudflare-verified crawlers pass. No challenge rules: a challenge is a visible gate.
 */

defined('ABSPATH') || exit;

/** The Cloudflare custom rules to paste under Security -> WAF -> Custom rules. */
function devdbotp_waf_rules()
{
    $scanner_paths = array('.env', '.git', 'wp-config.php.bak', 'wp-config.php.save', 'phpinfo.php', 'adminer.php', '/vendor/phpunit', '.aws/credentials');
    $parts = array();
    foreach ($scanner_paths as $p) {
        $parts[] = 'http.request.uri.path contains "' . $p . '"';
    }
    $scanners = '(' . implode(') or (', $parts) . ')';

    return array(
        array(
            'key'    => 'scanners',
            'name'   => 'DevDome: block scanners and exploit paths',
            'expr'   => '(' . $scanners . ') and not cf.client.bot',
            'action' => 'Block',
            'note'   => 'Safe for every site.',
        ),
        array(
            'key'    => 'xmlrpc',
            'name'   => 'DevDome: block XML-RPC',
            'expr'   => '(http.request.uri.path eq "/xmlrpc.php") and not cf.client.bot',
            'action' => 'Block',
            'note'   => 'Optional. Skip it if you use the WordPress mobile app, Jetpack or another service that talks to xmlrpc.php.',
        ),
    );
}
