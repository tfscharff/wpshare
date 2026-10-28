<?php
/**
 * Stub harness: mocks the WordPress functions the plugin uses, then runs tests/cases.php.
 * Run: php tests/run.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['wps_options'] = array();
$GLOBALS['wps_meta']    = array();  // post ID => [key => value]
$GLOBALS['wps_posts']   = array();  // ID => object
$GLOBALS['wps_users']   = array();  // ID => object
$GLOBALS['wps_mail']    = array();
$GLOBALS['wps_mail_ok'] = true;
$GLOBALS['wps_today']   = '2026-10-28';
$GLOBALS['wps_types']   = array( 'post' => 'Posts', 'page' => 'Pages', 'attachment' => 'Media', 'foyer_slide' => 'Slides' );

function add_action() {}
function add_filter() {}
function add_menu_page() {}
function add_submenu_page() {}
function register_deactivation_hook() {}
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['wps_options'] ) ? $GLOBALS['wps_options'][ $k ] : $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['wps_options'][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['wps_meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['wps_meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['wps_meta'][ $id ][ $k ] ); return true; }
function get_post( $id ) { return $GLOBALS['wps_posts'][ $id ] ?? null; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['wps_posts'] as $p ) {
		if ( $p->post_type === $args['post_type'] && in_array( $p->post_status, $args['post_status'], true ) ) { $out[] = $p; }
	}
	return $out;
}
function get_userdata( $id ) { return $GLOBALS['wps_users'][ $id ] ?? false; }
function get_users( $args ) { return array_values( array_filter( $GLOBALS['wps_users'], function ( $u ) { return $u->can; } ) ); }
function get_post_types( $args, $output ) {
	$out = array();
	foreach ( $GLOBALS['wps_types'] as $n => $l ) { $out[ $n ] = (object) array( 'label' => $l ); }
	return $out;
}
function wp_mail( $to, $subject, $body ) { $GLOBALS['wps_mail'][] = compact( 'to', 'subject', 'body' ); return $GLOBALS['wps_mail_ok']; }
function wp_date( $fmt ) { return date( $fmt, strtotime( $GLOBALS['wps_today'] . ' 09:30:00' ) ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function get_bloginfo( $k ) { return 'Wallace &amp; Library'; }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url_raw( $s, $protocols = null ) { return preg_match( '#^https?://#i', $s ) ? $s : ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return preg_replace( '/[^a-z0-9@._+\-]/i', '', (string) $s ); }
function is_email( $s ) { return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $s ); }
function wp_nonce_field( $action, $name, $ref, $echo ) { return '<input type="hidden" name="' . $name . '" value="nonce">'; }

function pg( $id, $title, $status = 'publish', $parent = 0, $type = 'page' ) {
	$GLOBALS['wps_posts'][ $id ] = (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_parent' => $parent, 'post_type' => $type );
}
function usr( $id, $name, $email, $can = true ) {
	$GLOBALS['wps_users'][ $id ] = (object) array( 'ID' => $id, 'display_name' => $name, 'user_email' => $email, 'can' => $can );
}

require dirname( __DIR__ ) . '/wpshare.php';

require __DIR__ . '/cases.php';
