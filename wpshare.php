<?php
/**
 * Plugin Name:       Sitemap
 * Description:       Shared care for a site. A wp-admin sitemap of every page (drafts included) where each page gets one owner, reminder emails to owners on dates you choose, notices from other plugins (such as wpa11y) sent to a page's owner, and control over what the public XML sitemap lists.
 * Version:           1.0.0
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 5.9
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPSHARE_VERSION', '1.0.0' );
define( 'WPSHARE_OPTION', 'wpshare' );            // settings; every write goes through wpshare_sanitize()
define( 'WPSHARE_STATE', 'wpshare_state' );       // {checked: 'Y-m-d'}, the last day the reminder check ran
define( 'WPSHARE_LOG', 'wpshare_log' );           // recent emails, newest first
define( 'WPSHARE_CAP', 'edit_pages' );
define( 'WPSHARE_OWNER_META', '_wpshare_owner' );          // user ID
define( 'WPSHARE_REVIEWED_META', '_wpshare_reviewed' );    // 'Y-m-d'
define( 'WPSHARE_REVIEWER_META', '_wpshare_reviewed_by' ); // user ID
define( 'WPSHARE_LOG_MAX', 50 );
define( 'WPSHARE_MAX_DATES', 50 );

/* ------------------------------------------------------------------ *
 *  Settings
 * ------------------------------------------------------------------ */

function wpshare_defaults() {
	// Matches the theme stopgap it replaces: only pages in the XML sitemap, no users sitemap.
	return array( 'post_types' => array( 'page' ), 'users' => false, 'dates' => array(), 'message' => '', 'fallback' => '' );
}

function wpshare_settings() {
	$v = get_option( WPSHARE_OPTION, null );
	return is_array( $v ) ? wpshare_sanitize( $v ) : wpshare_defaults();
}

function wpshare_save( $input ) {
	update_option( WPSHARE_OPTION, wpshare_sanitize( $input ) );
}

function wpshare_clip( $s, $max ) {
	return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
}

// Public post types the XML sitemap could list, name => label. Core never lists attachments.
function wpshare_public_types() {
	$out = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
		if ( 'attachment' !== $name ) { $out[ $name ] = isset( $obj->label ) ? $obj->label : $name; }
	}
	return $out;
}

/**
 * Reminder dates: MM-DD repeats every year, YYYY-MM-DD happens once. 02-29 is refused (it would
 * only fire in leap years). Returns [valid dates, rejected entries]; yearly dates sort first.
 */
function wpshare_parse_dates( $input ) {
	if ( is_array( $input ) ) { $input = implode( "\n", array_filter( $input, 'is_scalar' ) ); }
	$parts = preg_split( '/[\s,;]+/', is_scalar( $input ) ? (string) $input : '', -1, PREG_SPLIT_NO_EMPTY );
	$valid = array();
	$rejected = array();
	foreach ( $parts as $p ) {
		if ( preg_match( '/^(?:(\d{4})-)?(\d{2})-(\d{2})$/', $p, $m ) && checkdate( (int) $m[2], (int) $m[3], '' === $m[1] ? 2001 : (int) $m[1] ) ) {
			$valid[ $p ] = true;
		} else {
			$rejected[] = $p;
		}
	}
	$valid = array_map( 'strval', array_keys( $valid ) );
	usort( $valid, function ( $a, $b ) { return strlen( $a ) - strlen( $b ) ?: strcmp( $a, $b ); } );
	return array( array_slice( $valid, 0, WPSHARE_MAX_DATES ), $rejected );
}

function wpshare_sanitize( $in ) {
	$in = is_array( $in ) ? $in : array();
	$public = wpshare_public_types();
	$types = array();
	foreach ( (array) ( $in['post_types'] ?? array() ) as $t ) {
		if ( is_string( $t ) && isset( $public[ $t ] ) && ! in_array( $t, $types, true ) ) { $types[] = $t; }
	}
	$message = isset( $in['message'] ) && is_scalar( $in['message'] ) ? sanitize_textarea_field( (string) $in['message'] ) : '';
	$fallback = isset( $in['fallback'] ) && is_scalar( $in['fallback'] ) ? sanitize_email( (string) $in['fallback'] ) : '';
	return array(
		'post_types' => $types,
		'users'      => ! empty( $in['users'] ) && is_scalar( $in['users'] ),
		'dates'      => wpshare_parse_dates( $in['dates'] ?? '' )[0],
		'message'    => wpshare_clip( $message, 2000 ),
		'fallback'   => is_email( $fallback ) ? $fallback : '',
	);
}

