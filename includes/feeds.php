<?php
/**
 * Reference feeds + local matchers. The lists are NOT shipped — they are synced
 * daily from the DevDome bot-protection service (only while Outside Lookups is on) and matched LOCALLY on
 * each request. Three feeds:
 *   /list  -> { patterns:[...] }  Cloudflare verified-bot UA patterns (good bots)
 *   /asns  -> { asns:[int,...] }  datacenter / hosting ASNs
 *   /drop  -> { cidrs:[...] }     Spamhaus DROP IP ranges
 *
 * Fixes the two bugs flagged in BOT-PROTECTION-ARCHITECTURE.md when reusing the
 * Redirect Manager's bots.php: (1) IPv6 CIDR matching via inet_pton (ip2long is
 * IPv4-only); (2) the O(n) per-request scan over ~1600 DROP ranges -> a prebuilt
 * sorted IPv4 index searched in O(log n).
 */

defined('ABSPATH') || exit;

/** Daily cron + maintenance bus: refresh this plugin's OWN verified-bot IP ranges (the
 *  shared core owns the UA/ASN/DROP feeds) and fan out to the prune listeners. */
function devdbotp_refresh_feeds()
{
    $GLOBALS['devdbotp_feed_failures'] = 0;
    $feeds = devdbotp_get_setting('bp_feeds', array());
    if (!is_array($feeds)) {
        $feeds = array();
    }
    if (!devdbotp_outside_lookups_on()) {
        // Outside Lookups is off: no list is downloaded, the lists on hand stay in use (the prune listeners share the
        // cron hook). Bot Radar has its own consent (Rules tab), so its community list still syncs.
        if (function_exists('devdbotp_fleet_sync')) {
            devdbotp_fleet_sync($feeds);
            devdbotp_update_setting('bp_feeds', $feeds);
            unset($GLOBALS['devdbotp_cache']);
        }
        return true;
    }
    // The basic UA/ASN/DROP feeds are owned by the shared core (one sync for the whole
    // suite). Trigger it here too so the "Refresh now" button refreshes everything.
    if (function_exists('devdcorev1_refresh_feeds')) {
        devdcorev1_refresh_feeds();
    }
    // The basic lists normally live in the shared core. But the core keeps its download switched OFF as soon as
    // ANY WordPress.org build of a DevDome plugin is on the site (none of those use the lists). Bot Protection
    // does use them, so when the core has none it downloads the same three public lists itself, into its own
    // store. Nothing about the site or its visitors is sent: three plain GETs, disclosed in the readme.
    $core = get_option('devdcorev1_feeds', array()); // not devdcorev1_get_feeds(): that one is cached per request
    $core_fresh = is_array($core) && !empty($core['patterns']) && time() - (int) ($core['fetched'] ?? 0) <= DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS; // the core's own stale rule
    if ($core_fresh) {
        unset($feeds['patterns'], $feeds['asns'], $feeds['drop_v4'], $feeds['drop_v6']); // the core's copy wins while it is fresh
    } else {
        $feeds = devdbotp_fetch_basic_lists($feeds);
    }

    // Verified-bot published IP ranges (Google/Bing/Apple/OpenAI) — the SEO-safe
    // allowlist's IP PROOF, so a spoofed good-bot UA from a random IP is rejected.
    // Same searchable index format as DROP. A failed fetch keeps the previous ranges.
    // Kept PER SOURCE: a source that fails today keeps yesterday's ranges, the others still refresh.
    $sources = isset($feeds['bot_src']) && is_array($feeds['bot_src']) ? $feeds['bot_src'] : array();
    foreach (devdbotp_fetch_verified_bot_cidrs(true) as $url => $list) {
        $sources[$url] = $list;
    }
    $bot_cidrs = array();
    foreach ($sources as $list) {
        $bot_cidrs = array_merge($bot_cidrs, (array) $list);
    }
    $bot_cidrs = array_values(array_unique(array_filter($bot_cidrs)));
    if (!empty($bot_cidrs)) {
        $bidx = devdbotp_build_drop_index($bot_cidrs);
        $feeds['bot_src'] = $sources;
        $feeds['bot_v4']  = $bidx['v4'];
        $feeds['bot_v6']  = $bidx['v6'];
    }

    // Opt-in community blocklist (the fleet network) — synced into the same feed cache.
    if (function_exists('devdbotp_fleet_sync')) {
        devdbotp_fleet_sync($feeds);
    }

    $feeds['fetched'] = time(); // the last attempt
    if (empty($GLOBALS['devdbotp_feed_failures'])) {
        $feeds['fetched_ok'] = time(); // the last time EVERY source answered
    }
    devdbotp_update_setting('bp_feeds', $feeds);
    unset($GLOBALS['devdbotp_cache']);
    $stored = devdbotp_get_setting('bp_feeds', array());
    return empty($GLOBALS['devdbotp_feed_failures']) && is_array($stored) && isset($stored['fetched']) && (int) $stored['fetched'] === (int) $feeds['fetched'];
}
add_action('devdbotp_refresh_feeds', 'devdbotp_refresh_feeds');

