<?php
/**
 * Plugin_Compare tests: sandbox vs live plugin versions, the "kept as a
 * symlink" marker, and the release channel read from package symlinks.
 */

use SandboxSync\Auth;
use SandboxSync\Client;
use SandboxSync\Job;
use SandboxSync\Plugin_Compare;
use SandboxSync\Sync_Runner;

ss_test_section( 'Plugin compare: channel named by a symlink target' );

ss_assert_same( 'release', Plugin_Compare::channel_for_target( '../../../wordpress/plugins/newspack-plugin/latest' ), 'a relative target ending in latest is the release channel' );
ss_assert_same( 'alpha', Plugin_Compare::channel_for_target( '/wordpress/plugins/newspack-blocks/alpha' ), 'an absolute target ending in alpha is the alpha channel' );
ss_assert_same( 'canary', Plugin_Compare::channel_for_target( '../../../wordpress/themes/newspack-theme/canary/' ), 'a trailing slash does not hide the canary channel' );
ss_assert_same( '', Plugin_Compare::channel_for_target( '../../../wordpress/plugins/newspack-plugin/6.20.1' ), 'a pinned version directory is not a channel' );
ss_assert_same( '', Plugin_Compare::channel_for_target( '/Users/dev/repos/newspack-plugin' ), 'a development checkout is not a channel' );
ss_assert_same( '', Plugin_Compare::channel_for_target( '' ), 'an empty target is not a channel' );
ss_assert_same( '', Plugin_Compare::channel_for_target( false ), 'an unreadable link target is not a channel' );

ss_test_section( 'Plugin compare: link states of a plugins directory' );

$pc_root = sys_get_temp_dir() . '/ss-plugin-compare-' . getmypid();
ss_test_rmrf( $pc_root );
mkdir( $pc_root . '/externals/newspack-plugin/6.20.1', 0755, true );
mkdir( $pc_root . '/externals/newspack-plugin/6.21.0-alpha.1', 0755, true );
symlink( '6.20.1', $pc_root . '/externals/newspack-plugin/latest' );
symlink( '6.21.0-alpha.1', $pc_root . '/externals/newspack-plugin/alpha' );
file_put_contents( $pc_root . '/externals/hello.php', '<?php // Hello.' );
mkdir( $pc_root . '/plugins/real-plugin', 0755, true );
mkdir( $pc_root . '/plugins/branch-build', 0755, true );
mkdir( $pc_root . '/plugins/odd--symlink', 0755, true );
file_put_contents( $pc_root . '/plugins/index.php', '<?php // Silence is golden.' );
symlink( '../externals/newspack-plugin/alpha', $pc_root . '/plugins/newspack-plugin' );
symlink( '../externals/newspack-plugin/latest', $pc_root . '/plugins/newspack-plugin--symlink' );
symlink( $pc_root . '/externals/newspack-plugin/latest', $pc_root . '/plugins/absolute-link' );
symlink( '../externals/newspack-blocks/canary', $pc_root . '/plugins/branch-build--symlink' );
symlink( '../externals/nowhere/latest', $pc_root . '/plugins/dangling' );
symlink( '../externals/hello.php', $pc_root . '/plugins/hello.php' );

$pc_links = Plugin_Compare::link_states( $pc_root . '/plugins' );

ss_assert_same(
	array(
		'symlink' => true,
		'target'  => '../externals/newspack-plugin/alpha',
		'backup'  => null,
	),
	isset( $pc_links['newspack-plugin'] ) ? $pc_links['newspack-plugin'] : null,
	'a symlinked plugin is listed with its target exactly as written, and a backup link beside a symlink is not a branch build'
);
ss_assert_same( $pc_root . '/externals/newspack-plugin/latest', isset( $pc_links['absolute-link'] ) ? $pc_links['absolute-link']['target'] : null, 'an absolute link target is kept as written' );
ss_assert_same( true, isset( $pc_links['dangling'] ) ? $pc_links['dangling']['symlink'] : null, 'a dangling link is still a symlink (the sync still skips its path)' );
ss_assert_same( '../externals/hello.php', isset( $pc_links['hello.php'] ) ? $pc_links['hello.php']['target'] : null, 'a symlinked single-file plugin is listed' );
ss_assert_same(
	array(
		'symlink' => false,
		'target'  => '',
		'backup'  => '../externals/newspack-blocks/canary',
	),
	isset( $pc_links['branch-build'] ) ? $pc_links['branch-build'] : null,
	'a real directory with a --symlink link beside it records that backup target'
);
ss_assert_same( true, isset( $pc_links['branch-build--symlink'] ) ? $pc_links['branch-build--symlink']['symlink'] : null, 'the backup link itself is a symlink entry' );
ss_assert( ! isset( $pc_links['real-plugin'] ) && ! isset( $pc_links['index.php'] ) && ! isset( $pc_links['odd--symlink'] ), 'plain directories and files are not listed, whatever their name' );
ss_assert_same( array(), Plugin_Compare::link_states( $pc_root . '/missing' ), 'a missing directory yields no entries' );

