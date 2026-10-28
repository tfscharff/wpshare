<?php
/** Test cases. Run through tests/run.php, which loads the WordPress stubs and the plugin. */

$fails = 0; $passes = 0;
function ok( $cond, $msg ) {
	global $fails, $passes;
	if ( $cond ) { $passes++; } else { $fails++; echo "FAIL: $msg\n"; }
}

/* ---------------- settings ---------------- */

ok( wpshare_defaults() === wpshare_settings(), 'missing option reads as defaults' );
ok( array( 'page' ) === wpshare_settings()['post_types'] && false === wpshare_settings()['users'], 'defaults match the theme stopgap' );

$s = wpshare_sanitize( 'junk' );
ok( array() === $s['post_types'] && false === $s['users'] && array() === $s['dates'] && '' === $s['message'] && '' === $s['fallback'], 'junk input gives empty settings' );
ok( array( 'post_types', 'users', 'dates', 'message', 'fallback' ) === array_keys( $s ), 'settings keys are fixed' );

$s = wpshare_sanitize( array(
	'post_types' => array( 'page', 'page', 'attachment', 'nope', array( 'x' ), 'foyer_slide' ),
	'users'      => '1',
	'message'    => "<b>Hi</b>\nCheck please",
	'fallback'   => 'web@example.edu',
	'extra'      => 'dropped',
) );
ok( array( 'page', 'foyer_slide' ) === $s['post_types'], 'post types: only public, no attachments, no duplicates' );
ok( true === $s['users'], 'users checkbox' );
ok( "Hi\nCheck please" === $s['message'], 'message keeps new lines, loses tags' );
ok( 'web@example.edu' === $s['fallback'], 'valid fallback kept' );
ok( ! isset( $s['extra'] ), 'unknown keys dropped' );
ok( '' === wpshare_sanitize( array( 'fallback' => 'not an email' ) )['fallback'], 'bad fallback dropped' );
ok( false === wpshare_sanitize( array( 'users' => array( 1 ) ) )['users'] && false === wpshare_sanitize( array( 'users' => '0' ) )['users'], 'users junk is off' );
ok( 'page' === wpshare_sanitize( array( 'post_types' => 'page' ) )['post_types'][0], 'single post type string accepted' );
ok( 2000 === strlen( wpshare_sanitize( array( 'message' => str_repeat( 'a', 3000 ) ) )['message'] ), 'message capped' );

/* ---------------- dates ---------------- */

list( $v, $r ) = wpshare_parse_dates( "2027-03-01\n01-15, 08-01;01-15 02-29 13-01 2026-02-30 hello 2028-02-29 <b> 1-5" );
ok( array( '01-15', '08-01', '2027-03-01', '2028-02-29' ) === $v, 'dates: yearly first, sorted, unique; leap day ok with a year' );
ok( array( '02-29', '13-01', '2026-02-30', 'hello', '<b>', '1-5' ) === $r, 'bad dates reported' );
ok( array() === wpshare_parse_dates( null )[0] && array() === wpshare_parse_dates( array( array( 'x' ) ) )[0], 'junk dates give none' );
ok( array( '01-15' ) === wpshare_parse_dates( array( '01-15', 7 ) )[0], 'array of dates accepted' );
$many = array();
for ( $i = 1; $i <= 28; $i++ ) { $many[] = sprintf( '01-%02d', $i ); $many[] = sprintf( '02-%02d', $i ); }
ok( WPSHARE_MAX_DATES === count( wpshare_parse_dates( $many )[0] ), 'date count capped' );

ok( wpshare_date_due( array( '10-28' ), '2026-10-27', '2026-10-28' ), 'yearly date due on the day' );
ok( ! wpshare_date_due( array( '10-28' ), '2026-10-28', '2026-10-29' ), 'not due again the next day' );
ok( wpshare_date_due( array( '01-02' ), '2026-12-30', '2027-01-05' ), 'catch-up across new year' );
ok( wpshare_date_due( array( '2026-11-03' ), '2026-11-01', '2026-11-04' ) && ! wpshare_date_due( array( '2026-11-03' ), '2026-11-03', '2027-11-04' ), 'one-off date due once' );
ok( ! wpshare_date_due( array(), '2026-01-01', '2026-12-31' ), 'no dates, never due' );

