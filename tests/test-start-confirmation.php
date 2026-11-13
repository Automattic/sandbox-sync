<?php
/** Start confirmation: the lines a run shows follow its mode and selection. */

use SandboxSync\Safety;
use SandboxSync\Start_Confirmation;

ss_test_section( 'Start confirmation: lines follow the chosen mode' );

$confirm_strings = Start_Confirmation::strings();
$confirm_options = array(
	'db'                 => true,
	'dirs'               => array( 'plugins', 'mu-plugins', 'themes', 'uploads' ),
	'incremental'        => true,
	'excluded_tables'    => array(),
	'uploads_full_audit' => false,
	'uploads_adopt'      => false,
);
$confirm_all_dirs = 'plugins, mu-plugins, themes, uploads';

ss_assert_same(
	array(
		$confirm_strings['db_incremental'] ?? null,
		str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_incremental'] ?? '' ),
	),
	Start_Confirmation::mode_lines( $confirm_options ),
	'a default run shows the incremental database line, then the incremental files line with every directory'
);

ss_assert_same(
	array(
		$confirm_strings['db_full'] ?? null,
		str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_full'] ?? '' ),
	),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'incremental' => false ) ) ),
	'a full run shows the full database line, then the full files line'
);

ss_assert( ( $confirm_strings['db_incremental'] ?? '' ) !== ( $confirm_strings['db_full'] ?? '' ), 'the database sentence differs between an incremental and a full run' );
ss_assert( ( $confirm_strings['files_incremental'] ?? '' ) !== ( $confirm_strings['files_full'] ?? '' ), 'the files sentence differs between an incremental and a full run' );

ss_assert_same(
	array( $confirm_strings['db_incremental'] ?? null ),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'dirs' => array() ) ) ),
	'a database-only run shows no files line'
);

ss_assert_same(
	array( str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_incremental'] ?? '' ) ),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'db' => false ) ) ),
	'a files-only run shows no database line'
);

ss_assert_same(
	array( str_replace( '%s', 'plugins, themes', $confirm_strings['files_incremental'] ?? '' ) ),
	Start_Confirmation::mode_lines(
		array(
			'db'          => false,
			'dirs'        => array( 'plugins', 'themes' ),
			'incremental' => true,
		)
	),
	'the files line names only the selected directories'
);

ss_test_section( 'Start confirmation: lines that only show when they apply' );

ss_assert_same(
	array(
		$confirm_strings['db_incremental'] ?? null,
		$confirm_strings['excluded'] ?? null,
		str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_incremental'] ?? '' ),
	),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'excluded_tables' => array( 'actionscheduler_logs' ) ) ) ),
	'excluded tables add one line right after the database line'
);
ss_assert_same(
	array( str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_incremental'] ?? '' ) ),
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'db'              => false,
				'excluded_tables' => array( 'actionscheduler_logs' ),
			)
		)
	),
	'excluded tables are not mentioned when the database is not synced'
);

// Rescan and adopt are read only when uploads are change-tracked, which is an
// incremental run with uploads_fast on (the default). Anywhere else the run
// ignores them, and saying so beats a silent full download.
$confirm_full_ignored = array(
	$confirm_strings['db_full'] ?? null,
	str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_full'] ?? '' ),
	$confirm_strings['uploads_ignored'] ?? null,
);
ss_assert_same(
	$confirm_full_ignored,
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'incremental'   => false,
				'uploads_adopt' => true,
			)
		)
	),
	'a full run says that adopt has no effect'
);
ss_assert_same(
	$confirm_full_ignored,
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'incremental'        => false,
				'uploads_full_audit' => true,
			)
		)
	),
	'a full run says that rescan has no effect'
);
ss_assert_same(
	Start_Confirmation::mode_lines( $confirm_options ),
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'uploads_full_audit' => true,
				'uploads_adopt'      => true,
			)
		)
	),
	'rescan and adopt add no line to a run that uses them'
);
ss_assert_same(
	array(
		$confirm_strings['db_incremental'] ?? null,
		str_replace( '%s', $confirm_all_dirs, $confirm_strings['files_incremental'] ?? '' ),
		$confirm_strings['uploads_ignored'] ?? null,
	),
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'uploads_fast'       => false,
				'uploads_full_audit' => true,
			)
		)
	),
	'rescan has no effect on an incremental run without change tracking either'
);
ss_assert_same(
	array(
		$confirm_strings['db_full'] ?? null,
		str_replace( '%s', 'plugins, mu-plugins, themes', $confirm_strings['files_full'] ?? '' ),
	),
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'incremental'        => false,
				'dirs'               => array( 'plugins', 'mu-plugins', 'themes' ),
				'uploads_full_audit' => true,
				'uploads_adopt'      => true,
			)
		)
	),
	'uploads options show nothing when uploads are not synced'
);