ss_test_section( 'Plugin compare: sandbox plugin rows' );

// Shaped like get_plugins(): it follows directory symlinks, so a "--symlink"
// backup link shows up as a second copy of the plugin it backs up.
$pc_installed = array(
	'newspack-plugin/newspack.php'           => array(
		'Name'    => 'Newspack',
		'Version' => '6.21.0-alpha.1',
	),
	'newspack-plugin--symlink/newspack.php'  => array(
		'Name'    => 'Newspack',
		'Version' => '6.20.1',
	),
	'branch-build/branch-build.php'          => array(
		'Name'    => 'Branch Build',
		'Version' => '2.0.0-fix',
	),
	'branch-build--symlink/branch-build.php' => array(
		'Name'    => 'Branch Build',
		'Version' => '1.9.0',
	),
	'real-plugin/real-plugin.php'            => array(
		'Name'    => 'Real Plugin',
		'Version' => ' 1.3.2 ',
	),
	'real-plugin/extra.php'                  => array( 'Name' => 'Real Plugin Extra' ),
	'hello.php'                              => array(
		'Name'    => 'Hello Dolly',
		'Version' => '1.7.2',
	),
	'odd--symlink/odd.php'                   => array(
		'Name'    => 'Odd',
		'Version' => '0.1',
	),
);
$pc_local     = Plugin_Compare::local_plugins( $pc_installed, $pc_links );

ss_assert_same(
	array( 'newspack-plugin/newspack.php', 'branch-build/branch-build.php', 'real-plugin/real-plugin.php', 'real-plugin/extra.php', 'hello.php', 'odd--symlink/odd.php' ),
	is_array( $pc_local ) ? array_keys( $pc_local ) : null,
	'copies read through "--symlink" backup links are dropped; a real directory with that suffix stays'
);
ss_assert_same(
	array(
		'top'     => 'newspack-plugin',
		'version' => '6.21.0-alpha.1',
	),
	isset( $pc_local['newspack-plugin/newspack.php'] ) ? $pc_local['newspack-plugin/newspack.php'] : null,
	'a symlinked plugin is read through its link: the row carries the version the link points at'
);
ss_assert_same(
	array(
		'top'     => 'hello.php',
		'version' => '1.7.2',
	),
	isset( $pc_local['hello.php'] ) ? $pc_local['hello.php'] : null,
	'a single-file plugin is its own top-level entry'
);
ss_assert_same(
	array(
		'top'     => 'real-plugin',
		'version' => '1.3.2',
	),
	isset( $pc_local['real-plugin/real-plugin.php'] ) ? $pc_local['real-plugin/real-plugin.php'] : null,
	'the version is trimmed'
);
ss_assert_same(
	array(
		'top'     => 'real-plugin',
		'version' => '',
	),
	isset( $pc_local['real-plugin/extra.php'] ) ? $pc_local['real-plugin/extra.php'] : null,
	'a second plugin file shares the directory entry, and a missing Version header is an empty string'
);

ss_test_section( 'Plugin compare: live rows annotated with the sandbox state' );

// Rows as Source_Endpoints::handle_plugins returns them.
function ss_pc_live_row( $file, $version, $symlink = false, $active = true ) {
	$slash = strpos( $file, '/' );
	return array(
		'file'    => $file,
		'top'     => false === $slash ? $file : substr( $file, 0, $slash ),
		'name'    => 'Live ' . $file,
		'version' => $version,
		'active'  => $active,
		'symlink' => $symlink,
	);
}
// Rows as Plugin_Compare::local_plugins() returns them.
function ss_pc_local_row( $file, $version ) {
	$slash = strpos( $file, '/' );
	return array(
		'top'     => false === $slash ? $file : substr( $file, 0, $slash ),
		'version' => $version,
	);
}
function ss_pc_link( $target ) {
	return array(
		'symlink' => true,
		'target'  => $target,
		'backup'  => null,
	);
}