ok( array( '2026-10-28', '2026-11-03', '2027-01-15' ) === wpshare_upcoming( array( '01-15', '10-28', '2026-11-03', '2025-01-01' ), '2026-10-28' ), 'upcoming: today counts, past one-offs dropped, yearly rolls over' );
ok( 'January 15, every year' === wpshare_date_label( '01-15' ) && 'November 3, 2026, once' === wpshare_date_label( '2026-11-03' ), 'date labels' );

/* ---------------- public XML sitemap ---------------- */

$GLOBALS['wps_options'] = array();
$all = array( 'page' => 1, 'post' => 2, 'foyer_slide' => 3 );
ok( array( 'page' => 1 ) === wpshare_sitemap_post_types( $all ), 'default: only pages in the XML sitemap' );
ok( false === wpshare_sitemap_provider( 'P', 'users' ) && 'P' === wpshare_sitemap_provider( 'P', 'posts' ), 'default: no users sitemap; others untouched' );
wpshare_save( array( 'post_types' => array( 'post' ), 'users' => 1 ) );
ok( array( 'post' => 2 ) === wpshare_sitemap_post_types( $all ) && 'P' === wpshare_sitemap_provider( 'P', 'users' ), 'settings change the XML sitemap' );
wpshare_save( array() );
ok( array() === wpshare_sitemap_post_types( $all ), 'nothing chosen: no post types' );
$GLOBALS['wps_options'][ WPSHARE_OPTION ] = array( 'post_types' => array( 'gone_type' ), 'users' => 'x' );
ok( array() === wpshare_sitemap_post_types( $all ), 'stored junk is sanitized on read' );

/* ---------------- tree ---------------- */

$GLOBALS['wps_options'] = array();
pg( 1, 'Home' );
pg( 2, 'About', 'publish' );
pg( 3, 'Hours', 'draft', 2 );
pg( 4, 'Staff', 'private', 2 );
pg( 5, 'Old', 'trash' );
pg( 6, 'Orphan', 'pending', 5 );   // parent is trashed
pg( 7, 'Loop A', 'publish', 8 );
pg( 8, 'Loop B', 'publish', 7 );
pg( 9, 'Self', 'future', 9 );
pg( 10, 'A post', 'publish', 0, 'post' );
pg( 11, 'Deep', 'publish', 3 );
pg( 12, '', 'draft' );
pg( 13, 'Auto', 'auto-draft' );
$tree = wpshare_tree( wpshare_pages() );
$flat = array_map( function ( $r ) { return $r['post']->ID . ':' . $r['depth']; }, $tree );
ok( array( '1:0', '2:0', '3:1', '11:2', '4:1', '6:0', '9:0', '12:0', '7:0', '8:1' ) === $flat, 'tree order and depth; trash, auto-draft and posts left out; orphans, self-parents and loops at top' );
ok( '(no title)' === wpshare_title( $GLOBALS['wps_posts'][12] ), 'untitled page' );
ok( 'R&D "x"' === wpshare_title( (object) array( 'post_title' => '<i>R&amp;D</i> &quot;x&quot;' ) ), 'title: tags and entities removed for plain text' );

/* ---------------- owners and the Sitemap form ---------------- */

usr( 20, 'Ana', 'ana@example.edu' );
usr( 21, 'Ben', 'ben@example.edu' );
usr( 22, 'Sub', 'sub@example.edu', false );   // can't edit pages
list( $msg, $n ) = wpshare_apply_sitemap_post( array( 'owner' => array( 1 => '20', 3 => '21', 5 => '20', 10 => '20', 4 => '22', 2 => '99', 6 => 'x', 7 => array( 1 ), 'bad' => '20', 8 => '-3' ) ), 20 );
ok( 'saved' === $msg && 2 === $n, 'two owners set' );
ok( 20 === wpshare_owner( 1 ) && 21 === wpshare_owner( 3 ), 'owners stored' );
ok( 0 === wpshare_owner( 5 ) && 0 === wpshare_owner( 10 ), 'trashed page and posts not assignable here' );
ok( 0 === wpshare_owner( 4 ) && 0 === wpshare_owner( 2 ), 'only users who can edit pages, and real users' );
ok( 0 === wpshare_owner( 6 ) && 0 === wpshare_owner( 7 ) && 0 === wpshare_owner( 8 ), 'junk owner values ignored' );
list( , $n ) = wpshare_apply_sitemap_post( array( 'owner' => array( 1 => '20', 3 => '0' ) ), 20 );
ok( 1 === $n && 0 === wpshare_owner( 3 ) && ! isset( $GLOBALS['wps_meta'][3][ WPSHARE_OWNER_META ] ), 'unchanged skipped; 0 removes the owner' );
wpshare_apply_sitemap_post( array( 'owner' => array( 3 => '21' ) ), 20 );
$GLOBALS['wps_meta'][9][ WPSHARE_OWNER_META ] = 77;
ok( 0 === wpshare_owner( 9 ), 'deleted user is no owner' );

