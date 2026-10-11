<?php
/**
 * Request-wide database guard (DESIGN.md 24, suite standard since 2026-09-14).
 *
 * $wpdb reports a failed query only through last_error, and the NEXT query clears it. get_option() on a broken
 * table answers the default, so a failed read used to pass as "not connected", "setting off" or "saved". The
 * guard records every failed query as it happens (the 'query' filter at priority 1 runs inside wpdb::query()
 * BEFORE the flush), an ACTION opens a window (every admin-post / self-POST handler, the admin page render), inside a
 * window devdbotp_update_setting() refuses once a query failed and every boundary answers a database error instead
 * of "saved". Outside a window (the front-end engine, cron, the test suite) nothing changes.
 *
 * Reference implementation: Country Blocker 1.0.0 includes/db-guard.php, itself from the Malware Scanner.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['devdbotp_db_guard'] = array( 'errors' => array(), 'count' => 0, 'last' => '', 'mark' => 0, 'open' => 0, 'pending' => false );

/** Clear $wpdb->last_error before a read whose empty answer must be told from a failure; records first. */
function devdbotp_db_reset_error() {
	global $wpdb;
	devdbotp_db_guard_sync();
	$GLOBALS['devdbotp_db_guard']['pending'] = false;
	if ( isset( $wpdb->last_error ) ) {
		$wpdb->last_error = '';
	}
}

/** True when the query run since devdbotp_db_reset_error() failed. */
function devdbotp_db_failed() {
	global $wpdb;
	return isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error;
}

/** Record the pending $wpdb->last_error once (internal). */
function devdbotp_db_guard_sync() {
	global $wpdb;
	$g = &$GLOBALS['devdbotp_db_guard'];
	if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error && empty( $g['pending'] ) ) {
		$g['count'] = (int) $g['count'] + 1; // monotonic; the list below is diagnostic and capped
		$g['last']  = (string) $wpdb->last_error;
		if ( count( $g['errors'] ) < 50 ) {
			$g['errors'][] = (string) $wpdb->last_error;
		}
		$g['pending'] = true;
	}
}

/** 'query' filter (priority 1): the previous query's error is recorded before wpdb::query() flushes it. */
function devdbotp_db_guard_record( $query ) {
	devdbotp_db_guard_sync();
	$GLOBALS['devdbotp_db_guard']['pending'] = false;
	return $query;
}

/** Open a guard window. Nested windows share the outer list; only the outermost begin() clears it. */
function devdbotp_db_guard_begin() {
	global $wpdb;
	devdbotp_db_guard_sync();
	$g = &$GLOBALS['devdbotp_db_guard'];
	$g['open'] = (int) $g['open'] + 1;
	if ( 1 === $g['open'] ) {
		$g['errors']  = array();
		$g['count']   = 0;
		$g['last']    = '';
		$g['mark']    = 0;
		$g['pending'] = false;
		if ( isset( $wpdb->last_error ) ) {
			$wpdb->last_error = ''; // an error from before this action is not this action's
		}
	}
}

function devdbotp_db_guard_end() {
	$g = &$GLOBALS['devdbotp_db_guard'];
	$g['open'] = max( 0, (int) $g['open'] - 1 );
}

function devdbotp_db_guard_open() {
	return (int) $GLOBALS['devdbotp_db_guard']['open'] > 0;
}

/** Move the mark to now: errors before it are handled. */
function devdbotp_db_guard_rebase() {
	devdbotp_db_guard_sync();
	$GLOBALS['devdbotp_db_guard']['mark'] = (int) $GLOBALS['devdbotp_db_guard']['count'];
}

/** True when a query failed since the mark. */
function devdbotp_db_guard_failed() {
	devdbotp_db_guard_sync();
	$g = $GLOBALS['devdbotp_db_guard'];
	return (int) $g['count'] > (int) $g['mark'];
}

/** True when a window is open AND a query failed since its mark: writes and boundaries refuse on this. */
function devdbotp_db_guard_active() {
	return devdbotp_db_guard_open() && devdbotp_db_guard_failed();
}

function devdbotp_db_guard_error() {
	devdbotp_db_guard_sync();
	return (string) $GLOBALS['devdbotp_db_guard']['last'];
}

/** The one text every boundary uses. */
function devdbotp_db_guard_message() {
	$err = function_exists( 'devdbotp_redact' ) ? devdbotp_redact( devdbotp_db_guard_error() ) : devdbotp_db_guard_error(); // redact BEFORE the cut
	return 'A database query failed during this action' . ( '' !== $err ? ' (' . substr( $err, 0, 160 ) . ')' : '' ) . '. The result is not trusted and nothing more was changed: reload the page and check the current state before trying again.';
}

/** Error texts leave the site without visitor addresses, e-mail addresses, secrets or server paths (error report, guard message). */
function devdbotp_redact( $text ) {
	$text = (string) $text;
	foreach ( array( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', defined( 'ABSPATH' ) ? ABSPATH : '' ) as $root ) {
		$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
		if ( '' !== $root ) {
			$text = str_ireplace( array( $root, str_replace( '/', '\\', $root ) ), '<path>', $text );
		}
	}
	$text = preg_replace( '/(?<![\w.:])\/[A-Za-z0-9_.-]+(?:\/[^\s"\'<>,;)]*)+/', '<path>', $text ); // any other absolute path (temp files, MySQL data files)
	$text = preg_replace( '/\b[A-Za-z]:[\\\\\/][^\s"\'<>,;)]*/', '<path>', $text );
	$text = preg_replace( '/\b(authorization\b["\']?\s*[=:]\s*["\']?)(?:(?:bearer|basic)\s+)?[^\s,;)"\']+/i', '$1<redacted>', $text ); // the scheme word is not the secret
	$text = preg_replace( '/\b([A-Za-z0-9_]*(?:token|secret|key|password|pass|bearer|signature|hmac)[A-Za-z0-9_]*)\b(["\']?\s*[=:]\s*|\s+)(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s,;)"\']+)/i', '$1$2<redacted>', $text ); // plain, compound (DB_PASSWORD), quoted (escape-aware) and JSON forms
	$text = preg_replace( '/\b[A-Za-z0-9_\-]{32,}\b/', '<redacted>', $text ); // long opaque values (tokens, hashes) in a failed query
	$text = preg_replace_callback( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', function ( $m ) {
		$a = devdbotp_anonymize_ip( $m[0] );
		return '' !== $a ? $a : $m[0];
	}, (string) $text );
	$text = preg_replace_callback( '/(?<![\w:.])([0-9a-f:]{3,39})(?![\w:.\/])/i', function ( $m ) {
		if ( ! filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $m[0]; // a time like 10:00:21 is not an address
		}
		$a = devdbotp_anonymize_ip( $m[1] );
		return '' !== $a ? $a : $m[0];
	}, $text );
	return preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '<email>', $text );
}