$pc_live_rows  = array(
	ss_pc_live_row( 'same/same.php', ' 1.0.0 ' ),
	ss_pc_live_row( 'classic-widgets/classic-widgets.php', '0.3', false, false ),
	ss_pc_live_row( 'new-on-live/new.php', '2.0' ),
	'not-a-row',
	ss_pc_live_row( 'newspack-plugin/newspack.php', '6.20.1' ),
	ss_pc_live_row( 'newspack-blocks/newspack-blocks.php', '4.0.0', true ),
	ss_pc_live_row( 'newspack-ads/newspack-ads.php', '3.0.0', true ),
	ss_pc_live_row( 'stop-emails/stop-emails.php', '1.2.3' ),
	ss_pc_live_row( 'renamed/new-main.php', '2.0' ),
	ss_pc_live_row( 'multi/one.php', '1.0' ),
	ss_pc_live_row( 'multi/two.php', '1.0' ),
	ss_pc_live_row( 'empty-version/empty.php', '' ),
	ss_pc_live_row( 'half-empty/half.php', '' ),
	ss_pc_live_row( 'dangling/dangling.php', '1.0' ),
	array( 'file' => 'no-top/no-top.php' ),
	array(
		'file' => 'bare/bare.php',
		'top'  => 'bare',
	),
);
$pc_local_rows = array(
	'same/same.php'                       => ss_pc_local_row( 'same/same.php', '1.0.0' ),
	'classic-widgets/classic-widgets.php' => ss_pc_local_row( 'classic-widgets/classic-widgets.php', '0.2' ),
	'newspack-plugin/newspack.php'        => ss_pc_local_row( 'newspack-plugin/newspack.php', '6.21.0-alpha.1' ),
	'newspack-blocks/newspack-blocks.php' => ss_pc_local_row( 'newspack-blocks/newspack-blocks.php', '4.0.0' ),
	'newspack-ads/newspack-ads.php'       => ss_pc_local_row( 'newspack-ads/newspack-ads.php', '3.1.0-fix' ),
	'stop-emails/stop-emails.php'         => ss_pc_local_row( 'stop-emails/stop-emails.php', '1.2.4' ),
	'renamed/old-main.php'                => ss_pc_local_row( 'renamed/old-main.php', '1.0' ),
	'multi/one.php'                       => ss_pc_local_row( 'multi/one.php', '1.0' ),
	'multi/three.php'                     => ss_pc_local_row( 'multi/three.php', '9.0' ),
	'empty-version/empty.php'             => ss_pc_local_row( 'empty-version/empty.php', '' ),
	'half-empty/half.php'                 => ss_pc_local_row( 'half-empty/half.php', '1.0' ),
	'sandbox-only/sandbox-only.php'       => ss_pc_local_row( 'sandbox-only/sandbox-only.php', '5.0' ),
);
$pc_local_link = array(
	'newspack-plugin' => ss_pc_link( '../../../wordpress/plugins/newspack-plugin/alpha' ),
	'newspack-blocks' => ss_pc_link( '../../../wordpress/plugins/newspack-blocks/latest' ),
	'newspack-ads'    => array(
		'symlink' => false,
		'target'  => '',
		'backup'  => '../../../wordpress/plugins/newspack-ads/latest',
	),
	'dangling'        => ss_pc_link( '../nowhere' ),
);

$pc_rows = Plugin_Compare::annotate( $pc_live_rows, $pc_local_rows, $pc_local_link );
$pc_view = array();
foreach ( (array) $pc_rows as $pc_row ) {
	$pc_view[ $pc_row['file'] ] = array( $pc_row['sandbox_version'], $pc_row['differs'], $pc_row['sandbox_symlink'], $pc_row['channel_label'], $pc_row['will_change'] );
}
function ss_pc_view( $view, $file ) {
	return isset( $view[ $file ] ) ? $view[ $file ] : null;
}

ss_assert_same(
	array( 'same/same.php', 'classic-widgets/classic-widgets.php', 'new-on-live/new.php', 'newspack-plugin/newspack.php', 'newspack-blocks/newspack-blocks.php', 'newspack-ads/newspack-ads.php', 'stop-emails/stop-emails.php', 'renamed/new-main.php', 'multi/one.php', 'multi/two.php', 'empty-version/empty.php', 'half-empty/half.php', 'dangling/dangling.php', 'bare/bare.php' ),
	is_array( $pc_rows ) ? array_column( $pc_rows, 'file' ) : null,
	"rows keep live's order; malformed remote rows are dropped and sandbox-only plugins add none"
);
// Each view: sandbox version, differs, kept as a sandbox symlink, channel label, will change.
ss_assert_same( array( '1.0.0', false, false, '', false ), ss_pc_view( $pc_view, 'same/same.php' ), 'the same version on both sides does not differ, whitespace aside' );
ss_assert_same( array( '0.2', true, false, '', true ), ss_pc_view( $pc_view, 'classic-widgets/classic-widgets.php' ), 'a different version in a real directory differs and will change' );
ss_assert_same( array( null, false, false, '', false ), ss_pc_view( $pc_view, 'new-on-live/new.php' ), 'a plugin the sandbox does not have has no sandbox version and is not a version change' );
ss_assert_same( array( '6.21.0-alpha.1', true, true, 'Alpha', false ), ss_pc_view( $pc_view, 'newspack-plugin/newspack.php' ), 'a sandbox symlink to the alpha build differs from live but is kept, so it will not change' );
ss_assert_same( array( '4.0.0', false, true, 'Release', false ), ss_pc_view( $pc_view, 'newspack-blocks/newspack-blocks.php' ), 'a plugin symlinked on both sides is kept and names its channel' );
ss_assert_same( array( '3.1.0-fix', true, false, 'Branch build', false ), ss_pc_view( $pc_view, 'newspack-ads/newspack-ads.php' ), 'a branch build facing a live symlink is labelled, is not kept as a symlink, and is not counted as a version change' );
ss_assert_same( array( '1.2.4', true, false, '', false ), ss_pc_view( $pc_view, 'stop-emails/stop-emails.php' ), 'the protected email kill switch never counts as a version change' );
ss_assert_same( array( '1.0', true, false, '', true ), ss_pc_view( $pc_view, 'renamed/new-main.php' ), 'a renamed main file still matches when each side has one plugin in the directory' );
ss_assert_same( array( '1.0', false, false, '', false ), ss_pc_view( $pc_view, 'multi/one.php' ), 'a directory with several plugins matches by file' );
ss_assert_same( array( null, false, false, '', false ), ss_pc_view( $pc_view, 'multi/two.php' ), 'a directory with several plugins never guesses a match for an unmatched file' );
ss_assert_same( array( '', false, false, '', false ), ss_pc_view( $pc_view, 'empty-version/empty.php' ), 'two empty versions do not differ' );
ss_assert_same( array( '1.0', true, false, '', true ), ss_pc_view( $pc_view, 'half-empty/half.php' ), 'an empty live version against a sandbox version differs' );
ss_assert_same( array( null, false, true, '', false ), ss_pc_view( $pc_view, 'dangling/dangling.php' ), 'a dangling sandbox link has no version but is still kept as a symlink' );