list( $msg, $n ) = wpshare_apply_sitemap_post( array( 'owner' => array( 11 => '21' ), 'reviewed' => '3' ), 20 );
ok( 'reviewed' === $msg && 1 === $n && 21 === wpshare_owner( 11 ), 'Mark reviewed also saves owner changes' );
ok( '2026-10-28' === $GLOBALS['wps_meta'][3][ WPSHARE_REVIEWED_META ] && 20 === $GLOBALS['wps_meta'][3][ WPSHARE_REVIEWER_META ], 'review date and reviewer stored' );
ok( 'saved' === wpshare_apply_sitemap_post( array( 'reviewed' => '5' ), 20 )[0] && ! isset( $GLOBALS['wps_meta'][5][ WPSHARE_REVIEWED_META ] ), 'cannot review a trashed page' );
ok( 'saved' === wpshare_apply_sitemap_post( 'junk', 20 )[0] && 'saved' === wpshare_apply_sitemap_post( array( 'owner' => 'x', 'reviewed' => array() ), 20 )[0], 'junk form' );

/* ---------------- Sitemap screen ---------------- */

pg( 14, '<script>alert(1)</script>Evil & "quoted"', 'draft' );
$tree = wpshare_tree( wpshare_pages() );
$h = wpshare_render_sitemap( $tree, wpshare_owner_choices(), 'all', 20 );
ok( false === strpos( $h, '<script>' ), 'titles escaped' );
ok( false !== strpos( $h, 'Evil &amp; &quot;quoted&quot;' ), 'title text shown escaped' );
ok( false !== strpos( $h, 'All <span class="count">(11)</span>' ) && false !== strpos( $h, 'My pages <span class="count">(1)</span>' ) && false !== strpos( $h, 'No owner <span class="count">(8)</span>' ), 'filter counts' );
ok( 1 === substr_count( $h, 'aria-current="page"' ), 'one current filter' );
ok( false !== strpos( $h, '<option value="20" selected>Ana</option>' ) && false === strpos( $h, '>Sub<' ), 'owner select shows current owner, only page editors' );
ok( false !== strpos( $h, '<label class="screen-reader-text" for="wpshare-owner-1">Owner of Home</label>' ), 'each select has a label' );
ok( false !== strpos( $h, 'Level 3: </span><a href="https://example.test/wp-admin/post.php?post=11&amp;action=edit">Deep</a>' ), 'depth shown and announced; title links to the editor' );
ok( false !== strpos( $h, '2026-10-28 by Ana' ) && false !== strpos( $h, 'Mark reviewed<span class="screen-reader-text">: Hours</span>' ), 'review column' );
ok( false !== strpos( $h, '<td>Draft</td>' ) && false !== strpos( $h, '<td>Scheduled</td>' ), 'status names' );
ok( strpos( $h, 'name="save"' ) < strpos( $h, 'name="reviewed"' ), 'Save comes before row buttons, so Enter saves' );
$h = wpshare_render_sitemap( $tree, wpshare_owner_choices(), 'none', 20 );
ok( false !== strpos( $h, 'wpshare-page-2"' ) && false === strpos( $h, 'wpshare-page-1"' ), 'No owner shows only unowned pages' );
$h = wpshare_render_sitemap( $tree, wpshare_owner_choices(), 'mine', 20 );
ok( false !== strpos( $h, 'wpshare-page-1"' ) && false === strpos( $h, 'wpshare-page-3"' ), 'My pages shows only mine' );
ok( false !== strpos( wpshare_render_sitemap( $tree, wpshare_owner_choices(), 'mine', 99 ), "You don&#039;t own any pages yet." ), 'empty My pages' );
ok( false !== strpos( wpshare_render_sitemap( $tree, array(), 'all', 20, 'saved', 1 ), 'Saved. 1 owner changed.' ), 'saved notice' );
ok( false !== strpos( wpshare_render_sitemap( $tree, array(), 'all', 20, 'reviewed', 0, 3 ), 'Marked &quot;Hours&quot; reviewed today.' ), 'reviewed notice' );
ok( 'all' === wpshare_filter( 'x' ) && 'mine' === wpshare_filter( 'mine' ) && 'all' === wpshare_filter( array() ), 'filter whitelist' );

