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

if ( ! function_exists( 'maybe_serialize' ) ) {
	/** WordPress core's maybe_serialize(), for the values these tests store. */
	function maybe_serialize( $data ) {
		return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/** WordPress core's maybe_unserialize(). */
	function maybe_unserialize( $data ) {
		return is_serialized( $data ) ? @unserialize( trim( $data ) ) : $data;
	}
}

/**
 * The $wpdb calls the activation carry makes, run against an in-memory SQLite
 * database: its statements execute for real instead of being matched as text.
 */
final class SS_Test_Sqlite_Wpdb {
	/** @var string */
	public $options = 'wp_options';

	/** @var string */
	public $last_error = '';

	/** @var PDO */
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
	}

	/**
	 * Fills %i (identifier) and %s (string) placeholders in order, as
	 * wpdb::prepare() does.
	 *
	 * @param string $query   Statement with placeholders.
	 * @param mixed  ...$args One value per placeholder.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$pdo = $this->pdo;
		return (string) preg_replace_callback(
			'/%[is]/',
			function ( $match ) use ( &$args, $pdo ) {
				$value = (string) array_shift( $args );
				return '%i' === $match[0] ? '`' . str_replace( '`', '``', $value ) . '`' : $pdo->quote( $value );
			},
			$query
		);
	}

	/**
	 * @param string $sql Statement.
	 * @return string|null First column of the first row, or null when there is none.
	 */
	public function get_var( $sql ) {
		$statement = $this->pdo->query( $sql );
		if ( false === $statement ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() );
			return null;
		}
		$row = $statement->fetch( PDO::FETCH_NUM );
		return false === $row ? null : $row[0];
	}

	/**
	 * @param string $sql Statement.
	 * @return int|false Affected rows, or false when the database refuses it.
	 */
	public function query( $sql ) {
		$affected = $this->pdo->exec( $sql );
		if ( false === $affected ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() );
			return false;
		}
		return $affected;
	}
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

ss_assert_same(
	array( 'akismet', 'hello.php' ),
	Sync_Runner::kept_candidates(
		'plugins',
		array(
			'dirs'  => array( 'akismet' ),
			'files' => array( 'hello.php', 'index.php', 'notes.txt' ),
			'links' => array( 'linked-package' ),
		)
	),
	'a directory or a single PHP file can be a plugin of the sandbox\'s own; the index.php that guards the directory, a stray file and a symlink cannot'
);
ss_assert_same(
	array( 'my-theme' ),
	Sync_Runner::kept_candidates(
		'themes',
		array(
			'dirs'  => array( 'my-theme' ),
			'files' => array( 'index.php', 'scratch.php' ),
			'links' => array(),
		)
	),
	'only a directory can be a theme of the sandbox\'s own'
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
$real_entries    = new ReflectionMethod( Sync_Runner::class, 'real_entries' );
$discard_stage   = new ReflectionMethod( Sync_Runner::class, 'discard_file_stage' );
$rollback_dirs   = new ReflectionMethod( Sync_Runner::class, 'rollback_file_dirs' );
if ( PHP_VERSION_ID < 80100 ) {
	$clone_tree->setAccessible( true );
	$activate->setAccessible( true );
	$left_in_place->setAccessible( true );
	$real_entries->setAccessible( true );
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
$promoted = $real_entries->invoke( null, $swap_stage_dir );
ss_assert_same( array( 'akismet', 'both-sides', 'index.php', 'late-live' ), $promoted, 'before the swap the job notes the real entries it is about to promote, not the symlink stand-ins' );
ss_assert_same( array(), $real_entries->invoke( null, $plan_root . '/no-such-stage' ), 'a stage that is not there promotes nothing' );

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

// What happens on the sandbox between the swap and the failure.
mkdir( $plan_plugins . '/after-swap', 0755 );
file_put_contents( $plan_plugins . '/after-swap/after-swap.php', '<?php' );
file_put_contents( $plan_plugins . '/after-swap.php', '<?php' );
// A plugin the sync removed, installed again before the failure.
mkdir( $plan_plugins . '/legacy-live', 0755 );
file_put_contents( $plan_plugins . '/legacy-live/reinstalled.php', '<?php' );

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
		'promoted'     => $promoted,
	),
);
ss_assert_same( 1, $rollback_dirs->invokeArgs( null, array( &$failed_job ) ), 'the activated plugins/ tree is rolled back' );
ss_assert( is_file( $plan_plugins . '/after-swap/after-swap.php' ) && is_file( $plan_plugins . '/after-swap.php' ), 'plugins installed between the swap and the failure survive the rollback' );
ss_assert( is_file( $plan_plugins . '/legacy-live/legacy-live.php' ) && ! file_exists( $plan_plugins . '/legacy-live/reinstalled.php' ), 'a plugin installed again after the sync removed it gives way to the copy the rollback restores' );
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

