<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): DevDome Bot Protection exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Every write goes through the same function the screen uses (devdbotp_update_setting, the custom rule
 * functions, devdbotp_set_safe_mode). Lowering protection (protection off, Live back to Watch, pausing)
 * needs confirm: true. On WordPress older than 6.9 the API does not exist and this file registers nothing.
 * Modelled on Country Blocker includes/abilities.php.
 */

defined('ABSPATH') || exit;

function devdbotp_ability_ids()
{
    return array(
        'devdome-bot-protection/get-status',
        'devdome-bot-protection/get-settings',
        'devdome-bot-protection/update-settings',
        'devdome-bot-protection/list-rules',
        'devdome-bot-protection/add-rule',
        'devdome-bot-protection/delete-rule',
        'devdome-bot-protection/list-recent-events',
        'devdome-bot-protection/pause-protection',
        'devdome-bot-protection/resume-protection',
        'devdome-bot-protection/clear-statistics',
    );
}

add_action('wp_abilities_api_categories_init', 'devdbotp_register_ability_category');
function devdbotp_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-bot-protection', array(
        'label'       => __('DevDome Bot Protection', 'devdome-bot-protection'),
        'description' => __('Detect and block harmful bots: protection on or off, Watch or Live mode, a 30 minute pause, custom rules, the recent event log and the privacy settings.', 'devdome-bot-protection'),
    ));
}

function devdbotp_ability_can()
{
    return current_user_can('manage_options');
}

/** read / modify / destroy annotations; $idempotent overrides the default for that kind. */
function devdbotp_ability_meta($kind, $idempotent = null)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'modify'  => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
    );
    $ann = isset($map[$kind]) ? $map[$kind] : $map['modify'];
    if (null !== $idempotent) {
        $ann['idempotent'] = (bool) $idempotent;
    }
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => $ann,
        'mcp'          => array('type' => 'tool'),
    );
}

/** confirm: true or a WP_Error the agent must show the user. */
function devdbotp_ability_confirmed($input, $why)
{
    if (is_array($input) && isset($input['confirm']) && true === $input['confirm']) {
        return true;
    }
    return new WP_Error('devdbotp_confirm_required', $why . ' ' . __('Pass confirm: true after the user agreed.', 'devdome-bot-protection'));
}

/** Strict switch value: bool, 0/1 or the spellings true/false/yes/no/on/off; anything else = null (refused). */
function devdbotp_ability_bool($v)
{
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) && (0 === $v || 1 === $v)) {
        return 1 === $v;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }
        if (in_array($s, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }
    }
    return null;
}

/** Settings as the agent sees them, one shape for get-settings and the update read-back. */
function devdbotp_ability_settings()
{
    return array(
        'protection'     => 'off' === (string) devdbotp_get_setting('protection_level', 'recommended') ? 'off' : 'on',
        'mode'           => 'live' === (string) devdbotp_get_setting('mode', 'watching') ? 'live' : 'watching',
        'retention_days' => devdbotp_retention_days(), // read only: 90 days for every site
        'anonymize_ips'  => (bool) (int) devdbotp_get_setting('anonymize_ips', 0),
        'outside_lookups' => (bool) (int) devdbotp_get_setting('outside_lookups', 0), // read only: the consent switch is set on the Settings tab, next to its disclosure
    );
}

function devdbotp_ability_rule_row($r)
{
    return array(
        'id'       => (int) $r['id'],
        'enabled'  => !empty($r['enabled']),
        'name'     => devdbotp_ability_redact((string) $r['name']),
        'field'    => (string) $r['match_field'],
        'operator' => (string) $r['operator'],
        'pattern'  => devdbotp_ability_redact((string) $r['pattern']),
        'action'   => (string) $r['action'],
    );
}

/* ------------------------------ handlers ------------------------------ */