/**
 * Merged feed view: the shared core's basic signals (UA patterns / datacenter ASNs /
 * Spamhaus DROP) + this plugin's own verified-bot IP ranges (bot_v4/bot_v6). Everything
 * in this plugin that needs "feeds" goes through here so the basic lists come from core
 * and the anti-spoof ranges come from bp_feeds.
 */
function devdbotp_feeds()
{
    $core = function_exists('devdcorev1_get_feeds') ? devdcorev1_get_feeds() : array();
    $bp   = devdbotp_get_setting('bp_feeds', array());
    if (!is_array($core)) { $core = array(); }
    if (!is_array($bp)) { $bp = array(); }
    // Core LAST so its authoritative basic lists (patterns/asns/drop) win over any pre-migration leftovers in
    // bp_feeds; bp's own bot_v4/bot_v6 ranges survive. But only while the core's copy is FRESH (the same 26 h rule
    // the downloader uses): a stale core copy must not sit on top of the lists this plugin downloaded itself.
    $stored     = get_option('devdcorev1_feeds', array());
    $core_fresh = is_array($stored) && !empty($stored['patterns']) && time() - (int) ($stored['fetched'] ?? 0) <= DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS;
    return $core_fresh ? array_merge($bp, $core) : array_merge($core, $bp);
}

/**
 * The three basic lists (verified bot names, datacenter networks, Spamhaus DROP ranges), in the same shapes the
 * shared core stores them. A list that fails to download keeps its previous copy.
 */
function devdbotp_fetch_basic_lists($feeds)
{
    $base = 'https://api.devdome.com/bot-protection';
    $get  = function ($path, $key) use ($base) {
        // The plugin's own user agent: WordPress's default one carries the site URL, and the feed needs nothing about the site.
        $r = wp_remote_get($base . $path, array('timeout' => 10, 'user-agent' => 'DevDome-Bot-Protection/' . DEVDBOTP_VERSION));
        $d = (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) ? null : json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($d) || !isset($d[$key]) || !is_array($d[$key])) {
            $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1; // counted: the refresh reports it
            return array();
        }
        return $d[$key];
    };

    $patterns = $get('/list', 'patterns');
    if ($patterns) {
        $feeds['patterns'] = array_values(array_filter(array_map(function ($p) {
            return strtolower(trim((string) $p));
        }, $patterns)));
    }
    $asns = $get('/asns', 'asns');
    if ($asns) {
        $map = array();
        foreach ($asns as $a) {
            $map[(int) $a] = 1;
        }
        $feeds['asns'] = $map;
    }
    $cidrs = $get('/drop', 'cidrs');
    if ($cidrs) {
        $idx = devdbotp_build_drop_index(array_values(array_filter(array_map('trim', array_map('strval', $cidrs)))));
        $feeds['drop_v4'] = $idx['v4'];
        $feeds['drop_v6'] = $idx['v6'];
    }
    return $feeds;
}