/* ------------------------------------------------------------------ *
 *  Public XML sitemap (core, /wp-sitemap.xml). It only ever lists published content.
 * ------------------------------------------------------------------ */

function wpshare_sitemap_post_types( $types ) {
	return array_intersect_key( (array) $types, array_flip( wpshare_settings()['post_types'] ) );
}
add_filter( 'wp_sitemaps_post_types', 'wpshare_sitemap_post_types' );

// The users sitemap lists staff login names, so it's off unless chosen.
function wpshare_sitemap_provider( $provider, $name ) {
	return ( 'users' === $name && ! wpshare_settings()['users'] ) ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'wpshare_sitemap_provider', 10, 2 );

/* ------------------------------------------------------------------ *
 *  Pages, owners and reviews
 * ------------------------------------------------------------------ */

// Every status except trash (and WordPress's internal auto-draft and inherit).
function wpshare_statuses() {
	return array( 'publish' => 'Published', 'future' => 'Scheduled', 'draft' => 'Draft', 'pending' => 'Pending review', 'private' => 'Private' );
}

function wpshare_pages() {
	return get_posts( array(
		'post_type'   => 'page',
		'post_status' => array_keys( wpshare_statuses() ),
		'numberposts' => -1,
		'orderby'     => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
	) );
}

/**
 * Pages in tree order, as [['post' => WP_Post, 'depth' => int]]. A page whose parent is trashed
 * or missing is listed at the top level, and so is a page caught in a parent loop.
 */
function wpshare_tree( $posts ) {
	$ids = array();
	foreach ( $posts as $p ) { $ids[ $p->ID ] = true; }
	$kids = array();
	foreach ( $posts as $p ) {
		$parent = ( isset( $ids[ $p->post_parent ] ) && (int) $p->post_parent !== (int) $p->ID ) ? (int) $p->post_parent : 0;
		$kids[ $parent ][] = $p;
	}
	$out = array();
	$seen = array();
	$walk = function ( $parent, $depth ) use ( &$walk, &$out, &$seen, $kids ) {
		foreach ( $kids[ $parent ] ?? array() as $p ) {
			if ( isset( $seen[ $p->ID ] ) ) { continue; }
			$seen[ $p->ID ] = true;
			$out[] = array( 'post' => $p, 'depth' => $depth );
			$walk( (int) $p->ID, $depth + 1 );
		}
	};
	$walk( 0, 0 );
	foreach ( $posts as $p ) {
		if ( isset( $seen[ $p->ID ] ) ) { continue; }
		$seen[ $p->ID ] = true;
		$out[] = array( 'post' => $p, 'depth' => 0 );
		$walk( (int) $p->ID, 1 );
	}
	return $out;
}

// The owner's user ID, or 0 when there's none or the user has been deleted.
function wpshare_owner( $post_id ) {
	$id = (int) get_post_meta( (int) $post_id, WPSHARE_OWNER_META, true );
	return ( $id > 0 && get_userdata( $id ) ) ? $id : 0;
}

// Anyone who can edit pages can own one.
function wpshare_owner_choices() {
	return get_users( array( 'capability' => WPSHARE_CAP, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) );
}

function wpshare_mark_reviewed( $post_id, $user_id ) {
	update_post_meta( $post_id, WPSHARE_REVIEWED_META, wp_date( 'Y-m-d' ) );
	update_post_meta( $post_id, WPSHARE_REVIEWER_META, (int) $user_id );
}