function devdbotp_ability_get_status($input = array())
{
    $s     = devdbotp_ability_settings();
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return new WP_Error('devdbotp_db_error', __('The settings could not be read from the database. Try again.', 'devdome-bot-protection')); // defaults are not the state
    }
    $until = (int) devdbotp_get_setting('safe_mode_until', 0);
    $feeds = devdbotp_feeds();
    return array(
        'protection'     => $s['protection'],
        'mode'           => $s['mode'],
        'paused'         => $until > time(),
        'paused_until'   => $until > time() ? $until : 0,
        'blocked_today'  => (int) devdbotp_get_setting('stat_day_' . devdbotp_days_back(1)[0] . '_blocked', 0),
        'bots_seen'      => (int) devdbotp_get_setting('stat_bots_seen', 0),
        'humans_seen'    => (int) devdbotp_get_setting('stat_humans_seen', 0),
        'feeds_synced'   => is_array($feeds) && !empty($feeds['patterns']),
        'plugin_version' => defined('DEVDBOTP_VERSION') ? DEVDBOTP_VERSION : '',
    );
}

function devdbotp_ability_get_settings($input = array())
{
    $s = devdbotp_ability_settings();
    if (!empty($GLOBALS['devdbotp_settings_read_failed'])) {
        return new WP_Error('devdbotp_db_error', __('The settings could not be read from the database. Try again.', 'devdome-bot-protection')); // defaults are not the state
    }
    return array('settings' => $s);
}

function devdbotp_ability_update_settings($input = array())
{
    $input = is_array($input) ? $input : array();
    $was   = devdbotp_ability_settings();
    if (!empty($GLOBALS['devdbotp_settings_read_failed']) || devdbotp_db_guard_failed()) {
        return new WP_Error('devdbotp_db_error', __('The current settings could not be read; nothing was changed. Try again.', 'devdome-bot-protection')); // a default baseline is not a baseline
    }
    $patch = array();
    if (array_key_exists('mode', $input)) {
        $m = is_string($input['mode']) ? strtolower(trim($input['mode'])) : '';
        if (!in_array($m, array('watching', 'live'), true)) {
            return new WP_Error('devdbotp_invalid_input', __('mode must be watching or live.', 'devdome-bot-protection'));
        }
        $patch['mode'] = $m;
    }
    if (array_key_exists('protection', $input)) {
        $p = is_string($input['protection']) ? strtolower(trim($input['protection'])) : '';
        if (!in_array($p, array('on', 'off'), true)) {
            return new WP_Error('devdbotp_invalid_input', __('protection must be on or off.', 'devdome-bot-protection'));
        }
        $patch['protection'] = $p;
    }
    if (array_key_exists('anonymize_ips', $input)) {
        $b = devdbotp_ability_bool($input['anonymize_ips']);
        if (null === $b) {
            return new WP_Error('devdbotp_invalid_input', __('anonymize_ips must be true or false.', 'devdome-bot-protection'));
        }
        $patch['anonymize_ips'] = $b;
    }
    if (!$patch) {
        return new WP_Error('devdbotp_invalid_input', __('Pass at least one setting to change.', 'devdome-bot-protection'));
    }
    // Lowering protection needs the owner's yes: protection off, or Live back to Watch. Going Live does not.
    $turns_off = isset($patch['protection']) && 'off' === $patch['protection'] && 'off' !== $was['protection'];
    $to_watch  = isset($patch['mode']) && 'watching' === $patch['mode'] && 'live' === $was['mode'];
    $unmask    = isset($patch['anonymize_ips']) && !$patch['anonymize_ips'] && !empty($was['anonymize_ips']);
    if ($turns_off || $to_watch || $unmask) {
        $ok = devdbotp_ability_confirmed($input, $turns_off
            ? __('Turning protection off stops all bot detection and blocking.', 'devdome-bot-protection')
            : ($to_watch ? __('Switching from Live to Watch stops blocking: bots are only logged from then on.', 'devdome-bot-protection')
                : __('Turning Anonymize IPs off stores full visitor addresses from then on.', 'devdome-bot-protection')));
        if (is_wp_error($ok)) {
            return $ok;
        }
    }
    // The three writes are one change: when one is refused or fails, the ones before it go back (in a new error
    // window, since the guard refuses writes after a failed query) and the answer says nothing was applied.
    $applied = true;
    if (isset($patch['protection'])) {
        $applied = false !== devdbotp_set_site_state(array('on' => 'off' !== $patch['protection']));
    }
    if ($applied && isset($patch['mode'])) {
        $applied = false !== devdbotp_set_site_state(array('mode' => $patch['mode']));
    }
    if ($applied && isset($patch['anonymize_ips'])) {
        $applied = devdbotp_update_setting('anonymize_ips', $patch['anonymize_ips'] ? 1 : 0);
    }
    unset($GLOBALS['devdbotp_cache']);
    $now = devdbotp_ability_settings();
    $not_applied = array();
    foreach ($patch as $k => $want) {
        if (!$applied || devdbotp_db_guard_failed() || $now[$k] !== $want) {
            $not_applied[] = $k;
        }
    }
    if ($not_applied) {
        devdbotp_db_guard_rebase();
        if (isset($patch['protection'])) { devdbotp_set_site_state(array('on' => 'off' !== $was['protection'])); }
        if (isset($patch['mode'])) { devdbotp_set_site_state(array('mode' => $was['mode'])); }
        if (isset($patch['anonymize_ips'])) { devdbotp_update_setting('anonymize_ips', $was['anonymize_ips'] ? 1 : 0); }
        unset($GLOBALS['devdbotp_cache']);
        $now = devdbotp_ability_settings();
        $not_applied = array_keys($patch); // none of it counts as applied; the settings returned are what the site holds now
        $restored = true;
        foreach ($patch as $k => $want) {
            if ($now[$k] !== $was[$k]) {
                $restored = false; // the undo did not land for this key: the answer says so, the settings show what holds
            }
        }
        return array('updated' => false, 'not_applied' => $not_applied, 'restored' => $restored, 'settings' => $now);
    }
    return array('updated' => empty($not_applied), 'not_applied' => $not_applied, 'settings' => $now);
}

