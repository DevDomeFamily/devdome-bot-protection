<?php
/**
 * Known Bots: the same bot catalog + switches as the DevDome dashboard's
 * Bot Protection → Known Bots view, enforced on-site. The blocked list syncs
 * BOTH ways with the dashboard through the suite site token: pull on an hourly
 * cron + on tab render, push on every local toggle. Without a connected account
 * the switches still work locally.
 *
 * Catalog v3 (2026-09-16, owner): the AI Crawlers tab is folded in here. A blocked
 * bot gets a Disallow group in the virtual robots.txt AND a 403 (Live mode); a
 * robots_only entry (Google AI, Apple AI) is a data-use token that never visits, so
 * only the robots.txt line applies to it.
 */

defined('ABSPATH') || exit;

/**
 * Catalog mirror of the dashboard's BP_GROUPS: same ids (the sync contract), same order, names, one-line ds, dsLong
 * tooltip and recommendations. tokens = lowercase UA substrings for the 403 match, none = longer tokens that must not
 * match (plain Applebot, never Applebot-Extended), robots = the robots.txt User-agent tokens, robots_only = no visits.
 */
function devdbotp_kb_catalog()
{
    return array(
        array('id' => 'ai', 'label' => 'AI Crawlers', 'rec' => 'block', 'bots' => array(
            array('id' => 'openai',       'name' => 'OpenAI training',       'ds' => 'GPTBot, model training crawler', 'tokens' => array('gptbot'), 'robots' => array('GPTBot')),
            array('id' => 'oaisearch',    'name' => 'OpenAI search',         'ds' => 'Powers ChatGPT search results', 'dsLong' => 'OAI-SearchBot, blocking removes you from ChatGPT search results', 'tokens' => array('oai-searchbot'), 'robots' => array('OAI-SearchBot'), 'rec' => 'allow'),
            array('id' => 'chatgptuser',  'name' => 'ChatGPT user requests', 'ds' => 'ChatGPT user page fetcher', 'dsLong' => 'ChatGPT-User, fetches pages a real person asked for; may not honor robots.txt', 'tokens' => array('chatgpt-user'), 'robots' => array('ChatGPT-User'), 'rec' => 'allow'),
            array('id' => 'oaiads',       'name' => 'OpenAI ads',            'ds' => 'OpenAI ads verification bot', 'dsLong' => 'OAI-AdsBot, ads quality/verification fetcher', 'tokens' => array('oai-adsbot'), 'robots' => array('OAI-AdsBot'), 'rec' => 'allow'),
            array('id' => 'anthropic',    'name' => 'Anthropic training',    'ds' => 'ClaudeBot training crawler', 'dsLong' => 'ClaudeBot, model training crawler', 'tokens' => array('claudebot', 'claude-web', 'anthropic-ai'), 'robots' => array('ClaudeBot', 'Claude-Web', 'anthropic-ai')),
            array('id' => 'claudeuser',   'name' => 'Claude user requests',  'ds' => 'Claude user page fetcher', 'dsLong' => 'Claude-User, fetches pages a real person asked for', 'tokens' => array('claude-user'), 'robots' => array('Claude-User'), 'rec' => 'allow'),
            array('id' => 'claudesearch', 'name' => 'Claude search',         'ds' => 'Powers Claude search results', 'dsLong' => 'Claude-SearchBot, blocking reduces your visibility in Claude search', 'tokens' => array('claude-searchbot'), 'robots' => array('Claude-SearchBot'), 'rec' => 'allow'),
            array('id' => 'perplexity',   'name' => 'Perplexity',            'ds' => 'PerplexityBot answer engine', 'dsLong' => 'PerplexityBot, indexes for Perplexity answers with a link to you; not used for model training', 'tokens' => array('perplexitybot'), 'robots' => array('PerplexityBot'), 'rec' => 'allow'),
            array('id' => 'perplexityuser', 'name' => 'Perplexity user requests', 'ds' => 'Perplexity user page fetcher', 'dsLong' => 'Perplexity-User, fetches pages a real person asked for', 'tokens' => array('perplexity-user'), 'robots' => array('Perplexity-User'), 'rec' => 'allow'),
            array('id' => 'ccbot',        'name' => 'Common Crawl',          'ds' => 'Feeds most AI training sets', 'dsLong' => 'CCBot, feeds most AI training sets', 'tokens' => array('ccbot'), 'robots' => array('CCBot')),
            array('id' => 'googleext',    'name' => 'Google AI',             'ds' => 'Gemini answers and training token', 'dsLong' => 'Google-Extended, one token for Gemini answer grounding AND training; blocking it also drops you from Gemini answers. Google Search is separate', 'tokens' => array(), 'robots' => array('Google-Extended'), 'robots_only' => true, 'rec' => 'allow'),
            array('id' => 'googleother',  'name' => 'Google research',       'ds' => 'GoogleOther, internal research crawls', 'dsLong' => 'GoogleOther, Google research and development crawls, no search benefit', 'tokens' => array('googleother'), 'robots' => array('GoogleOther')),
            array('id' => 'appleext',     'name' => 'Apple AI',              'ds' => 'Apple Intelligence training opt-out token', 'dsLong' => 'Applebot-Extended, Apple Intelligence training opt-out token', 'tokens' => array(), 'robots' => array('Applebot-Extended'), 'robots_only' => true),
            array('id' => 'bytespider',   'name' => 'ByteDance',             'ds' => 'Bytespider, TikTok’s crawler', 'tokens' => array('bytespider'), 'robots' => array('Bytespider')),
            array('id' => 'metaai',       'name' => 'Meta AI',               'ds' => 'Meta AI training crawler', 'dsLong' => 'meta-externalagent, FacebookBot', 'tokens' => array('meta-externalagent', 'facebookbot'), 'robots' => array('meta-externalagent', 'FacebookBot')),
            array('id' => 'metaindex',    'name' => 'Meta AI search',        'ds' => 'Meta AI search indexing', 'dsLong' => 'Meta-WebIndexer, indexes for Meta AI search answers', 'tokens' => array('meta-webindexer'), 'robots' => array('Meta-WebIndexer'), 'rec' => 'allow'),
            array('id' => 'metafetch',    'name' => 'Meta AI user requests', 'ds' => 'Meta AI user page fetcher', 'dsLong' => 'meta-externalfetcher, fetches pages a real person asked Meta AI for', 'tokens' => array('meta-externalfetcher'), 'robots' => array('meta-externalfetcher'), 'rec' => 'allow'),
            array('id' => 'amazonbot',    'name' => 'Amazonbot',             'ds' => 'Amazon AI crawler', 'dsLong' => 'Amazonbot, Amazon crawler incl. model training; Alexa search and user fetches have their own bots below', 'tokens' => array('amazonbot'), 'robots' => array('Amazonbot')),
            array('id' => 'amznsearch',   'name' => 'Amazon search',         'ds' => 'Alexa and Amazon search indexing', 'dsLong' => 'Amzn-SearchBot, indexes for Alexa and Amazon search answers, not training', 'tokens' => array('amzn-searchbot'), 'robots' => array('Amzn-SearchBot'), 'rec' => 'allow'),
            array('id' => 'amznuser',     'name' => 'Amazon user requests',  'ds' => 'Alexa user page fetcher', 'dsLong' => 'Amzn-User, fetches pages a real person asked Alexa for', 'tokens' => array('amzn-user'), 'robots' => array('Amzn-User'), 'rec' => 'allow'),
            array('id' => 'diffbot',      'name' => 'Diffbot',               'ds' => 'Diffbot search crawler', 'dsLong' => 'Diffbot, crawls for its search and citation products, not model training', 'tokens' => array('diffbot'), 'none' => array('diffbot-user'), 'robots' => array('Diffbot'), 'rec' => 'allow'),
            array('id' => 'diffbotuser',  'name' => 'Diffbot user requests', 'ds' => 'Diffbot user page fetcher', 'dsLong' => 'Diffbot-User, fetches pages a real person asked for', 'tokens' => array('diffbot-user'), 'robots' => array('Diffbot-User'), 'rec' => 'allow'),
        )),
        array('id' => 'search', 'label' => 'Search Engines', 'rec' => 'allow', 'bots' => array(
            array('id' => 'googlebot', 'name' => 'Google',     'ds' => 'Google’s search index crawler', 'dsLong' => 'Googlebot search indexing', 'tokens' => array('googlebot'), 'robots' => array('Googlebot')),
            array('id' => 'bingbot',   'name' => 'Bing',       'ds' => 'Bingbot search index crawler', 'dsLong' => 'bingbot search indexing', 'tokens' => array('bingbot'), 'robots' => array('Bingbot')),
            array('id' => 'duckduck',  'name' => 'DuckDuckGo', 'ds' => 'DuckDuckBot search crawler', 'tokens' => array('duckduckbot'), 'robots' => array('DuckDuckBot')),
            array('id' => 'yandex',    'name' => 'Yandex',     'ds' => 'Yandex search index crawler', 'dsLong' => 'YandexBot, the search index for Russian-speaking markets; block it only if you never want that traffic', 'tokens' => array('yandexbot'), 'robots' => array('YandexBot')),
            array('id' => 'baidu',     'name' => 'Baidu',      'ds' => 'Baidu search index crawler', 'dsLong' => 'Baiduspider, the search index for mainland China; block it only if you never want that traffic', 'tokens' => array('baiduspider'), 'robots' => array('Baiduspider')),
            array('id' => 'naver',     'name' => 'Naver',      'ds' => 'Yeti, Naver search crawler', 'dsLong' => 'Yeti, the search index for Korea', 'tokens' => array('yeti/'), 'robots' => array('Yeti')),
            array('id' => 'seznam',    'name' => 'Seznam',     'ds' => 'SeznamBot search crawler', 'dsLong' => 'SeznamBot, the search index for Czechia', 'tokens' => array('seznambot'), 'robots' => array('SeznamBot')),
            array('id' => 'storebot',  'name' => 'Google Shopping', 'ds' => 'Storebot-Google product crawler', 'dsLong' => 'Storebot-Google, reads product pages for Google Shopping', 'tokens' => array('storebot-google'), 'robots' => array('Storebot-Google')),
            array('id' => 'ginspect',  'name' => 'Google Inspection', 'ds' => 'Search Console testing tool', 'dsLong' => 'Google-InspectionTool, the URL inspection and rich results tests you run yourself', 'tokens' => array('google-inspectiontool'), 'robots' => array('Google-InspectionTool')),
            array('id' => 'applebot',  'name' => 'Apple',      'ds' => 'Applebot (Siri and Spotlight)', 'tokens' => array('applebot'), 'none' => array('applebot-extended'), 'robots' => array('Applebot')),
        )),
        array('id' => 'seo', 'label' => 'SEO And Backlink Crawlers', 'rec' => 'block', 'bots' => array(
            array('id' => 'ahrefs',        'name' => 'Ahrefs',         'ds' => 'AhrefsBot backlink index and Yep search', 'dsLong' => 'AhrefsBot, also powers the Yep search engine, so blocking costs that discovery', 'tokens' => array('ahrefsbot'), 'robots' => array('AhrefsBot'), 'rec' => 'allow'),
            array('id' => 'ahrefsaudit',   'name' => 'Ahrefs Site Audit', 'ds' => 'Audit crawls you start yourself', 'tokens' => array('ahrefssiteaudit'), 'robots' => array('AhrefsSiteAudit'), 'rec' => 'allow'),
            array('id' => 'semrush',       'name' => 'Semrush',        'ds' => 'SemrushBot SEO data crawler', 'tokens' => array('semrushbot'), 'none' => array('semrushbot-sa'), 'robots' => array('SemrushBot')),
            array('id' => 'semrushaudit',  'name' => 'Semrush Site Audit', 'ds' => 'Audit crawls you start yourself', 'tokens' => array('siteauditbot', 'semrushbot-sa'), 'robots' => array('SiteAuditBot', 'SemrushBot-SA'), 'rec' => 'allow'),
            array('id' => 'majestic',      'name' => 'Majestic',       'ds' => 'MJ12bot backlink crawler', 'tokens' => array('mj12bot'), 'robots' => array('MJ12bot')),
            array('id' => 'moz',           'name' => 'Moz',            'ds' => 'DotBot and rogerbot crawlers', 'tokens' => array('dotbot', 'rogerbot'), 'robots' => array('DotBot', 'rogerbot')),
            array('id' => 'dataforseo',    'name' => 'DataForSEO',     'ds' => 'DataForSeoBot SEO data crawler', 'tokens' => array('dataforseo'), 'robots' => array('DataForSeoBot')),
            array('id' => 'blexbot',       'name' => 'BLEXBot',        'ds' => 'WebMeUp backlink crawler', 'tokens' => array('blexbot'), 'robots' => array('BLEXBot')),
            array('id' => 'screamingfrog', 'name' => 'Screaming Frog', 'ds' => 'Site-audit spider you run yourself', 'tokens' => array('screaming frog'), 'robots' => array('Screaming Frog SEO Spider'), 'rec' => 'allow'),
            array('id' => 'serpstat',      'name' => 'Serpstat',       'ds' => 'serpstatbot SEO crawler', 'tokens' => array('serpstatbot'), 'robots' => array('serpstatbot')),
        )),
        array('id' => 'social', 'label' => 'Social Link Previews', 'rec' => 'allow', 'bots' => array(
            array('id' => 'fbpreview', 'name' => 'Facebook',    'ds' => 'Facebook link preview fetcher', 'dsLong' => 'facebookexternalhit link preview', 'tokens' => array('facebookexternalhit', 'facebookcatalog'), 'robots' => array('facebookexternalhit')),
            array('id' => 'twitter',   'name' => 'X / Twitter', 'ds' => 'Twitterbot card preview', 'tokens' => array('twitterbot'), 'none' => array('telegrambot'), 'robots' => array('Twitterbot')),
            array('id' => 'linkedin',  'name' => 'LinkedIn',    'ds' => 'LinkedInBot preview', 'tokens' => array('linkedinbot'), 'robots' => array('LinkedInBot')),
            array('id' => 'slack',     'name' => 'Slack',       'ds' => 'Slackbot link expander', 'tokens' => array('slackbot'), 'robots' => array('Slackbot')),
            array('id' => 'discord',   'name' => 'Discord',     'ds' => 'Discordbot preview', 'tokens' => array('discordbot'), 'robots' => array('Discordbot')),
            array('id' => 'telegram',  'name' => 'Telegram',    'ds' => 'TelegramBot preview', 'tokens' => array('telegrambot'), 'robots' => array('TelegramBot')),
            array('id' => 'whatsapp',  'name' => 'WhatsApp',    'ds' => 'Link preview fetcher', 'tokens' => array('whatsapp'), 'robots' => array('WhatsApp')),
            array('id' => 'pinterest', 'name' => 'Pinterest',   'ds' => 'Pinterestbot pin data crawler', 'tokens' => array('pinterest'), 'robots' => array('Pinterestbot')),
        )),
        array('id' => 'monitor', 'label' => 'Uptime Monitors', 'rec' => 'allow', 'bots' => array(
            array('id' => 'uptimerobot', 'name' => 'UptimeRobot', 'ds' => 'Uptime checks',     'tokens' => array('uptimerobot'), 'robots' => array('UptimeRobot')),
            array('id' => 'pingdom',     'name' => 'Pingdom',     'ds' => 'Uptime checks',     'tokens' => array('pingdom'), 'robots' => array('Pingdom')),
            array('id' => 'statuscake',  'name' => 'StatusCake',  'ds' => 'Uptime checks',     'tokens' => array('statuscake'), 'robots' => array('StatusCake')),
            array('id' => 'site24',      'name' => 'Site24x7',    'ds' => 'Uptime checks',     'tokens' => array('site24x7'), 'robots' => array('Site24x7')),
            array('id' => 'gtmetrix',    'name' => 'GTmetrix',    'ds' => 'Performance tests', 'tokens' => array('gtmetrix'), 'robots' => array('GTmetrix')),
        )),
    );
}

