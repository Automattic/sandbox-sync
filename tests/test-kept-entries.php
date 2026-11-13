<?php
/**
 * Kept sandbox-only entries: what a sync keeps in plugins/ and themes/, what
 * it records as delivered by live, and which plugin activations carry over.
 */

use SandboxSync\Files;
use SandboxSync\Sync_Runner;

/**
 * A job as the plan step receives it: no kept entries decided yet.
 *
 * @param string[]|null $selection Per-plugin selection, or null for all plugins.
 * @return array<string, mixed>
 */
function ss_kept_test_job( $selection ) {
	return array(
		'id'      => '',
		'options' => array(
			'plugins'       => $selection,
			'uploads_scope' => null,
		),
		'log'     => array(),
	);
}

ss_test_section( 'Kept entries: which sandbox entries a sync keeps' );

$kept_local     = array( 'akismet', 'dev-tool', 'hello.php', 'legacy-live', 'newspack-sponsors', 'stop-emails' );
$kept_remote    = array( 'akismet', 'index.php' );
$kept_links     = array( 'newspack-sponsors' );
$kept_protected = array( 'stop-emails' );

ss_assert_same(
	array( 'dev-tool', 'hello.php', 'newspack-sponsors' ),
	Sync_Runner::kept_top_level_names( $kept_local, $kept_remote, array( 'akismet', 'legacy-live' ), $kept_links, $kept_protected ),
	'entries neither on live nor recorded as synced are kept; one that live removed after a sync is not'
);
ss_assert_same(
	array( 'dev-tool', 'hello.php', 'newspack-sponsors' ),
	Sync_Runner::kept_top_level_names( $kept_local, $kept_remote, array( 'akismet', 'legacy-live', 'newspack-sponsors' ), $kept_links, $kept_protected ),
	'a recorded entry that live now runs as a symlink counts as sandbox-only (branch build over a stale record)'
);
ss_assert_same(
	array( 'newspack-sponsors' ),
	Sync_Runner::kept_top_level_names( $kept_local, $kept_remote, null, $kept_links, $kept_protected ),
	'without a record only a real entry that live runs as a symlink is kept'
);
ss_assert_same(
	array( 'newspack-sponsors' ),
	Sync_Runner::kept_top_level_names( $kept_local, $kept_remote, false, $kept_links, $kept_protected ),
	'a missing option (false) is no record'
);
ss_assert_same(
	array( 'dev-tool', 'hello.php', 'legacy-live', 'newspack-sponsors' ),
	Sync_Runner::kept_top_level_names( $kept_local, $kept_remote, array(), $kept_links, $kept_protected ),
	'an empty record is still a record: nothing was synced, so every sandbox-only entry is kept'
);
ss_assert_same(
	array(),
	Sync_Runner::kept_top_level_names( array( 'akismet', 'stop-emails' ), array( 'akismet' ), null, array( 'akismet', 'stop-emails' ), $kept_protected ),
	'an entry live delivers is never kept, nor is a protected one, whatever live reports as symlinked'
);

ss_test_section( 'Kept entries: record of what the last sync wrote' );

ss_assert_same(
	array( 'akismet', 'index.php' ),
	Sync_Runner::next_synced_record( array( 'index.php', 'akismet', 'akismet', 'linked-dev' ), array( 'old' ), null, array( 'linked-dev' ) ),
	'a full run replaces the record with what live delivered; a name the sandbox holds as a symlink was not written'
);
ss_assert_same(
	array( 'akismet', 'old' ),
	Sync_Runner::next_synced_record( array( 'akismet', 'jetpack' ), array( 'old', 'dropped' ), array( 'akismet', 'dropped' ), array() ),
	'a selection run updates only the selected entries: unselected ones keep their state, a selected one gone from live leaves the record'
);
ss_assert_same(
	array( 'b', 'old' ),
	Sync_Runner::next_synced_record( array( 'akismet' ), array( 'old', 'b', 'old' ), array(), array() ),
	'an empty selection leaves the previous record as it was'
);

ss_test_section( 'Kept entries: plugin activation carried across the database swap' );

$carry_imported = array( 'akismet/akismet.php', 'hello.php' );
$carry_local    = array( 'akismet/akismet.php', 'dev-tool/dev-tool.php', 'hello.php', 'other/other.php' );

ss_assert_same(
	array( 'akismet/akismet.php', 'hello.php', 'dev-tool/dev-tool.php' ),
	Sync_Runner::carry_kept_active_plugins( $carry_imported, $carry_local, array( 'dev-tool', 'hello.php' ) ),
	'kept plugins active on the sandbox are appended once, after live\'s list; other sandbox activations are dropped'
);
ss_assert_same(
	array( 'dev-tool/dev-tool.php' ),
	Sync_Runner::carry_kept_active_plugins( 'not-an-array', $carry_local, array( 'dev-tool' ) ),
	'a malformed imported list is treated as empty'
);
ss_assert_same(
	$carry_imported,
	Sync_Runner::carry_kept_active_plugins( $carry_imported, $carry_local, array() ),
	'with nothing kept the imported list is returned unchanged'
);
ss_assert_same(
	$carry_imported,
	Sync_Runner::carry_kept_active_plugins( $carry_imported, false, array( 'dev-tool' ) ),
	'a sandbox without an activation list carries nothing'
);
ss_assert_same(
	array( 'akismet/akismet.php', 'dev-tool/dev-tool.php' ),
	Sync_Runner::carry_kept_active_plugins(
		array(
			3 => 'akismet/akismet.php',
			7 => 42,
		),
		array( 'dev-tool/dev-tool.php', array( 'nested' ) ),
		array( 'dev-tool' )
	),
	'the merged list is re-indexed and holds plugin paths only'
);