ss_test_section( 'Kept entries: a swap or a rollback that cannot finish destroys nothing' );

// Both cases need a rename the filesystem refuses, which a read-only
// directory gives every user but root.
$undo_is_root = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
ss_assert( ! $undo_is_root || false === getenv( 'CI' ), 'CI does not run the suite as root, so the refused renames below happen there' );
if ( $undo_is_root ) {
	echo "  SKIPPED: running as root, for whom a read-only directory refuses nothing.\n";
} else {
	$undo_root   = $plan_root . '/undo';
	$undo_target = $undo_root . '/plugins';
	$undo_stage  = $undo_root . '/.stage';
	$undo_backup = $undo_root . '/.bak';
	$undo_seed   = function () use ( $undo_root, $undo_target, $undo_stage ) {
		ss_test_rmrf( $undo_root );
		foreach ( array( $undo_target . '/old-plugin', $undo_target . '/kept-plugin', $undo_stage . '/new-plugin', $undo_stage . '/old-plugin', $undo_root . '/elsewhere' ) as $undo_dir ) {
			mkdir( $undo_dir, 0755, true );
		}
		file_put_contents( $undo_target . '/old-plugin/old-plugin.php', 'before the sync' );
		file_put_contents( $undo_target . '/kept-plugin/kept-plugin.php', 'sandbox only' );
		file_put_contents( $undo_stage . '/old-plugin/old-plugin.php', 'from live' );
		file_put_contents( $undo_stage . '/new-plugin/new-plugin.php', 'from live' );
		file_put_contents( $undo_stage . '/aaa-new.php', 'from live' );
		symlink( $undo_root . '/elsewhere', $undo_target . '/linked-package' );
	};

	// The first staged entry is promoted, the second refuses to move: a
	// read-only directory cannot change parents.
	$undo_seed();
	chmod( $undo_stage . '/new-plugin', 0555 );
	$undo_failed = $activate->invokeArgs( null, array( $undo_target, $undo_stage, $undo_backup, array( 'kept-plugin' ) ) );
	chmod( $undo_stage . '/new-plugin', 0755 );
	ss_assert( is_wp_error( $undo_failed ) && 'file_activate' === $undo_failed->get_error_code(), 'a swap that cannot promote every staged entry reports the failure' );
	ss_assert_same( 'before the sync', @file_get_contents( $undo_target . '/old-plugin/old-plugin.php' ), 'the entries it had moved away are back' );
	ss_assert( ! file_exists( $undo_target . '/aaa-new.php' ) && ! file_exists( $undo_target . '/new-plugin' ), 'nothing of the stage is left in the directory, not even the entry it had already promoted' );
	ss_assert_same( 'sandbox only', @file_get_contents( $undo_target . '/kept-plugin/kept-plugin.php' ), 'the entry left in place is untouched' );
	ss_assert( is_link( $undo_target . '/linked-package' ), 'and so is the symlink' );
	ss_assert( ! file_exists( $undo_backup ), 'the rollback copy is consumed once everything is back' );

	// The swap went through; the rollback copy then refuses to give its
	// entries back.
	$undo_seed();
	$undo_promoted = $real_entries->invoke( null, $undo_stage );
	ss_assert_same( true, $activate->invokeArgs( null, array( $undo_target, $undo_stage, $undo_backup, array( 'kept-plugin' ) ) ), 'the swap goes through' );
	$undo_job = array(
		'id'           => 'keptundofail',
		'log'          => array(),
		'working_dirs' => array(
			'plugins' => array(
				'target'       => $undo_target,
				'stage'        => $undo_stage,
				'backup'       => $undo_backup,
				'activated'    => true,
				'had_target'   => true,
				'content_swap' => true,
				'left'         => array( 'kept-plugin' ),
				'promoted'     => $undo_promoted,
			),
		),
	);
	chmod( $undo_backup, 0555 );
	$rollback_dirs->invokeArgs( null, array( &$undo_job ) );
	chmod( $undo_backup, 0755 );
	ss_assert_same( 'before the sync', @file_get_contents( $undo_backup . '/old-plugin/old-plugin.php' ), 'a rollback that cannot put an entry back keeps the rollback copy, the one place that entry still exists' );
	$undo_log = implode( "\n", array_column( $undo_job['log'], 1 ) );
	ss_assert( false !== strpos( $undo_log, 'ROLLBACK OF plugins/ INCOMPLETE' ) && false !== strpos( $undo_log, 'are kept in ' . $undo_backup ), 'the job log says the rollback of that directory is incomplete, and where the entries are' );
	ss_assert_same( 'sandbox only', @file_get_contents( $undo_target . '/kept-plugin/kept-plugin.php' ), 'the entry left in place is untouched by the failed rollback' );

	// A swap made before promoted entries were noted: its rollback has no
	// such list, so it removes everything the swap did not leave in place.
	$undo_seed();
	ss_assert_same( true, $activate->invokeArgs( null, array( $undo_target, $undo_stage, $undo_backup, array( 'kept-plugin' ) ) ), 'the swap goes through' );
	mkdir( $undo_target . '/after-swap', 0755 );
	$undo_legacy_job = array(
		'id'           => 'keptundolegacy',
		'log'          => array(),
		'working_dirs' => array(
			'plugins' => array(
				'target'       => $undo_target,
				'stage'        => $undo_stage,
				'backup'       => $undo_backup,
				'activated'    => true,
				'had_target'   => true,
				'content_swap' => true,
				'left'         => array( 'kept-plugin' ),
			),
		),
	);
	$rollback_dirs->invokeArgs( null, array( &$undo_legacy_job ) );
	ss_assert_same( 'before the sync', @file_get_contents( $undo_target . '/old-plugin/old-plugin.php' ), 'a rollback without the list still puts back what the swap moved away' );
	ss_assert( ! file_exists( $undo_target . '/new-plugin' ) && ! file_exists( $undo_target . '/aaa-new.php' ) && ! file_exists( $undo_target . '/after-swap' ), 'and removes every entry the swap did not leave in place' );
	ss_assert_same( 'sandbox only', @file_get_contents( $undo_target . '/kept-plugin/kept-plugin.php' ), 'the entry left in place is untouched by it' );
	ss_assert_same( array(), $undo_legacy_job['log'], 'a rollback that finishes logs nothing of its own' );
}

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
// Every plugins/ directory has one, and an older live site, which lists
// plugins only, never mentions it.
file_put_contents( WP_CONTENT_DIR . '/plugins/index.php', '<?php // Silence is golden.' );

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
		'themes'  => null,
	),
	Sync_Runner::preview_first_sync_removals( $older_live ),
	'for an older live site the preview names plugins only: it cannot tell which themes live has, and says that some may go rather than none'
);
ss_assert_same(
	array(
		'plugins' => null,
		'themes'  => null,
	),
	Sync_Runner::preview_first_sync_removals( new WP_Error( 'http_500', 'HTTP 500 from the source' ) ),
	'when live does not answer at all, neither directory can be named'
);

