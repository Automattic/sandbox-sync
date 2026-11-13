<?php
/**
 * Wording of the confirmation shown before a sync starts: what the chosen mode
 * replaces, skips and removes. The admin page and the CLI both take these
 * sentences from here; the admin page adds lines of its own around them.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Start_Confirmation {

	/**
	 * The sentences, by key. The admin script receives this table as
	 * SandboxSyncAdmin.confirm and must pick the same keys, under the same
	 * conditions, as mode_lines(), outro() and safety_line().
	 *
	 * Lines are list items: lower-case first, no closing period. A %s takes a
	 * comma-separated list: directories, or safety rule keys.
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			'intro'             => 'This will replace content on this sandbox with content from the live site:',
			// A table is skipped only when its checksum is unchanged on both
			// sides, so a table edited only on the sandbox is replaced too. The
			// posts/postmeta row delta keeps the sandbox rows of every post it
			// does not touch, which is why the sentence does not promise a copy
			// of those two tables.
			'db_incremental'    => 'database: tables that changed on live or on this sandbox since the last sync are replaced with the live copy (posts and postmeta post by post when few posts changed) and the rest are skipped; a first sync skips none; excluded tables are kept and schema-only tables are recreated empty',
			'db_full'           => 'database: every table is replaced with the live copy, changed or not; excluded tables are kept and schema-only tables are recreated empty',
			// "Within what is selected": plugins and uploads periods left out of
			// the selection are neither downloaded nor removed.
			'files_incremental' => 'files (%s): within what is selected, files that differ from live are replaced and files that are not on live are removed; unchanged files are skipped',
			'files_full'        => 'files (%s): within what is selected, every file is downloaded again from live and files that are not on live are removed',
			// Tracked uploads are compared folder by folder (a year or a month),
			// and only where WordPress recorded a change on either site. A
			// folder with no completed sync from this source is cleared first.
			'uploads_tracked'   => 'uploads: in folders where WordPress recorded changes on either site since the last sync, files that differ from live are replaced and files that are not on live are removed; the other folders are skipped',
			'uploads_audit'     => 'uploads: in every selected folder, files that differ from live are replaced and files that are not on live are removed',
			'uploads_baseline'  => 'uploads: a folder never synced before is cleared and copied from live in full',
			'uploads_adopt'     => 'uploads: a folder never synced before is compared file by file instead of cleared; only missing or changed files are downloaded and files that are not on live are removed',
			'uploads_ignored'   => 'uploads: the rescan and adopt options have no effect on this run',
			'safety'            => 'safety envelope enabled before activation; selected data rules: %s',
			'safety_none'       => 'none (production credentials will stay live!)',
			'outro_incremental' => 'Changes made on this sandbox are lost wherever a table, post or file is replaced or removed. Continue?',
			'outro_full'        => 'Changes made on this sandbox in the selected tables and directories will be lost. Continue?',
		);
	}

	/**
	 * The lines that depend on the mode and on what is selected, in display
	 * order: database, files, uploads.
	 *
	 * @param array $options Sync options: db, dirs, incremental, uploads_fast, uploads_full_audit, uploads_adopt.
	 * @return string[]
	 */
	public static function mode_lines( $options ) {
		$strings     = self::strings();
		$incremental = ! empty( $options['incremental'] );
		$dirs        = isset( $options['dirs'] ) ? array_values( (array) $options['dirs'] ) : array();
		$uploads     = in_array( 'uploads', $dirs, true );
		$audit       = ! empty( $options['uploads_full_audit'] );
		$adopt       = ! empty( $options['uploads_adopt'] );
		// Same condition as the runner: uploads are change-tracked on an
		// incremental run unless uploads_fast was explicitly turned off.
		$tracked = $uploads && $incremental && ( ! array_key_exists( 'uploads_fast', $options ) || ! empty( $options['uploads_fast'] ) );
		$lines   = array();

		if ( ! empty( $options['db'] ) ) {
			$lines[] = $strings[ $incremental ? 'db_incremental' : 'db_full' ];
		}

		// The files sentence describes a file-by-file comparison, which is not
		// what tracked uploads get: they have their own lines below.
		$listed = $tracked ? array_values( array_diff( $dirs, array( 'uploads' ) ) ) : $dirs;
		if ( ! empty( $listed ) ) {
			$lines[] = sprintf( $strings[ $incremental ? 'files_incremental' : 'files_full' ], implode( ', ', $listed ) );
		}

		if ( $tracked ) {
			$lines[] = $strings[ $audit ? 'uploads_audit' : 'uploads_tracked' ];
			$lines[] = $strings[ $adopt ? 'uploads_adopt' : 'uploads_baseline' ];
		} elseif ( $uploads && ( $audit || $adopt ) ) {
			// Rescan and adopt are read only on the tracked uploads path.
			$lines[] = $strings['uploads_ignored'];
		}

		return $lines;
	}

	/**
	 * The closing warning and question.
	 *
	 * @param array $options Sync options: incremental.
	 * @return string
	 */
	public static function outro( $options ) {
		$strings = self::strings();
		return $strings[ empty( $options['incremental'] ) ? 'outro_full' : 'outro_incremental' ];
	}

	/**
	 * The safety line: the data rules this run applies, or the warning that it
	 * applies none. Lists what a job keeps, which is known rule keys only, so a
	 * mistyped key reads as the unprotected run it would be.
	 *
	 * @param array $options Sync options: safety (absent means the default rules).
	 * @return string
	 */
	public static function safety_line( $options ) {
		$strings  = self::strings();
		$selected = array_key_exists( 'safety', $options ) && is_array( $options['safety'] ) ? $options['safety'] : Safety::default_keys();
		$applied  = array_values( array_intersect( $selected, array_keys( Safety::rules() ) ) );
		return sprintf( $strings['safety'], empty( $applied ) ? $strings['safety_none'] : implode( ', ', $applied ) );
	}
}
