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
	'uploads_full_audit' => false,
	'uploads_adopt'      => false,
);
// Hand-filled copies of the two files sentences, so the expectations do not
// run through the code under test.
$confirm_code_files = str_replace( '%s', 'plugins, mu-plugins, themes', $confirm_strings['files_incremental'] ?? '' );
$confirm_all_files  = str_replace( '%s', 'plugins, mu-plugins, themes, uploads', $confirm_strings['files_full'] ?? '' );

ss_assert_same(
	array(
		$confirm_strings['db_incremental'] ?? null,
		$confirm_code_files,
		$confirm_strings['uploads_tracked'] ?? null,
		$confirm_strings['uploads_baseline'] ?? null,
	),
	Start_Confirmation::mode_lines( $confirm_options ),
	'a default run shows the incremental database line, the code directories, then the two uploads lines'
);

ss_assert_same(
	array(
		$confirm_strings['db_full'] ?? null,
		$confirm_all_files,
	),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'incremental' => false ) ) ),
	'a full run shows the full database line and one files line that includes uploads'
);

ss_assert( ( $confirm_strings['db_incremental'] ?? '' ) !== ( $confirm_strings['db_full'] ?? '' ), 'the database sentence differs between an incremental and a full run' );
ss_assert( ( $confirm_strings['files_incremental'] ?? '' ) !== ( $confirm_strings['files_full'] ?? '' ), 'the files sentence differs between an incremental and a full run' );

ss_assert_same(
	array( $confirm_strings['db_incremental'] ?? null ),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'dirs' => array() ) ) ),
	'a database-only run shows no files or uploads line'
);

ss_assert_same(
	array( $confirm_code_files, $confirm_strings['uploads_tracked'] ?? null, $confirm_strings['uploads_baseline'] ?? null ),
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

ss_test_section( 'Start confirmation: uploads follow their own rules' );

// Tracked uploads (incremental with change tracking, the default) are compared
// folder by folder, not file by file like the code directories, so they stay
// out of the files sentence and say for themselves what is replaced and removed.
ss_assert_same(
	array( $confirm_strings['uploads_tracked'] ?? null, $confirm_strings['uploads_baseline'] ?? null ),
	Start_Confirmation::mode_lines(
		array(
			'db'          => false,
			'dirs'        => array( 'uploads' ),
			'incremental' => true,
		)
	),
	'an incremental uploads-only run shows the uploads lines and no files line'
);

ss_assert_same(
	array( $confirm_strings['db_incremental'] ?? null, $confirm_code_files, $confirm_strings['uploads_audit'] ?? null, $confirm_strings['uploads_baseline'] ?? null ),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'uploads_full_audit' => true ) ) ),
	'a rescan replaces the change-tracking line'
);

ss_assert_same(
	array( $confirm_strings['db_incremental'] ?? null, $confirm_code_files, $confirm_strings['uploads_tracked'] ?? null, $confirm_strings['uploads_adopt'] ?? null ),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'uploads_adopt' => true ) ) ),
	'adopt replaces the line about never-synced folders being cleared'
);