function devdbotp_ability_list_rules($input = array())
{
    $rows = devdbotp_all_custom_rules();
    if (!empty($GLOBALS['devdbotp_rules_read_failed'])) {
        return new WP_Error('devdbotp_db_error', __('The rules could not be read from the database. Try again.', 'devdome-bot-protection')); // never "no rules"
    }
    return array('rules' => array_map('devdbotp_ability_rule_row', $rows));
}

function devdbotp_ability_add_rule($input = array())
{
    $input = is_array($input) ? $input : array();
    foreach (array('field', 'operator', 'pattern', 'action') as $k) {
        if (!isset($input[$k]) || !is_string($input[$k])) {
            /* translators: %s is an input key. */
            return new WP_Error('devdbotp_invalid_input', sprintf(__('%s is required and must be a string.', 'devdome-bot-protection'), $k));
        }
    }
    if ($input['action'] === 'allow' && (empty($input['confirm']) || $input['confirm'] !== true)) {
        return new WP_Error('devdbotp_confirm_required', __('An Allow rule lets matching requests through every check. Pass confirm: true.', 'devdome-bot-protection'));
    }
    $name = isset($input['name']) && is_string($input['name']) ? $input['name'] : '';
    $id   = devdbotp_add_custom_rule($input['field'], $input['operator'], $input['pattern'], $input['action'], $name);
    if (false === $id) {
        return new WP_Error('devdbotp_invalid_input', __('The rule was refused. field is user_agent, path, ip, country or asn. operator is contains, equals, starts_with or regex for user_agent and path, equals or cidr for ip, equals for country and asn. action is allow, log or block. pattern is 1 to 255 characters and a regex must compile.', 'devdome-bot-protection'));
    }
    if ($id < 1) {
        return new WP_Error('devdbotp_write_failed', __('The rule could not be stored: the database did not keep it.', 'devdome-bot-protection'));
    }
    foreach (devdbotp_all_custom_rules() as $r) {
        if ((int) $r['id'] === (int) $id) {
            return array('added' => true, 'rule' => devdbotp_ability_rule_row($r));
        }
    }
    return new WP_Error('devdbotp_write_failed', __('The rule could not be read back after the write.', 'devdome-bot-protection'));
}