$pc_bare = null;
foreach ( (array) $pc_rows as $pc_row ) {
	if ( 'bare/bare.php' === $pc_row['file'] ) {
		$pc_bare = array( $pc_row['name'], $pc_row['version'], $pc_row['active'], $pc_row['symlink'] );
	}
}
ss_assert_same( array( '', '', false, false ), $pc_bare, 'fields a remote row leaves out get safe defaults for the picker and the CLI' );

ss_test_section( 'Plugin compare: counts for the confirmation and the job report' );

// In $pc_rows three plugins will change version (classic-widgets, renamed,
// half-empty) and three are symlinks on the sandbox (newspack-plugin,
// newspack-blocks, dangling).
ss_assert_same(
	array(
		'version_changes' => 3,
		'kept_symlinks'   => 3,
	),
	Plugin_Compare::summarize( (array) $pc_rows, null ),
	'with every plugin in scope, each differing real-directory plugin is a version change'
);
ss_assert_same(
	array(
		'version_changes' => 1,
		'kept_symlinks'   => 3,
	),
	Plugin_Compare::summarize( (array) $pc_rows, array( 'classic-widgets', 'same', 'newspack-plugin' ) ),
	'a selection limits the version changes to the selected plugins; symlinks are kept whether selected or not'
);
ss_assert_same(
	array(
		'version_changes' => 0,
		'kept_symlinks'   => 3,
	),
	Plugin_Compare::summarize( (array) $pc_rows, array() ),
	'an empty selection syncs no plugin, so nothing changes version'
);
ss_assert_same(
	array(
		'version_changes' => 0,
		'kept_symlinks'   => 0,
	),
	Plugin_Compare::summarize( array(), null ),
	'no rows, no counts'
);

ss_test_section( 'Plugin compare: release channel of the sandbox' );

function ss_pc_branch_build( $backup_target ) {
	return array(
		'symlink' => false,
		'target'  => '',
		'backup'  => $backup_target,
	);
}
function ss_pc_channel_label( $plugin_links, $theme_links = array(), $stored_branch = '' ) {
	return Plugin_Compare::site_channel_label( $plugin_links, $theme_links, $stored_branch );
}

$pc_latest = ss_pc_link( '../../../wordpress/plugins/pkg/latest' );
$pc_alpha  = ss_pc_link( '../../../wordpress/plugins/pkg/alpha' );
$pc_canary = ss_pc_link( '/wordpress/plugins/pkg/canary' );
$pc_dev    = ss_pc_link( '/Users/dev/repos/pkg' );

ss_assert_same(
	'Release',
	ss_pc_channel_label(
		array(
			'a' => $pc_latest,
			'b' => $pc_latest,
		)
	),
	'packages all linked to latest are on the release channel'
);
ss_assert_same(
	'Alpha',
	ss_pc_channel_label(
		array(
			'a' => $pc_latest,
			'b' => $pc_alpha,
		)
	),
	'alpha wins over release: packages with no prerelease stay on latest after a switch to alpha'
);
ss_assert_same(
	'Canary',
	ss_pc_channel_label(
		array(
			'a' => $pc_alpha,
			'b' => $pc_canary,
			'c' => $pc_latest,
		)
	),
	'canary is the most forward channel'
);
ss_assert_same( '', ss_pc_channel_label( array( 'a' => $pc_dev ) ), 'links that point at no channel link (development checkouts) name no channel' );
ss_assert_same( '', ss_pc_channel_label( array() ), 'no links, no channel' );
ss_assert_same(
	'Release',
	ss_pc_channel_label(
		array(
			'a'          => $pc_latest,
			'a--symlink' => $pc_canary,
		)
	),
	'a backup link is never read as a package of its own'
);
ss_assert_same( 'Alpha', ss_pc_channel_label( array( 'a' => $pc_latest ), array( 't' => $pc_alpha ) ), 'theme packages count towards the channel' );
ss_assert_same( 'Canary', ss_pc_channel_label( array( 'x' => $pc_canary ), array( 'x' => $pc_latest ) ), 'a plugin and a theme with the same name are both read' );