ss_test_section( 'Kept entries: what live reports about its code directories' );

$live_response = array(
	'plugins' => array(
		array(
			'file'    => 'akismet/akismet.php',
			'top'     => 'akismet',
			'name'    => 'Akismet',
			'version' => '5.3',
			'active'  => true,
			'symlink' => false,
		),
		array(
			'file'    => 'newspack-sponsors/newspack-sponsors.php',
			'top'     => 'newspack-sponsors',
			'name'    => 'Newspack Sponsors',
			'version' => '1.0.0',
			'active'  => false,
			'symlink' => true,
		),
	),
	'tops'    => array(
		'plugins' => array(
			'dirs'  => array( 'no-header-dir', 'akismet' ),
			'files' => array( 'index.php' ),
			'links' => array( 'newspack-sponsors', 'broken-link' ),
		),
		'themes'  => array(
			'dirs'  => array( 'twentytwentyfive' ),
			'files' => array( 'index.php' ),
			'links' => array( 'newspack-theme' ),
		),
	),
);

ss_assert_same(
	array(
		'real'  => array( 'akismet', 'index.php', 'no-header-dir' ),
		'links' => array( 'broken-link', 'newspack-sponsors' ),
	),
	Sync_Runner::live_top_levels( $live_response, 'plugins' ),
	'the reported layout covers entries WordPress does not list as plugins'
);
ss_assert_same(
	array(
		'real'  => array( 'index.php', 'twentytwentyfive' ),
		'links' => array( 'newspack-theme' ),
	),
	Sync_Runner::live_top_levels( $live_response, 'themes' ),
	'symlinked themes are reported per directory'
);

$older_live = array( 'plugins' => $live_response['plugins'] );
ss_assert_same(
	array(
		'real'  => array( 'akismet' ),
		'links' => array( 'newspack-sponsors' ),
	),
	Sync_Runner::live_top_levels( $older_live, 'plugins' ),
	'a live site that predates the layout report still tells symlinked plugins apart'
);
ss_assert_same(
	array(
		'real'  => null,
		'links' => array(),
	),
	Sync_Runner::live_top_levels( $older_live, 'themes' ),
	'that older live site reports nothing about themes'
);
ss_assert_same(
	array(
		'real'  => null,
		'links' => array(),
	),
	Sync_Runner::live_top_levels( 'not-an-array', 'plugins' ),
	'a malformed response yields no layout'
);
ss_assert_same(
	array(
		'real'  => array(),
		'links' => array( 'ok' ),
	),
	Sync_Runner::live_top_levels(
		array(
			'tops' => array(
				'plugins' => array(
					'dirs'  => 'nope',
					'links' => array( 'ok', 7, null, '', 'ok' ),
				),
			),
		),
		'plugins'
	),
	'only non-empty string names are taken from the reported layout'
);

ss_test_section( 'Kept entries: hidden from the sync of their own directory' );

$in_scope = new ReflectionMethod( Sync_Runner::class, 'file_in_scope' );
if ( PHP_VERSION_ID < 80100 ) {
	$in_scope->setAccessible( true );
}
$scope_job = array(
	'options'      => array(
		'plugins'       => null,
		'uploads_scope' => null,
	),
	'kept_plugins' => array( 'dev-tool', 'hello.php' ),
	'kept_themes'  => array( 'my-theme' ),
);
ss_assert_same( false, $in_scope->invoke( null, array( 'p' => 'dev-tool/dev-tool.php' ), 'plugins', $scope_job, array() ), 'a kept plugin directory is out of scope' );
ss_assert_same( false, $in_scope->invoke( null, array( 'p' => 'hello.php' ), 'plugins', $scope_job, array() ), 'a kept single-file plugin is out of scope' );
ss_assert_same( false, $in_scope->invoke( null, array( 'p' => 'my-theme/style.css' ), 'themes', $scope_job, array() ), 'a kept theme is out of scope' );
ss_assert_same( true, $in_scope->invoke( null, array( 'p' => 'akismet/akismet.php' ), 'plugins', $scope_job, array() ), 'plugins live delivers stay in scope' );
ss_assert_same( true, $in_scope->invoke( null, array( 'p' => 'dev-tool/style.css' ), 'themes', $scope_job, array() ), 'kept plugin names do not hide a theme of the same name' );
ss_assert_same( true, $in_scope->invoke( null, array( 'p' => 'my-theme/my-theme.php' ), 'plugins', $scope_job, array() ), 'kept theme names do not hide a plugin of the same name' );
ss_assert_same( true, $in_scope->invoke( null, array( 'p' => 'dev-tool/dev-tool.php' ), 'mu-plugins', $scope_job, array() ), 'mu-plugins/ has no kept entries' );
$scope_selected                       = $scope_job;
$scope_selected['options']['plugins'] = array( 'dev-tool' );
ss_assert_same( false, $in_scope->invoke( null, array( 'p' => 'dev-tool/dev-tool.php' ), 'plugins', $scope_selected, array() ), 'a kept plugin stays out of scope even when a selection names it' );
unset( $scope_job['kept_plugins'], $scope_job['kept_themes'] );
ss_assert_same( true, $in_scope->invoke( null, array( 'p' => 'dev-tool/dev-tool.php' ), 'plugins', $scope_job, array() ), 'a job that kept nothing syncs every entry' );