$first_sync_options = array(
	'dirs'    => array( 'plugins', 'mu-plugins', 'themes', 'uploads' ),
	'plugins' => null,
);
ss_assert_same( array( 'plugins', 'themes' ), Sync_Runner::first_sync_dirs( $first_sync_options ), 'a sync of plugins/ and themes/ with no earlier sync recorded still removes sandbox-only entries from both' );
ss_assert_same( array( 'themes' ), Sync_Runner::first_sync_dirs( array_merge( $first_sync_options, array( 'plugins' => array( 'akismet' ) ) ) ), 'a per-plugin selection leaves every other plugin alone' );
ss_assert_same( array( 'plugins' ), Sync_Runner::first_sync_dirs( array( 'dirs' => array( 'plugins', 'uploads' ) ) ), 'a directory the sync does not touch loses nothing' );
ss_assert_same( array(), Sync_Runner::first_sync_dirs( array( 'dirs' => array() ) ), 'a database-only sync removes no files' );

ss_assert_same(
	array(
		'No earlier sync is recorded for plugins/, so this one still removes the plugins only this sandbox has: dev-tool, hello.php.',
		'No earlier sync is recorded for themes/, so this one still removes the themes only this sandbox has: live-theme, my-theme.',
	),
	Sync_Runner::first_sync_removal_lines( $live_response, array( 'plugins', 'themes' ) ),
	'the command line names what a first sync removes'
);
ss_assert_same(
	array( 'No earlier sync is recorded for themes/, so this one still removes the themes only this sandbox has: live-theme, my-theme.' ),
	Sync_Runner::first_sync_removal_lines( $live_response, array( 'themes' ) ),
	'only the directories asked about are named'
);
ss_assert_same(
	array(
		'No earlier sync is recorded for plugins/, so this one still removes the plugins only this sandbox has: dev-tool, hello.php.',
		'No earlier sync is recorded for themes/, so this one still removes any themes only this sandbox has. Live\'s themes could not be listed, so they cannot be named here.',
	),
	Sync_Runner::first_sync_removal_lines( $older_live, array( 'plugins', 'themes' ) ),
	'where live\'s directory cannot be listed, the line says that any such entries go, without claiming there are some'
);
ss_assert_same(
	array(),
	Sync_Runner::first_sync_removal_lines(
		array(
			'plugins' => array(),
			'tops'    => array(
				'plugins' => array(
					'dirs'  => array( 'akismet', 'dev-tool', 'newspack-sponsors' ),
					'files' => array( 'hello.php' ),
					'links' => array(),
				),
				'themes'  => array(
					'dirs'  => array( 'live-theme', 'my-theme' ),
					'files' => array(),
					'links' => array(),
				),
			),
		),
		array( 'plugins', 'themes' )
	),
	'a first sync that removes nothing has nothing to say'
);