function wpshare_title( $post ) {
	$t = trim( html_entity_decode( strip_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' ) );
	return '' === $t ? '(no title)' : $t;
}

function wpshare_user_name( $user_id ) {
	$u = $user_id ? get_userdata( (int) $user_id ) : false;
	return $u ? $u->display_name : '';
}

function wpshare_edit_url( $post_id ) {
	// Not get_edit_post_link(): it returns nothing when cron runs, because no one is logged in.
	return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
}

function wpshare_sitemap_url( $filter = 'all' ) {
	return admin_url( 'admin.php?page=wpshare' . ( 'all' === $filter ? '' : '&filter=' . $filter ) );
}

/* ------------------------------------------------------------------ *
 *  Email: reminders, notices and the log
 * ------------------------------------------------------------------ */

function wpshare_site_name() {
	return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

function wpshare_log( $kind, $source, $post_id, $to, $subject, $ok ) {
	$log = get_option( WPSHARE_LOG, array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array(
		'at'      => wp_date( 'Y-m-d H:i' ),
		'kind'    => $kind,
		'source'  => $source,
		'post'    => (int) $post_id,
		'to'      => $to,
		'subject' => $subject,
		'ok'      => (bool) $ok,
	) );
	update_option( WPSHARE_LOG, array_slice( $log, 0, WPSHARE_LOG_MAX ), false );
}

function wpshare_reminder_body( $posts, $message, $owned ) {
	$lines = array();
	$lines[] = '' !== $message ? $message : 'Please check that these pages are still accurate and up to date.';
	$lines[] = '';
	$lines[] = ( $owned ? 'Your pages' : 'Pages without an owner' ) . ' (' . count( $posts ) . '):';
	$statuses = wpshare_statuses();
	foreach ( $posts as $p ) {
		$reviewed = (string) get_post_meta( $p->ID, WPSHARE_REVIEWED_META, true );
		$lines[] = '';
		$lines[] = '- ' . wpshare_title( $p ) . ' (' . ( $statuses[ $p->post_status ] ?? $p->post_status ) . ', ' . ( '' !== $reviewed ? 'last reviewed ' . $reviewed : 'never reviewed' ) . ')';
		$lines[] = '  ' . wpshare_edit_url( $p->ID );
	}
	$lines[] = '';
	$lines[] = "When you've checked a page, mark it reviewed on the Sitemap screen:";
	$lines[] = wpshare_sitemap_url( $owned ? 'mine' : 'none' );
	return implode( "\n", $lines );
}

// One email per owner listing their pages; pages without an owner go to the fallback address.
function wpshare_send_reminders() {
	$s = wpshare_settings();
	$groups = array();
	foreach ( wpshare_tree( wpshare_pages() ) as $row ) {
		$groups[ wpshare_owner( $row['post']->ID ) ][] = $row['post'];
	}
	$sent = 0;
	foreach ( $groups as $owner => $posts ) {
		$user = $owner ? get_userdata( $owner ) : false;
		$to = $user ? $user->user_email : $s['fallback'];
		if ( '' === (string) $to ) { continue; }
		$subject = '[' . wpshare_site_name() . '] ' . ( $owner ? 'Please check your pages' : 'Pages without an owner need checking' );
		$ok = wp_mail( $to, $subject, wpshare_reminder_body( $posts, $s['message'], (bool) $owner ) );
		wpshare_log( 'reminder', 'wpshare', 0, $to, $subject, $ok );
		if ( $ok ) { $sent++; }
	}
	return $sent;
}

// True when a reminder date falls after $after and on or before $through (both 'Y-m-d').
function wpshare_date_due( $dates, $after, $through ) {
	foreach ( $dates as $d ) {
		$candidates = array();
		if ( 10 === strlen( $d ) ) {
			$candidates[] = $d;
		} else {
			for ( $y = (int) substr( $after, 0, 4 ); $y <= (int) substr( $through, 0, 4 ); $y++ ) { $candidates[] = sprintf( '%04d-%s', $y, $d ); }
		}
		foreach ( $candidates as $c ) {
			if ( $c > $after && $c <= $through ) { return true; }
		}
	}
	return false;
}

// The next $n reminder days on or after $today.
function wpshare_upcoming( $dates, $today, $n = 5 ) {
	$out = array();
	$year = (int) substr( $today, 0, 4 );
	foreach ( $dates as $d ) {
		if ( 10 === strlen( $d ) ) {
			if ( $d >= $today ) { $out[] = $d; }
		} else {
			$c = sprintf( '%04d-%s', $year, $d );
			$out[] = $c >= $today ? $c : sprintf( '%04d-%s', $year + 1, $d );
		}
	}
	$out = array_values( array_unique( $out ) );
	sort( $out );
	return array_slice( $out, 0, $n );
}

/**
 * Runs hourly from WP-Cron. Sends reminders once on a reminder date, and catches up if cron
 * didn't run that day (WP-Cron only runs when the site gets visits).
 */
function wpshare_check( $today = null ) {
	$today = is_string( $today ) && '' !== $today ? $today : wp_date( 'Y-m-d' );
	$state = get_option( WPSHARE_STATE );
	$last = is_array( $state ) && isset( $state['checked'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $state['checked'] )
		? $state['checked'] : gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
	if ( $last >= $today ) { return 0; }
	// Record the day first, so a slow send isn't repeated by the next run.
	update_option( WPSHARE_STATE, array( 'checked' => $today ), false );
	return wpshare_date_due( wpshare_settings()['dates'], $last, $today ) ? wpshare_send_reminders() : 0;
}
add_action( 'wpshare_check', 'wpshare_check' );

// "Upload → Replace current" skips activation hooks, so schedule on init.
function wpshare_schedule() {
	if ( ! wp_next_scheduled( 'wpshare_check' ) ) { wp_schedule_event( time(), 'hourly', 'wpshare_check' ); }
}
add_action( 'init', 'wpshare_schedule' );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'wpshare_check' ); } );

/**
 * The surface for other plugins (such as wpa11y): email a page's owner about that page.
 *
 *   do_action( 'wpshare_notify', $post_id, array(
 *       'source'  => 'wpa11y',                       // short slug, shown in the log
 *       'subject' => 'Accessibility issues found',   // plain text
 *       'message' => "Plain-text body.\nNew lines kept.",
 *       'url'     => 'https://…',                    // optional link to the details
 *   ) );
 *
 * Call it through do_action() so nothing breaks while this plugin is inactive. A page with no
 * owner (or any non-page post) goes to the fallback address in Settings, or nowhere if that's
 * empty. The caller decides when a notice is worth sending; this sends every one it gets.
 * Returns true when the email was handed to wp_mail() successfully.
 */
function wpshare_notify( $post_id, $args = array() ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || ! isset( wpshare_statuses()[ $post->post_status ] ) ) { return false; }
	$args = is_array( $args ) ? $args : array();
	$str = function ( $k ) use ( $args ) { return isset( $args[ $k ] ) && is_scalar( $args[ $k ] ) ? (string) $args[ $k ] : ''; };
	$title = wpshare_title( $post );
	$source = sanitize_key( $str( 'source' ) );
	$source = '' !== $source ? $source : 'notice';
	$subject = wpshare_clip( sanitize_text_field( $str( 'subject' ) ), 200 );
	$subject = '[' . wpshare_site_name() . '] ' . ( '' !== $subject ? $subject : 'A notice about "' . $title . '"' );
	$url = esc_url_raw( $str( 'url' ), array( 'http', 'https' ) );
	$owner = 'page' === $post->post_type ? wpshare_owner( $post->ID ) : 0;
	$user = $owner ? get_userdata( $owner ) : false;
	$to = $user ? $user->user_email : wpshare_settings()['fallback'];
	if ( '' === (string) $to ) {
		wpshare_log( 'notice', $source, $post->ID, '', $subject, false );
		return false;
	}
	$body = array( 'Page: ' . $title, wpshare_edit_url( $post->ID ), '' );
	$message = wpshare_clip( sanitize_textarea_field( $str( 'message' ) ), 5000 );
	if ( '' !== $message ) { $body[] = $message; $body[] = ''; }
	if ( '' !== $url ) { $body[] = 'Details: ' . $url; $body[] = ''; }
	$body[] = '--';
	$body[] = $user
		? 'You get this because you own this page on the Sitemap screen: ' . wpshare_sitemap_url( 'mine' )
		: 'This page has no owner, so this went to the Sitemap fallback address: ' . wpshare_sitemap_url( 'none' );
	$ok = (bool) wp_mail( $to, $subject, implode( "\n", $body ) );
	wpshare_log( 'notice', $source, $post->ID, $to, $subject, $ok );
	return $ok;
}
add_action( 'wpshare_notify', 'wpshare_notify', 10, 2 );