/* ---------------- reminders ---------------- */

$GLOBALS['wps_mail'] = array();
$GLOBALS['wps_options'] = array();
ok( 2 === wpshare_send_reminders(), 'one email per owner; unowned skipped without a fallback' );
$m = $GLOBALS['wps_mail'];
ok( 'ana@example.edu' === $m[0]['to'] && '[Wallace & Library] Please check your pages' === $m[0]['subject'], 'reminder to Ana' );
ok( false !== strpos( $m[0]['body'], "Your pages (1):\n\n- Home (Published, never reviewed)\n  https://example.test/wp-admin/post.php?post=1&action=edit" ), 'reminder lists the page with its edit link' );
ok( false !== strpos( $m[0]['body'], 'Please check that these pages' ) && false !== strpos( $m[0]['body'], 'admin.php?page=wpshare&filter=mine' ), 'default message and My pages link' );
ok( false !== strpos( $m[1]['body'], 'Hours (Draft, last reviewed 2026-10-28)' ), 'drafts included, review date shown' );
ok( 2 === count( get_option( WPSHARE_LOG ) ) && 'reminder' === get_option( WPSHARE_LOG )[0]['kind'], 'reminders logged' );

$GLOBALS['wps_mail'] = array();
wpshare_save( array( 'message' => 'Hello team', 'fallback' => 'web@example.edu' ) );
ok( 3 === wpshare_send_reminders(), 'fallback gets the unowned pages' );
$last = array_values( array_filter( $GLOBALS['wps_mail'], function ( $m ) { return 'web@example.edu' === $m['to']; } ) )[0];
ok( 'web@example.edu' === $last['to'] && 0 === strpos( $last['body'], 'Hello team' ) && false !== strpos( $last['body'], 'Pages without an owner (8)' ) && false !== strpos( $last['body'], 'filter=none' ), 'fallback email' );
$GLOBALS['wps_mail_ok'] = false;
ok( 0 === wpshare_send_reminders(), 'failed sends not counted' );
ok( false === get_option( WPSHARE_LOG )[0]['ok'], 'failure logged' );
$GLOBALS['wps_mail_ok'] = true;

/* ---------------- daily check ---------------- */

$GLOBALS['wps_mail'] = array();
wpshare_save( array( 'dates' => "10-28\n2026-11-03" ) );
unset( $GLOBALS['wps_options'][ WPSHARE_STATE ] );
ok( 2 === wpshare_check( '2026-10-28' ), 'first run on a reminder date sends' );
ok( 0 === wpshare_check( '2026-10-28' ), 'same day: not again' );
ok( 0 === wpshare_check( '2026-10-29' ), 'next day: nothing due' );
ok( 2 === wpshare_check( '2026-11-05' ), 'missed one-off date caught up' );
ok( 0 === wpshare_check( '2026-11-06' ) && 0 === wpshare_check( '2026-11-01' ), 'earlier day is ignored' );
ok( 2 === wpshare_check( '2027-10-28' ), 'yearly date fires next year' );
$GLOBALS['wps_options'][ WPSHARE_STATE ] = 'junk';
ok( 0 === wpshare_check( '2027-12-01' ) && '2027-12-01' === get_option( WPSHARE_STATE )['checked'], 'junk state recovers' );

/* ---------------- notices (the wpa11y surface) ---------------- */

$GLOBALS['wps_mail'] = array();
wpshare_save( array() );
ok( true === wpshare_notify( 1, array( 'source' => 'wpa11y', 'subject' => 'Accessibility <b>issues</b>', 'message' => "2 new errors\nSee below", 'url' => 'https://example.test/wp-admin/admin.php?page=wpa11y&post=1' ) ), 'notice sent to owner' );
$m = $GLOBALS['wps_mail'][0];
ok( 'ana@example.edu' === $m['to'] && '[Wallace & Library] Accessibility issues' === $m['subject'], 'notice to owner, subject cleaned and prefixed' );
ok( 0 === strpos( $m['body'], "Page: Home\nhttps://example.test/wp-admin/post.php?post=1&action=edit\n\n2 new errors\nSee below\n\nDetails: https://example.test/wp-admin/admin.php?page=wpa11y&post=1" ), 'notice body' );
ok( false !== strpos( $m['body'], 'You get this because you own this page' ), 'notice says why' );
$log = get_option( WPSHARE_LOG )[0];
ok( 'notice' === $log['kind'] && 'wpa11y' === $log['source'] && 1 === $log['post'] && $log['ok'], 'notice logged' );