// A full run takes the plain directory path: rescan and adopt are never read.
ss_assert_same(
	array( $confirm_strings['db_full'] ?? null, $confirm_all_files, $confirm_strings['uploads_ignored'] ?? null ),
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
	array( $confirm_strings['db_full'] ?? null, $confirm_all_files, $confirm_strings['uploads_ignored'] ?? null ),
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

// The engine tracks uploads only when uploads_fast is on as well (the default).
// Without it an incremental run compares uploads file by file like any directory.
ss_assert_same(
	array(
		$confirm_strings['db_incremental'] ?? null,
		str_replace( '%s', 'plugins, mu-plugins, themes, uploads', $confirm_strings['files_incremental'] ?? '' ),
	),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'uploads_fast' => false ) ) ),
	'untracked incremental uploads are described by the files sentence'
);
ss_assert_same(
	array(
		$confirm_strings['db_incremental'] ?? null,
		str_replace( '%s', 'plugins, mu-plugins, themes, uploads', $confirm_strings['files_incremental'] ?? '' ),
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
	'rescan has no effect on untracked incremental uploads either'
);
ss_assert_same(
	Start_Confirmation::mode_lines( $confirm_options ),
	Start_Confirmation::mode_lines( array_merge( $confirm_options, array( 'uploads_fast' => true ) ) ),
	'change tracking is the default when the option is not given'
);

ss_assert_same(
	array( $confirm_strings['db_incremental'] ?? null, $confirm_code_files ),
	Start_Confirmation::mode_lines(
		array_merge(
			$confirm_options,
			array(
				'dirs'               => array( 'plugins', 'mu-plugins', 'themes' ),
				'uploads_full_audit' => true,
				'uploads_adopt'      => true,
			)
		)
	),
	'uploads options show nothing when uploads are not synced'
);

ss_test_section( 'Start confirmation: closing warning' );

ss_assert_same( $confirm_strings['outro_incremental'] ?? null, Start_Confirmation::outro( $confirm_options ), 'an incremental run closes on the incremental warning' );
ss_assert_same( $confirm_strings['outro_full'] ?? null, Start_Confirmation::outro( array_merge( $confirm_options, array( 'incremental' => false ) ) ), 'a full run closes on the full warning' );
ss_assert( ( $confirm_strings['outro_incremental'] ?? '' ) !== ( $confirm_strings['outro_full'] ?? '' ), 'the closing warning differs between an incremental and a full run' );

ss_test_section( 'Start confirmation: safety rules' );

ss_assert_same(
	str_replace( '%s', 'scrub_secrets, jetpack', $confirm_strings['safety'] ?? '' ),
	Start_Confirmation::safety_line( array( 'safety' => array( 'scrub_secrets', 'jetpack' ) ) ),
	'the safety line names the selected rules'
);
ss_assert_same(
	str_replace( '%s', $confirm_strings['safety_none'] ?? '', $confirm_strings['safety'] ?? '' ),
	Start_Confirmation::safety_line( array( 'safety' => array() ) ),
	'no selected rule shows the production-credentials warning'
);
// A job keeps only the rule keys it knows, so a mistyped key selects nothing.
ss_assert_same(
	str_replace( '%s', 'jetpack', $confirm_strings['safety'] ?? '' ),
	Start_Confirmation::safety_line( array( 'safety' => array( 'jetpak', 'jetpack' ) ) ),
	'a rule key the job would drop is not listed'
);
ss_assert_same(
	str_replace( '%s', $confirm_strings['safety_none'] ?? '', $confirm_strings['safety'] ?? '' ),
	Start_Confirmation::safety_line( array( 'safety' => array( 'jetpak' ) ) ),
	'only mistyped rule keys show the production-credentials warning'
);
ss_assert_same(
	str_replace( '%s', implode( ', ', Safety::default_keys() ), $confirm_strings['safety'] ?? '' ),
	Start_Confirmation::safety_line( array() ),
	'the default rules apply when no selection is given'
);
ss_assert( count( Safety::default_keys() ) > 0, 'there are default safety rules to list' );

ss_test_section( 'Start confirmation: wording contract' );

ss_assert( count( $confirm_strings ) > 0, 'the sentence table is not empty' );
foreach ( $confirm_strings as $confirm_key => $confirm_text ) {
	ss_assert( is_string( $confirm_text ) && '' !== $confirm_text, "the {$confirm_key} sentence is a non-empty string" );
	ss_assert( 1 !== preg_match( '/\b[A-Z]{2,}\b/', (string) $confirm_text ), "the {$confirm_key} sentence has no all-caps word" );
	// The admin script fills one list into the two files sentences and into
	// the safety sentence, and nothing anywhere else.
	ss_assert_same(
		in_array( $confirm_key, array( 'files_incremental', 'files_full', 'safety' ), true ) ? 1 : 0,
		substr_count( (string) $confirm_text, '%' ),
		"the {$confirm_key} sentence has exactly the placeholders the admin script fills"
	);
}
foreach ( array( 'files_incremental', 'files_full', 'safety' ) as $confirm_key ) {
	ss_assert_same( 1, substr_count( $confirm_strings[ $confirm_key ] ?? '', '%s' ), "the {$confirm_key} placeholder is %s" );
}
