<?php
/**
 * What the plugin picker shows about the sandbox: the installed version next
 * to live's, which plugins are symlinks (and therefore kept as they are), and
 * the release channel the managed packages are linked to.
 *
 * Nothing here changes what a sync does. The file sync already never follows,
 * overwrites or deletes a symlink (Files::symlink_paths, Sync_Runner's
 * file_in_scope); this class only makes that visible before the sync starts.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Plugin_Compare {

	/**
	 * Name of the link a managed package points at => release channel.
	 *
	 * Newspack packages reach WP Cloud sites as symlinks into a tree that
	 * keeps `latest` (stable), `alpha` and `canary` links beside each version
	 * directory, and Newspack Manager reads a site's channel from the last
	 * segment of those targets. Reading the same segment here means no list
	 * of package names has to be kept in step with Manager's.
	 */
	const LINK_CHANNELS = array(
		'latest' => 'release',
		'alpha'  => 'alpha',
		'canary' => 'canary',
	);

	/**
	 * A branch build is a real directory that replaced a package symlink.
	 * Newspack Manager keeps the displaced link beside it under this suffix,
	 * e.g. "newspack-plugin--symlink".
	 */
	const BACKUP_SUFFIX = '--symlink';

	/**
	 * Option in which Newspack Manager records the branch it last installed.
	 * Only read here, never written.
	 */
	const BRANCH_OPTION = 'newspack_manager_release_channel_branch';

	const CHANNEL_LABELS = array(
		'release' => 'Release',
		'alpha'   => 'Alpha',
		'canary'  => 'Canary',
		'branch'  => 'Branch build',
	);

	/**
	 * Release channel named by a symlink target, or '' when the target is not
	 * one of the channel links (a pinned version, a development checkout).
	 *
	 * @param string|false $target readlink() result.
	 * @return string 'release', 'alpha', 'canary' or ''.
	 */
	public static function channel_for_target( $target ) {
		$link = basename( (string) $target );
		return isset( self::LINK_CHANNELS[ $link ] ) ? self::LINK_CHANNELS[ $link ] : '';
	}

	/**
	 * Which top-level entries of a plugins/ or themes/ directory are symlinks,
	 * and which real directories have a backup link beside them.
	 *
	 * Only those two kinds are listed; a plain directory or file has no entry.
	 * Targets are returned as written (relative or absolute) and are never
	 * resolved or checked: a dangling link is still a link the sync skips.
	 *
	 * @param string $dir Absolute directory path.
	 * @return array[] Entry name => [ 'symlink' => bool, 'target' => string,
	 *                 'backup' => string|null ]. 'backup' is the target of the
	 *                 "<name>--symlink" link beside a real directory, null
	 *                 when there is none.
	 */
	public static function link_states( $dir ) {
		$states = array();
		if ( ! is_dir( $dir ) ) {
			return $states;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return $states;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				$states[ $entry ] = array(
					'symlink' => true,
					'target'  => (string) readlink( $path ),
					'backup'  => null,
				);
			} elseif ( is_dir( $path ) && is_link( $path . self::BACKUP_SUFFIX ) ) {
				$states[ $entry ] = array(
					'symlink' => false,
					'target'  => '',
					'backup'  => (string) readlink( $path . self::BACKUP_SUFFIX ),
				);
			}
		}
		return $states;
	}

	/**
	 * This site's plugins, reduced to what the comparison reads.
	 *
	 * @param array[] $installed get_plugins() result: plugin file => headers.
	 * @param array[] $links     link_states() of the plugins directory.
	 * @return array[] Plugin file => [ 'top' => string, 'version' => string ].
	 */
	public static function local_plugins( array $installed, array $links ) {
		$rows = array();
		foreach ( $installed as $file => $headers ) {
			$file = (string) $file;
			$top  = self::top_level_entry( $file );
			// get_plugins() follows directory symlinks, so the backup link of
			// a branch build would list the same plugin a second time.
			if ( self::is_backup_link( $top, $links ) ) {
				continue;
			}
			$rows[ $file ] = array(
				'top'     => $top,
				'version' => isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Adds this sandbox's state to the plugin rows the live site returned.
	 *
	 * The result is still live's list, in live's order: a plugin that exists
	 * only on the sandbox adds no row. Each row gains:
	 *  - sandbox_version  string, or null when the sandbox does not have it
	 *  - differs          both sides have it and the versions are not equal
	 *  - sandbox_symlink  its top-level entry is a symlink here, so the sync
	 *                     leaves it alone and skips live's copy
	 *  - channel_label    release channel of that entry, '' when none
	 *  - will_change      a sync that includes this plugin replaces the
	 *                     sandbox copy with a different version
	 *
	 * @param array   $live  'plugins' list from the source (untrusted shape).
	 * @param array[] $local local_plugins() rows.
	 * @param array[] $links link_states() of the plugins directory.
	 * @return array[]
	 */
	public static function annotate( array $live, array $local, array $links ) {
		$live_per_top  = array();
		$local_per_top = array();
		foreach ( $live as $row ) {
			if ( self::is_plugin_row( $row ) ) {
				$live_per_top[ $row['top'] ] = isset( $live_per_top[ $row['top'] ] ) ? $live_per_top[ $row['top'] ] + 1 : 1;
			}
		}
		foreach ( $local as $row ) {
			$local_per_top[ $row['top'] ][] = $row;
		}

		$rows = array();
		foreach ( $live as $row ) {
			if ( ! self::is_plugin_row( $row ) ) {
				continue;
			}
			$top     = $row['top'];
			$version = isset( $row['version'] ) && is_scalar( $row['version'] ) ? trim( (string) $row['version'] ) : '';
			$symlink = ! empty( $row['symlink'] );

			$match = isset( $local[ $row['file'] ] ) ? $local[ $row['file'] ] : null;
			// The main file was renamed between versions: the directory is
			// still the same plugin when each side has exactly one in it.
			if ( null === $match && 1 === $live_per_top[ $top ] && isset( $local_per_top[ $top ] ) && 1 === count( $local_per_top[ $top ] ) ) {
				$match = $local_per_top[ $top ][0];
			}

			$state           = self::link_state( $top, $links );
			$channel         = self::state_channel( $state );
			$differs         = null !== $match && $match['version'] !== $version;
			$sandbox_symlink = $state['symlink'];

			$rows[] = array(
				'file'            => $row['file'],
				'top'             => $top,
				'name'            => isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '',
				'version'         => $version,
				'active'          => ! empty( $row['active'] ),
				'symlink'         => $symlink,
				'sandbox_version' => null !== $match ? $match['version'] : null,
				'differs'         => $differs,
				'sandbox_symlink' => $sandbox_symlink,
				'channel_label'   => isset( self::CHANNEL_LABELS[ $channel ] ) ? self::CHANNEL_LABELS[ $channel ] : '',
				'will_change'     => $differs && ! $symlink && ! $sandbox_symlink && ! in_array( $top, Sync_Runner::PROTECTED_PLUGINS, true ),
			);
		}
		return $rows;
	}

	/**
	 * Counts for the start confirmation and the job report.
	 *
	 * @param array[]       $rows      annotate() rows.
	 * @param string[]|null $selection The job's 'plugins' option: top-level
	 *                                 entries the sync is limited to, null
	 *                                 for all of them.
	 * @return array [ 'version_changes' => int, 'kept_symlinks' => int ]
	 */
	public static function summarize( array $rows, $selection = null ) {
		$version_changes = 0;
		$kept_symlinks   = 0;
		foreach ( $rows as $row ) {
			if ( $row['sandbox_symlink'] ) {
				++$kept_symlinks;
			}
			if ( $row['will_change'] && ( ! is_array( $selection ) || in_array( $row['top'], $selection, true ) ) ) {
				++$version_changes;
			}
		}
		return array(
			'version_changes' => $version_changes,
			'kept_symlinks'   => $kept_symlinks,
		);
	}

	/**
	 * Release channel of this site, by the rules Newspack Manager applies to
	 * its own packages, so the picker and Manager's own screen agree. Read
	 * from disk, so Manager need not be active:
	 *
	 *  - a package symlink names its channel by its target; the most forward
	 *    one found wins (canary, then alpha, then release), because a switch
	 *    leaves packages with no prerelease on their stable link;
	 *  - a real directory with a backup link is a branch build, and backup
	 *    links decide the base only when no package symlink names a channel;
	 *  - a recorded branch name counts even when no build is found on disk.
	 *
	 * @param array[] $plugin_links  link_states() of plugins/.
	 * @param array[] $theme_links   link_states() of themes/.
	 * @param mixed   $stored_branch Value of the BRANCH_OPTION option.
	 * @return string "Alpha", "Release, with branch fix/x layered on top",
	 *                "an unknown branch", … or '' when nothing on this site
	 *                points at a channel.
	 */
	public static function site_channel_label( array $plugin_links, array $theme_links, $stored_branch = '' ) {
		$linked     = array();
		$backed_up  = array();
		$has_branch = false;
		foreach ( array( $plugin_links, $theme_links ) as $links ) {
			foreach ( $links as $name => $state ) {
				if ( self::is_backup_link( (string) $name, $links ) ) {
					continue;
				}
				if ( $state['symlink'] ) {
					$linked[] = self::channel_for_target( $state['target'] );
				} elseif ( null !== $state['backup'] ) {
					$has_branch  = true;
					$backed_up[] = self::channel_for_target( $state['backup'] );
				}
			}
		}

		$base = self::most_forward_channel( $linked );
		if ( '' === $base ) {
			$base = self::most_forward_channel( $backed_up );
		}
		$branch = self::branch_name( $stored_branch );
		if ( '' !== $branch ) {
			$has_branch = true;
		}

		$label = isset( self::CHANNEL_LABELS[ $base ] ) ? self::CHANNEL_LABELS[ $base ] : '';
		if ( ! $has_branch ) {
			return $label;
		}
		$layered = '' !== $branch ? 'branch ' . $branch : 'an unknown branch';
		return '' !== $label ? $label . ', with ' . $layered . ' layered on top' : $layered;
	}

	/**
	 * This site's side of the comparison, read from WordPress and the disk.
	 *
	 * @param string $content_dir wp-content directory; plugins/ and themes/
	 *                            are read below it, as the file sync does.
	 * @return array [ 'installed' => get_plugins() result, 'plugin_links' and
	 *               'theme_links' => link_states(), 'branch' => the value of
	 *               Newspack Manager's branch option (unvalidated) ].
	 */
	public static function read_local( $content_dir = WP_CONTENT_DIR ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array(
			'installed'    => get_plugins(),
			'plugin_links' => self::link_states( $content_dir . '/plugins' ),
			'theme_links'  => self::link_states( $content_dir . '/themes' ),
			'branch'       => get_option( self::BRANCH_OPTION, '' ),
		);
	}

	/**
	 * Live's plugin rows annotated with this site's state, plus the site's
	 * release channel: everything the picker and `source-plugins` show.
	 *
	 * @param array $live  'plugins' list from the source.
	 * @param array $local read_local() result.
	 * @return array [ 'rows' => annotate() rows,
	 *               'channel' => site_channel_label() ]
	 */
	public static function compare( array $live, array $local ) {
		return array(
			'rows'    => self::annotate( $live, self::local_plugins( $local['installed'], $local['plugin_links'] ), $local['plugin_links'] ),
			'channel' => self::site_channel_label( $local['plugin_links'], $local['theme_links'], $local['branch'] ),
		);
	}

	/**
	 * What a job records about its plugins before anything is swapped.
	 *
	 * The comparison is informational, so a live plugin list that could not
	 * be read yields null rather than an error: the sync must carry on.
	 *
	 * @param array|\WP_Error $response  Source response to the `plugins` action.
	 * @param array           $local     read_local() result.
	 * @param string[]|null   $selection The job's 'plugins' option.
	 * @return array|null [ 'version_changes' => int, 'kept_symlinks' => int,
	 *                    'channel' => string ] or null.
	 */
	public static function job_summary( $response, array $local, $selection = null ) {
		if ( ! is_array( $response ) || ! isset( $response['plugins'] ) || ! is_array( $response['plugins'] ) ) {
			return null;
		}
		$compared           = self::compare( $response['plugins'], $local );
		$summary            = self::summarize( $compared['rows'], $selection );
		$summary['channel'] = $compared['channel'];
		return $summary;
	}

	/**
	 * The counts as one phrase, shared by the job log, the CLI and the admin
	 * history. A finished sync changed versions; a failed or cancelled one was
	 * rolled back, so its changes were only planned.
	 *
	 * @param array  $summary job_summary() result (missing keys read as zero).
	 * @param string $status  Job status; anything but done/failed/cancelled
	 *                        is a sync that has not swapped anything yet.
	 * @return string
	 */
	public static function describe( array $summary, $status = '' ) {
		$version_changes = isset( $summary['version_changes'] ) ? (int) $summary['version_changes'] : 0;
		$kept_symlinks   = isset( $summary['kept_symlinks'] ) ? (int) $summary['kept_symlinks'] : 0;
		$channel         = isset( $summary['channel'] ) && is_string( $summary['channel'] ) ? $summary['channel'] : '';

		if ( Job::S_DONE === $status ) {
			$change = 'changed version';
		} elseif ( Job::S_FAILED === $status || Job::S_CANCELLED === $status ) {
			$change = 'planned to change version';
		} else {
			$change = 'will change version';
		}

		$text = sprintf( '%d %s, %d %s', $version_changes, $change, $kept_symlinks, 1 === $kept_symlinks ? 'kept as a symlink' : 'kept as symlinks' );
		if ( '' !== $channel ) {
			$text .= '; sandbox release channel: ' . $channel;
		}
		return $text;
	}

	/**
	 * One line of `wp sandbox-sync source-plugins`. Every line starts with the
	 * name to pass to `--plugins=`.
	 *
	 * @param array $row                 annotate() row.
	 * @param bool  $with_sandbox_column Reserve the sandbox-version column.
	 *                                   The command does so only when some
	 *                                   plugin differs, which keeps the names
	 *                                   aligned and the output unchanged
	 *                                   when none does.
	 * @return string
	 */
	public static function cli_line( array $row, $with_sandbox_column ) {
		$notes = '';
		if ( $row['sandbox_symlink'] ) {
			$notes .= $row['symlink'] ? ' [kept (symlink)]' : ' [kept (symlink), live copy skipped]';
		} elseif ( $row['symlink'] ) {
			$notes .= ' [symlinked — not synced]';
		}
		if ( '' !== $row['channel_label'] ) {
			$notes .= ' [' . $row['channel_label'] . ']';
		}

		$sandbox = '';
		if ( $with_sandbox_column ) {
			$sandbox = sprintf( '%-24s ', $row['differs'] ? 'sandbox v' . $row['sandbox_version'] : '' );
		}

		return sprintf(
			'%-32s %-10s %s%s (%s)%s',
			$row['top'],
			'v' . $row['version'],
			$sandbox,
			$row['name'],
			$row['active'] ? 'active' : 'inactive',
			$notes
		);
	}

	private static function most_forward_channel( array $channels ) {
		foreach ( array( 'canary', 'alpha', 'release' ) as $channel ) {
			if ( in_array( $channel, $channels, true ) ) {
				return $channel;
			}
		}
		return '';
	}

	/**
	 * The recorded branch name, or '' unless it is a plain branch name by the
	 * rule Newspack Manager applies before recording one. The value comes
	 * from the database and ends up in the job log.
	 */
	private static function branch_name( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 ) {
			return '';
		}
		if ( ! preg_match( '#^[A-Za-z0-9._/-]+$#D', $value ) ) {
			return '';
		}
		if ( '-' === $value[0] || '/' === $value[0] || '/' === substr( $value, -1 ) ) {
			return '';
		}
		if ( false !== strpos( $value, '..' ) || false !== strpos( $value, '//' ) ) {
			return '';
		}
		return $value;
	}

	/** A remote row is usable when it names a plugin file and its top-level entry. */
	private static function is_plugin_row( $row ) {
		return is_array( $row )
			&& isset( $row['file'], $row['top'] )
			&& is_string( $row['file'] ) && '' !== $row['file']
			&& is_string( $row['top'] ) && '' !== $row['top'];
	}

	/**
	 * Top-level entry of plugins/ that holds a plugin file: its directory, or
	 * the file itself for a single-file plugin such as hello.php.
	 */
	private static function top_level_entry( $file ) {
		$slash = strpos( $file, '/' );
		return false === $slash ? $file : substr( $file, 0, $slash );
	}

	private static function link_state( $name, array $links ) {
		if ( isset( $links[ $name ] ) ) {
			return $links[ $name ];
		}
		return array(
			'symlink' => false,
			'target'  => '',
			'backup'  => null,
		);
	}

	/** True for the "<name>--symlink" link Newspack Manager leaves beside a branch build. */
	private static function is_backup_link( $name, array $links ) {
		$suffix = self::BACKUP_SUFFIX;
		return strlen( $name ) > strlen( $suffix )
			&& substr( $name, -strlen( $suffix ) ) === $suffix
			&& ! empty( $links[ $name ]['symlink'] );
	}

	/**
	 * Channel of one entry: the channel its link target names, 'branch' for a
	 * real directory with a backup link beside it, '' otherwise.
	 */
	private static function state_channel( array $state ) {
		if ( $state['symlink'] ) {
			return self::channel_for_target( $state['target'] );
		}
		return null !== $state['backup'] ? 'branch' : '';
	}
}