/** Published verified-bot IP-range JSON sources (each is {"prefixes":[{ipv4Prefix|ipv6Prefix}]}). */
function devdbotp_verified_bot_ip_sources()
{
    return array(
        'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
        'https://developers.google.com/static/search/apis/ipranges/special-crawlers.json',
        'https://www.bing.com/toolbox/bingbot.json',
        'https://search.developer.apple.com/applebot.json',
        'https://openai.com/gptbot.json',
        'https://openai.com/searchbot.json',
        'https://openai.com/chatgpt-user.json',
    );
}

/** Fetch + merge all published verified-bot CIDR prefixes (best-effort; a failed source is skipped). */
function devdbotp_fetch_verified_bot_cidrs($per_source = false)
{
    $cidrs  = array();
    $by_src = array();
    foreach (devdbotp_verified_bot_ip_sources() as $url) {
        $r = wp_remote_get($url, array('timeout' => 10, 'user-agent' => 'DevDome-Bot-Protection/' . DEVDBOTP_VERSION));
        $d = (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) ? null : json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($d) || empty($d['prefixes']) || !is_array($d['prefixes'])) {
            $GLOBALS['devdbotp_feed_failures'] = (int) ($GLOBALS['devdbotp_feed_failures'] ?? 0) + 1;
            continue;
        }
        $mine = array();
        foreach ($d['prefixes'] as $p) {
            if (!empty($p['ipv4Prefix'])) { $mine[] = trim((string) $p['ipv4Prefix']); }
            if (!empty($p['ipv6Prefix'])) { $mine[] = trim((string) $p['ipv6Prefix']); }
        }
        $mine = array_values(array_unique(array_filter($mine)));
        if ($mine) {
            $by_src[$url] = $mine;
            $cidrs        = array_merge($cidrs, $mine);
        }
    }
    return $per_source ? $by_src : array_values(array_unique(array_filter($cidrs)));
}

/** Build the searchable CIDR index — delegates to the shared core. Kept as a thin
 *  wrapper so the verified-bot-range builder above doesn't need to change. */
function devdbotp_build_drop_index($cidrs)
{
    return function_exists('devdcorev1_build_cidr_index')
        ? devdcorev1_build_cidr_index($cidrs)
        : array('v4' => array(), 'v6' => array());
}

/** Client IP, preferring Cloudflare's connecting-IP header when present. */
/**
 * Cloudflare edge IP ranges (public list, https://www.cloudflare.com/ips/), refreshed with plugin
 * updates.
 */
function devdbotp_cf_ranges()
{
    return array('173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32');
}

/**
 * True only when the request really connects from a Cloudflare edge. Every Cloudflare header
 * (CF-Connecting-IP, CF-IPCountry, the ASN headers, the bot score) is believed ONLY then, so a
 * direct visitor cannot pick their own address or country by sending the header themselves.
 */
function devdbotp_behind_cloudflare()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $peer = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
    if (!filter_var($peer, FILTER_VALIDATE_IP)) {
        return $cached = false;
    }
    foreach (devdbotp_cf_ranges() as $cidr) {
        if (devdbotp_ip_in_cidr($peer, $cidr)) {
            return $cached = true;
        }
    }
    return $cached = false;
}

function devdbotp_client_ip()
{
    $headers = devdbotp_behind_cloudflare() ? array('HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR') : array('REMOTE_ADDR');
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(sanitize_text_field(wp_unslash($_SERVER[$h])));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '';
}

/**
 * Read available Cloudflare request signals (Phase 2). Graceful by design — country
 * & ray ship on the free plan (IP Geolocation, on by default); ASN needs a transform
 * rule; bot-score is Enterprise-only. Anything absent comes back empty/null.
 */