/** Dashboard default: AI + SEO crawlers blocked (mirror of BP_DEFAULT_BLOCKED). */
function devdbotp_kb_default_blocked()
{
    return array('openai', 'anthropic', 'perplexity', 'ccbot', 'googleext', 'appleext', 'bytespider', 'metaai', 'amazonbot', 'diffbot', 'ahrefs', 'semrush', 'majestic', 'moz', 'dataforseo', 'blexbot', 'screamingfrog', 'serpstat');
}

/** The dashboard's "Use recommended" set: every bot whose own rec, else its group's rec, is block. */
function devdbotp_kb_rec_blocked()
{
    $ids = array();
    foreach (devdbotp_kb_catalog() as $g) {
        foreach ($g['bots'] as $b) {
            $rec = isset($b['rec']) ? $b['rec'] : $g['rec'];
            if ($rec === 'block') {
                $ids[] = $b['id'];
            }
        }
    }
    return $ids;
}

/** The bot ids of one group, in catalog order. */
function devdbotp_kb_group_ids($group_id)
{
    foreach (devdbotp_kb_catalog() as $g) {
        if ($g['id'] === $group_id) {
            return array_map(function ($b) { return $b['id']; }, $g['bots']);
        }
    }
    return array();
}

/** All valid catalog ids (sync sanitizer). */
function devdbotp_kb_all_ids()
{
    $ids = array();
    foreach (devdbotp_kb_catalog() as $g) {
        foreach ($g['bots'] as $b) {
            $ids[] = $b['id'];
        }
    }
    return $ids;
}

/** Currently blocked ids (setting, seeded to the dashboard default). */
function devdbotp_kb_blocked()
{
    $v = devdbotp_get_setting('known_bots_blocked', null);
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return array(); // the saved policy could not be read: block nothing on this request rather than the default list
    }
    return is_array($v) ? $v : devdbotp_kb_default_blocked();
}

/** A change made outside the tab's instant save (config import, the v3 migration). A connected site marks
 *  it pending and queues the push, so the dashboard's copy follows on the
     next check. */