/* ------------------------------------------------------------------ *
 *  Admin: Sitemap → All pages, Sitemap → Settings
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page( 'Sitemap', 'Sitemap', WPSHARE_CAP, 'wpshare', 'wpshare_page_sitemap', 'dashicons-networking', 21 );
	add_submenu_page( 'wpshare', 'Sitemap', 'All pages', WPSHARE_CAP, 'wpshare', 'wpshare_page_sitemap' );
	add_submenu_page( 'wpshare', 'Sitemap settings', 'Settings', WPSHARE_CAP, 'wpshare-settings', 'wpshare_page_settings' );
} );
add_action( 'admin_post_wpshare_sitemap', 'wpshare_handle_sitemap' );
add_action( 'admin_post_wpshare_settings', 'wpshare_handle_settings' );
add_action( 'admin_post_wpshare_send', 'wpshare_handle_send' );

function wpshare_filter( $f ) {
	return in_array( $f, array( 'mine', 'none' ), true ) ? $f : 'all';
}

function wpshare_deny() {
	wp_die( 'Sorry, you are not allowed to do that.', 403 );
}

/**
 * Applies the Sitemap form: owner changes for the pages posted, then "Mark reviewed" if a page's
 * button was pressed (owner changes are saved either way, so none are lost).
 * Returns [message code, owners changed].
 */