function devdbotp_ability_delete_rule($input = array())
{
    $input = is_array($input) ? $input : array();
    if (empty($input['confirm']) || $input['confirm'] !== true) {
        return new WP_Error('devdbotp_confirm_required', __('Deleting a rule cannot be undone. Pass confirm: true.', 'devdome-bot-protection'));
    }
    $input = is_array($input) ? $input : array();
    $id    = isset($input['id']) && (is_int($input['id']) || (is_string($input['id']) && ctype_digit($input['id']))) ? (int) $input['id'] : 0;
    if ($id < 1) {
        return new WP_Error('devdbotp_invalid_input', __('id must be the id of a custom rule.', 'devdome-bot-protection'));
    }
    $found = false;
    $rows  = devdbotp_all_custom_rules();
    if (!empty($GLOBALS['devdbotp_rules_read_failed'])) {
        return new WP_Error('devdbotp_db_error', __('The rules could not be read from the database; nothing was deleted. Try again.', 'devdome-bot-protection'));
    }
    foreach ($rows as $r) {
        if ((int) $r['id'] === $id) {
            $found = true;
        }
    }
    if (!$found) {
        return new WP_Error('devdbotp_not_found', __('There is no custom rule with this id.', 'devdome-bot-protection'));
    }
    devdbotp_delete_custom_rule($id);
    $rows = devdbotp_all_custom_rules();
    if (!empty($GLOBALS['devdbotp_rules_read_failed'])) {
        return new WP_Error('devdbotp_db_error', __('The rules could not be read back after the delete; check the list.', 'devdome-bot-protection'));
    }
    foreach ($rows as $r) {
        if ((int) $r['id'] === $id) {
            return new WP_Error('devdbotp_write_failed', __('The rule could not be deleted: it is still in the database.', 'devdome-bot-protection'));
        }
    }
    return array('deleted' => true, 'id' => $id);
}