ss_test_section( 'Kept entries: setting entries aside before plugins/ is staged' );

ss_test_reset_options();
$plan_root    = sys_get_temp_dir() . '/ss-kept-plan-' . bin2hex( random_bytes( 4 ) );
$plan_plugins = $plan_root . '/plugins';
foreach ( array( 'akismet', 'both-sides', 'dev-tool', 'legacy-live', 'newspack-sponsors', 'stop-emails' ) as $plan_dir ) {
	mkdir( $plan_plugins . '/' . $plan_dir, 0755, true );
	file_put_contents( $plan_plugins . '/' . $plan_dir . '/' . $plan_dir . '.php', '<?php' );
}
file_put_contents( $plan_plugins . '/hello.php', '<?php' );
file_put_contents( $plan_plugins . '/.DS_Store', 'x' );
mkdir( $plan_root . '/elsewhere', 0755, true );
// A Manager branch build: real directory, previous channel symlink beside it.
symlink( $plan_root . '/elsewhere', $plan_plugins . '/newspack-sponsors--symlink' );
// A dev checkout the sandbox links, which live ships as a real directory.
symlink( $plan_root . '/elsewhere', $plan_plugins . '/linked-dev' );

// Live delivers akismet, index.php, linked-dev, and both-sides: a plugin the
// sandbox also installed on its own and no sync has written yet.
$plan_manifest = $plan_root . '/manifest-plugins.ndjson';
file_put_contents(
	$plan_manifest,
	'{"p":"akismet/akismet.php","s":5,"m":1}' . "\n"
	. '{"p":"both-sides/both-sides.php","s":9,"m":1}' . "\n"
	. '{"p":"index.php","s":28,"m":1}' . "\n"
	. '{"p":"linked-dev/linked-dev.php","s":5,"m":1}' . "\n"
);
$plan_live_links = array( 'newspack-sponsors' );

$plan_set_aside = new ReflectionMethod( Sync_Runner::class, 'plan_set_aside' );
$plan_kept      = new ReflectionMethod( Sync_Runner::class, 'plan_kept_entries' );
if ( PHP_VERSION_ID < 80100 ) {
	$plan_set_aside->setAccessible( true );
	$plan_kept->setAccessible( true );
}

update_option( Sync_Runner::OPT_SYNCED_PLUGINS, array( 'akismet', 'legacy-live', 'newspack-sponsors' ) );
$plan_job = ss_kept_test_job( null );
$plan     = $plan_set_aside->invokeArgs( null, array( &$plan_job, 'plugins', $plan_plugins, $plan_live_links ) );
ss_assert_same(
	array( 'both-sides', 'dev-tool', 'hello.php', 'newspack-sponsors' ),
	$plan['set_aside'],
	'every entry that may be sandbox-only is set aside: not recorded as synced, or a real directory live runs as a symlink'
);
ss_assert_same(
	array( '.DS_Store', 'akismet', 'legacy-live', 'linked-dev', 'newspack-sponsors--symlink' ),
	$plan['staged_tops'],
	'only what live may replace or remove is staged; set-aside and protected entries are not'
);
ss_assert_same(
	array( '.DS_Store', 'akismet', 'both-sides', 'dev-tool', 'hello.php', 'legacy-live', 'newspack-sponsors', 'stop-emails' ),
	$plan_job['planned_plugins'],
	'the job remembers which entries existed when the plan was made'
);

ss_test_section( 'Kept entries: deciding what plugins/ keeps once live has answered' );

$plan_result = $plan_kept->invokeArgs( null, array( &$plan_job, 'plugins', $plan_manifest, $plan ) );
ss_assert_same( array( 'dev-tool', 'hello.php', 'newspack-sponsors' ), $plan_result, 'sandbox-only plugins and the branch build are kept; the plugin live delivers, the one it removed, the stray file and the protected plugin are not' );
ss_assert_same( array( 'dev-tool', 'hello.php', 'newspack-sponsors' ), $plan_job['kept_plugins'], 'the job carries the kept plugins' );
ss_assert_same( array( 'dev-tool', 'hello.php', 'newspack-sponsors', 'stop-emails' ), $plan_job['in_place_plugins'], 'kept and protected entries are the ones activation leaves where they are' );
ss_assert_same( array( 'akismet', 'both-sides', 'index.php' ), $plan_job['synced_plugins_next'], 'the next record is what live delivers, without the names the sandbox holds as symlinks' );
$plan_log = implode( "\n", array_column( $plan_job['log'], 1 ) );
ss_assert( false !== strpos( $plan_log, 'dev-tool, hello.php, newspack-sponsors' ), 'the job log names what is kept' );
ss_assert( 1 === preg_match( '/^(?!.*dev-tool).*both-sides.*$/m', $plan_log ), 'the job log says, on its own line, that live\'s copy replaces a plugin the sandbox had on its own' );
ss_assert_same( array( 'akismet', 'legacy-live', 'newspack-sponsors' ), get_option( Sync_Runner::OPT_SYNCED_PLUGINS ), 'planning never rewrites the stored record' );