function devdbotp_cf_signals()
{
    $cf = devdbotp_behind_cloudflare(); // headers from anyone else are not believed
    $country = '';
    if ($cf && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $c = strtoupper(preg_replace('/[^A-Za-z]/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY']))));
        if (strlen($c) === 2) {
            $country = $c;
        }
    }

    $asn = 0;
    // x-dd-asn is set by our Cloudflare Transform Rule (cf-* headers can't be set by rules, so we use
    // a custom header); cf-asn / cf-ipasnum kept for setups that already expose them.
    foreach (array('HTTP_X_DD_ASN', 'HTTP_CF_ASN', 'HTTP_CF_IPASNUM') as $h) {
        if ($cf && !empty($_SERVER[$h]) && ctype_digit(sanitize_text_field(wp_unslash($_SERVER[$h])))) {
            $asn = (int) $_SERVER[$h];
            break;
        }
    }
    // Universal fallback: no ASN header (site not behind Cloudflare, or no Transform Rule) -> resolve
    // it via geo-resolve, cached per-IP (a lookup per NEW IP, never per request). Gated to when there
    // is actually a flagged-ASN list to match against, so most sites never do a lookup.
    if ($asn === 0 && function_exists('devdbotp_asn_matching_active') && devdbotp_asn_matching_active()
        && function_exists('devdbotp_resolve_asn') && function_exists('devdbotp_client_ip')) {
        $asn = devdbotp_resolve_asn(devdbotp_client_ip());
    }

    $bot_score = null;
    if ($cf && isset($_SERVER['HTTP_CF_BOT_SCORE']) && ctype_digit(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_BOT_SCORE'])))) {
        $bot_score = max(0, min(100, (int) $_SERVER['HTTP_CF_BOT_SCORE']));
    }

    $ray = '';
    if ($cf && !empty($_SERVER['HTTP_CF_RAY'])) { // like every other Cloudflare header: only from a Cloudflare edge
        $ray = substr(preg_replace('/[^A-Za-z0-9\-]/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_RAY']))), 0, 64);
    }

    return array('country' => $country, 'asn' => $asn, 'bot_score' => $bot_score, 'ray' => $ray);
}

/** Mask an IP for privacy: IPv4 -> /24 (last octet 0), IPv6 -> /48. Empty/invalid pass through. */
function devdbotp_anonymize_ip($ip)
{
    if ($ip === '') {
        return $ip;
    }
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return $ip;
    }
    if (strlen($packed) === 4) {
        return inet_ntop(substr($packed, 0, 3) . chr(0));
    }
    if (strlen($packed) === 16) {
        return inet_ntop(substr($packed, 0, 6) . str_repeat(chr(0), 10));
    }
    return $ip;
}

/** Does the UA match a known verified good-bot pattern? (Recognition only — no IP proof.
 *  The good-bot pattern list IS the shared core's verified-bot UA list.) */
function devdbotp_ua_matches_good_bot_pattern($ua, $feeds = null)
{
    if ($feeds === null) {
        $feeds = devdbotp_feeds();
    }
    return function_exists('devdcorev1_ua_is_bot') ? devdcorev1_ua_is_bot($ua, $feeds) : false;
}

/**
 * SEO-safe allowlist signal: the UA claims a verified good bot AND the request IP
 * PROVES it — the IP is in that bot's published range, or cached forward-confirmed
 * reverse DNS resolves to a good-bot domain. A spoofed good-bot UA from a random IP
 * is NOT allowlisted (the cardinal rule — "never UA alone", BOT-PROTECTION-ARCHITECTURE.md
 * §3.1/§7). UA-only fallback applies ONLY until the first feed sync (fresh install) or
 * when there is no IP to verify against (CLI / the SEO self-test).
 */
function devdbotp_ua_is_verified_bot($ua, $ip = '', $feeds = null)
{
    if ($feeds === null) {
        $feeds = devdbotp_feeds();
    }
    if (!devdbotp_ua_matches_good_bot_pattern($ua, $feeds)) {
        return false;
    }
    if ($ip === '') {
        return true; // no IP to verify (self-test / CLI)
    }
    $has_ranges = !empty($feeds['bot_v4']) || !empty($feeds['bot_v6']);
    if (!$has_ranges) {
        return devdbotp_fcrdns_good_bot($ip); // no ranges yet (first sync pending): the reverse-DNS check decides, a user agent alone never does
    }
    if (devdbotp_ip_in_verified_ranges($ip, $feeds)) {
        return true; // proven by a published verified-bot range — zero DNS
    }
    return devdbotp_fcrdns_good_bot($ip); // cached reverse-DNS fallback (Yandex/DuckDuckGo/etc.)
}