function wpshare_apply_sitemap_post( $in, $user_id ) {
	$pages = array();
	foreach ( wpshare_pages() as $p ) { $pages[ (int) $p->ID ] = true; }
	$valid = array();
	foreach ( wpshare_owner_choices() as $u ) { $valid[ (int) $u->ID ] = true; }
	$changed = 0;
	$owners = isset( $in['owner'] ) && is_array( $in['owner'] ) ? $in['owner'] : array();
	foreach ( $owners as $pid => $uid ) {
		$pid = (int) $pid;
		$uid = is_scalar( $uid ) && preg_match( '/^\d+$/', (string) $uid ) ? (int) $uid : -1;
		if ( ! isset( $pages[ $pid ] ) || ( 0 !== $uid && ! isset( $valid[ $uid ] ) ) || wpshare_owner( $pid ) === $uid ) { continue; }
		if ( 0 === $uid ) { delete_post_meta( $pid, WPSHARE_OWNER_META ); } else { update_post_meta( $pid, WPSHARE_OWNER_META, $uid ); }
		$changed++;
	}
	$reviewed = isset( $in['reviewed'] ) && is_scalar( $in['reviewed'] ) ? (int) $in['reviewed'] : 0;
	if ( $reviewed && isset( $pages[ $reviewed ] ) ) {
		wpshare_mark_reviewed( $reviewed, $user_id );
		return array( 'reviewed', $changed );
	}
	return array( 'saved', $changed );
}

function wpshare_handle_sitemap() {
	if ( ! current_user_can( WPSHARE_CAP ) ) { wpshare_deny(); }
	check_admin_referer( 'wpshare_sitemap' );
	$in = wp_unslash( $_POST );
	list( $msg, $n ) = wpshare_apply_sitemap_post( $in, get_current_user_id() );
	$args = array( 'page' => 'wpshare', 'filter' => wpshare_filter( $in['filter'] ?? '' ), 'msg' => $msg, 'n' => $n );
	$fragment = '';
	if ( 'reviewed' === $msg ) {
		$args['rid'] = (int) $in['reviewed'];
		$fragment = '#wpshare-page-' . $args['rid'];
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . $fragment );
	exit;
}

function wpshare_handle_settings() {
	if ( ! current_user_can( WPSHARE_CAP ) ) { wpshare_deny(); }
	check_admin_referer( 'wpshare_settings' );
	$in = wp_unslash( $_POST );
	wpshare_save( $in );
	$rejected = wpshare_parse_dates( $in['dates'] ?? '' )[1];
	$args = array( 'page' => 'wpshare-settings', 'msg' => 'saved' );
	if ( $rejected ) { $args['rejected'] = rawurlencode( wpshare_clip( implode( ', ', array_slice( $rejected, 0, 10 ) ), 300 ) ); }
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}

function wpshare_handle_send() {
	if ( ! current_user_can( WPSHARE_CAP ) ) { wpshare_deny(); }
	check_admin_referer( 'wpshare_send' );
	$n = wpshare_send_reminders();
	wp_safe_redirect( add_query_arg( array( 'page' => 'wpshare-settings', 'msg' => 'sent', 'n' => $n ), admin_url( 'admin.php' ) ) );
	exit;
}