$selection_job  = ss_kept_test_job( array( 'akismet' ) );
$selection_plan = $plan_set_aside->invokeArgs( null, array( &$selection_job, 'plugins', $plan_plugins, $plan_live_links ) );
$plan_kept->invokeArgs( null, array( &$selection_job, 'plugins', $plan_manifest, $selection_plan ) );
ss_assert_same( array( 'akismet', 'linked-dev', 'newspack-sponsors--symlink' ), $selection_plan['staged_tops'], 'with a selection only the selected plugins are staged' );
ss_assert_same( array( 'dev-tool', 'hello.php', 'newspack-sponsors' ), $selection_job['kept_plugins'], 'a selection does not change which plugins count as kept' );
ss_assert_same(
	array( '.DS_Store', 'both-sides', 'dev-tool', 'hello.php', 'legacy-live', 'newspack-sponsors', 'stop-emails' ),
	$selection_job['in_place_plugins'],
	'every unselected entry stays where it is, including one live delivers and one live removed'
);
ss_assert_same( array( 'akismet', 'legacy-live', 'newspack-sponsors' ), $selection_job['synced_plugins_next'], 'a selection run updates the record for the selected entries only' );
ss_assert( false === strpos( implode( "\n", array_column( $selection_job['log'], 1 ) ), 'both-sides' ), 'an unselected plugin is not announced as replaced' );

delete_option( Sync_Runner::OPT_SYNCED_PLUGINS );
$first_job  = ss_kept_test_job( array( 'akismet' ) );
$first_plan = $plan_set_aside->invokeArgs( null, array( &$first_job, 'plugins', $plan_plugins, $plan_live_links ) );
$plan_kept->invokeArgs( null, array( &$first_job, 'plugins', $plan_manifest, $first_plan ) );
ss_assert_same( array( 'newspack-sponsors' ), $first_plan['set_aside'], 'without a record only the build over a package live runs as a symlink is set aside' );
ss_assert_same( array( 'newspack-sponsors' ), $first_job['kept_plugins'], 'first sync without a record: only the branch build is kept' );
ss_assert_same(
	array( 'akismet', 'both-sides', 'dev-tool', 'hello.php', 'legacy-live', 'stop-emails' ),
	$first_job['synced_plugins_next'],
	'first sync with a selection: everything already on the sandbox counts as synced, the branch build does not'
);

ss_test_section( 'Kept entries: planning themes/' );

$plan_themes = $plan_root . '/themes';
foreach ( array( 'live-theme', 'my-theme', 'newspack-theme', 'old-theme' ) as $plan_dir ) {
	mkdir( $plan_themes . '/' . $plan_dir, 0755, true );
	file_put_contents( $plan_themes . '/' . $plan_dir . '/style.css', '/* Theme Name: ' . $plan_dir . ' */' );
}
file_put_contents( $plan_themes . '/scratch.php', '<?php' );
symlink( $plan_root . '/elsewhere', $plan_themes . '/newspack-theme--symlink' );
$themes_manifest = $plan_root . '/manifest-themes.ndjson';
file_put_contents(
	$themes_manifest,
	'{"p":"index.php","s":28,"m":1}' . "\n"
	. '{"p":"live-theme/style.css","s":30,"m":1}' . "\n"
);
update_option( Sync_Runner::OPT_SYNCED_THEMES, array( 'live-theme', 'old-theme' ) );
$themes_job  = ss_kept_test_job( array( 'akismet' ) );
$themes_plan = $plan_set_aside->invokeArgs( null, array( &$themes_job, 'themes', $plan_themes, array( 'newspack-theme' ) ) );
$plan_kept->invokeArgs( null, array( &$themes_job, 'themes', $themes_manifest, $themes_plan ) );
ss_assert_same( array( 'live-theme', 'newspack-theme--symlink', 'old-theme', 'scratch.php' ), $themes_plan['staged_tops'], 'a plugin selection does not narrow what themes/ stages' );
ss_assert_same( array( 'my-theme', 'newspack-theme' ), $themes_job['kept_themes'], 'a sandbox-only theme and a branch-built theme are kept; a theme live removed and a stray PHP file are not' );
ss_assert_same( array( 'my-theme', 'newspack-theme' ), $themes_job['in_place_themes'], 'kept themes stay where they are' );
ss_assert_same( array( 'index.php', 'live-theme' ), $themes_job['synced_themes_next'], 'a plugin selection does not narrow the themes record' );

ss_test_section( 'Kept entries: a sync that fails before activation leaves them in place' );

$swap_stage_dir  = $plan_root . '/.stage-plugins';
$swap_backup_dir = $plan_root . '/.bak-plugins';
$clone_tree      = new ReflectionMethod( Sync_Runner::class, 'clone_tree_for_stage' );
$activate        = new ReflectionMethod( Sync_Runner::class, 'activate_dir_by_contents' );
$left_in_place   = new ReflectionMethod( Sync_Runner::class, 'entries_left_in_place' );
$discard_stage   = new ReflectionMethod( Sync_Runner::class, 'discard_file_stage' );
$rollback_dirs   = new ReflectionMethod( Sync_Runner::class, 'rollback_file_dirs' );
if ( PHP_VERSION_ID < 80100 ) {
	$clone_tree->setAccessible( true );
	$activate->setAccessible( true );
	$left_in_place->setAccessible( true );
	$discard_stage->setAccessible( true );
	$rollback_dirs->setAccessible( true );
}
update_option( Sync_Runner::OPT_SYNCED_PLUGINS, array( 'akismet', 'legacy-live', 'newspack-sponsors' ) );