/** Good-bot reverse-DNS domain suffixes for the FCrDNS fallback. */
function devdbotp_good_bot_dns_suffixes()
{
    return array(
        '.googlebot.com', '.google.com', '.search.msn.com', '.applebot.apple.com',
        '.yandex.com', '.yandex.net', '.yandex.ru', '.duckduckgo.com', '.crawl.yahoo.net',
    );
}

/** Cached forward-confirmed reverse DNS: does $ip actually belong to a good-bot operator? */
function devdbotp_fcrdns_good_bot($ip)
{
    if ($ip === '') {
        return false;
    }
    $key = 'devdbotp_fcr_' . md5($ip);
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached === '1';
    }
    $ok = devdbotp_fcrdns_check($ip) ? '1' : '0';
    set_transient($key, $ok, $ok === '1' ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
    return $ok === '1';
}

function devdbotp_fcrdns_check($ip)
{
    if (!function_exists('gethostbyaddr') || !function_exists('gethostbyname') || !function_exists('dns_get_record')) {
        return false; // DNS functions disabled on this host (some sandboxes): unverified, never a fatal
    }
    $host = @gethostbyaddr($ip);
    if (!$host || $host === $ip) {
        return false;
    }
    $host = strtolower(rtrim($host, '.'));
    $match = false;
    foreach (devdbotp_good_bot_dns_suffixes() as $sfx) {
        if (strlen($host) > strlen($sfx) && substr($host, -strlen($sfx)) === $sfx) {
            $match = true;
            break;
        }
    }
    if (!$match) {
        return false;
    }
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }
    if (strlen($packed) === 4) {
        return @gethostbyname($host) === $ip; // forward-confirm IPv4
    }
    $recs = @dns_get_record($host, DNS_AAAA); // forward-confirm IPv6
    if (is_array($recs)) {
        foreach ($recs as $rec) {
            if (!empty($rec['ipv6']) && @inet_pton($rec['ipv6']) === $packed) {
                return true;
            }
        }
    }
    return false;
}

/** Is this ASN a known datacenter/hosting ASN? (Delegates to the shared core.) */
function devdbotp_is_datacenter_asn($asn, $feeds = null)
{
    if ($feeds === null) {
        $feeds = devdbotp_feeds();
    }
    return function_exists('devdcorev1_is_datacenter_asn') ? devdcorev1_is_datacenter_asn($asn, $feeds) : false;
}

/** Is the IP inside a Spamhaus DROP range? (Delegates to the shared core.) */
function devdbotp_ip_in_drop($ip, $feeds = null)
{
    if ($feeds === null) {
        $feeds = devdbotp_feeds();
    }
    return function_exists('devdcorev1_ip_in_drop') ? devdcorev1_ip_in_drop($ip, $feeds) : false;
}

/** Is the IP inside a published verified-good-bot range (Google / Bing / Apple / OpenAI)? */
function devdbotp_ip_in_verified_ranges($ip, $feeds = null)
{
    if ($feeds === null) {
        $feeds = devdbotp_feeds();
    }
    return devdbotp_ip_in_cidr_index(
        $ip,
        isset($feeds['bot_v4']) ? $feeds['bot_v4'] : array(),
        isset($feeds['bot_v6']) ? $feeds['bot_v6'] : array()
    );
}

/** Core membership test (delegates to the shared core's CIDR index search). */
function devdbotp_ip_in_cidr_index($ip, $v4, $v6)
{
    return function_exists('devdcorev1_ip_in_cidr_index') ? devdcorev1_ip_in_cidr_index($ip, $v4, $v6) : false;
}

/** The shared library's beacon may believe CF-Connecting-IP only when the peer really is Cloudflare. */
add_filter('devdcorev1_trust_cf_ip', 'devdbotp_trust_cf_ip_filter');
function devdbotp_trust_cf_ip_filter($trust)
{
    return $trust || devdbotp_behind_cloudflare();
}