function devdbotp_kb_set_local($blocked)
{
    if (!devdbotp_kb_lock(2)) {
        return false; // another request is syncing: nothing written, the caller reports it (reentrant under an import)
    }
    try {
        $list = array_values(array_unique(array_map('strval', $blocked)));
        if (!devdbotp_update_setting('known_bots_blocked', $list)) {
            return false;
        }
        unset($GLOBALS['devdbotp_cache']);
        if (devdbotp_kb_blocked() !== $list) {
            return false;
        }
        if (devdbotp_kb_conn()) {
            if (!devdbotp_update_setting('kb_push_pending', 1)) {
                return false; // without the mark the change would not be sent up again
            }
            if (!wp_next_scheduled('devdbotp_kb_push_event')) {
                wp_schedule_single_event(time() + 30, 'devdbotp_kb_push_event');
            }
        }
        return true;
    } finally {
        devdbotp_kb_unlock();
    }
}

/** The catalog id a lowercase user agent belongs to, blocked or not ('' when none). Robots-only tokens never visit. */
function devdbotp_kb_ua_id($ua)
{
    $ua = strtolower((string) $ua);
    if ($ua === '') {
        return '';
    }
    static $flat = null;
    if ($flat === null) {
        $flat = array();
        foreach (devdbotp_kb_catalog() as $g) {
            foreach ($g['bots'] as $b) {
                if (!empty($b['tokens'])) {
                    $flat[] = array($b['id'], $b['tokens'], isset($b['none']) ? $b['none'] : array());
                }
            }
        }
    }
    foreach ($flat as $entry) {
        foreach ($entry[1] as $t) {
            if (strpos($ua, $t) === false) {
                continue;
            }
            foreach ($entry[2] as $ex) {
                if (strpos($ua, $ex) !== false) {
                    continue 2;
                }
            }
            return $entry[0];
        }
    }
    return '';
}

/** Match a lowercase UA against the BLOCKED bots only. Returns the bot id or ''. */
function devdbotp_kb_block_match($ua)
{
    $id = devdbotp_kb_ua_id($ua);
    return ($id !== '' && in_array($id, devdbotp_kb_blocked(), true)) ? $id : '';
}

/** The Disallow groups for every blocked bot (the dashboard's robots.txt snippet, applied to the virtual robots.txt). */
function devdbotp_kb_robots_block()
{
    $blocked = array_flip(devdbotp_kb_blocked());
    $out = '';
    foreach (devdbotp_kb_catalog() as $g) {
        foreach ($g['bots'] as $b) {
            if (!isset($blocked[$b['id']])) {
                continue;
            }
            $out .= '# ' . $b['name'] . "\n";
            foreach ($b['robots'] as $t) {
                $out .= 'User-agent: ' . $t . "\n";
            }
            $out .= "Disallow: /\n\n";
        }
    }
    return $out === '' ? '' : "# DevDome Bot Protection: Known Bots blocked on this site\n" . $out;
}

/** Append the Known Bots Disallow groups to WordPress's virtual robots.txt (a physical robots.txt file is not touched). */
function devdbotp_kb_robots_txt($output, $public)
{
    $block = devdbotp_kb_robots_block();
    return $block === '' ? $output : rtrim($output) . "\n\n" . $block;
}
add_filter('robots_txt', 'devdbotp_kb_robots_txt', 9, 2);

/* ---------- Dashboard sync (via the DevDome Analytics plugin's site token) ---------- */

/** {site, token, url} when the analytics plugin is connected, else null. */
function devdbotp_kb_conn()
{
    // The suite-wide connection lives under devdcorev1_* (shared core >= 1.4); the devdome_*
    // names are the pre-1.4 rows, still read so an old install keeps syncing until it migrates.
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    if ($site === '' || $token === '') {
        $site  = (string) get_option('devdome_site_id', '');
        $token = (string) get_option('devdome_site_token', '');
    }
    if ($site === '' || $token === '') {
        return null;
    }
    // Connected means linked to an account right now (server verified, cached by the core), not
    // merely a token on disk: an unlinked token would only collect 401s.
    if (function_exists('devdcorev1_account_is_connected') && !devdcorev1_account_is_connected()) {
        return null;
    }
    return array('site' => $site, 'token' => $token, 'url' => 'https://analytics.devdome.com/api/plugin/protection');
}

/** Capability heartbeat query args: what THIS plugin actually is and does, so the
 *  dashboard can prove installed/mode/revision instead of inferring it from the
 *  Analytics token. No secrets, no visitor data. */
function devdbotp_kb_heartbeat_args()
{
    // Totals for the dashboard's "On Your Site" line: counts only, never an address or a visitor.
    $b7 = 0;
    foreach (devdbotp_days_back(7) as $d) {
        $b7 += (int) devdbotp_get_setting('stat_day_' . $d . '_blocked', 0);
    }
    // The same day bucket the Overview reads (stat_blocked_today misses part of it).
    $today = (int) devdbotp_get_setting('stat_day_' . devdbotp_days_back(1)[0] . '_blocked', 0);
    return array(
        'v'    => rawurlencode(DEVDBOTP_VERSION),
        'mode' => rawurlencode((string) devdbotp_get_setting('mode', 'watching')),
        'rev'  => (int) devdbotp_get_setting('kb_applied_rev', 0),
        'mu'   => 0, // no early-block file since 1.0.1
        'on'   => devdbotp_get_setting('protection_level', 'recommended') === 'off' ? 0 : 1,
        'bt'   => $today,
        'b7'   => $b7,
    );
}

/**
 * The one place a switch made ON THE SITE goes through (admin screen, abilities): Watch / Live ('mode'), protection
 * on / off ('on', bool), the pause ('pause_until', Unix seconds, 0 resumes) and the six built-in rules
 * ('rule_actions', key => allow | log | block) and the whole custom rule list ('custom_rules', as
 * devdbotp_kb_site_rules_out() gives it). Connected: the dashboard learns it
 * now. $force = the change is already stored (a custom rule was just added), send it anyway.
 */
function devdbotp_set_site_state($want, $force = false)
{
    $locked = devdbotp_kb_lock(2); // up to 2 s for a sync that is running
    if (!$locked) {
        return false; // another request is writing policy and did not finish in 2 s: this change is refused, never overlapped
    }
    try {
        return devdbotp_set_site_state_locked($want, $force);
    } finally {
        devdbotp_kb_unlock();
    }
}

function devdbotp_set_site_state_locked($want, $force = false)
{
    unset($GLOBALS['devdbotp_cache']);
    devdbotp_get_setting('mode', 'watching'); // one load, judged before anything is compared or written
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return false; // the current state could not be read: a write on defaults is refused
    }
    $apply = function () use ($want) {
        $changed = false;
        if (isset($want['mode'])) {
            $mode = $want['mode'] === 'live' ? 'live' : 'watching';
            if ($mode !== (string) devdbotp_get_setting('mode', 'watching')) {
                if (!devdbotp_update_setting('mode', $mode)) { $GLOBALS['devdbotp_apply_failed'] = true; }
                $changed = true;
            }
        }
        if (isset($want['on'])) {
            $level = $want['on'] ? 'recommended' : 'off';
            if (($level === 'off') !== (devdbotp_get_setting('protection_level', 'recommended') === 'off')) {
                if (!devdbotp_update_setting('protection_level', $level)) { $GLOBALS['devdbotp_apply_failed'] = true; }
                $changed = true;
            }
        }
        if (isset($want['rule_actions']) && is_array($want['rule_actions'])) {
            $next = devdbotp_rule_actions();
            foreach ($want['rule_actions'] as $k => $v) {
                if (isset($next[$k]) && in_array($v, array('allow', 'log', 'block'), true)) {
                    $next[$k] = $v;
                }
            }
            if ($next !== devdbotp_rule_actions()) {
                if (!devdbotp_update_setting('rule_actions', $next)) { $GLOBALS['devdbotp_apply_failed'] = true; }
                $changed = true;
            }
        }
        $mine = isset($want['custom_rules']) && is_array($want['custom_rules']) ? devdbotp_kb_site_rules_out() : null;
        if (isset($want['custom_rules']) && is_array($want['custom_rules']) && empty($GLOBALS['devdbotp_rules_read_failed']) && $mine !== null
            && devdbotp_kb_site_rules_key($want['custom_rules']) !== devdbotp_kb_site_rules_key($mine)) { // null = more rules than the sync carries: this site keeps its own
            if (!devdbotp_kb_site_rules_replace($want['custom_rules'])) {
                $GLOBALS['devdbotp_apply_failed'] = true;
            }
            $changed = true;
        }
        if (isset($want['timezone']) && (string) $want['timezone'] !== '' && !in_array((string) $want['timezone'], timezone_identifiers_list(), true)) {
            $GLOBALS['devdbotp_apply_failed'] = true; // not a time zone name: refused, nothing stored
        } elseif (isset($want['timezone']) && (string) $want['timezone'] !== (string) devdbotp_get_setting('timezone', '')) {
            if (!devdbotp_update_setting('timezone', (string) $want['timezone'])) { $GLOBALS['devdbotp_apply_failed'] = true; } // '' = follow WordPress
            $changed = true;
        }
        if (isset($want['pause_until'])) {
            $until = max(0, (int) $want['pause_until']);
            if ($until !== (int) devdbotp_get_setting('safe_mode_until', 0)) {
                if (!devdbotp_set_safe_mode($until)) {
                    $GLOBALS['devdbotp_apply_failed'] = true; // the setting did not land: the caller must not say paused / resumed
                }
                $changed = true;
            }
        }
        return $changed;
    };
    $GLOBALS['devdbotp_apply_failed'] = false;
    $did = $apply();
    if (!empty($GLOBALS['devdbotp_apply_failed'])) {
        return false;
    }
    if (!$did && !$force) {
        return;
    }
    if (devdbotp_kb_conn()) {
        if (!devdbotp_update_setting('kb_push_pending', 1)) {
            return false; // the mark did not land: the change would not be sent up again, so it is reported as not saved
        }
        if (!empty($GLOBALS['devdbotp_push_deferred'])) {
            return true; // an import applies several keys under one lock and pushes once at its end
        }
        devdbotp_kb_push(); // a 409 is settled inside: the dashboard's revision is read and the upload repeated
    }
}