mkdir( $swap_stage_dir, 0755 );
$clone_state = null;
$clone_tree->invokeArgs( null, array( $plan_plugins, $swap_stage_dir, &$plan_job, &$clone_state, $plan['staged_tops'] ) );
ss_assert( is_file( $swap_stage_dir . '/akismet/akismet.php' ) && is_file( $swap_stage_dir . '/legacy-live/legacy-live.php' ) && is_link( $swap_stage_dir . '/linked-dev' ), 'what live may replace or remove is cloned into the stage' );
ss_assert(
	! file_exists( $swap_stage_dir . '/dev-tool' ) && ! file_exists( $swap_stage_dir . '/hello.php' ) && ! file_exists( $swap_stage_dir . '/newspack-sponsors' )
	&& ! file_exists( $swap_stage_dir . '/both-sides' ) && ! file_exists( $swap_stage_dir . '/stop-emails' ),
	'entries the sync leaves alone, or takes whole from live, are never copied'
);
$aborted_job                 = $plan_job;
$aborted_job['working_dirs'] = array(
	'plugins' => array(
		'target'     => $plan_plugins,
		'stage'      => $swap_stage_dir,
		'backup'     => $swap_backup_dir,
		'activated'  => false,
		'had_target' => true,
	),
);
$discard_stage->invokeArgs( null, array( &$aborted_job, 'plugins' ) );
ss_assert( ! file_exists( $swap_stage_dir ), 'the abandoned stage is removed' );
ss_assert(
	is_file( $plan_plugins . '/dev-tool/dev-tool.php' ) && is_file( $plan_plugins . '/hello.php' )
	&& is_file( $plan_plugins . '/newspack-sponsors/newspack-sponsors.php' ) && is_link( $plan_plugins . '/newspack-sponsors--symlink' ),
	'kept plugins and the branch build are still in the active tree'
);
ss_assert( is_file( $plan_plugins . '/legacy-live/legacy-live.php' ), 'the plugin live removed is still there: nothing was activated' );
ss_assert_same( array( 'akismet', 'legacy-live', 'newspack-sponsors' ), get_option( Sync_Runner::OPT_SYNCED_PLUGINS ), 'an abandoned sync leaves the record as it was' );

ss_test_section( 'Kept entries: left where they are by the content swap' );

mkdir( $swap_stage_dir, 0755 );
$clone_state = null;
$clone_tree->invokeArgs( null, array( $plan_plugins, $swap_stage_dir, &$plan_job, &$clone_state, $plan['staged_tops'] ) );

$swap_links     = Files::symlink_paths( $plan_plugins );
$swap_predicate = function ( $file ) use ( $in_scope, $plan_job, $swap_links ) {
	return $in_scope->invoke( null, $file, 'plugins', $plan_job, $swap_links );
};
$swap_fetched   = array();
$swap_deleted   = array();
Files::diff_iterators(
	Files::filter_iterator( Files::read_ndjson_manifest( $plan_manifest ), $swap_predicate ),
	Files::filter_iterator( Files::walk( $plan_plugins, false, '', $plan['staged_tops'] ), $swap_predicate ),
	function ( $file ) use ( &$swap_fetched ) {
		$swap_fetched[] = $file['p'];
		return true;
	},
	function ( $path ) use ( &$swap_deleted ) {
		$swap_deleted[] = $path;
	}
);
ss_assert_same(
	array( 'akismet/akismet.php', 'both-sides/both-sides.php', 'index.php' ),
	$swap_fetched,
	'a plugin live delivers over the sandbox\'s own copy is fetched whole; nothing of a kept entry or behind a sandbox symlink is'
);
ss_assert_same( array( '.DS_Store', 'legacy-live/legacy-live.php' ), $swap_deleted, 'the plugin live removed and the stray file are deleted; kept and protected entries are not' );

// What the transfer steps would do to the stage.
Files::delete_paths( $swap_stage_dir, $swap_deleted );
mkdir( $swap_stage_dir . '/both-sides', 0755 );
file_put_contents( $swap_stage_dir . '/both-sides/both-sides.php', 'from live' );
file_put_contents( $swap_stage_dir . '/index.php', 'from live' );

// What happens on the sandbox while a long sync runs, after the plan.
file_put_contents( $plan_plugins . '/dev-tool/edited-during-sync.txt', 'work in progress' );
$dev_tool_inode = fileinode( $plan_plugins . '/dev-tool' );
mkdir( $plan_plugins . '/late-plugin', 0755 );
file_put_contents( $plan_plugins . '/late-plugin/late-plugin.php', '<?php' );
// A plugin installed during the sync that live happens to deliver as well.
mkdir( $plan_plugins . '/late-live', 0755 );
file_put_contents( $plan_plugins . '/late-live/local.php', '<?php' );
mkdir( $swap_stage_dir . '/late-live', 0755 );
file_put_contents( $swap_stage_dir . '/late-live/from-live.php', 'from live' );
// A linked package replaced by a real build during the sync.
unlink( $plan_plugins . '/linked-dev' );
mkdir( $plan_plugins . '/linked-dev', 0755 );
file_put_contents( $plan_plugins . '/linked-dev/build.php', '<?php' );
symlink( $plan_root . '/elsewhere', $plan_plugins . '/linked-dev--symlink' );