ss_test_section( 'Start confirmation: safety rules' );

$confirm_default_rules = Safety::default_keys();
ss_assert( count( $confirm_default_rules ) > 2, 'there are default safety rules to turn off' );

ss_assert_same( null, Start_Confirmation::safety_line( array() ), 'the default rules need no line' );
ss_assert_same( null, Start_Confirmation::safety_line( array( 'safety' => $confirm_default_rules ) ), 'the default rules, given explicitly, need no line' );
ss_assert_same(
	$confirm_strings['safety_none'] ?? null,
	Start_Confirmation::safety_line( array( 'safety' => array() ) ),
	'no selected rule shows the production-credentials warning'
);
ss_assert_same(
	str_replace( '%s', $confirm_default_rules[0] . ', ' . $confirm_default_rules[2], $confirm_strings['safety_off'] ?? '' ),
	Start_Confirmation::safety_line( array( 'safety' => array_values( array_diff( $confirm_default_rules, array( $confirm_default_rules[0], $confirm_default_rules[2] ) ) ) ) ),
	'default rules that are turned off are named'
);
// A job keeps only the rule keys it knows, so a mistyped key selects nothing.
ss_assert_same(
	$confirm_strings['safety_none'] ?? null,
	Start_Confirmation::safety_line( array( 'safety' => array( 'not_a_rule' ) ) ),
	'only mistyped rule keys show the production-credentials warning'
);
ss_assert_same(
	str_replace( '%s', implode( ', ', array_slice( $confirm_default_rules, 1 ) ), $confirm_strings['safety_off'] ?? '' ),
	Start_Confirmation::safety_line( array( 'safety' => array( 'not_a_rule', $confirm_default_rules[0] ) ) ),
	'a mistyped key does not count as a selected rule'
);

ss_test_section( 'Start confirmation: wording contract' );

ss_assert( count( $confirm_strings ) > 0, 'the sentence table is not empty' );
foreach ( $confirm_strings as $confirm_key => $confirm_text ) {
	ss_assert( is_string( $confirm_text ) && '' !== $confirm_text, "the {$confirm_key} sentence is a non-empty string" );
	ss_assert( 1 !== preg_match( '/\b[A-Z]{2,}\b/', (string) $confirm_text ), "the {$confirm_key} sentence has no all-caps word" );
	// The admin script fills one list into each of these three sentences and
	// nothing anywhere else.
	ss_assert_same(
		in_array( $confirm_key, array( 'files_incremental', 'files_full', 'safety_off' ), true ) ? 1 : 0,
		substr_count( (string) $confirm_text, '%' ),
		"the {$confirm_key} sentence has exactly the placeholders the admin script fills"
	);
}
foreach ( array( 'files_incremental', 'files_full', 'safety_off' ) as $confirm_key ) {
	ss_assert_same( 1, substr_count( $confirm_strings[ $confirm_key ] ?? '', '%s' ), "the {$confirm_key} placeholder is %s" );
}
// A confirmation nobody reads protects nothing: keep every sentence short.
foreach ( $confirm_strings as $confirm_key => $confirm_text ) {
	ss_assert( str_word_count( (string) $confirm_text ) <= 22, "the {$confirm_key} sentence stays within 22 words" );
}