function devdbotp_set_mode($mode)
{
    devdbotp_set_site_state(array('mode' => $mode));
}

/** The dashboard check (15-min gate unless $force): reads the revision of the dashboard's copy and, when that copy
 *  is not what this site last sent, sends this site's settings up again. One way only (1.0.2): nothing in the answer
 *  changes a setting here. The site token travels in an Authorization header, never the query string. */
/**
 * The policy lock: a MySQL named lock (GET_LOCK), owned by this request's database connection and released when the
 * connection ends, so a crashed request never leaves a lock behind and a slow one can never be stolen from. $wait =
 * seconds to wait server side (0 = try once). Reentrant inside one request (a pull that calls the state setter, a 409
 * that calls the pull) through a depth counter. Nobody writes policy without owning it.
 */
function devdbotp_kb_lock_name()
{
    global $wpdb;
    return 'devdbotp_policy_' . md5(DB_NAME . '|' . $wpdb->prefix); // 48 chars, one per site (blog prefix included)
}

function devdbotp_kb_lock($wait = 0)
{
    global $wpdb;
    if (!empty($GLOBALS['devdbotp_kb_lock_depth'])) {
        $GLOBALS['devdbotp_kb_lock_depth']++;
        return true; // reentrant: this request already owns it
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock itself
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', devdbotp_kb_lock_name(), max(0, (int) $wait)));
    if ((string) $got !== '1') {
        return false; // 0 = held by another live request, NULL = error
    }
    $GLOBALS['devdbotp_kb_lock_depth'] = 1;
    return true;
}

function devdbotp_kb_unlock()
{
    global $wpdb;
    if (empty($GLOBALS['devdbotp_kb_lock_depth'])) {
        return;
    }
    $GLOBALS['devdbotp_kb_lock_depth']--;
    if ($GLOBALS['devdbotp_kb_lock_depth'] > 0) {
        return;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases this connection's own lock
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', devdbotp_kb_lock_name()));
}

function devdbotp_kb_pull($force = false, $after_conflict = false)
{
    $conn = devdbotp_kb_conn();
    if (!$conn) {
        return false;
    }
    if (!$force && get_transient('devdbotp_kb_pulled')) {
        return true;
    }
    if (!$after_conflict && (int) devdbotp_get_setting('kb_push_pending', 0) === 1) {
        return devdbotp_kb_push(); // a 409 makes the push read the dashboard's revision (after_conflict) and push once more
    }
    $args = array_merge(array('site' => rawurlencode($conn['site'])), devdbotp_kb_heartbeat_args());
    $resp = wp_remote_get(add_query_arg($args, $conn['url']), array(
        'timeout' => 6,
        'headers' => array('Authorization' => 'Bearer ' . $conn['token']),
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return false;
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) {
        return false; // A 200 with an unusable body is a failure — do not gate retries on it.
    }
    // The ONLY value read from the answer: the revision counter of the dashboard's copy (a whole number). It is the
    // base the next upload names. No setting, rule, list or switch of this site is ever taken from the dashboard.
    $has = isset($body['protection']) && is_array($body['protection']);
    $rev = $has && isset($body['protection']['rev']) ? max(0, (int) $body['protection']['rev']) : 0;
    if (!devdbotp_kb_lock()) {
        return false; // another request is sending right now: the next check tries again
    }
    try {
        unset($GLOBALS['devdbotp_cache']);
        $applied = (int) devdbotp_get_setting('kb_applied_rev', 0);
        if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
            return false; // the local state could not be read: nothing is compared or written, the next check tries again
        }
        // The dashboard's copy differs from what this site last sent (it was edited there, or it is new or empty):
        // this site's settings go up again, on the dashboard's revision. The site is the only place they are set.
        $differs = !$has || $rev !== $applied || (array_key_exists('timezone', $body) && $body['timezone'] === null && in_array(devdbotp_tz()->getName(), timezone_identifiers_list(), true)); // an offset like +02:00 is never sent, so it cannot fill the dashboard's empty zone
        if ($rev !== $applied) {
            devdbotp_update_setting('kb_applied_rev', $rev);
            unset($GLOBALS['devdbotp_cache']);
            if ((int) devdbotp_get_setting('kb_applied_rev', -1) !== $rev) {
                return false;
            }
        }
        if ($after_conflict) {
            return true; // the caller (the upload that got a 409) sends again on this revision
        }
        if ($differs) {
            static $sent = false; // one upload per request
            if ($sent) {
                return true;
            }
            $sent = true;
            devdbotp_update_setting('kb_push_pending', 1);
            if (!devdbotp_kb_push()) {
                return false;
            }
        } else {
            devdbotp_update_setting('known_bots_synced_at', time());
        }
        set_transient('devdbotp_kb_pulled', 1, 15 * MINUTE_IN_SECONDS); // the gate: only a finished check arms it
        return true;
    } finally {
        devdbotp_kb_unlock();
    }
}

/** Most custom rules the dashboard takes. A site with more keeps them to itself: they are neither sent nor replaced. */
function devdbotp_kb_site_rules_max()
{
    return 200;
}

/** The custom rules as the dashboard stores them, in list order. null = too many to sync. */
function devdbotp_kb_site_rules_out()
{
    $rows = function_exists('devdbotp_all_custom_rules') ? devdbotp_all_custom_rules() : array();
    if (!empty($GLOBALS['devdbotp_rules_read_failed'])) {
        return null; // the read failed (devdbotp_all_custom_rules sets the flag): not "no rules"
    }
    if (count($rows) > devdbotp_kb_site_rules_max()) {
        return null;
    }
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'field'   => (string) $r['match_field'],
            'op'      => (string) $r['operator'],
            'pattern' => (string) $r['pattern'],
            'action'  => (string) $r['action'] === 'challenge' ? 'log' : (string) $r['action'],
            'enabled' => !empty($r['enabled']),
            'name'    => (string) $r['name'],
        );
    }
    return $out;
}

/** What makes two lists the same: every rule's field, operator, value, action and on / off, in order (not the name). */
function devdbotp_kb_site_rules_key($list)
{
    if (!is_array($list)) {
        return '';
    }
    $k = array();
    foreach ($list as $r) {
        if (is_array($r)) {
            $k[] = array(
                isset($r['field']) ? (string) $r['field'] : '', isset($r['op']) ? (string) $r['op'] : '',
                isset($r['pattern']) ? (string) $r['pattern'] : '', isset($r['action']) ? ((string) $r['action'] === 'challenge' ? 'log' : (string) $r['action']) : '',
                !isset($r['enabled']) || $r['enabled'] ? 1 : 0,
            );
        }
    }
    return wp_json_encode($k);
}

/** A rule list as rows this site can store: [field, op, pattern, action, enabled, name] each; null when one rule cannot be stored. */
function devdbotp_kb_site_rules_normalise($list)
{
    if (!is_array($list) || count($list) > devdbotp_kb_site_rules_max() || !function_exists('devdbotp_rule_field_ops')) {
        return null;
    }
    $field_ops = devdbotp_rule_field_ops();
    $rows = array();
    foreach ($list as $r) {
        $field   = is_array($r) && isset($r['field']) ? sanitize_key($r['field']) : '';
        $op      = is_array($r) && isset($r['op']) ? sanitize_key($r['op']) : '';
        $action  = is_array($r) && isset($r['action']) ? sanitize_key($r['action']) : '';
        $pattern = is_array($r) && isset($r['pattern']) ? trim(sanitize_text_field((string) $r['pattern'])) : '';
        if (!isset($field_ops[$field]) || !in_array($op, $field_ops[$field], true) || !in_array($action, devdbotp_rule_actions_list(), true)
            || $pattern === '' || strlen($pattern) > 255) {
            return null; // one rule we cannot store: keep what we have, never apply half a list
        }
        if ($op === 'regex' && @preg_match('~' . str_replace('~', '\~', $pattern) . '~i', '') === false) {
            return null;
        }
        $rows[] = array($field, $op, $pattern, $action, !isset($r['enabled']) || $r['enabled'] ? 1 : 0, isset($r['name']) ? substr(sanitize_text_field((string) $r['name']), 0, 255) : '');
    }
    return $rows;
}