$left = $left_in_place->invoke( null, $plan_job, 'plugins', $plan_plugins, $swap_stage_dir );
ss_assert_same( array( 'late-plugin', 'linked-dev' ), $left['late'], 'entries that appeared after the plan are left alone unless live delivers one of that name' );
ss_assert_same(
	array( 'dev-tool', 'hello.php', 'late-plugin', 'linked-dev', 'newspack-sponsors', 'stop-emails' ),
	$left['leave'],
	'activation leaves kept, protected and late entries where they are'
);

ss_assert_same( true, $activate->invokeArgs( null, array( $plan_plugins, $swap_stage_dir, $swap_backup_dir, $left['leave'] ) ), 'the staged tree activates' );
ss_assert_same( $dev_tool_inode, fileinode( $plan_plugins . '/dev-tool' ), 'a kept plugin is the same directory after the swap, not a copy' );
ss_assert_same( 'work in progress', @file_get_contents( $plan_plugins . '/dev-tool/edited-during-sync.txt' ), 'work done in a kept plugin while the sync ran is still there' );
ss_assert( ! file_exists( $swap_backup_dir . '/dev-tool' ) && ! file_exists( $swap_backup_dir . '/stop-emails' ), 'entries left in place never enter the rollback copy' );
ss_assert( is_file( $plan_plugins . '/hello.php' ), 'a kept single-file plugin is still there' );
ss_assert( is_dir( $plan_plugins . '/newspack-sponsors' ) && ! is_link( $plan_plugins . '/newspack-sponsors' ) && is_file( $plan_plugins . '/newspack-sponsors/newspack-sponsors.php' ), 'the branch build is still a real directory with its files' );
ss_assert( is_link( $plan_plugins . '/newspack-sponsors--symlink' ), 'the branch build keeps its backup symlink beside it' );
ss_assert( is_file( $plan_plugins . '/stop-emails/stop-emails.php' ), 'the protected plugin is untouched' );
ss_assert( is_file( $plan_plugins . '/late-plugin/late-plugin.php' ), 'a plugin installed during the sync is still there' );
ss_assert( is_dir( $plan_plugins . '/linked-dev' ) && ! is_link( $plan_plugins . '/linked-dev' ) && is_file( $plan_plugins . '/linked-dev/build.php' ), 'a build that replaced a linked package during the sync is not swapped back for the link' );
ss_assert( is_file( $plan_plugins . '/late-live/from-live.php' ) && ! file_exists( $plan_plugins . '/late-live/local.php' ), 'a plugin installed during the sync gives way when live delivers one of that name' );
ss_assert_same( 'from live', @file_get_contents( $plan_plugins . '/both-sides/both-sides.php' ), 'live\'s copy replaces a plugin the sandbox had on its own' );
ss_assert( ! file_exists( $plan_plugins . '/legacy-live' ) && ! file_exists( $plan_plugins . '/.DS_Store' ), 'a plugin removed on live and a stray file are gone from the sandbox' );

ss_test_section( 'Kept entries: a sync that fails after activation rolls back around them' );

$failed_job                 = $plan_job;
$failed_job['id']           = 'keptrollback';
$failed_job['working_dirs'] = array(
	'plugins' => array(
		'target'       => $plan_plugins,
		'stage'        => $swap_stage_dir,
		'backup'       => $swap_backup_dir,
		'activated'    => true,
		'had_target'   => true,
		'content_swap' => true,
		'left'         => $left['leave'],
	),
);
ss_assert_same( 1, $rollback_dirs->invokeArgs( null, array( &$failed_job ) ), 'the activated plugins/ tree is rolled back' );
ss_assert_same( $dev_tool_inode, fileinode( $plan_plugins . '/dev-tool' ), 'the rollback does not touch a kept plugin' );
ss_assert_same( 'work in progress', @file_get_contents( $plan_plugins . '/dev-tool/edited-during-sync.txt' ), 'work done in a kept plugin survives the rollback too' );
ss_assert( is_file( $plan_plugins . '/hello.php' ) && is_file( $plan_plugins . '/stop-emails/stop-emails.php' ) && is_file( $plan_plugins . '/late-plugin/late-plugin.php' ), 'the other entries left in place are still there' );
ss_assert( is_file( $plan_plugins . '/newspack-sponsors/newspack-sponsors.php' ) && is_link( $plan_plugins . '/newspack-sponsors--symlink' ) && is_file( $plan_plugins . '/linked-dev/build.php' ), 'both builds and the backup symlink are still in place' );
ss_assert( is_file( $plan_plugins . '/legacy-live/legacy-live.php' ) && is_file( $plan_plugins . '/.DS_Store' ), 'what the failed sync removed is restored' );
ss_assert_same( '<?php', @file_get_contents( $plan_plugins . '/both-sides/both-sides.php' ), 'the sandbox\'s own copy is back in place of live\'s' );
ss_assert( is_file( $plan_plugins . '/late-live/local.php' ) && ! file_exists( $plan_plugins . '/late-live/from-live.php' ), 'the plugin installed during the sync is back as it was' );
ss_assert( ! file_exists( $plan_plugins . '/index.php' ), 'what the failed sync delivered is removed' );
ss_assert( ! file_exists( $swap_backup_dir ), 'the rollback copy is consumed' );
ss_assert_same( array( 'akismet', 'legacy-live', 'newspack-sponsors' ), get_option( Sync_Runner::OPT_SYNCED_PLUGINS ), 'a sync that failed after activation leaves the record as it was' );