function devdbotp_ability_list_recent_events($input = array())
{
    global $wpdb;
    $input = is_array($input) ? $input : array();
    $limit = isset($input['limit']) ? (int) $input['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $t     = $wpdb->prefix . 'devdbotp_events';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; the limit is prepared.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, created_at, ip_text, path, user_agent, bot_category, action, reason, country, asn FROM $t ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    $out  = array();
    foreach ((array) $rows as $r) {
        $out[] = array(
            'id'           => (int) $r['id'],
            'time'         => (string) $r['created_at'],
            'ip'           => function_exists('devdbotp_anonymize_ip') ? (string) devdbotp_anonymize_ip((string) $r['ip_text']) : '', // agent output never carries a full visitor address
            'path'         => devdbotp_ability_redact((string) strtok((string) $r['path'], '?')), // never the query string, never an address in the path
            'user_agent'   => devdbotp_ability_redact((string) $r['user_agent']),
            'bot_category' => (string) $r['bot_category'],
            'action'       => (string) $r['action'],
            'reason'       => devdbotp_ability_redact((string) $r['reason']),
            'country'      => (string) $r['country'],
            'asn'          => (int) $r['asn'],
        );
    }
    return array('events' => $out);
}

function devdbotp_ability_pause_protection($input = array())
{
    $ok = devdbotp_ability_confirmed($input, __('This pauses all blocking for 30 minutes.', 'devdome-bot-protection'));
    if (is_wp_error($ok)) {
        return $ok;
    }
    $until = time() + 1800;
    devdbotp_set_site_state(array('pause_until' => $until));
    unset($GLOBALS['devdbotp_cache']);
    $have = (int) devdbotp_get_setting('safe_mode_until', 0);
    if ($have !== $until) {
        return new WP_Error('devdbotp_write_failed', __('The pause could not be stored. Protection is not paused.', 'devdome-bot-protection'));
    }
    return array('paused' => true, 'paused_until' => $until);
}

function devdbotp_ability_resume_protection($input = array())
{
    devdbotp_set_site_state(array('pause_until' => 0));
    unset($GLOBALS['devdbotp_cache']);
    if ((int) devdbotp_get_setting('safe_mode_until', 0) > time()) {
        return new WP_Error('devdbotp_write_failed', __('The pause could not be cleared. Protection is still paused.', 'devdome-bot-protection'));
    }
    return array('paused' => false, 'paused_until' => 0);
}

function devdbotp_ability_clear_statistics($input = array())
{
    if (empty($input['confirm']) || $input['confirm'] !== true) {
        return new WP_Error('devdbotp_confirm_required', __('Pass confirm: true to clear every statistic on this site.', 'devdome-bot-protection'));
    }
    if (!function_exists('devdbotp_clear_statistics')) {
        return new WP_Error('devdbotp_unavailable', __('Clearing is not available in this request.', 'devdome-bot-protection'));
    }
    $ok = devdbotp_clear_statistics();
    if (!$ok) {
        return new WP_Error('devdbotp_write_failed', __('The statistics could not be cleared completely: a database query failed.', 'devdome-bot-protection'));
    }
    return array('cleared' => true);
}

/** Agent output never carries an e-mail address or a token-looking query value. */
function devdbotp_ability_redact($s)
{
    $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', (string) $s);
    if (function_exists('devdbotp_anonymize_ip')) { // an address inside a path, a user agent or a reason is masked like the ip field
        $s = preg_replace_callback('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', function ($m) { $a = devdbotp_anonymize_ip($m[0]); return '' !== $a ? $a : $m[0]; }, $s);
        $s = preg_replace_callback('/(?<![\w:.])((?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4})(?![\w:.])/i', function ($m) { // ::1234:5678 and 2001:db8::5/128 too (the suffix stays)
            if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { return $m[0]; }
            $a = devdbotp_anonymize_ip($m[1]); return '' !== $a ? $a : $m[0];
        }, $s);
    }
    $s = preg_replace('/-----BEGIN [A-Z ]+-----.*?(?:-----END [A-Z ]+-----|$)/s', '[redacted]', $s); // a key or certificate block, whole, ended or cut off
    // putenv("NAME=value"), define('NAME', 'value'), setenv: the whole quoted value goes, however many lines it spans,
    // and the same number of line breaks stays so line numbers around it still mean something.
    $s = preg_replace_callback('/\b(putenv|setenv|define)\(\s*(["\'])((?:\\\\.|(?!\2).)*?(?:token|key|secret|pass|auth|sig|cookie|session)(?:\\\\.|(?!\2).)*?)\2((?:\s*,\s*(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^)"\']+))*\s*)\)/is', function ($m) { // quoted arguments are taken whole: an escaped quote or a ) inside a value does not end the match
        return $m[1] . '(' . $m[2] . '[redacted]' . str_repeat("\n", substr_count($m[3] . $m[4], "\n")) . $m[2] . ')';
    }, $s);
    // Secrets in any shape: name=value, name: value, "name":"value" (JSON), Authorization: Bearer x, quoted values, and a
    // value that continues on indented lines (a multi-line assignment) is taken whole.
    $s = preg_replace_callback('/((?:token|key|secret|pass|password|auth|sig|bearer|cookie|session)[a-z_-]*[\'"]?\s*[=:,]\s*)((?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|(?:bearer|basic|token|digest)\s+[^&\n]+|[^&\n]+)(?:\n[ \t]+[^\n]*)*)/i', function ($m) { // an escaped quote inside a quoted value does not end it
        return $m[1] . '[redacted]' . str_repeat("\n", substr_count($m[2], "\n")); // the line count around it stays true
    }, $s);
    $s = preg_replace('#(?:^|[\s=:"\'])[A-Za-z]:(?:\\\\[^\\\\/:*?"<>|\r\n]+){2,}#', ' [path]', $s);                          // a Windows path (spaces allowed in its folders)
    $s = preg_replace('#(?:^|[\s=:"\'])/(?:var|srv|home|usr|etc|opt|tmp|root|mnt|media|private|Users|Volumes|Applications|www|data|app)(?:/[^/\s"\'\n]+(?: [^/\s"\'\n]+)*)*(?![\w-])#', ' [path]', $s); // a server path under a usual root, the root alone too, spaces in its folders allowed
    return preg_replace('#(?:^|[\s=:"\'])(?:[A-Za-z]:)?(?:[\\\\/][\w.-]+){3,}#', ' [path]', $s);                          // absolute file system paths (three or more segments, / or \), also after = : or a quote
}

/** A field name that means "secret": its value is dropped whole (the plugin's own answers never use such names). */
function devdbotp_ability_secret_key($k)
{
    return is_string($k) && (bool) preg_match('/(?:^|_)(?:token|secret|password|passwd|pass|api_?key|private_?key|auth|signature|sig|cookie|session_?id|bearer)(?:_|$)/i', $k);
}

/** The same redaction over a whole answer: every string in a nested array, and a WP_Error's message and data. */
function devdbotp_ability_redact_deep($v)
{
    if (is_string($v)) {
        return devdbotp_ability_redact($v);
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            $v[$k] = devdbotp_ability_secret_key($k) ? '[redacted]' : devdbotp_ability_redact_deep($x); // a field NAMED like a secret loses its value whatever it looks like
        }
        return $v;
    }
    if (is_object($v) && !is_wp_error($v)) {
        foreach (get_object_vars($v) as $k => $x) {
            $v->$k = devdbotp_ability_secret_key($k) ? '[redacted]' : devdbotp_ability_redact_deep($x); // a plain object (a decoded JSON row, a settings object): every field
        }
        return $v;
    }
    if (is_wp_error($v)) {
        $e = new WP_Error();
        foreach ($v->get_error_codes() as $code) {
            foreach ($v->get_error_messages($code) as $msg) {
                $e->add($code, devdbotp_ability_redact((string) $msg), devdbotp_ability_redact_deep($v->get_error_data($code)));
            }
        }
        return $e;
    }
    return $v;
}