ss_assert_same(
	'Release, with an unknown branch layered on top',
	ss_pc_channel_label(
		array(
			'a' => $pc_latest,
			'b' => ss_pc_branch_build( '../../../wordpress/plugins/b/alpha' ),
		)
	),
	'a branch build without a recorded name is an unknown branch, and live links decide the base over backup links'
);
ss_assert_same(
	'Release, with branch fix/foo-1.2_x layered on top',
	ss_pc_channel_label(
		array(
			'a' => $pc_latest,
			'b' => ss_pc_branch_build( '../../../wordpress/plugins/b/alpha' ),
		),
		array(),
		'fix/foo-1.2_x'
	),
	'a branch build with a recorded name names the branch'
);
ss_assert_same(
	'Alpha, with an unknown branch layered on top',
	ss_pc_channel_label(
		array(
			'a' => ss_pc_branch_build( '../../../wordpress/plugins/a/alpha' ),
			'b' => ss_pc_branch_build( '../../../wordpress/plugins/b/latest' ),
		)
	),
	'when a branch built every package, the backup links decide the base'
);
ss_assert_same( 'branch feat/x', ss_pc_channel_label( array( 'a' => ss_pc_branch_build( '../../../wordpress/plugins/a/6.20.1' ) ), array(), 'feat/x' ), 'a branch build whose backup names no channel has a branch but no base' );
ss_assert_same( 'an unknown branch', ss_pc_channel_label( array( 'a' => ss_pc_branch_build( '' ) ) ), 'a branch build with neither base nor name is an unknown branch' );
ss_assert_same( 'Alpha, with branch fix/bar layered on top', ss_pc_channel_label( array( 'a' => $pc_alpha ), array(), 'fix/bar' ), 'a recorded branch counts even when no build is found on disk, as in Newspack Manager' );
ss_assert_same( 'branch trunk', ss_pc_channel_label( array(), array(), 'trunk' ), 'a recorded branch with nothing else recognisable is still reported' );

foreach ( array( "fix/foo\nFAILED: injected", ' padded ', '-leading-dash', '/leading-slash', 'trailing/', 'a..b', 'a//b', 'has space', str_repeat( 'x', 256 ), array( 'fix/foo' ), 42, null ) as $pc_bad_branch ) {
	ss_assert_same(
		'Alpha',
		ss_pc_channel_label( array( 'a' => $pc_alpha ), array(), $pc_bad_branch ),
		'a recorded value that is not a plain branch name is ignored: ' . wp_json_encode( $pc_bad_branch )
	);
}

ss_test_section( 'Plugin compare: the sentence for the log, the CLI and the history' );

$pc_summary = array(
	'version_changes' => 2,
	'kept_symlinks'   => 14,
	'channel'         => 'Alpha',
);
ss_assert_same( '2 will change version, 14 kept as symlinks; sandbox release channel: Alpha', Plugin_Compare::describe( $pc_summary, 'running' ), 'before the swap the counts are what the sync is about to do' );
ss_assert_same( '2 changed version, 14 kept as symlinks; sandbox release channel: Alpha', Plugin_Compare::describe( $pc_summary, 'done' ), 'a finished sync reports the changes in the past tense' );
ss_assert_same( '2 planned to change version, 14 kept as symlinks; sandbox release channel: Alpha', Plugin_Compare::describe( $pc_summary, 'failed' ), 'a failed sync is rolled back, so its changes were only planned' );
ss_assert_same( '2 planned to change version, 14 kept as symlinks; sandbox release channel: Alpha', Plugin_Compare::describe( $pc_summary, 'cancelled' ), 'a cancelled sync is rolled back, so its changes were only planned' );
ss_assert_same(
	'1 will change version, 1 kept as a symlink',
	Plugin_Compare::describe(
		array(
			'version_changes' => 1,
			'kept_symlinks'   => 1,
			'channel'         => '',
		),
		'running'
	),
	'no channel part when none was detected, and one symlink is singular'
);
ss_assert_same( '0 changed version, 0 kept as symlinks', Plugin_Compare::describe( array(), 'done' ), 'a report entry without the counts reads as zero instead of failing' );

ss_test_section( 'Plugin compare: summary for a job, from both plugin lists' );

// What read_local() returns: get_plugins(), the link states of plugins/ and
// themes/, and the recorded branch option.
$pc_sandbox = array(
	'installed'    => array(
		'classic-widgets/classic-widgets.php' => array(
			'Name'    => 'Classic Widgets',
			'Version' => '0.2',
		),
		'newspack-plugin/newspack.php'        => array(
			'Name'    => 'Newspack',
			'Version' => '6.21.0-alpha.1',
		),
	),
	'plugin_links' => array( 'newspack-plugin' => ss_pc_link( '../../../wordpress/plugins/newspack-plugin/alpha' ) ),
	'theme_links'  => array( 'newspack-theme' => ss_pc_link( '../../../wordpress/themes/newspack-theme/latest' ) ),
	'branch'       => '',
);
$pc_response = array(
	'plugins' => array(
		ss_pc_live_row( 'classic-widgets/classic-widgets.php', '0.3' ),
		ss_pc_live_row( 'newspack-plugin/newspack.php', '6.20.1' ),
	),
);