/** Make the custom rule table equal to $list (an imported configuration). */
function devdbotp_kb_site_rules_replace($list)
{
    global $wpdb;
    $rows = devdbotp_kb_site_rules_normalise($list);
    if ($rows === null) {
        return false;
    }
    if (!devdbotp_kb_site_rules_recover()) {
        return false; // an earlier failure's copy is not back yet: nothing new is written over it
    }
    $t   = devdbotp_rules_table();
    $now = current_time('mysql', true);
    // The old list is read first: with a transactional table the ROLLBACK restores it, without one the copy is re-inserted.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    $backup = $wpdb->get_results("SELECT * FROM {$t} WHERE rule_type = 'custom' ORDER BY id", ARRAY_A);
    if (!is_array($backup) || '' !== (string) $wpdb->last_error) {
        return false; // cannot read what would be replaced: nothing is touched
    }
    // The copy is stored and read back BEFORE the first delete, so a failure later can never lose it (a write that
    // fails after a failed query is refused by the guard, which is why it happens first).
    $normalised = array();
    foreach ($rows as $row) {
        $normalised[] = array('field' => $row[0], 'op' => $row[1], 'pattern' => $row[2], 'action' => $row[3], 'enabled' => (bool) $row[4], 'name' => $row[5]);
    }
    $new_key = devdbotp_kb_site_rules_key($normalised); // the exact rows that will be inserted
    if (!devdbotp_kb_backup_store($backup, $new_key)) {
        return false; // no verified copy = nothing is replaced
    }
    if (false === $wpdb->query('START TRANSACTION')) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction boundary
        return false;
    }
    $ok = false !== $wpdb->delete($t, array('rule_type' => 'custom'));
    foreach ($rows as $row) {
        if (!$ok) {
            break;
        }
        $ok = false !== $wpdb->insert($t, array(
            'enabled' => $row[4], 'priority' => 100, 'rule_type' => 'custom',
            'name' => $row[5] !== '' ? $row[5] : ($row[0] . ' ' . $row[1] . ' ' . $row[2]),
            'match_field' => $row[0], 'operator' => $row[1], 'pattern' => $row[2], 'action' => $row[3],
            'risk_points' => 0, 'created_at' => $now, 'updated_at' => $now,
        ));
    }
    if ($ok) {
        $ok = false !== $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction boundary
    }
    // Does the table hold exactly the old rows? Used by both failure branches below.
    $same = function () use ($wpdb, $t, $backup) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
        $cur = $wpdb->get_results("SELECT * FROM {$t} WHERE rule_type = 'custom' ORDER BY id", ARRAY_A);
        return is_array($cur) && $cur === $backup;
    };
    if (!$ok) {
        $wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction boundary (also after a failed COMMIT)
        // The table must hold exactly the old rows again. A transactional table is back after the ROLLBACK; a table
        // without transactions kept whatever landed, so the copy is written back row by row (same ids) and compared.
        if (!$same()) {
            $wpdb->delete($t, array('rule_type' => 'custom'));
            foreach ($backup as $row) {
                $wpdb->insert($t, $row);
            }
        }
        // Restored AND the cache rebuilt to match: the stored copy is no longer needed. Otherwise it stays for recovery.
        if ($same() && devdbotp_rebuild_custom_rules_cache()) {
            devdbotp_kb_backup_clear();
        }
        return false; // the previous list stays
    }
    // Committed. The engine's cache is rebuilt and verified BEFORE the copy is dropped: table and cache must agree.
    if (!devdbotp_rebuild_custom_rules_cache()) {
        // The table holds the new list but the engine would run on a stale cache: the old list goes back (in a new
        // error window), verified, and the copy is dropped only when table and cache agree again.
        devdbotp_db_guard_rebase();
        $wpdb->delete($t, array('rule_type' => 'custom'));
        foreach ($backup as $row) {
            $wpdb->insert($t, $row);
        }
        if ($same() && devdbotp_rebuild_custom_rules_cache()) {
            devdbotp_kb_backup_clear();
        }
        return false; // the copy stays until the rollback is verified (recover() finishes it next time)
    }
    // If the drop fails the copy stays with new_key = this list, which tells the recovery that the replacement went
    // through and the cache is fine: it is never undone.
    devdbotp_kb_backup_clear();
    return true;
}

/** Store a copy of the custom rows (and the key of the list that is about to replace them) and read it back. */
function devdbotp_kb_backup_store($rows, $new_key)
{
    devdbotp_update_setting('custom_rules_backup', array('at' => time(), 'rows' => $rows, 'new_key' => $new_key));
    unset($GLOBALS['devdbotp_cache']);
    $kept = devdbotp_get_setting('custom_rules_backup', null);
    return is_array($kept) && isset($kept['rows'], $kept['new_key']) && $kept['rows'] === $rows && $kept['new_key'] === $new_key;
}

/** Drop the stored copy and read back that it is gone. */
function devdbotp_kb_backup_clear()
{
    devdbotp_update_setting('custom_rules_backup', '');
    unset($GLOBALS['devdbotp_cache']);
    return '' === (string) devdbotp_get_setting('custom_rules_backup', '');
}

/** The comparison key of table rows (the same key the outgoing list uses). */
function devdbotp_kb_rows_key($rows)
{
    $out = array();
    foreach ((array) $rows as $r) {
        $out[] = array(
            'field'   => (string) $r['match_field'],
            'op'      => (string) $r['operator'],
            'pattern' => (string) $r['pattern'],
            'action'  => (string) $r['action'] === 'challenge' ? 'log' : (string) $r['action'],
            'enabled' => !empty($r['enabled']),
            'name'    => (string) $r['name'],
        );
    }
    return devdbotp_kb_site_rules_key($out);
}

/**
 * Recovery: a replacement that failed AND could not put the old list back left its copy in custom_rules_backup.
 * The next request that writes rules puts it back first (same ids), verified; only then is the copy dropped.
 */
