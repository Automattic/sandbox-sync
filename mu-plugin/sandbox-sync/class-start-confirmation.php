<?php
/**
 * Wording of the confirmation shown before a sync starts: what the chosen mode
 * replaces, skips and removes. The admin page and the CLI both take these
 * sentences from here.
 *
 * Kept short on purpose: a long confirmation is one people stop reading. It
 * says what a run replaces and removes, and leaves how to the README.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Start_Confirmation {

	/**
	 * The sentences, by key. The admin script receives this table as
	 * SandboxSyncAdmin.confirm and must pick the same keys, under the same
	 * conditions, as mode_lines() and safety_line().
	 *
	 * Lines are list items: lower-case first, no closing period. A %s takes a
	 * comma-separated list: directories, or safety rule keys.
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			'intro'             => 'This will sync this sandbox from live:',
			// "On either site": a table is skipped only when its checksum is
			// unchanged on both sides, so one edited only here is replaced too.
			// A first sync has no recorded checksums and replaces everything.
			'db_incremental'    => 'database: tables changed on either site are replaced (all of them the first time); the rest are skipped',
			'db_full'           => 'database: every table is replaced',
			'excluded'          => 'excluded tables are kept',
			'files_incremental' => '%s: files that differ from live are replaced, files not on live are removed',
			'files_full'        => '%s: every file is downloaded again, files not on live are removed',
			'uploads_ignored'   => 'uploads: rescan and adopt have no effect on this run',
			'safety_off'        => 'safety rules off: %s',
			'safety_none'       => 'no safety rules: production credentials will stay live!',
			'outro'             => 'Sandbox changes in what is replaced will be lost. Continue?',
		);
	}

	/**
	 * The lines that depend on the mode and on what is selected, in display
	 * order: database, files.
	 *
	 * @param array $options Sync options: db, dirs, incremental, excluded_tables, uploads_fast, uploads_full_audit, uploads_adopt.
	 * @return string[]
	 */
	public static function mode_lines( $options ) {
		$strings     = self::strings();
		$incremental = ! empty( $options['incremental'] );
		$dirs        = isset( $options['dirs'] ) ? array_values( (array) $options['dirs'] ) : array();
		$lines       = array();

		if ( ! empty( $options['db'] ) ) {
			$lines[] = $strings[ $incremental ? 'db_incremental' : 'db_full' ];
			if ( ! empty( $options['excluded_tables'] ) ) {
				$lines[] = $strings['excluded'];
			}
		}

		if ( ! empty( $dirs ) ) {
			$lines[] = sprintf( $strings[ $incremental ? 'files_incremental' : 'files_full' ], implode( ', ', $dirs ) );
		}

		// Rescan and adopt are read only when uploads are change-tracked: an
		// incremental run with uploads_fast left on. Any other run downloads
		// uploads like the other directories, which is worth one line when the
		// person asked for the opposite.
		$tracked = $incremental && ( ! array_key_exists( 'uploads_fast', $options ) || ! empty( $options['uploads_fast'] ) );
		$asked   = ! empty( $options['uploads_full_audit'] ) || ! empty( $options['uploads_adopt'] );
		if ( in_array( 'uploads', $dirs, true ) && $asked && ! $tracked ) {
			$lines[] = $strings['uploads_ignored'];
		}

		return $lines;
	}

	/**
	 * The safety line, or null when the default rules all apply. Goes by what
	 * a job keeps, which is known rule keys only, so a mistyped key reads as
	 * the unprotected run it would be.
	 *
	 * @param array $options Sync options: safety (absent means the default rules).
	 * @return string|null
	 */
	public static function safety_line( $options ) {
		$strings  = self::strings();
		$selected = array_key_exists( 'safety', $options ) && is_array( $options['safety'] ) ? $options['safety'] : Safety::default_keys();
		$applied  = array_intersect( $selected, array_keys( Safety::rules() ) );
		if ( empty( $applied ) ) {
			return $strings['safety_none'];
		}
		$off = array_values( array_diff( Safety::default_keys(), $applied ) );
		return empty( $off ) ? null : sprintf( $strings['safety_off'], implode( ', ', $off ) );
	}
}