ss_assert_same(
	array(
		'version_changes' => 1,
		'kept_symlinks'   => 1,
		'channel'         => 'Alpha',
	),
	Plugin_Compare::job_summary( $pc_response, $pc_sandbox, null ),
	'a job that syncs every plugin: the real-directory plugin changes version, the alpha symlink is kept'
);
ss_assert_same(
	array(
		'version_changes' => 0,
		'kept_symlinks'   => 1,
		'channel'         => 'Alpha',
	),
	Plugin_Compare::job_summary( $pc_response, $pc_sandbox, array( 'newspack-plugin' ) ),
	'a job limited to other plugins changes no version'
);
ss_assert_same( null, Plugin_Compare::job_summary( new WP_Error( 'http_request_failed', 'Operation timed out' ), $pc_sandbox, null ), 'a failed read of the live plugin list yields no summary instead of an error' );
ss_assert_same( null, Plugin_Compare::job_summary( array( 'error' => 'Unauthorized.' ), $pc_sandbox, null ), 'a response without a plugin list yields no summary' );
ss_assert_same( null, Plugin_Compare::job_summary( array( 'plugins' => 'not-a-list' ), $pc_sandbox, null ), 'a malformed plugin list yields no summary' );

$pc_compared = Plugin_Compare::compare( $pc_response['plugins'], $pc_sandbox );
ss_assert_same(
	array( array( '0.2', '6.21.0-alpha.1' ), 'Alpha' ),
	is_array( $pc_compared ) ? array( array_column( $pc_compared['rows'], 'sandbox_version' ), $pc_compared['channel'] ) : null,
	'the picker and the CLI get the annotated rows and the site channel in one call'
);

ss_test_section( 'Plugin compare: one line per plugin for `wp sandbox-sync source-plugins`' );

$pc_cli = array();
foreach ( (array) $pc_rows as $pc_row ) {
	$pc_cli[ $pc_row['file'] ] = $pc_row;
}

ss_assert_same(
	'same                             v1.0.0     Live same/same.php (active)',
	Plugin_Compare::cli_line( $pc_cli['same/same.php'], false ),
	'without a differing plugin the line is what the command printed before'
);
ss_assert_same(
	'newspack-ads                     v3.0.0     Live newspack-ads/newspack-ads.php (active) [symlinked — not synced] [Branch build]',
	Plugin_Compare::cli_line( $pc_cli['newspack-ads/newspack-ads.php'], false ),
	'a plugin symlinked on live only keeps its existing note, followed by the sandbox channel'
);

$pc_line_differs = (string) Plugin_Compare::cli_line( $pc_cli['classic-widgets/classic-widgets.php'], true );
$pc_line_same    = (string) Plugin_Compare::cli_line( $pc_cli['same/same.php'], true );
ss_assert( 1 === preg_match( '/^classic-widgets\s+v0\.3\s+sandbox v0\.2\s+Live classic-widgets\/classic-widgets\.php \(inactive\)$/', $pc_line_differs ), 'a differing plugin shows the sandbox version in an extra column after the live version' );
ss_assert( false === strpos( $pc_line_same, 'sandbox' ), 'the extra column stays empty for a plugin whose version is the same' );
ss_assert_same( strpos( $pc_line_differs, 'Live classic-widgets' ), strpos( $pc_line_same, 'Live same' ), 'the extra column has the same width on every line, so the names stay aligned' );
ss_assert( '[kept (symlink), live copy skipped] [Alpha]' === substr( (string) Plugin_Compare::cli_line( $pc_cli['newspack-plugin/newspack.php'], true ), -43 ), 'a sandbox symlink facing a real live copy is kept, the live copy skipped, and its channel named' );
ss_assert( '[kept (symlink)] [Release]' === substr( (string) Plugin_Compare::cli_line( $pc_cli['newspack-blocks/newspack-blocks.php'], true ), -26 ), 'a plugin symlinked on both sides is kept; there is no live copy to skip' );
ss_assert( 0 === strpos( (string) Plugin_Compare::cli_line( $pc_cli['newspack-plugin/newspack.php'], true ), 'newspack-plugin ' ), 'every line still starts with the name to pass to --plugins=' );

ss_test_section( 'Plugin compare: this site, read from WordPress and the disk' );
ss_test_reset_options();

mkdir( $pc_root . '/themes', 0755, true );
symlink( '../externals/newspack-theme/canary', $pc_root . '/themes/newspack-theme' );
$GLOBALS['ss_test_plugins'] = $pc_installed;
// The option Newspack Manager records a branch install in.
update_option( 'newspack_manager_release_channel_branch', 'fix/foo' );