ss_test_rmrf( $plan_root );
ss_test_reset_options();

ss_test_section( 'Kept entries: record stored once the sync is final' );

ss_test_reset_options();
$commit_records = new ReflectionMethod( Sync_Runner::class, 'commit_synced_records' );
if ( PHP_VERSION_ID < 80100 ) {
	$commit_records->setAccessible( true );
}
update_option( Sync_Runner::OPT_SYNCED_PLUGINS, array( 'before' ) );
update_option( Sync_Runner::OPT_SYNCED_THEMES, array( 'old-theme' ) );
$commit_records->invoke( null, array( 'synced_plugins_next' => array( 'akismet', 'index.php' ) ) );
ss_assert_same( array( 'akismet', 'index.php' ), get_option( Sync_Runner::OPT_SYNCED_PLUGINS ), 'a finished sync replaces the record of the directory it planned' );
ss_assert_same( array( 'old-theme' ), get_option( Sync_Runner::OPT_SYNCED_THEMES ), 'a directory the sync did not plan keeps its record' );
$commit_records->invoke( null, array( 'synced_themes_next' => array() ) );
ss_assert_same( array(), get_option( Sync_Runner::OPT_SYNCED_THEMES ), 'a sync that delivered nothing stores an empty record, which still counts as one' );

ss_test_section( 'Kept entries: report' );

ss_test_reset_options();
$write_report = new ReflectionMethod( Sync_Runner::class, 'write_report' );
if ( PHP_VERSION_ID < 80100 ) {
	$write_report->setAccessible( true );
}
$report_job = array(
	'id'           => 'abcdef123456',
	'created_at'   => time(),
	'steps'        => array(),
	'imported'     => array(),
	'options'      => array( 'dirs' => array( 'plugins', 'themes' ) ),
	'manifest'     => array( 'site' => array( 'home' => 'https://live.example' ) ),
	'kept_plugins' => array( 'dev-tool', 'hello.php' ),
	'kept_themes'  => array( 'my-theme' ),
);
$write_report->invoke( null, $report_job );
$last_report = get_option( Sync_Runner::OPT_LAST_REPORT );
ss_assert_same( array( 'dev-tool', 'hello.php' ), $last_report['kept_plugins'], 'the report lists kept plugins' );
ss_assert_same( array( 'my-theme' ), $last_report['kept_themes'], 'the report lists kept themes' );
unset( $report_job['kept_plugins'], $report_job['kept_themes'] );
$write_report->invoke( null, $report_job );
$last_report = get_option( Sync_Runner::OPT_LAST_REPORT );
ss_assert_same( array(), $last_report['kept_plugins'], 'a sync that kept nothing reports an empty list rather than no key' );
ss_assert_same( array(), $last_report['kept_themes'], 'the same holds for themes' );
ss_test_reset_options();

ss_test_section( 'Kept entries: preview for the start confirmation' );

$preview_dirs = array(
	WP_CONTENT_DIR . '/plugins/akismet',
	WP_CONTENT_DIR . '/plugins/dev-tool',
	WP_CONTENT_DIR . '/plugins/newspack-sponsors',
	WP_CONTENT_DIR . '/themes/live-theme',
	WP_CONTENT_DIR . '/themes/my-theme',
);
ss_test_rmrf( WP_CONTENT_DIR . '/plugins' );
ss_test_rmrf( WP_CONTENT_DIR . '/themes' );
foreach ( $preview_dirs as $preview_dir ) {
	mkdir( $preview_dir, 0755, true );
}
file_put_contents( WP_CONTENT_DIR . '/plugins/hello.php', '<?php' );
file_put_contents( WP_CONTENT_DIR . '/plugins/notes.txt', 'x' );

ss_assert_same(
	array(
		'plugins' => array( 'newspack-sponsors' ),
		'themes'  => array(),
	),
	Sync_Runner::preview_kept( $live_response ),
	'before the first recorded sync the preview only promises the build live runs as a symlink'
);

ss_assert_same(
	array(
		'plugins' => array( 'dev-tool', 'hello.php' ),
		'themes'  => array( 'live-theme', 'my-theme' ),
	),
	Sync_Runner::preview_first_sync_removals( $live_response ),
	'before the first recorded sync the preview names the sandbox-only entries that sync removes'
);
ss_assert_same(
	array(
		'plugins' => array( 'dev-tool', 'hello.php' ),
		'themes'  => array(),
	),
	Sync_Runner::preview_first_sync_removals( $older_live ),
	'for an older live site the preview names plugins only: it cannot tell which themes live has'
);