function wpshare_notice( $text, $type = 'success' ) {
	return '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $text ) . '</p></div>';
}

function wpshare_page_sitemap() {
	if ( ! current_user_can( WPSHARE_CAP ) ) { wpshare_deny(); }
	$get = wp_unslash( $_GET );
	echo wpshare_render_sitemap(
		wpshare_tree( wpshare_pages() ),
		wpshare_owner_choices(),
		wpshare_filter( $get['filter'] ?? '' ),
		get_current_user_id(),
		isset( $get['msg'] ) && is_string( $get['msg'] ) ? $get['msg'] : '',
		(int) ( $get['n'] ?? 0 ),
		(int) ( $get['rid'] ?? 0 )
	); // phpcs:ignore -- escaped inside
}

function wpshare_render_sitemap( $rows, $choices, $filter, $me, $msg = '', $n = 0, $rid = 0 ) {
	$counts = array( 'all' => count( $rows ), 'mine' => 0, 'none' => 0 );
	$owners = array();
	foreach ( $rows as $row ) {
		$o = wpshare_owner( $row['post']->ID );
		$owners[ $row['post']->ID ] = $o;
		if ( $o && $o === (int) $me ) { $counts['mine']++; }
		if ( ! $o ) { $counts['none']++; }
	}
	$h = '<div class="wrap"><h1>Sitemap</h1>';
	if ( 'saved' === $msg ) {
		$h .= wpshare_notice( 1 === $n ? 'Saved. 1 owner changed.' : 'Saved. ' . $n . ' owners changed.' );
	} elseif ( 'reviewed' === $msg ) {
		$p = get_post( $rid );
		$h .= wpshare_notice( 'Marked "' . ( $p ? wpshare_title( $p ) : 'the page' ) . '" reviewed today.' . ( $n ? ' Owner changes saved too.' : '' ) );
	}
	$h .= '<p>Every page on the site except those in the trash, drafts included. Each page has one owner, who gets the reminder emails and any notices about it. The public XML sitemap lists only published pages: see <a href="' . esc_url( admin_url( 'admin.php?page=wpshare-settings' ) ) . '">Settings</a>.</p>';

	$labels = array( 'all' => 'All', 'mine' => 'My pages', 'none' => 'No owner' );
	$links = array();
	foreach ( $labels as $k => $label ) {
		$current = $k === $filter ? ' class="current" aria-current="page"' : '';
		$links[] = '<li><a href="' . esc_url( wpshare_sitemap_url( $k ) ) . '"' . $current . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $k ] . ')</span></a></li>';
	}
	$h .= '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul><div class="clear"></div>';

	$shown = array();
	foreach ( $rows as $row ) {
		$o = $owners[ $row['post']->ID ];
		if ( 'mine' === $filter && ( ! $o || $o !== (int) $me ) ) { continue; }
		if ( 'none' === $filter && $o ) { continue; }
		$shown[] = $row;
	}
	if ( ! $shown ) {
		$empty = array( 'all' => 'There are no pages yet.', 'mine' => "You don't own any pages yet.", 'none' => 'Every page has an owner.' );
		return $h . '<p>' . esc_html( $empty[ $filter ] ) . '</p></div>';
	}

	$statuses = wpshare_statuses();
	$save = '<p><button type="submit" class="button button-primary" name="save" value="1">Save owners</button></p>';
	$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="wpshare_sitemap">'
		. '<input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">'
		. wp_nonce_field( 'wpshare_sitemap', '_wpnonce', true, false )
		. $save
		. '<table class="widefat striped"><caption class="screen-reader-text">Pages</caption>'
		. '<thead><tr><th scope="col">Page</th><th scope="col">Status</th><th scope="col">Owner</th><th scope="col">Last reviewed</th></tr></thead><tbody>';
	foreach ( $shown as $row ) {
		$p = $row['post'];
		$id = (int) $p->ID;
		$title = wpshare_title( $p );
		$depth = (int) $row['depth'];
		// The dashes show depth as the Pages screen does; screen readers hear "Level N" instead.
		$indent = $depth ? '<span aria-hidden="true">' . str_repeat( '&#8212; ', $depth ) . '</span><span class="screen-reader-text">Level ' . ( $depth + 1 ) . ': </span>' : '';
		$select = '<label class="screen-reader-text" for="wpshare-owner-' . $id . '">Owner of ' . esc_html( $title ) . '</label>'
			. '<select name="owner[' . $id . ']" id="wpshare-owner-' . $id . '"><option value="0">No owner</option>';
		foreach ( $choices as $u ) {
			$select .= '<option value="' . (int) $u->ID . '"' . ( (int) $u->ID === $owners[ $id ] ? ' selected' : '' ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		$select .= '</select>';
		$reviewed = (string) get_post_meta( $id, WPSHARE_REVIEWED_META, true );
		$by = wpshare_user_name( get_post_meta( $id, WPSHARE_REVIEWER_META, true ) );
		$review = '' !== $reviewed ? esc_html( $reviewed . ( '' !== $by ? ' by ' . $by : '' ) ) : 'Never';
		$review .= ' <button type="submit" class="button button-small" name="reviewed" value="' . $id . '">Mark reviewed<span class="screen-reader-text">: ' . esc_html( $title ) . '</span></button>';
		$h .= '<tr id="wpshare-page-' . $id . '"><th scope="row">' . $indent . '<a href="' . esc_url( wpshare_edit_url( $id ) ) . '">' . esc_html( $title ) . '</a></th>'
			. '<td>' . esc_html( $statuses[ $p->post_status ] ?? $p->post_status ) . '</td>'
			. '<td>' . $select . '</td><td>' . $review . '</td></tr>';
	}
	return $h . '</tbody></table>' . $save . '</form></div>';
}

function wpshare_date_label( $d ) {
	$parts = array_map( 'intval', explode( '-', $d ) );
	if ( 2 === count( $parts ) ) { return gmdate( 'F j', gmmktime( 0, 0, 0, $parts[0], $parts[1], 2001 ) ) . ', every year'; }
	return gmdate( 'F j, Y', gmmktime( 0, 0, 0, $parts[1], $parts[2], $parts[0] ) ) . ', once';
}

function wpshare_page_settings() {
	if ( ! current_user_can( WPSHARE_CAP ) ) { wpshare_deny(); }
	$get = wp_unslash( $_GET );
	$log = get_option( WPSHARE_LOG, array() );
	echo wpshare_render_settings(
		wpshare_settings(),
		wpshare_public_types(),
		is_array( $log ) ? $log : array(),
		wp_date( 'Y-m-d' ),
		isset( $get['msg'] ) && is_string( $get['msg'] ) ? $get['msg'] : '',
		(int) ( $get['n'] ?? 0 ),
		isset( $get['rejected'] ) && is_string( $get['rejected'] ) ? rawurldecode( $get['rejected'] ) : ''
	); // phpcs:ignore -- escaped inside
}

function wpshare_render_settings( $s, $types, $log, $today, $msg = '', $n = 0, $rejected = '' ) {
	$h = '<div class="wrap"><h1>Sitemap settings</h1>';
	if ( 'saved' === $msg ) { $h .= wpshare_notice( 'Settings saved.' ); }
	if ( 'sent' === $msg ) { $h .= wpshare_notice( 1 === $n ? 'Sent 1 reminder email.' : 'Sent ' . $n . ' reminder emails.' ); }
	if ( '' !== $rejected ) { $h .= wpshare_notice( "These aren't dates in the form MM-DD or YYYY-MM-DD, so they were left out: " . $rejected, 'error' ); }

	$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="wpshare_settings">'
		. wp_nonce_field( 'wpshare_settings', '_wpnonce', true, false );

	$h .= '<h2>Public XML sitemap</h2><p>What search engines read at <a href="' . esc_url( home_url( '/wp-sitemap.xml' ) ) . '">/wp-sitemap.xml</a>. It only ever lists published content: drafts, pending, scheduled and private pages are never in it.</p>'
		. '<fieldset><legend><strong>List these kinds of content</strong></legend>';
	foreach ( $types as $name => $label ) {
		$h .= '<p><label><input type="checkbox" name="post_types[]" value="' . esc_attr( $name ) . '"' . ( in_array( $name, $s['post_types'], true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . ' <code>' . esc_html( $name ) . '</code></label></p>';
	}
	$h .= '</fieldset><p><label><input type="checkbox" name="users" value="1"' . ( $s['users'] ? ' checked' : '' ) . '> List users. This shows staff login names, so leave it off.</label></p>';

	$h .= '<h2>Reminders</h2><p>On each date below, every page owner gets one email listing their pages and asking them to check them. Pages without an owner go to the fallback address under Notices.</p>'
		. '<p><label for="wpshare-dates"><strong>Reminder dates</strong></label><br>'
		. '<textarea id="wpshare-dates" name="dates" rows="5" cols="30" aria-describedby="wpshare-dates-hint">' . esc_html( implode( "\n", $s['dates'] ) ) . '</textarea></p>'
		. '<p id="wpshare-dates-hint">One date per line. MM-DD repeats every year (01-15 is January 15). YYYY-MM-DD is sent once. For the end of February use 02-28, not 02-29.</p>';
	$next = wpshare_upcoming( $s['dates'], $today );
	if ( $next ) {
		$h .= '<p>Coming up:</p><ul class="ul-disc">';
		foreach ( $next as $d ) { $h .= '<li>' . esc_html( gmdate( 'l, F j, Y', strtotime( $d . ' 00:00:00 UTC' ) ) ) . '</li>'; }
		$h .= '</ul>';
	} else {
		$h .= '<p>No reminders are scheduled.</p>';
	}
	$h .= '<p><label for="wpshare-message"><strong>Message at the top of the email</strong></label><br>'
		. '<textarea id="wpshare-message" name="message" rows="4" cols="60" aria-describedby="wpshare-message-hint">' . esc_html( $s['message'] ) . '</textarea></p>'
		. '<p id="wpshare-message-hint">Plain text. Leave it empty to use: "Please check that these pages are still accurate and up to date."</p>';

	$h .= '<h2>Notices</h2><p>Other plugins, such as wpa11y, can send a notice about a page. It goes to the page\'s owner.</p>'
		. '<p><label for="wpshare-fallback"><strong>Fallback address</strong></label><br>'
		. '<input type="email" id="wpshare-fallback" name="fallback" class="regular-text" autocomplete="email" value="' . esc_attr( $s['fallback'] ) . '" aria-describedby="wpshare-fallback-hint"></p>'
		. '<p id="wpshare-fallback-hint">Reminders and notices for pages without an owner go here. Leave it empty to send nothing for those pages.</p>'
		. '<p><button type="submit" class="button button-primary">Save settings</button></p></form>';

	$h .= '<h2>Send reminders now</h2><p>Sends the reminder emails now, whatever the dates. Useful for a test.</p>'
		. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpshare_send">'
		. wp_nonce_field( 'wpshare_send', '_wpnonce', true, false )
		. '<p><button type="submit" class="button">Send reminders now</button></p></form>';

	$h .= '<h2>Recent emails</h2>';
	if ( ! $log ) { return $h . '<p>No emails sent yet.</p></div>'; }
	$h .= '<table class="widefat striped"><caption class="screen-reader-text">The last ' . WPSHARE_LOG_MAX . ' emails, newest first</caption>'
		. '<thead><tr><th scope="col">When</th><th scope="col">From</th><th scope="col">Page</th><th scope="col">To</th><th scope="col">Subject</th><th scope="col">Result</th></tr></thead><tbody>';
	foreach ( $log as $e ) {
		if ( ! is_array( $e ) ) { continue; }
		$p = ! empty( $e['post'] ) ? get_post( (int) $e['post'] ) : null;
		$page = $p ? '<a href="' . esc_url( wpshare_edit_url( $p->ID ) ) . '">' . esc_html( wpshare_title( $p ) ) . '</a>' : ( 'reminder' === ( $e['kind'] ?? '' ) ? 'Reminder' : '' );
		$result = ! empty( $e['ok'] ) ? 'Sent' : ( '' === (string) ( $e['to'] ?? '' ) ? 'Not sent: no owner or fallback address' : 'Failed' );
		$h .= '<tr><td>' . esc_html( $e['at'] ?? '' ) . '</td><td>' . esc_html( $e['source'] ?? '' ) . '</td><td>' . $page . '</td><td>' . esc_html( $e['to'] ?? '' ) . '</td><td>' . esc_html( $e['subject'] ?? '' ) . '</td><td>' . esc_html( $result ) . '</td></tr>';
	}
	return $h . '</tbody></table></div>';
}