function devdbotp_kb_site_rules_recover()
{
    global $wpdb;
    $kept = devdbotp_get_setting('custom_rules_backup', null);
    if (!is_array($kept) || !isset($kept['rows']) || !is_array($kept['rows'])) {
        return true; // nothing to recover (an empty list IS a backup: rows => array())
    }
    $t = devdbotp_rules_table();
    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
    $cur = $wpdb->get_results("SELECT * FROM {$t} WHERE rule_type = 'custom' ORDER BY id", ARRAY_A);
    if (!is_array($cur) || '' !== (string) $wpdb->last_error) {
        return false;
    }
    if ($cur !== $kept['rows'] && isset($kept['new_key']) && devdbotp_kb_rows_key($cur) === $kept['new_key']) {
        // The replacement this copy belonged to went through (the table holds the new list); only the drop of the
        // copy failed. It is obsolete: dropped now, never restored over the committed list.
        if (!devdbotp_custom_rules_cache_verify() && !devdbotp_rebuild_custom_rules_cache()) {
            return false; // the engine's cache is still stale: the copy stays until table and cache agree
        }
        return devdbotp_kb_backup_clear();
    }
    if ($cur !== $kept['rows']) {
        $wpdb->delete($t, array('rule_type' => 'custom'));
        foreach ($kept['rows'] as $row) {
            $wpdb->insert($t, $row);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name
        $cur = $wpdb->get_results("SELECT * FROM {$t} WHERE rule_type = 'custom' ORDER BY id", ARRAY_A);
        if (!is_array($cur) || $cur !== $kept['rows']) {
            return false; // still not back: the copy stays
        }
        if (!devdbotp_rebuild_custom_rules_cache()) {
            return false;
        }
    }
    if (!devdbotp_custom_rules_cache_verify() && !devdbotp_rebuild_custom_rules_cache()) {
        return false; // the table is right but the engine's cache is not: the copy stays until they agree
    }
    return devdbotp_kb_backup_clear();
}

/** A custom rule was added, removed or switched on the site: the dashboard gets the whole list now. */
function devdbotp_site_rules_changed()
{
    $list = devdbotp_kb_site_rules_out();
    if ($list !== null && false === devdbotp_set_site_state(array('custom_rules' => $list), true)) {
        devdbotp_update_setting('kb_push_pending', 1); // refused (another request held the lock): the list goes up with the next pull
    }
}

/** The six rule actions as the dashboard stores them (allow | log | block; an old 'off' reads as allow). */
function devdbotp_kb_rule_actions_out()
{
    $out = array();
    foreach (devdbotp_rule_actions() as $k => $v) {
        $out[$k] = in_array($v, array('log', 'block'), true) ? $v : 'allow';
    }
    return $out;
}

/** Send this site's settings to the dashboard with the base revision we last saw. On a 409 (the dashboard's copy
 *  moved on) the newer revision is read and the upload repeated: the site wins. The synced timestamp is only
 *  recorded when the dashboard accepted the write. */
function devdbotp_kb_push()
{
    $conn = devdbotp_kb_conn();
    if (!$conn) {
        return false;
    }
    if (!devdbotp_kb_lock(2)) {
        return false; // another request is writing policy: the pending change stays marked and goes up next time
    }
    try {
        return devdbotp_kb_push_locked($conn);
    } finally {
        devdbotp_kb_unlock();
    }
}

function devdbotp_kb_push_locked($conn)
{
    // The whole snapshot is read inside the lock under a guard: a settings read that failed would send defaults
    // (Watching, no blocked bots) as if they were the stored choices, so nothing is sent then.
    devdbotp_db_guard_begin();
    unset($GLOBALS['devdbotp_cache']);
    $site_rules = devdbotp_kb_site_rules_out();
    $snapshot   = array_merge(array(
        'site'        => $conn['site'],
        'blockedBots' => devdbotp_kb_blocked(),
        'policyMode'  => (string) devdbotp_get_setting('mode', 'watching'), // a local Watch / Live switch reaches the dashboard
        'policyOn'    => devdbotp_get_setting('protection_level', 'recommended') !== 'off', // so do on / off and the pause
        'policyPauseUntil' => (int) devdbotp_get_setting('safe_mode_until', 0) > time() ? (int) devdbotp_get_setting('safe_mode_until', 0) : 0,
        'ruleActions' => devdbotp_kb_rule_actions_out(), // the six built-in rules: allow | log | block
    ), in_array(devdbotp_tz()->getName(), timezone_identifiers_list(), true) ? array('timezone' => devdbotp_tz()->getName()) : array(), /* an offset like +02:00 is not a zone name: not sent */ $site_rules !== null ? array('siteRules' => $site_rules) : array(), array(
        'baseRev'     => (int) devdbotp_get_setting('kb_applied_rev', 0),
    ), devdbotp_kb_heartbeat_args());
    $unreadable = devdbotp_db_guard_failed() || ($site_rules === null && !empty($GLOBALS['devdbotp_rules_read_failed']));
    devdbotp_db_guard_end();
    if ($unreadable) {
        return false; // a snapshot that could not be read is not sent; the pending mark stays
    }
    $resp = wp_remote_post($conn['url'], array(
        'timeout' => 6,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $conn['token'],
        ),
        'body'    => wp_json_encode($snapshot),
    ));
    if (is_wp_error($resp)) {
        return false;
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code === 409) {
        // Conflict: the dashboard's copy has a newer revision than the one this upload named. This site is the source
        // of truth: read that revision (after_conflict = the check does not push itself), then send once more on top of
        // it. One conflict round per request: a second 409 leaves kb_push_pending set for the next cron check.
        if (!empty($GLOBALS['devdbotp_kb_conflict_retried'])) {
            return false;
        }
        $GLOBALS['devdbotp_kb_conflict_retried'] = true;
        if (!devdbotp_kb_pull(true, true)) {
            return false;
        }
        return devdbotp_kb_push();
    }
    if ($code !== 200) {
        return false;
    }
    // The acknowledgement: the revision the dashboard now holds is recorded and read back FIRST; only then is the
    // pending mark cleared (and read back). A write that did not land leaves the mark, so the next pull pushes again.
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $rev_ok = is_array($body) && isset($body['revision'])
        && ((is_int($body['revision']) && $body['revision'] >= 0) || (is_string($body['revision']) && ctype_digit($body['revision'])));
    if (!$rev_ok || (int) $body['revision'] < (int) $snapshot['baseRev']) {
        return false; // a 200 without a valid, non-regressing revision is not an acknowledgement: nothing changes
    }
    devdbotp_update_setting('kb_applied_rev', (int) $body['revision']);
    unset($GLOBALS['devdbotp_cache']);
    if ((int) devdbotp_get_setting('kb_applied_rev', -1) !== (int) $body['revision']) {
        return false;
    }
    devdbotp_update_setting('kb_push_pending', 0);
    unset($GLOBALS['devdbotp_cache']);
    if ((int) devdbotp_get_setting('kb_push_pending', 1) !== 0) {
        return false;
    }
    devdbotp_update_setting('known_bots_synced_at', time());
    return true;
}

/**
 * Send this site's own daily totals to the dashboard, so both show the same numbers: per day in the site's time zone, Caught By Your
 * Rules, Visits From Bots, Visits From People, the same day buckets the Overview reads. Counts only: no address, no
 * visitor, no page. The values are absolute, so sending a day again replaces it. The first time everything kept
 * (90 days) goes out, after that the last 3 days.
 */
function devdbotp_kb_send_days($clear = false)
{
    $clear = $clear || (int) devdbotp_get_setting('kb_clear_pending', 0) === 1; // a clear the dashboard has not acknowledged yet rides the next push
    $conn = devdbotp_kb_conn();
    if (!$conn) {
        return false;
    }
    devdbotp_db_guard_begin(); // the totals are authoritative replacements on the dashboard: a failed read must send nothing
    unset($GLOBALS['devdbotp_cache']);
    $span = (int) devdbotp_get_setting('kb_days_sent', 0) === 1 ? 3 : min(1095, devdbotp_retention_days());
    $days = array();
    foreach (devdbotp_days_back($span) as $i => $d) {
        $row = array(
            (int) devdbotp_get_setting('stat_day_' . $d . '_blocked', 0),
            (int) devdbotp_get_setting('stat_day_' . $d . '_bots', 0),
            (int) devdbotp_get_setting('stat_day_' . $d . '_humans', 0),
        );
        if ($i < 3 || array_sum($row) > 0) { // old empty days are not worth a row
            $days[$d] = $row;
        }
    }
    $unreadable = devdbotp_db_guard_failed();
    devdbotp_db_guard_end();
    if ($unreadable) {
        return false; // zeros from a failed read would replace real totals on the dashboard
    }
    $resp = wp_remote_post($conn['url'] . '/days', array(
        'timeout' => 6,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $conn['token'],
        ),
        'body'    => wp_json_encode($clear ? array('site' => $conn['site'], 'days' => $days, 'clear' => true) : array('site' => $conn['site'], 'days' => $days)),
    ));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
        return false;
    }
    $ack = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($ack) || !isset($ack['ok']) || true !== $ack['ok']) {
        return false; // a 200 that is not the dashboard's {"ok":true} (a proxy page, an error page) is no acknowledgement
    }
    devdbotp_update_setting('kb_days_sent', 1);
    if ($clear) {
        devdbotp_update_setting('kb_clear_pending', 0);
    }
    unset($GLOBALS['devdbotp_cache']);
    return (int) devdbotp_get_setting('kb_days_sent', 0) === 1 && (!$clear || (int) devdbotp_get_setting('kb_clear_pending', 1) === 0); // the markers landed
}

/** The moment an account is connected (the shared library says so): sync now, do not wait for the hourly check. */
add_action('devdcorev1_connected', function () {
    delete_transient('devdbotp_kb_pulled');
    devdbotp_update_setting('kb_push_pending', 1); // this site's settings go up; nothing comes down
    devdbotp_kb_pull(true);
    devdbotp_kb_send_days();
}, 20);

