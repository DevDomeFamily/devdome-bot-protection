<?php
/**
 * WordPress Site Health tests (Tools > Site Health): protection state, threat feeds.
 * Direct tests only. Watch mode is a recommendation, never a critical issue.
 */

defined('ABSPATH') || exit;

add_filter('site_status_tests', 'devdbotp_site_health_tests');
function devdbotp_site_health_tests($tests)
{
    if (!is_array($tests)) {
        return $tests;
    }
    if (!isset($tests['direct']) || !is_array($tests['direct'])) {
        $tests['direct'] = array();
    }
    $tests['direct']['devdbotp_protection'] = array(
        'label' => __('DevDome Bot Protection state', 'devdome-bot-protection'),
        'test'  => 'devdbotp_site_health_protection',
    );
    $tests['direct']['devdbotp_feeds'] = array(
        'label' => __('DevDome Bot Protection threat feeds', 'devdome-bot-protection'),
        'test'  => 'devdbotp_site_health_feeds',
    );
    return $tests;
}

/** One result in the shape Site Health expects. */
function devdbotp_site_health_result($test, $status, $label, $description)
{
    return array(
        'label'       => $label,
        'status'      => $status, // good | recommended
        'badge'       => array(
            'label' => __('Security', 'devdome-bot-protection'),
            'color' => 'good' === $status ? 'blue' : 'orange',
        ),
        'description' => '<p>' . esc_html($description) . '</p>',
        'actions'     => '',
        'test'        => $test,
    );
}

function devdbotp_site_health_protection()
{
    $test = 'devdbotp_protection';
    $level = (string) devdbotp_get_setting('protection_level', 'recommended');
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return devdbotp_site_health_result($test, 'critical',
            __('Bot protection state could not be read', 'devdome-bot-protection'),
            __('The plugin could not read its settings from the database, so its state is unknown. Check the database and load this page again.', 'devdome-bot-protection'));
    }
    if ('off' === $level) {
        return devdbotp_site_health_result($test, 'recommended',
            __('Bot protection is turned off', 'devdome-bot-protection'),
            __('DevDome Bot Protection is installed but protection is off, so no request is checked or blocked. Turn it on in the plugin screen.', 'devdome-bot-protection'));
    }
    if ((int) devdbotp_get_setting('safe_mode_until', 0) > time()) {
        return devdbotp_site_health_result($test, 'recommended',
            __('Bot protection is paused', 'devdome-bot-protection'),
            __('Protection is paused for up to 30 minutes and nothing is blocked during that time. It resumes by itself, or you can resume it in the plugin screen.', 'devdome-bot-protection'));
    }
    if ('live' === (string) devdbotp_get_setting('mode', 'watching')) {
        return devdbotp_site_health_result($test, 'good',
            __('Bot protection is on and Live', 'devdome-bot-protection'),
            __('Harmful bots are detected and blocked.', 'devdome-bot-protection'));
    }
    return devdbotp_site_health_result($test, 'recommended',
        __('Bot protection is watching only', 'devdome-bot-protection'),
        __('Bots are detected and logged but nothing is blocked. This is the safe starting mode. Choose Block on the plugin screen when the log looks right.', 'devdome-bot-protection'));
}

function devdbotp_site_health_feeds()
{
    $test  = 'devdbotp_feeds';
    $feeds = devdbotp_feeds();
    if (is_array($feeds) && !empty($feeds['patterns'])) {
        return devdbotp_site_health_result($test, 'good',
            __('Threat feeds are synced', 'devdome-bot-protection'),
            __('The bot pattern lists used for detection are present on this site.', 'devdome-bot-protection'));
    }
    return devdbotp_site_health_result($test, 'recommended',
        __('Threat feeds are not synced yet', 'devdome-bot-protection'),
        __('The bot pattern lists have not been downloaded, so detection is limited. They refresh once a day. You can refresh them now in the plugin settings.', 'devdome-bot-protection'));
}