update_option( Sync_Runner::OPT_SYNCED_PLUGINS, array( 'akismet', 'gone-from-live' ) );
update_option( Sync_Runner::OPT_SYNCED_THEMES, array( 'live-theme' ) );
ss_assert_same(
	array(
		'plugins' => array(),
		'themes'  => array(),
	),
	Sync_Runner::preview_first_sync_removals( $live_response ),
	'once a record exists there is no first-sync removal to announce'
);
ss_assert_same(
	array(
		'plugins' => array( 'dev-tool', 'hello.php', 'newspack-sponsors' ),
		'themes'  => array( 'my-theme' ),
	),
	Sync_Runner::preview_kept( $live_response ),
	'with a record the preview lists sandbox-only plugins and themes, and no stray non-PHP file'
);
ss_assert_same(
	array(
		'plugins' => array( 'dev-tool', 'hello.php', 'newspack-sponsors' ),
		'themes'  => array(),
	),
	Sync_Runner::preview_kept( $older_live ),
	'an older live site cannot say which themes it has, so the preview promises nothing about themes'
);
ss_assert_same(
	array(
		'plugins' => array(),
		'themes'  => array(),
	),
	Sync_Runner::preview_kept( array( 'plugins' => array(), 'tops' => array( 'plugins' => array( 'dirs' => array( 'akismet', 'dev-tool', 'newspack-sponsors' ), 'files' => array( 'hello.php' ), 'links' => array() ), 'themes' => array( 'dirs' => array( 'live-theme', 'my-theme' ), 'files' => array(), 'links' => array() ) ) ) ),
	'nothing is promised for entries live delivers itself'
);

ss_test_rmrf( WP_CONTENT_DIR . '/plugins' );
ss_test_rmrf( WP_CONTENT_DIR . '/themes' );
ss_test_reset_options();

ss_test_section( 'Kept entries: whose activation a database swap carries' );

$for_activation = new ReflectionMethod( Sync_Runner::class, 'kept_plugins_for_activation' );
$plan_carry     = new ReflectionMethod( Sync_Runner::class, 'plan_activation_carry' );
if ( PHP_VERSION_ID < 80100 ) {
	$for_activation->setAccessible( true );
	$plan_carry->setAccessible( true );
}
ss_assert_same( array( 'planned-kept' ), $for_activation->invoke( null, array( 'kept_plugins' => array( 'planned-kept' ), 'activation_kept' => array( 'other' ) ) ), 'a sync that planned plugins/ carries exactly what that plan kept' );
ss_assert_same( array(), $for_activation->invoke( null, array( 'kept_plugins' => array(), 'activation_kept' => array( 'other' ) ) ), 'a plan that kept nothing carries nothing' );
ss_assert_same( array( 'db-only-kept' ), $for_activation->invoke( null, array( 'activation_kept' => array( 'db-only-kept' ) ) ), 'a sync that leaves plugins/ alone carries what it worked out from live\'s layout' );
ss_assert_same( array(), $for_activation->invoke( null, array() ), 'a job that decided nothing carries nothing' );

// A database-only sync has to reach the same answer a plugins/ sync would.
ss_test_rmrf( WP_CONTENT_DIR . '/plugins' );
foreach ( array( 'akismet', 'dev-tool', 'seo-suite', 'newspack-newsletters', 'stop-emails' ) as $carry_dir ) {
	mkdir( WP_CONTENT_DIR . '/plugins/' . $carry_dir, 0755, true );
}
file_put_contents( WP_CONTENT_DIR . '/plugins/hello.php', '<?php' );
$carry_tops = array(
	'plugins' => array(
		// seo-suite: installed here by hand, and live has it too.
		'real'  => array( 'akismet', 'index.php', 'seo-suite' ),
		// newspack-newsletters: a build here over a package live links.
		'links' => array( 'newspack-newsletters' ),
	),
	'themes'  => array(
		'real'  => array(),
		'links' => array(),
	),
);
update_option( Sync_Runner::OPT_SYNCED_PLUGINS, array( 'akismet', 'newspack-newsletters' ) );
$carry_job = ss_kept_test_job( null );
$plan_carry->invokeArgs( null, array( &$carry_job, $carry_tops ) );
ss_assert_same(
	array( 'dev-tool', 'hello.php', 'newspack-newsletters' ),
	$carry_job['activation_kept'],
	'sandbox-only plugins and a build over a stale record are carried; a plugin live has and the protected one are not'
);
delete_option( Sync_Runner::OPT_SYNCED_PLUGINS );
$carry_first = ss_kept_test_job( null );
$plan_carry->invokeArgs( null, array( &$carry_first, $carry_tops ) );
ss_assert_same( array( 'newspack-newsletters' ), $carry_first['activation_kept'], 'without a record only the build over a package live links is carried, as in a plugins/ sync' );

ss_test_rmrf( WP_CONTENT_DIR . '/plugins' );
ss_test_reset_options();

ss_test_section( 'Kept entries: one-line summary of a report' );

ss_assert_same(
	'kept plugins: dev-tool, hello.php; kept themes: my-theme',
	Sync_Runner::kept_summary(
		array(
			'kept_plugins' => array( 'dev-tool', 'hello.php' ),
			'kept_themes'  => array( 'my-theme' ),
		)
	),
	'the summary names kept plugins and themes'
);
ss_assert_same(
	'kept themes: my-theme',
	Sync_Runner::kept_summary(
		array(
			'kept_plugins' => array(),
			'kept_themes'  => array( 'my-theme' ),
		)
	),
	'a directory that kept nothing is left out'
);
ss_assert_same( '', Sync_Runner::kept_summary( array( 'dirs' => array( 'plugins' ) ) ), 'a report written before kept entries existed has no summary' );
ss_assert_same( '', Sync_Runner::kept_summary( null ), 'a missing report has no summary' );