$pc_read = Plugin_Compare::read_local( $pc_root );
ss_assert_same( $pc_installed, isset( $pc_read['installed'] ) ? $pc_read['installed'] : null, 'installed plugins are what get_plugins() lists' );
ss_assert_same( $pc_links, isset( $pc_read['plugin_links'] ) ? $pc_read['plugin_links'] : null, 'plugin link states are read from the plugins directory under the content directory' );
ss_assert_same( array( 'newspack-theme' => ss_pc_link( '../externals/newspack-theme/canary' ) ), isset( $pc_read['theme_links'] ) ? $pc_read['theme_links'] : null, 'theme link states are read from the themes directory beside it' );
ss_assert_same( 'fix/foo', isset( $pc_read['branch'] ) ? $pc_read['branch'] : null, "the recorded branch is read from Newspack Manager's option" );

$pc_site = is_array( $pc_read ) ? Plugin_Compare::compare( array(), $pc_read ) : null;
ss_assert_same(
	'Canary, with branch fix/foo layered on top',
	is_array( $pc_site ) ? $pc_site['channel'] : null,
	'read from disk, the theme on canary decides the channel and the branch build names its branch'
);
$GLOBALS['ss_test_plugins'] = array();

ss_test_section( 'Client: bounded read of the live plugin list for a running job' );
ss_test_reset_options();
Auth::save_connection( 'https://live.example.com', 'test-bearer' );

$pc_requests                   = array();
$GLOBALS['ss_test_remote_get'] = function ( $url, $args ) use ( &$pc_requests, $pc_response ) {
	$pc_requests[] = array( $url, $args );
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $pc_response ),
	);
};
$pc_listed                     = Client::get_source_plugins();
ss_assert_same( $pc_response, $pc_listed, 'the decoded plugin list of the connected source is returned' );
ss_assert_same( array( 'https://live.example.com/?sandbox_sync=plugins' ), array_column( $pc_requests, 0 ), 'it is one request to the plugins action' );
ss_assert_same( 'Bearer test-bearer', isset( $pc_requests[0][1]['headers']['Authorization'] ) ? $pc_requests[0][1]['headers']['Authorization'] : null, 'the request carries the bearer token' );
ss_assert(
	isset( $pc_requests[0][1]['timeout'] ) && $pc_requests[0][1]['timeout'] <= 15,
	'the request is bounded to seconds: a job whose heartbeat is ' . Job::CHUNK_LOCK_TTL . ' s old loses its lease to another worker'
);

$pc_calls                      = 0;
$GLOBALS['ss_test_remote_get'] = function () use ( &$pc_calls ) {
	++$pc_calls;
	return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
};
$pc_timed_out                  = Client::get_source_plugins();
ss_assert( is_wp_error( $pc_timed_out ), 'a timed-out read comes back as an error for the caller to ignore' );
ss_assert_same( 1, $pc_calls, 'a failed read is never retried, although the error is one the client retries elsewhere' );
ss_assert_same( null, Plugin_Compare::job_summary( $pc_timed_out, $pc_sandbox, null ), 'and the job then records no plugin summary' );

$pc_calls                      = 0;
$GLOBALS['ss_test_remote_get'] = function () use ( &$pc_calls ) {
	++$pc_calls;
	return array(
		'response' => array( 'code' => 503 ),
		'body'     => 'Service Unavailable',
	);
};
ss_assert( is_wp_error( Client::get_source_plugins() ) && 1 === $pc_calls, 'a 503 from the source is an error after one attempt' );

$pc_calls = 0;
Auth::disconnect();
ss_assert( is_wp_error( Client::get_source_plugins() ) && 0 === $pc_calls, 'without a connection nothing is sent' );
unset( $GLOBALS['ss_test_remote_get'] );

ss_test_section( 'Sync runner: plugin comparison recorded at the manifest step' );
ss_test_reset_options();

// Runs the private recording step on a job and returns the job it left.
function ss_pc_record_plugin_changes( array $job ) {
	$record = new ReflectionMethod( Sync_Runner::class, 'record_plugin_changes' );
	if ( PHP_VERSION_ID < 80100 ) {
		$record->setAccessible( true );
	}
	$record->invokeArgs( null, array( &$job ) );
	return $job;
}
Auth::save_connection( 'https://live.example.com', 'test-bearer' );
$GLOBALS['ss_test_plugins'] = array(
	'classic-widgets/classic-widgets.php' => array(
		'Name'    => 'Classic Widgets',
		'Version' => '0.2',
	),
);
$pc_running                 = array(
	'id'       => '',
	'status'   => Job::S_RUNNING,
	'options'  => array(
		'dirs'    => array( 'plugins' ),
		'plugins' => null,
	),
	'manifest' => array( 'dirs' => array( 'plugins', 'uploads' ) ),
	'log'      => array(),
);
$pc_live_list               = array( 'plugins' => array( ss_pc_live_row( 'classic-widgets/classic-widgets.php', '0.3' ) ) );