/* ------------------------------ registration ------------------------------ */

add_action('wp_abilities_api_init', 'devdbotp_register_abilities');
function devdbotp_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $empty = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $bool  = function ($desc) { return array('type' => 'boolean', 'description' => $desc); };
    $str   = function ($desc) { return array('type' => 'string', 'description' => $desc); };
    $int   = function ($desc) { return array('type' => 'integer', 'description' => $desc); };
    $list  = function ($desc) { return array('type' => 'array', 'items' => array('type' => 'string'), 'description' => $desc); };

    $settings_props = array(
        'protection'     => array('type' => 'string', 'enum' => array('on', 'off'), 'description' => 'on = requests are checked; off = nothing is checked or blocked. Turning it off needs confirm: true.'),
        'mode'           => array('type' => 'string', 'enum' => array('watching', 'live'), 'description' => 'watching = bots are logged, nobody is blocked; live = harmful bots are blocked. Going from live to watching needs confirm: true.'),
        'anonymize_ips'  => $bool('Mask logged addresses (IPv4 to /24, IPv6 to /48).'),
    );
    $settings_out = array('type' => 'object', 'properties' => $settings_props + array('retention_days' => $int('Read only: how many days events are kept (90).'), 'outside_lookups' => $bool('Read only: whether Outside Lookups (bot list downloads and country / network lookups) is on. It is switched on the Settings tab only.')));
    $rule_out = array('type' => 'object', 'properties' => array(
        'id' => $int(''), 'enabled' => $bool(''), 'name' => $str(''), 'field' => $str(''), 'operator' => $str(''), 'pattern' => $str(''), 'action' => $str(''),
    ));
    $pause_out = array('type' => 'object', 'properties' => array('paused' => $bool(''), 'paused_until' => $int('Unix time the pause ends, 0 when not paused.')));

    // Every answer passes the guard (DESIGN.md 24: a failed query = a database error, never "done").
    $guarded = function ($cb) {
        return function ($input = array()) use ($cb) {
            devdbotp_db_guard_begin();
            try {
                $r = call_user_func($cb, $input);
                if (devdbotp_db_guard_failed()) {
                    $r = new WP_Error('devdbotp_db_error', devdbotp_db_guard_message());
                }
            } finally {
                devdbotp_db_guard_end();
            }
            return devdbotp_ability_redact_deep($r); // one sanitizer over every answer and every error, before anything leaves
        };
    };
    $reg = function ($id, $label, $desc, $in, $out, $cb, $kind, $idempotent = null) use ($guarded) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-bot-protection',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $guarded($cb),
            'permission_callback' => 'devdbotp_ability_can',
            'meta'                => devdbotp_ability_meta($kind, $idempotent),
        ));
    };

    $reg('devdome-bot-protection/get-status', __('Get Bot Protection status', 'devdome-bot-protection'),
        __('What the plugin is doing right now: protection on or off, Watch or Live mode, whether it is paused, the counters (blocked today, bots seen, humans seen), and whether the threat feeds are synced. Read-only.', 'devdome-bot-protection'),
        $empty,
        array('type' => 'object', 'properties' => array(
            'protection' => $str('on or off.'), 'mode' => $str('watching or live.'), 'paused' => $bool(''), 'paused_until' => $int('Unix time the pause ends, 0 when not paused.'),
            'blocked_today' => $int(''), 'bots_seen' => $int(''), 'humans_seen' => $int(''), 'feeds_synced' => $bool(''), 'plugin_version' => $str(''),
        )),
        'devdbotp_ability_get_status', 'read');
    $reg('devdome-bot-protection/get-settings', __('Get Bot Protection settings', 'devdome-bot-protection'),
        __('Protection on or off, the mode, the event retention in days and the Anonymize IPs option. Read-only.', 'devdome-bot-protection'),
        $empty, array('type' => 'object', 'properties' => array('settings' => $settings_out)), 'devdbotp_ability_get_settings', 'read');
    $reg('devdome-bot-protection/update-settings', __('Update Bot Protection settings', 'devdome-bot-protection'),
        __('Change settings; only the keys you pass change. Turning protection off or switching from live to watching lowers protection and needs confirm: true. Going live does not. Every value is read back before updated: true is returned; keys that could not be applied are listed in not_applied.', 'devdome-bot-protection'),
        array('type' => 'object', 'properties' => array_merge($settings_props, array('confirm' => $bool('Required (true) when the change turns protection off, switches from live to watching, or turns Anonymize IPs off.'))), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated' => $bool(''), 'not_applied' => $list(''), 'restored' => $bool('Present after a failed change: whether the earlier values are back.'), 'settings' => $settings_out)),
        'devdbotp_ability_update_settings', 'modify');
    $reg('devdome-bot-protection/list-rules', __('List custom rules', 'devdome-bot-protection'),
        __('Every custom rule, enabled or not: id, name, field, operator, pattern and action. Read-only.', 'devdome-bot-protection'),
        $empty, array('type' => 'object', 'properties' => array('rules' => array('type' => 'array', 'items' => $rule_out))), 'devdbotp_ability_list_rules', 'read');
    $reg('devdome-bot-protection/add-rule', __('Add a custom rule', 'devdome-bot-protection'),
        __('Adds one enabled custom rule through the same validation the screen uses. A block rule blocks matching requests in Live mode.', 'devdome-bot-protection'),
        array('type' => 'object', 'properties' => array(
            'field'    => array('type' => 'string', 'enum' => array('user_agent', 'path', 'ip', 'country', 'asn'), 'description' => 'What the rule matches on.'),
            'operator' => array('type' => 'string', 'enum' => array('contains', 'equals', 'starts_with', 'regex', 'cidr'), 'description' => 'user_agent and path: contains, equals, starts_with, regex. ip: equals or cidr. country and asn: equals.'),
            'pattern'  => $str('The value to match, 1 to 255 characters. country = ISO 3166-1 alpha-2 code, asn = number.'),
            'action'   => array('type' => 'string', 'enum' => array('allow', 'log', 'block'), 'description' => 'What happens on a match.'),
            'name'     => $str('Optional label for the rule.'),
            'confirm'  => $bool('Required as true when action is allow: an Allow rule lets matching requests through every check.'),
        ), 'required' => array('field', 'operator', 'pattern', 'action'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('added' => $bool(''), 'rule' => $rule_out)),
        'devdbotp_ability_add_rule', 'modify', false);
    $reg('devdome-bot-protection/delete-rule', __('Delete a custom rule', 'devdome-bot-protection'),
        __('Deletes one custom rule for good. Built-in rules cannot be deleted.', 'devdome-bot-protection'),
        array('type' => 'object', 'properties' => array('id' => $int('The id of the custom rule, from list-rules.'), 'confirm' => $bool('Must be true: the rule is deleted for good.')), 'required' => array('id', 'confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('deleted' => $bool(''), 'id' => $int(''))),
        'devdbotp_ability_delete_rule', 'destroy');
    $reg('devdome-bot-protection/list-recent-events', __('List recent events', 'devdome-bot-protection'),
        __('The newest logged bot and blocked requests: time (UTC), address as stored (masked when Anonymize IPs is on), path, user agent, bot category, action, reason, country and ASN. Read-only.', 'devdome-bot-protection'),
        array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50, 'description' => 'How many events to return.')), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('events' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
            'id' => $int(''), 'time' => $str('UTC, Y-m-d H:i:s'), 'ip' => $str('Masked address (last octet or the IPv6 tail removed).'), 'path' => $str('Request path, no query string.'),
            'user_agent' => $str(''), 'bot_category' => $str(''), 'action' => $str('log | block | allow'), 'reason' => $str(''), 'country' => $str('ISO 3166 alpha-2 or empty.'), 'asn' => $int(''),
        ))))),
        'devdbotp_ability_list_recent_events', 'read');
    $reg('devdome-bot-protection/pause-protection', __('Pause protection for 30 minutes', 'devdome-bot-protection'),
        __('Needs confirm: true. Pauses protection for 30 minutes: nothing is blocked, then protection resumes by itself.', 'devdome-bot-protection'),
        array('type' => 'object', 'properties' => array('confirm' => $bool('Must be true: all blocking stops for 30 minutes.')), 'required' => array('confirm'), 'additionalProperties' => false),
        $pause_out, 'devdbotp_ability_pause_protection', 'modify', false);
    $reg('devdome-bot-protection/resume-protection', __('Resume protection', 'devdome-bot-protection'),
        __('Ends a pause at once. Does nothing when protection is not paused.', 'devdome-bot-protection'),
        $empty, $pause_out, 'devdbotp_ability_resume_protection', 'modify');
    $reg('devdome-bot-protection/clear-statistics', __('Clear statistics', 'devdome-bot-protection'),
        __('Needs confirm: true. Deletes every logged event, the daily and hourly totals, sessions and the learned offender history on this site. Rules, Known Bots choices, custom rules and the DevDome connection stay. Cannot be undone.', 'devdome-bot-protection'),
        array('type' => 'object', 'properties' => array('confirm' => $bool('Must be true: every statistic on this site is deleted.')), 'required' => array('confirm'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('cleared' => $bool(''))), 'devdbotp_ability_clear_statistics', 'destroy', false);
}

/** MCP Adapter: list every Bot Protection ability as a tool on the default server. Harmless when the adapter is not installed. */
add_filter('mcp_adapter_default_server_config', 'devdbotp_mcp_default_server_tools');
function devdbotp_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdbotp_ability_ids())));
    return $config;
}