// A themes directory that holds only a linked theme has nothing to lose.
ss_test_rmrf( WP_CONTENT_DIR . '/themes' );
mkdir( WP_CONTENT_DIR . '/themes', 0755, true );
symlink( sys_get_temp_dir(), WP_CONTENT_DIR . '/themes/linked-theme' );
$older_live_removals = Sync_Runner::preview_first_sync_removals( $older_live );
ss_assert_same( array(), $older_live_removals['themes'], 'a directory with no entry of its own loses nothing, whatever live can or cannot list' );
unlink( WP_CONTENT_DIR . '/themes/linked-theme' );
foreach ( array( 'live-theme', 'my-theme' ) as $preview_theme ) {
	mkdir( WP_CONTENT_DIR . '/themes/' . $preview_theme, 0755, true );
}

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
ss_assert_same( array(), Sync_Runner::first_sync_dirs( $first_sync_options ), 'once both directories have a record, no sync is a first one' );
ss_assert_same( array(), Sync_Runner::first_sync_removal_lines( $older_live, array( 'plugins', 'themes' ) ), 'and nothing is announced, whatever live can or cannot list' );
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

$for_activation   = new ReflectionMethod( Sync_Runner::class, 'kept_plugins_for_activation' );
$plan_carry       = new ReflectionMethod( Sync_Runner::class, 'plan_activation_carry' );
$carry_candidates = new ReflectionMethod( Sync_Runner::class, 'activation_carry_candidates' );
$live_answered    = new ReflectionMethod( Sync_Runner::class, 'live_answered_unusably' );
if ( PHP_VERSION_ID < 80100 ) {
	$for_activation->setAccessible( true );
	$plan_carry->setAccessible( true );
	$carry_candidates->setAccessible( true );
	$live_answered->setAccessible( true );
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
file_put_contents( WP_CONTENT_DIR . '/plugins/index.php', '<?php // Silence is golden.' );
file_put_contents( WP_CONTENT_DIR . '/plugins/notes.txt', 'x' );
symlink( sys_get_temp_dir(), WP_CONTENT_DIR . '/plugins/linked-package' );

$carry_scope_job = array(
	'options'  => array(
		'db'                 => true,
		'excluded_tables'    => array(),
		'schema_only_tables' => array(),
	),
	'manifest' => array(
		'site'   => array( 'prefix' => 'live_' ),
		'tables' => array(
			array( 'name' => 'live_options' ),
			array( 'name' => 'live_posts' ),
		),
	),
);
$carry_names     = $carry_candidates->invoke( null, $carry_scope_job );
ss_assert_same(
	array( 'akismet', 'dev-tool', 'hello.php', 'newspack-newsletters', 'seo-suite' ),
	$carry_names,
	'a swap that replaces the options table may have to carry any real plugin of the sandbox: not a linked package, the protected plugin or a stray file'
);
$carry_excluded                               = $carry_scope_job;
$carry_excluded['options']['excluded_tables'] = array( 'options' );
ss_assert_same( array(), $carry_candidates->invoke( null, $carry_excluded ), 'a sync that leaves the options table alone keeps the sandbox\'s activation list as it is, so it has nothing to carry' );
$carry_no_db                  = $carry_scope_job;
$carry_no_db['options']['db'] = false;
ss_assert_same( array(), $carry_candidates->invoke( null, $carry_no_db ), 'neither has a sync without the database' );

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
$plan_carry->invokeArgs( null, array( &$carry_job, $carry_tops, $carry_names ) );
ss_assert_same(
	array( 'dev-tool', 'hello.php', 'newspack-newsletters' ),
	$carry_job['activation_kept'],
	'sandbox-only plugins and a build over a stale record are carried; a plugin live has is not'
);
ss_assert_same( array(), $carry_job['log'], 'a layout live did report is not logged about' );
delete_option( Sync_Runner::OPT_SYNCED_PLUGINS );
$carry_first = ss_kept_test_job( null );
$plan_carry->invokeArgs( null, array( &$carry_first, $carry_tops, $carry_names ) );
ss_assert_same( array( 'newspack-newsletters' ), $carry_first['activation_kept'], 'without a record only the build over a package live links is carried, as in a plugins/ sync' );

// Live's layout only refines the answer. When live answers the request with
// an error, the database sync goes on and the sandbox's own record decides.
update_option( Sync_Runner::OPT_SYNCED_PLUGINS, array( 'akismet', 'newspack-newsletters' ) );
$carry_blind = ss_kept_test_job( null );
$plan_carry->invokeArgs( null, array( &$carry_blind, new WP_Error( 'http_500', 'HTTP 500 from the source' ), $carry_names ) );
ss_assert_same( array( 'dev-tool', 'hello.php', 'seo-suite' ), $carry_blind['activation_kept'], 'when live does not report its layout, the plugins no sync has recorded are carried' );
ss_assert( false !== strpos( implode( "\n", array_column( $carry_blind['log'], 1 ) ), 'HTTP 500 from the source' ), 'the job log says that live did not report it, and why' );
delete_option( Sync_Runner::OPT_SYNCED_PLUGINS );
$carry_blind_first = ss_kept_test_job( null );
$plan_carry->invokeArgs( null, array( &$carry_blind_first, new WP_Error( 'http_500', 'HTTP 500 from the source' ), $carry_names ) );
ss_assert_same( array(), $carry_blind_first['activation_kept'], 'with neither a record nor live\'s layout nothing is carried' );

// Only an answer that cannot be used is passed over. A request that got no
// answer leaves the connection in doubt, and stops the step like any other.
foreach ( array( 'http_500', 'http_404', 'bad_json', 'response_too_large' ) as $carry_code ) {
	ss_assert_same( true, $live_answered->invoke( null, new WP_Error( $carry_code, 'x' ) ), "live answered, unusably: {$carry_code}" );
}
foreach ( array( 'curl', 'curl_multi', 'http_request_failed', 'response_empty', 'not_connected', 'http_request', 'http_5000' ) as $carry_code ) {
	ss_assert_same( false, $live_answered->invoke( null, new WP_Error( $carry_code, 'x' ) ), "no usable sign that live answered: {$carry_code}" );
}

unlink( WP_CONTENT_DIR . '/plugins/linked-package' );
// Only a linked package, the protected plugin and stray files are left.
ss_test_rmrf( WP_CONTENT_DIR . '/plugins' );
mkdir( WP_CONTENT_DIR . '/plugins/stop-emails', 0755, true );
file_put_contents( WP_CONTENT_DIR . '/plugins/index.php', '<?php // Silence is golden.' );
symlink( sys_get_temp_dir(), WP_CONTENT_DIR . '/plugins/linked-package' );
ss_assert_same( array(), $carry_candidates->invoke( null, $carry_scope_job ), 'a sandbox that holds only linked packages and the protected plugin has nothing to carry' );
unlink( WP_CONTENT_DIR . '/plugins/linked-package' );

ss_test_rmrf( WP_CONTENT_DIR . '/plugins' );
ss_test_reset_options();

ss_test_section( 'Kept entries: the activation list written into the shadow options table' );

$carry_has_sqlite = class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true );
ss_assert( $carry_has_sqlite || false === getenv( 'CI' ), 'CI has PDO SQLite, so the statements below run there' );
if ( ! $carry_has_sqlite ) {
	echo "  SKIPPED: PDO SQLite is not available here, so the activation carry statements were not run.\n";
} else {
	$carry_write = new ReflectionMethod( Sync_Runner::class, 'carry_kept_plugin_activation' );
	if ( PHP_VERSION_ID < 80100 ) {
		$carry_write->setAccessible( true );
	}
	$carry_db = new SS_Test_Sqlite_Wpdb();
	foreach ( array( 'wp_options', 'ss_shadow_options' ) as $carry_table ) {
		$carry_db->query( "CREATE TABLE {$carry_table} (option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL)" );
	}
	$carry_set = function ( $table, $plugins ) use ( $carry_db ) {
		$carry_db->query( $carry_db->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, 'active_plugins' ) );
		if ( null !== $plugins ) {
			$carry_db->query( $carry_db->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'yes')", $table, 'active_plugins', serialize( $plugins ) ) );
		}
	};
	$carry_row = function ( $column = 'option_value' ) use ( $carry_db ) {
		$value = $carry_db->get_var( "SELECT {$column} FROM ss_shadow_options WHERE option_name = 'active_plugins'" );
		return 'option_value' === $column && null !== $value ? unserialize( $value ) : $value;
	};
	$carry_rows = function () use ( $carry_db ) {
		return (int) $carry_db->get_var( "SELECT COUNT(*) FROM ss_shadow_options WHERE option_name = 'active_plugins'" );
	};
	$carry_logged = function ( $job ) {
		return count( $job['log'] );
	};
	$previous_wpdb   = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	$GLOBALS['wpdb'] = $carry_db;

	// Live's list is replaced in place when live has one.
	$carry_set( 'wp_options', array( 'akismet/akismet.php', 'dev-tool/dev-tool.php', 'hello.php', 'other/other.php' ) );
	$carry_set( 'ss_shadow_options', array( 'akismet/akismet.php', 'live-only/live-only.php' ) );
	$carry_sql_job                 = ss_kept_test_job( null );
	$carry_sql_job['kept_plugins'] = array( 'dev-tool', 'hello.php' );
	ss_assert_same( true, $carry_write->invokeArgs( null, array( &$carry_sql_job, 'ss_shadow_options' ) ), 'the carry succeeds against a shadow table that has live\'s list' );
	ss_assert_same(
		array( 'akismet/akismet.php', 'live-only/live-only.php', 'dev-tool/dev-tool.php', 'hello.php' ),
		$carry_row(),
		'kept plugins active on the sandbox are appended to live\'s list in the shadow table'
	);
	ss_assert_same( 1, $carry_rows(), 'the shadow table still has one activation row' );
	ss_assert( false !== strpos( implode( "\n", array_column( $carry_sql_job['log'], 1 ) ), 'dev-tool/dev-tool.php, hello.php' ), 'the job log names the activations it carried' );
	ss_assert_same(
		array( 'akismet/akismet.php', 'dev-tool/dev-tool.php', 'hello.php', 'other/other.php' ),
		unserialize( (string) $carry_db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" ) ),
		'the sandbox\'s own row is read, never written'
	);

	// The activate step can run twice: a worker that loses its lease after
	// this write is replaced by one that prepares the swap again.
	$carry_logged_once = $carry_logged( $carry_sql_job );
	ss_assert_same( true, $carry_write->invokeArgs( null, array( &$carry_sql_job, 'ss_shadow_options' ) ), 'a replayed carry succeeds' );
	ss_assert_same(
		array( 'akismet/akismet.php', 'live-only/live-only.php', 'dev-tool/dev-tool.php', 'hello.php' ),
		$carry_row(),
		'a replayed carry adds nothing twice'
	);
	ss_assert_same( $carry_logged_once, $carry_logged( $carry_sql_job ), 'and does not report the same activations again' );

	// Live without an activation row at all: the row is created.
	$carry_set( 'ss_shadow_options', null );
	$carry_insert_job                 = ss_kept_test_job( null );
	$carry_insert_job['kept_plugins'] = array( 'dev-tool' );
	ss_assert_same( true, $carry_write->invokeArgs( null, array( &$carry_insert_job, 'ss_shadow_options' ) ), 'the carry succeeds against a shadow table with no activation row' );
	ss_assert_same( array( 'dev-tool/dev-tool.php' ), $carry_row(), 'the missing row is created with the kept activations' );
	ss_assert_same( 'yes', $carry_row( 'autoload' ), 'the created row is autoloaded, as WordPress keeps it' );
	ss_assert_same( true, $carry_write->invokeArgs( null, array( &$carry_insert_job, 'ss_shadow_options' ) ), 'a replay after the row was created succeeds' );
	ss_assert_same( 1, $carry_rows(), 'and does not create a second row' );

	// Nothing to carry: nothing is written.
	$carry_set( 'ss_shadow_options', array( 'akismet/akismet.php' ) );
	$carry_none_job                 = ss_kept_test_job( null );
	$carry_none_job['kept_plugins'] = array( 'inactive-kept' );
	ss_assert_same( true, $carry_write->invokeArgs( null, array( &$carry_none_job, 'ss_shadow_options' ) ), 'a kept plugin that is not active carries nothing' );
	ss_assert_same( array( 'akismet/akismet.php' ), $carry_row(), 'live\'s list is then left as imported' );
	ss_assert_same( array(), $carry_none_job['log'], 'and nothing is logged' );
	$carry_set( 'wp_options', null );
	$carry_none_job['kept_plugins'] = array( 'dev-tool' );
	ss_assert_same( true, $carry_write->invokeArgs( null, array( &$carry_none_job, 'ss_shadow_options' ) ), 'a sandbox with no activation row carries nothing' );
	ss_assert_same( array( 'akismet/akismet.php' ), $carry_row(), 'live\'s list is left as imported then too' );

	// A write the database refuses stops the swap instead of going unnoticed.
	$carry_set( 'wp_options', array( 'dev-tool/dev-tool.php' ) );
	$carry_db->query( 'CREATE TABLE ss_narrow_options (option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL)' );
	$carry_fail_job                 = ss_kept_test_job( null );
	$carry_fail_job['kept_plugins'] = array( 'dev-tool' );
	$carry_failed                   = $carry_write->invokeArgs( null, array( &$carry_fail_job, 'ss_narrow_options' ) );
	ss_assert( is_wp_error( $carry_failed ) && 'active_plugins_carry' === $carry_failed->get_error_code(), 'a refused write is returned as an error' );
	ss_assert_same( array(), $carry_fail_job['log'], 'and no activation is reported as carried' );

	if ( null === $previous_wpdb ) {
		unset( $GLOBALS['wpdb'] );
	} else {
		$GLOBALS['wpdb'] = $previous_wpdb;
	}
}

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