/** Hourly cron check + self-heal scheduling (existing installs never re-activate). */
add_action('devdbotp_kb_sync', 'devdbotp_kb_pull');
add_action('devdbotp_kb_sync', 'devdbotp_kb_send_days', 20);
add_action('admin_init', function () {
    if (!wp_next_scheduled('devdbotp_kb_sync')) {
        wp_schedule_event(time() + 300, 'hourly', 'devdbotp_kb_sync');
    }
    // Catalog v2 migration (one-time): the old grouped 'openai' switch covered
    // GPTBot + ChatGPT-User + OAI-SearchBot. The purpose split must not silently
    // UNBLOCK agents the owner had blocked, so a pre-v2 openai block expands to
    // the split ids once. (Claude-User/Claude-SearchBot were never matched by the
    // old anthropic tokens, so nothing to expand there.)
    if ((int) devdbotp_get_setting('kb_catalog_v', 1) < 2) {
        $blocked = devdbotp_kb_blocked();
        if (in_array('openai', $blocked, true)) {
            $blocked = array_values(array_unique(array_merge($blocked, array('oaisearch', 'chatgptuser'))));
            devdbotp_kb_set_local($blocked); // marked pending + a queued push, so the dashboard's older list cannot undo the split
            unset($GLOBALS['devdbotp_cache']);
            if (devdbotp_kb_blocked() !== $blocked) {
                return; // the split did not land: the migration runs again on the next load
            }
        }
        devdbotp_update_setting('kb_catalog_v', 2);
    }
    // Catalog v3 (2026-09-16): the AI Crawlers tab is folded into Known Bots. Its choices join the Known Bots list as a
    // union (nothing the owner blocked becomes allowed), its three settings go, and a connected site sends the merged
    // list to the dashboard (queued, never inside this admin request; a pull sends it first if it is still pending).
    if ((int) devdbotp_get_setting('kb_catalog_v', 1) < 3) {
        $map = array('gptbot' => 'openai', 'chatgpt_user' => 'chatgptuser', 'oai_search' => 'oaisearch', 'claudebot' => 'anthropic', 'anthropic_ai' => 'anthropic', 'google_ext' => 'googleext', 'ccbot' => 'ccbot', 'perplexity' => 'perplexity', 'bytespider' => 'bytespider', 'amazonbot' => 'amazonbot', 'meta_ai' => 'metaai', 'applebot_ext' => 'appleext');
        $ai_saved  = devdbotp_get_setting('ai_crawlers', array());
        $ai_saved  = is_array($ai_saved) ? $ai_saved : array();
        $ai_policy = (string) devdbotp_get_setting('ai_crawler_policy', 'allow');
        $blocked   = devdbotp_kb_blocked();
        foreach ($map as $key => $id) {
            $choice = isset($ai_saved[$key]) ? $ai_saved[$key] : $ai_policy;
            if ($choice === 'block' && !in_array($id, $blocked, true)) {
                $blocked[] = $id;
            }
        }
        if ($blocked !== devdbotp_kb_blocked()) {
            devdbotp_kb_set_local($blocked);
            unset($GLOBALS['devdbotp_cache']);
            if (devdbotp_kb_blocked() !== $blocked) {
                return; // the merged list did not land: nothing deleted, the migration runs again on the next load
            }
        }
        global $wpdb;
        $gone = $wpdb->query('DELETE FROM ' . devdbotp_settings_table() . " WHERE setting_name IN ('ai_crawlers', 'ai_crawler_policy', 'ai_hard_block')"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- plugin's own settings table, fixed names
        unset($GLOBALS['devdbotp_cache']);
        if (false !== $gone) {
            devdbotp_update_setting('kb_catalog_v', 3); // a failed delete leaves the migration for the next load
        }
    }
});

/** A queued push (the catalog v3 migration uses it so no admin request waits on the network). */
add_action('devdbotp_kb_push_event', 'devdbotp_kb_push');

/* ---------- Hits per bot (the Known Bots sparklines and counts) ---------- */

/**
 * Hits per catalog bot from this site's own bot log, bucketed like the dashboard: 24 hours by hour, 7, 30 and 90 days by
 * day, in the site's time zone. Null when the log could not be read (the view then says "timeline unavailable" instead of
 * drawing a flat line that looks like "no visits"). Cached for 5 minutes.
 *
 * @return array|null array('ranges' => array(key => array('labels' => string[], 'bots' => array(id => int[]))))
 */
function devdbotp_kb_series()
{
    $cached = get_transient('devdbotp_kb_series2');
    if (is_array($cached)) {
        return $cached;
    }
    global $wpdb;
    $t      = $wpdb->prefix . 'devdbotp_events';
    $offset = (int) (new DateTime('now', devdbotp_tz()))->getOffset(); // the plugin's Time Zone setting, as the day buckets
    $now    = time() + $offset; // "local" clock: every bucket below is in the site's time zone
    $today  = gmdate('Y-m-d', $now);

    list(, , $set) = devdbotp_activity_range(); // the same pills as the other tabs (owner 2026-09-28): Today, 7, 30, 90 days
    $max_days = max(array_keys($set));

    devdbotp_db_reset_error(); // records a pending error first, then clears (never hides an earlier failure)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own events table, cached above
    $days = $wpdb->get_results($wpdb->prepare(
        "SELECT user_agent AS ua, DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS b, COUNT(*) AS n FROM {$t} WHERE created_at >= %s AND user_agent <> '' GROUP BY ua, b",
        $offset,
        gmdate('Y-m-d H:i:s', strtotime($today . ' 00:00:00') - ($max_days - 1) * DAY_IN_SECONDS - $offset)
    ), ARRAY_A);
    $day_error = $wpdb->last_error;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin's own events table, cached above
    $hours = $wpdb->get_results($wpdb->prepare(
        "SELECT user_agent AS ua, DATE_FORMAT(DATE_ADD(created_at, INTERVAL %d SECOND), '%%Y-%%m-%%d %%H') AS b, COUNT(*) AS n FROM {$t} WHERE created_at >= %s AND user_agent <> '' GROUP BY ua, b",
        $offset,
        gmdate('Y-m-d H:00:00', $now - 23 * HOUR_IN_SECONDS - $offset) // Today = the last 24 hours, as the dashboard
    ), ARRAY_A);
    if ($day_error !== '' || $wpdb->last_error !== '' || !is_array($days) || !is_array($hours)) {
        return null;
    }

    $ranges = array();
    foreach ($set as $n => $lbl) {
        if ($n === 1) {
            continue;
        }
        $key    = (string) $n;
        $labels = array();
        $index  = array();
        for ($i = $n - 1; $i >= 0; $i--) {
            $ts = strtotime($today . ' 00:00:00') - $i * DAY_IN_SECONDS;
            $index[gmdate('Y-m-d', $ts)] = count($labels);
            $labels[] = gmdate('M j', $ts);
        }
        $ranges[$key] = array('labels' => $labels, 'index' => $index, 'bots' => array());
    }
    $labels = array();
    $index  = array();
    for ($i = 23; $i >= 0; $i--) {
        $ts = $now - $i * HOUR_IN_SECONDS;
        $index[gmdate('Y-m-d H', $ts)] = count($labels);
        $labels[] = gmdate('H:00', $ts);
    }
    $ranges['1'] = array('labels' => $labels, 'index' => $index, 'bots' => array());

    $memo = array();
    $add  = function (&$range, $rows) use (&$memo) {
        foreach ($rows as $r) {
            $ua = (string) $r['ua'];
            if (!isset($memo[$ua])) {
                $memo[$ua] = devdbotp_kb_ua_id($ua);
            }
            $id = $memo[$ua];
            if ($id === '' || !isset($range['index'][$r['b']])) {
                continue;
            }
            if (!isset($range['bots'][$id])) {
                $range['bots'][$id] = array_fill(0, count($range['labels']), 0);
            }
            $range['bots'][$id][$range['index'][$r['b']]] += (int) $r['n'];
        }
    };
    foreach ($ranges as $key => $unused) {
        if ($key !== '1') {
            $add($ranges[$key], $days);
        }
    }
    $add($ranges['1'], $hours);
    foreach ($ranges as $key => $range) {
        unset($ranges[$key]['index']);
    }
    $out = array('ranges' => $ranges);
    set_transient('devdbotp_kb_series2', $out, 5 * MINUTE_IN_SECONDS);
    return $out;
}

/* ---------- Admin: the Known Bots tab (the dashboard view 1:1) ---------- */

function devdbotp_render_knownbots_tab()
{
    devdbotp_kb_pull(); // Gated: keeps the dashboard's copy equal to this site's settings.
    $blocked = array_flip(devdbotp_kb_blocked());
    $conn    = devdbotp_kb_conn();
    $synced  = (int) devdbotp_get_setting('known_bots_synced_at', 0);
    $mode    = (string) devdbotp_get_setting('mode', 'watching');
    $catalog = devdbotp_kb_catalog();
    $state   = array(
        'blocked' => array_values(devdbotp_kb_blocked()),
        'rec'     => devdbotp_kb_rec_blocked(),
        'groups'  => array_map(function ($g) {
            return array('id' => $g['id'], 'bots' => array_map(function ($b) {
                return array('id' => $b['id'], 'name' => $b['name'], 'robots' => $b['robots']);
            }, $g['bots']));
        }, $catalog),
        'series'  => devdbotp_kb_series(),
        'site'    => (string) wp_parse_url(home_url(), PHP_URL_HOST),
        'ajax'    => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('devdbotp_kb'),
        'synced'  => $conn ? ($synced ? 'Synced with your DevDome dashboard ' . human_time_diff($synced) . ' ago' : 'Connected, not synced yet') : 'Local only, not connected',
    );
    $svg_rec    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>';
    $svg_robots = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';
    $svg_sync   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
    $svg_hint   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
    $svg_kebab  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
    ?>
    <div class="max-w-5xl px-6 pt-3 pb-6">
        <?php if ($conn) : ?>
            <form method="post" id="bp-kb-sync-form" hidden>
                <?php wp_nonce_field('devdbotp_kb', '_bpkb'); ?>
                <input type="hidden" name="devdbotp_kb_action" value="pull">
                <button type="submit" id="bp-kb-sync">Sync Now</button>
            </form>
        <?php endif; ?>
        <div class="view-bp" id="bp-kb-view" data-kb="<?php echo esc_attr(wp_json_encode($state)); ?>">
            <div class="bpk-legend" id="bp-kb-hint" hidden>Switch on = the bot may crawl your site. Switch off = blocked: the bot gets a Disallow group in this site's robots.txt and, while the plugin is set to Block, a 403 (Watch mode only logs, it never blocks). Google AI and Apple AI are robots.txt data-use tokens, so only the robots.txt line applies to them. Every switch saves at once and syncs with your DevDome dashboard when the site is connected.</div>
            <div class="controls-bar bp-date-filter">
                <div class="date-buttons">
                    <?php list(, , $kb_ranges) = devdbotp_activity_range(); $i = 0; foreach ($kb_ranges as $n => $lbl) : $i++; ?>
                        <button type="button" data-range="<?php echo (int) $n; ?>" class="date-button<?php echo $n === 7 ? ' active' : ''; ?><?php echo $i === count($kb_ranges) ? ' pl-last' : ''; ?>"><?php echo esc_html($lbl); ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="bp-actions">
                    <span class="bp-autosave" data-bp-save></span>
                    <div class="bpk-kebab-wrap">
                        <button type="button" class="bpk-kebab" id="bp-kb-kebab" aria-label="Bot actions" aria-haspopup="true" aria-expanded="false" aria-controls="bp-kb-kebab-menu"><?php echo wp_kses($svg_kebab, devdbotp_kses_allowed()); ?></button>
                        <div class="bpk-kebab-menu hidden" id="bp-kb-kebab-menu">
                            <button type="button" class="bpk-kebab-item" data-kact="bp-kb-rec"><?php echo wp_kses($svg_rec, devdbotp_kses_allowed()); ?>Use Recommended</button>
                            <button type="button" class="bpk-kebab-item" data-kact="bp-kb-robots"><?php echo wp_kses($svg_robots, devdbotp_kses_allowed()); ?>Download robots.txt</button>
                            <?php if ($conn) : ?>
                                <button type="button" class="bpk-kebab-item" data-kact="bp-kb-sync"><?php echo wp_kses($svg_sync, devdbotp_kses_allowed()); ?>Sync Now</button>
                            <?php endif; ?>
                            <button type="button" class="bpk-kebab-item" data-kb-hint aria-controls="bp-kb-hint" aria-expanded="false"><?php echo wp_kses($svg_hint, devdbotp_kses_allowed()); ?>What is this page?</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bp-known-cols" id="bp-known">
                <?php foreach ($catalog as $g) : ?>
                    <div class="bpk-sec">
                        <div class="bpk-head">
                            <span class="bpk-title"><?php echo esc_html($g['label']); ?></span>
                            <button type="button" class="all" data-all="<?php echo esc_attr($g['id']); ?>" aria-label="<?php echo esc_attr('Toggle All ' . $g['label']); ?>">Toggle All</button>
                        </div>
                        <div class="bpk-group" data-group="<?php echo esc_attr($g['id']); ?>">
                            <?php foreach ($g['bots'] as $b) :
                                $allowed = !isset($blocked[$b['id']]);
                                $long    = isset($b['dsLong']) ? $b['dsLong'] : $b['ds']; ?>
                                <label class="bpk-row<?php echo $allowed ? '' : ' off'; ?>">
                                    <div class="bpk-info"><div class="bpk-nm"><?php echo esc_html($b['name']); ?></div><div class="bpk-ds" title="<?php echo esc_attr($long); ?>"><?php echo esc_html($b['ds']); ?></div></div>
                                    <?php if (!empty($b['robots_only'])) : ?>
                                        <span class="bpk-ds bpk-ro" title="A robots.txt data-use control, not a visiting crawler, it has no request hits">robots.txt policy only</span>
                                    <?php else : ?>
                                        <span class="bpk-spark" data-spark="<?php echo esc_attr($b['id']); ?>" data-spark-name="<?php echo esc_attr($b['name']); ?>"></span>
                                        <span class="bpk-num" title="Hits in the selected range">0</span>
                                    <?php endif; ?>
                                    <span class="bpk-state<?php echo $allowed ? '' : ' blocked'; ?>"><?php echo $allowed ? 'Allowed' : 'Blocked'; ?></span>
                                    <span class="bpk-flash"></span>
                                    <span class="bp-switch"><input type="checkbox" data-bot="<?php echo esc_attr($b['id']); ?>" <?php checked($allowed); ?>><span class="tr"></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

/** Instant save of one switch, a Toggle All or Use Recommended: the whole blocked list, then the dashboard push. */
function devdbotp_ajax_kb_save()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('devdbotp_kb', 'nonce');
    $raw  = isset($_POST['blocked_json']) ? json_decode(wp_unslash($_POST['blocked_json']), true) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON list, every entry is matched against the catalog ids below
    if (!is_array($raw)) {
        wp_send_json_error('bad_request', 400);
    }
    $ids = array_values(array_intersect(devdbotp_kb_all_ids(), array_map('strval', $raw)));
    if (!devdbotp_kb_lock(2)) {
        wp_send_json_error('busy', 503); // another request is syncing right now: the click is not saved, the page says so
    }
    devdbotp_db_guard_begin(); // a failed query anywhere in this save = not saved
    try {
        unset($GLOBALS['devdbotp_cache']);
        $stored = devdbotp_kb_blocked(); // the baseline, read inside the lock
        if (!empty($GLOBALS['devdbotp_settings_read_failed']) || devdbotp_db_guard_failed()) {
            wp_send_json_error('not_saved', 500); // the list could not be read: nothing written, the dashboard-only ids are not lost
        }
        $ids = array_values(array_unique(array_merge($ids, array_diff($stored, devdbotp_kb_all_ids())))); // ids this catalog does not know stay as they are (the dashboard's choices)
        devdbotp_update_setting('known_bots_blocked', $ids);
        unset($GLOBALS['devdbotp_cache']);
        if (devdbotp_kb_blocked() !== $ids) {
            wp_send_json_error('not_saved', 500);
        }
        $pushed = devdbotp_kb_push(); // a 409 (the dashboard's copy moved on) is settled inside: this list goes up again
        if (!$pushed && !devdbotp_kb_mark_pending()) {
            wp_send_json_error('not_saved', 500); // the mark did not land: the change would not be sent up again
        }
        if (devdbotp_db_guard_failed()) {
            wp_send_json_error('not_saved', 500);
        }
    } finally {
        devdbotp_db_guard_end();
        devdbotp_kb_unlock();
    }
    wp_send_json_success(array(
        'blocked' => array_values(devdbotp_kb_blocked()),
        'pushed'  => (bool) $pushed,
    ));
}
add_action('wp_ajax_devdbotp_kb_save', 'devdbotp_ajax_kb_save');

/** After a failed immediate push: the pending mark, read back, and the queued push event so the change goes up soon. */
function devdbotp_kb_mark_pending()
{
    devdbotp_update_setting('kb_push_pending', 1);
    unset($GLOBALS['devdbotp_cache']);
    if ((int) devdbotp_get_setting('kb_push_pending', 0) !== 1) {
        return false;
    }
    if (!wp_next_scheduled('devdbotp_kb_push_event')) {
        wp_schedule_single_event(time() + 30, 'devdbotp_kb_push_event');
    }
    return true;
}

/** The two Known Bots actions in the page header, right of the bug icon (owner 2026-09-28); shown only while the Known Bots tab is open (data-bp-tab). */
function devdbotp_kb_head_buttons()
{
    $svg_rec    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>';
    $svg_robots = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';
    ?>
    <span class="bp-kb-headbtns">
        <button class="bp-bug-btn bp-round" id="bp-kb-rec" type="button" title="Use recommended: keep search, AI answers, link previews and monitoring; block training-only crawlers and bulk SEO collection." aria-label="Use Recommended"><?php echo wp_kses($svg_rec, devdbotp_kses_allowed()); ?></button>
        <button class="bp-bug-btn bp-round" id="bp-kb-robots" type="button" title="Download a merge-ready snippet of Disallow groups for your blocked crawlers. This site already serves them in its virtual robots.txt; use the snippet when a physical robots.txt file replaces it." aria-label="Download robots.txt snippet"><?php echo wp_kses($svg_robots, devdbotp_kses_allowed()); ?></button>
    </span>
    <?php
}

/** Sync Now handler (and the old form save, kept for a page that has no JavaScript). */
function devdbotp_handle_kb()
{
    if (empty($_POST['devdbotp_kb_action']) || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('devdbotp_kb', '_bpkb');
    $action = sanitize_key(wp_unslash($_POST['devdbotp_kb_action']));

    $ok = false;
    if ($action === 'pull') {
        $ok = devdbotp_kb_pull(true);
    } elseif ($action === 'save') {
        $blocked = array();
        foreach (devdbotp_kb_all_ids() as $id) {
            if (empty($_POST['bp_kb_' . $id])) {
                $blocked[] = $id;
            }
        }
        if (devdbotp_kb_lock(2)) {
            try {
                unset($GLOBALS['devdbotp_cache']);
                $stored = devdbotp_kb_blocked(); // the baseline, read inside the lock
                if (empty($GLOBALS['devdbotp_settings_read_failed'])) {
                    $blocked = array_values(array_unique(array_merge($blocked, array_diff($stored, devdbotp_kb_all_ids())))); // unknown ids stay
                    $ok = devdbotp_update_setting('known_bots_blocked', $blocked);
                    unset($GLOBALS['devdbotp_cache']);
                    $ok = $ok && devdbotp_kb_blocked() === $blocked;
                    if ($ok && !devdbotp_kb_push()) {
                        $ok = devdbotp_kb_mark_pending(); // saved here; the dashboard gets it on the next check (the mark must land)
                    }
                }
            } finally {
                devdbotp_kb_unlock();
            }
        }
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDBOTP_PAGE, 'bp_tab' => 'knownbots', $ok ? 'bp_saved' : 'bp_dberr' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdbotp_handle_kb');