ok( false === wpshare_notify( 2, array( 'source' => 'wpa11y' ) ), 'unowned page, no fallback: not sent' );
ok( '' === get_option( WPSHARE_LOG )[0]['to'] && false === get_option( WPSHARE_LOG )[0]['ok'], 'unsent notice still logged' );
wpshare_save( array( 'fallback' => 'web@example.edu' ) );
ok( true === wpshare_notify( 10, 'junk' ), 'non-page post goes to fallback' );
$m = end( $GLOBALS['wps_mail'] );
ok( 'web@example.edu' === $m['to'] && '[Wallace & Library] A notice about "A post"' === $m['subject'] && false !== strpos( $m['body'], 'no owner' ), 'default subject; fallback explained' );
ok( 'notice' === get_option( WPSHARE_LOG )[0]['source'], 'missing source logged as notice' );
ok( false === wpshare_notify( 5 ) && false === wpshare_notify( 999 ) && false === wpshare_notify( 13 ), 'trashed, missing and auto-draft posts: nothing sent' );
wpshare_notify( 1, array( 'url' => 'javascript:alert(1)', 'subject' => array( 'x' ) ) );
$m = end( $GLOBALS['wps_mail'] );
ok( false === strpos( $m['body'], 'javascript' ) && false === strpos( $m['body'], 'Details:' ), 'non-http url dropped' );

for ( $i = 0; $i < 60; $i++ ) { wpshare_log( 'notice', 't', 1, 'a@b.cc', 's' . $i, true ); }
ok( WPSHARE_LOG_MAX === count( get_option( WPSHARE_LOG ) ) && 's59' === get_option( WPSHARE_LOG )[0]['subject'], 'log capped, newest first' );

/* ---------------- Settings screen ---------------- */

$s = wpshare_sanitize( array( 'post_types' => array( 'page' ), 'dates' => "01-15\n2026-11-03", 'message' => 'Hi <there>', 'fallback' => 'web@example.edu' ) );
$GLOBALS['wps_options'][ WPSHARE_LOG ][0]['subject'] = '<img src=x onerror=alert(1)>';
$h = wpshare_render_settings( $s, wpshare_public_types(), get_option( WPSHARE_LOG ), '2026-10-28', 'saved', 0, '<b>13-01</b>' );
ok( false !== strpos( $h, 'value="page" checked' ) && false !== strpos( $h, 'value="post">' ) && false === strpos( $h, 'attachment' ), 'post type checkboxes' );
ok( false !== strpos( $h, "01-15\n2026-11-03</textarea>" ), 'dates in the textarea' );
ok( false !== strpos( $h, '<li>Tuesday, November 3, 2026</li><li>Friday, January 15, 2027</li>' ), 'upcoming dates listed' );
ok( false === strpos( $h, '<img' ) && false === strpos( $h, '<b>13-01' ) && false !== strpos( $h, 'left out: &lt;b&gt;13-01' ), 'log and rejected dates escaped' );
ok( false !== strpos( $h, 'for="wpshare-dates"' ) && false !== strpos( $h, 'for="wpshare-message"' ) && false !== strpos( $h, 'for="wpshare-fallback"' ) && false !== strpos( $h, '<legend>' ), 'every field labeled' );
ok( false !== strpos( $h, 'Settings saved.' ), 'saved notice' );
ok( false !== strpos( wpshare_render_settings( wpshare_defaults(), array(), array(), '2026-10-28', 'sent', 1 ), 'Sent 1 reminder email.' ), 'sent notice' );
ok( false !== strpos( wpshare_render_settings( wpshare_defaults(), array(), array(), '2026-10-28' ), 'No reminders are scheduled.' ), 'no dates' );
ok( false !== strpos( wpshare_render_settings( wpshare_defaults(), array(), array(), '2026-10-28' ), 'No emails sent yet.' ), 'empty log' );
ok( false !== strpos( wpshare_render_settings( wpshare_defaults(), array(), array( array( 'kind' => 'notice', 'to' => '', 'ok' => false ), 'junk' ), '2026-10-28' ), 'Not sent: no owner or fallback address' ), 'unsent row explained; junk rows skipped' );

echo "\n$passes passed, $fails failed\n";
exit( $fails ? 1 : 0 );
