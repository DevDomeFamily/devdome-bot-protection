<?php
/**
 * Multisite: every site of a network gets its own tables and seeded settings on network activation,
 * and a site created later gets them through wp_initialize_site. Removal per site is in uninstall.php.
 * Same shape as Country Blocker includes/install.php.
 */

defined('ABSPATH') || exit;

/** Run a per-site callback across every site of a network, in batches of 100. */
function devdbotp_for_each_site($callback)
{
    $offset = 0;
    do {
        $sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset));
        foreach ($sites as $blog_id) {
            switch_to_blog((int) $blog_id);
            call_user_func($callback);
            restore_current_blog();
        }
        $offset += 100;
    } while (count($sites) === 100);
}

/**
 * The per-site activation for a site that is not the one being activated by hand: devdbotp_activate() with
 * every HTTP request refused, so a large network never waits on the network (activation makes none anyway).
 */
function devdbotp_activate_site()
{
    unset($GLOBALS['devdbotp_cache']); // the settings cache belongs to the previous site
    add_filter('pre_http_request', 'devdbotp_multisite_no_http', 10, 1);
    devdbotp_activate();
    remove_filter('pre_http_request', 'devdbotp_multisite_no_http', 10);
    unset($GLOBALS['devdbotp_cache']);
}

function devdbotp_multisite_no_http($pre)
{
    return new WP_Error('devdbotp_deferred', 'Deferred to the scheduled feed refresh.');
}

/** Network activation: the main file's hook has set up the current site, this does the others. */
function devdbotp_network_activate($network_wide = false)
{
    if (!is_multisite() || !$network_wide) {
        return;
    }
    $current = get_current_blog_id();
    devdbotp_for_each_site(function () use ($current) {
        if (get_current_blog_id() !== $current) {
            devdbotp_activate_site();
        }
    });
}
add_action('activate_' . plugin_basename(DEVDBOTP_DIR . 'devdome-bot-protection.php'), 'devdbotp_network_activate', 20, 1);

/** New subsite created while the plugin is network-active: give it its tables + settings. */
function devdbotp_initialize_site($new_site)
{
    // The network-active list straight from its option: no core file is pulled in for is_plugin_active_for_network().
    $network = (array) get_site_option('active_sitewide_plugins', array());
    if (!isset($network[plugin_basename(DEVDBOTP_DIR . 'devdome-bot-protection.php')])) {
        return;
    }
    switch_to_blog((int) $new_site->blog_id);
    devdbotp_activate_site();
    restore_current_blog();
}
add_action('wp_initialize_site', 'devdbotp_initialize_site', 10, 1);

/** Network deactivation: every blog's activation flag and schedules go, not only the current blog's. */
add_action('deactivate_' . plugin_basename(DEVDBOTP_DIR . 'devdome-bot-protection.php'), 'devdbotp_network_deactivate_mark', 1, 1);
function devdbotp_network_deactivate_mark($network_wide = false)
{
    $GLOBALS['devdbotp_network_deactivating'] = (bool) $network_wide;
}