$pc_reads                      = 0;
$GLOBALS['ss_test_remote_get'] = function () use ( &$pc_reads, $pc_live_list ) {
	++$pc_reads;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $pc_live_list ),
	);
};
$pc_job_ok                     = ss_pc_record_plugin_changes( $pc_running );
// This reads the real content directory of the test run, so only the count
// that the fixtures here decide is asserted.
ss_assert_same( 1, isset( $pc_job_ok['plugin_changes']['version_changes'] ) ? $pc_job_ok['plugin_changes']['version_changes'] : null, 'the job records how many plugins it is about to move to another version' );
ss_assert( 1 === count( $pc_job_ok['log'] ) && 0 === strpos( $pc_job_ok['log'][0][1], 'Plugins: 1 will change version, ' ), 'and logs the counts, which is what a CLI run prints' );
ss_assert_same( 1, $pc_reads, "it reads live's plugin list once" );

$pc_reads                      = 0;
$GLOBALS['ss_test_remote_get'] = function () use ( &$pc_reads ) {
	++$pc_reads;
	return new WP_Error( 'http_request_failed', 'Operation timed out' );
};
$pc_job_unread                 = ss_pc_record_plugin_changes( $pc_running );
ss_assert( ! array_key_exists( 'plugin_changes', $pc_job_unread ), 'when the live list cannot be read the job carries no counts' );
ss_assert( 1 === count( $pc_job_unread['log'] ) && false !== strpos( $pc_job_unread['log'][0][1], 'Operation timed out' ), 'the job log says why the comparison is missing' );
ss_assert_same( 1, $pc_reads, 'and the read is not repeated' );

$pc_reads                           = 0;
$pc_not_selected                    = $pc_running;
$pc_not_selected['options']['dirs'] = array( 'uploads' );
$pc_job_skipped                     = ss_pc_record_plugin_changes( $pc_not_selected );
$pc_not_on_live                     = $pc_running;
$pc_not_on_live['manifest']['dirs'] = array( 'uploads' );
$pc_job_no_dir                      = ss_pc_record_plugin_changes( $pc_not_on_live );
ss_assert( ! array_key_exists( 'plugin_changes', $pc_job_skipped ) && array() === $pc_job_skipped['log'], 'a job that does not sync plugins/ records and logs nothing' );
ss_assert( ! array_key_exists( 'plugin_changes', $pc_job_no_dir ) && array() === $pc_job_no_dir['log'], 'nor does a job whose source has no plugins/ directory to sync' );
ss_assert_same( 0, $pc_reads, "and neither asks live for its plugin list" );

unset( $GLOBALS['ss_test_remote_get'] );
$GLOBALS['ss_test_plugins'] = array();
Auth::disconnect();

ss_test_section( 'Sync report: plugin counts reach the last report and the history' );
ss_test_reset_options();

$pc_write_report = new ReflectionMethod( Sync_Runner::class, 'write_report' );
if ( PHP_VERSION_ID < 80100 ) {
	$pc_write_report->setAccessible( true );
}
$pc_job = array(
	'id'             => 'abcdef012345',
	'created_at'     => time() - 90,
	'steps'          => array(),
	'manifest'       => array( 'site' => array( 'home' => 'https://live.example.com' ) ),
	'imported'       => array(),
	'options'        => array( 'dirs' => array( 'plugins' ) ),
	'error'          => '',
	'error_info'     => null,
	'plugin_changes' => $pc_summary,
);

$pc_write_report->invoke( null, $pc_job );
$pc_last    = get_option( Sync_Runner::OPT_LAST_REPORT );
$pc_history = get_option( Sync_Runner::OPT_HISTORY );
ss_assert_same( $pc_summary, isset( $pc_last['plugin_changes'] ) ? $pc_last['plugin_changes'] : null, 'a finished job carries its plugin counts into the last report' );
ss_assert_same( $pc_summary, isset( $pc_history[0]['plugin_changes'] ) ? $pc_history[0]['plugin_changes'] : null, 'and into its history entry' );

$pc_job['id'] = 'abcdef012346';
$pc_write_report->invoke( null, $pc_job, 'failed' );
$pc_history = get_option( Sync_Runner::OPT_HISTORY );
ss_assert_same(
	array( 'failed', $pc_summary ),
	isset( $pc_history[1]['plugin_changes'] ) ? array( $pc_history[1]['status'], $pc_history[1]['plugin_changes'] ) : null,
	'a failed job keeps the counts it had planned in its history entry'
);

$pc_job['id'] = 'abcdef012347';
unset( $pc_job['plugin_changes'] );
$pc_write_report->invoke( null, $pc_job );
$pc_last = get_option( Sync_Runner::OPT_LAST_REPORT );
ss_assert( is_array( $pc_last ) && 'abcdef012347' === $pc_last['job_id'] && ! array_key_exists( 'plugin_changes', $pc_last ), 'a job that compared no plugins (plugins/ not synced, or the live list unreadable) reports none' );

ss_test_reset_options();
ss_test_rmrf( $pc_root );
