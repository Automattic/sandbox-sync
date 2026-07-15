<?php
/**
 * Row-level delta import for wp_posts + wp_postmeta.
 *
 * When a table checksum misses, clone the sandbox table into the shadow and
 * apply only INSERT/UPDATE/DELETE changes discovered by comparing a live
 * (ID, post_modified_gmt) index to the local table — no WordPress hooks.
 * postmeta is refreshed for the dirty post_id set only.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Table_Delta {

	const INDEX_PAGE_ROWS   = 5000;
	const ID_FETCH_BATCH    = 100;
	const POSTMETA_ID_BATCH = 50;
	const MAX_CHANGED_ROWS  = 10000;
	const MAX_CHANGED_RATIO = 0.25;

	/**
	 * @return bool True when $source_table is the posts table for this prefix.
	 */
	public static function is_posts_table( $source_table, $source_prefix ) {
		return self::table_suffix( $source_table, $source_prefix ) === 'posts';
	}

	/**
	 * @return bool
	 */
	public static function is_postmeta_table( $source_table, $source_prefix ) {
		return self::table_suffix( $source_table, $source_prefix ) === 'postmeta';
	}

	/**
	 * @return string
	 */
	public static function table_suffix( $source_table, $source_prefix ) {
		$source_table  = (string) $source_table;
		$source_prefix = (string) $source_prefix;
		if ( '' !== $source_prefix && 0 === strpos( $source_table, $source_prefix ) ) {
			return substr( $source_table, strlen( $source_prefix ) );
		}
		return $source_table;
	}

	/**
	 * Source protocol supports index / rows-by-id endpoints.
	 */
	public static function source_supports_delta( $job ) {
		$protocol = isset( $job['manifest']['site']['protocol'] ) ? (int) $job['manifest']['site']['protocol'] : 0;
		return $protocol >= 14;
	}

	/**
	 * Builds (or returns cached) posts row plan: new / updated / deleted / dirty IDs.
	 *
	 * @return array|\WP_Error
	 */
	public static function posts_plan( &$job, $posts_source_table, $posts_target_table ) {
		if ( isset( $job['posts_row_plan'] ) && is_array( $job['posts_row_plan'] ) ) {
			return $job['posts_row_plan'];
		}
		if ( ! self::table_exists( $posts_target_table ) ) {
			return new \WP_Error( 'delta_no_local', "No local {$posts_target_table} to diff against; use a full import." );
		}

		$local = self::load_local_posts_index( $posts_target_table );
		if ( is_wp_error( $local ) ) {
			return $local;
		}

		$remote = self::fetch_remote_posts_index( $job, $posts_source_table );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$new     = array();
		$updated = array();
		foreach ( $remote as $id => $modified ) {
			$id = (int) $id;
			if ( ! isset( $local[ $id ] ) ) {
				$new[] = $id;
			} elseif ( (string) $local[ $id ] !== (string) $modified ) {
				$updated[] = $id;
			}
		}
		$deleted = array();
		foreach ( $local as $id => $_modified ) {
			$id = (int) $id;
			if ( ! isset( $remote[ $id ] ) ) {
				$deleted[] = $id;
			}
		}

		$dirty = array_values( array_unique( array_merge( $new, $updated, $deleted ) ) );
		$plan  = array(
			'posts_table'    => $posts_source_table,
			'target'         => $posts_target_table,
			'new'            => $new,
			'updated'        => $updated,
			'deleted'        => $deleted,
			'dirty'          => $dirty,
			'local_count'    => count( $local ),
			'remote_count'   => count( $remote ),
			'fetched_at'     => time(),
		);
		$job['posts_row_plan'] = $plan;
		Job::save( $job );
		return $plan;
	}

	/**
	 * Whether the plan is small enough for delta (else full import).
	 *
	 * @return true|\WP_Error
	 */
	public static function assert_plan_within_budget( $plan ) {
		$changed = count( $plan['dirty'] );
		$base    = max( (int) $plan['local_count'], (int) $plan['remote_count'], 1 );
		if ( $changed > self::MAX_CHANGED_ROWS ) {
			return new \WP_Error( 'delta_too_large', sprintf( 'Row delta touches %d rows (cap %d); falling back to full import.', $changed, self::MAX_CHANGED_ROWS ) );
		}
		if ( ( $changed / $base ) > self::MAX_CHANGED_RATIO ) {
			return new \WP_Error( 'delta_too_large', sprintf( 'Row delta touches %.0f%% of rows (cap %.0f%%); falling back to full import.', 100 * $changed / $base, 100 * self::MAX_CHANGED_RATIO ) );
		}
		return true;
	}

	/**
	 * Clone local target → shadow, apply posts deletes + upserts, URL-rewrite upserted rows.
	 *
	 * @return array|\WP_Error Step result.
	 */
	public static function apply_posts_delta( &$job, $source_table, $shadow, $checkpoint_table, $manifest_table, $plan ) {
		global $wpdb;
		$budget = self::assert_plan_within_budget( $plan );
		if ( is_wp_error( $budget ) ) {
			return $budget;
		}

		$target = $plan['target'];
		$schema = self::assert_schema_compatible( $target, $manifest_table );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		$cloned = self::clone_table_to_shadow( $target, $shadow, $checkpoint_table );
		if ( is_wp_error( $cloned ) ) {
			return $cloned;
		}

		if ( ! empty( $plan['deleted'] ) ) {
			foreach ( array_chunk( $plan['deleted'], 500 ) as $chunk ) {
				$id_list = implode( ',', array_map( 'intval', $chunk ) );
				if ( false === $wpdb->query( "DELETE FROM `{$shadow}` WHERE `ID` IN ({$id_list})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs.
					return new \WP_Error( 'delta_delete', "Could not delete removed posts from {$shadow}: " . $wpdb->last_error );
				}
			}
		}

		$upsert_ids = array_values( array_unique( array_merge( $plan['new'], $plan['updated'] ) ) );
		$pairs      = Sync_Runner::build_replacement_pairs_public( $job['manifest']['site'] );
		$fetched    = 0;
		foreach ( array_chunk( $upsert_ids, self::ID_FETCH_BATCH ) as $chunk ) {
			$checkpoint = Sync_Runner::checkpoint_public( $job, true, Job::CHUNK_LOCK_TTL );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$applied = self::fetch_and_replace_rows( $job, $source_table, $shadow, $chunk, $pairs, array( 'guid' ) );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$fetched += (int) $applied;
		}

		if ( ! isset( $job['delta_imported'] ) || ! is_array( $job['delta_imported'] ) ) {
			$job['delta_imported'] = array();
		}
		$job['delta_imported'][] = $source_table;
		$job['delta_imported']   = array_values( array_unique( $job['delta_imported'] ) );

		return array(
			'message' => sprintf(
				'Delta-imported %s: %d new, %d updated, %d deleted (%d rows fetched).',
				$source_table,
				count( $plan['new'] ),
				count( $plan['updated'] ),
				count( $plan['deleted'] ),
				$fetched
			),
			'delta'   => true,
		);
	}

	/**
	 * Clone local postmeta → shadow; refresh meta for dirty post_ids.
	 *
	 * @return array|\WP_Error
	 */
	public static function apply_postmeta_delta( &$job, $source_table, $target, $shadow, $checkpoint_table, $manifest_table, $plan ) {
		global $wpdb;
		$schema = self::assert_schema_compatible( $target, $manifest_table );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		if ( ! self::table_exists( $target ) ) {
			return new \WP_Error( 'delta_no_local', "No local {$target} to delta; use a full import." );
		}

		$cloned = self::clone_table_to_shadow( $target, $shadow, $checkpoint_table );
		if ( is_wp_error( $cloned ) ) {
			return $cloned;
		}

		$dirty = array_values( array_unique( array_map( 'intval', $plan['dirty'] ) ) );
		if ( empty( $dirty ) ) {
			if ( ! isset( $job['delta_imported'] ) || ! is_array( $job['delta_imported'] ) ) {
				$job['delta_imported'] = array();
			}
			$job['delta_imported'][] = $source_table;
			$job['delta_imported']   = array_values( array_unique( $job['delta_imported'] ) );
			return array(
				'message' => sprintf( 'Delta-imported %s: no post_id set changed with posts.', $source_table ),
				'delta'   => true,
			);
		}

		foreach ( array_chunk( $dirty, 500 ) as $chunk ) {
			$id_list = implode( ',', $chunk );
			if ( false === $wpdb->query( "DELETE FROM `{$shadow}` WHERE `post_id` IN ({$id_list})" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs.
				return new \WP_Error( 'delta_delete', "Could not clear postmeta for dirty posts in {$shadow}: " . $wpdb->last_error );
			}
		}

		// Re-fetch meta only for posts that still exist on live (not deleted).
		$fetch_ids = array_values( array_unique( array_merge( $plan['new'], $plan['updated'] ) ) );
		$pairs     = Sync_Runner::build_replacement_pairs_public( $job['manifest']['site'] );
		$fetched   = 0;
		foreach ( array_chunk( $fetch_ids, self::POSTMETA_ID_BATCH ) as $chunk ) {
			$checkpoint = Sync_Runner::checkpoint_public( $job, true, Job::CHUNK_LOCK_TTL );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$statement_limit = isset( $job['preflight']['statement_limit'] ) ? (int) $job['preflight']['statement_limit'] : Sync_Runner::database_statement_limit_public();
			$response         = Client::get_json(
				'table',
				array(
					'table'           => $source_table,
					'post_ids'        => implode( ',', $chunk ),
					'max_bytes'       => $statement_limit,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$applied = self::apply_insert_chunk_to_shadow( $source_table, $shadow, $response, $pairs, array(), null, $chunk );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$fetched += (int) $applied;
		}

		if ( ! isset( $job['delta_imported'] ) || ! is_array( $job['delta_imported'] ) ) {
			$job['delta_imported'] = array();
		}
		$job['delta_imported'][] = $source_table;
		$job['delta_imported']   = array_values( array_unique( $job['delta_imported'] ) );

		return array(
			'message' => sprintf(
				'Delta-imported %s for %d dirty post_id(s): removed local meta for those IDs, fetched %d live row(s).',
				$source_table,
				count( $dirty ),
				$fetched
			),
			'delta'   => true,
		);
	}

	/** @return array<int,string>|\WP_Error id => post_modified_gmt */
	private static function load_local_posts_index( $table ) {
		global $wpdb;
		$map  = array();
		$last = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_modified_gmt FROM `{$table}` WHERE ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from working set.
					$last,
					self::INDEX_PAGE_ROWS
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) {
				return new \WP_Error( 'delta_local_index', "Could not read local posts index from {$table}: " . $wpdb->last_error );
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$id         = (int) $row['ID'];
				$map[ $id ] = (string) $row['post_modified_gmt'];
				$last       = $id;
			}
			if ( count( $rows ) < self::INDEX_PAGE_ROWS ) {
				break;
			}
		}
		return $map;
	}

	/** @return array<int,string>|\WP_Error */
	private static function fetch_remote_posts_index( &$job, $source_table ) {
		$map    = array();
		$cursor = null;
		while ( true ) {
			$checkpoint = Sync_Runner::checkpoint_public( $job, true, Job::CHUNK_LOCK_TTL );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$query = array(
				'table'      => $source_table,
				'row_index'  => 1,
				'columns'    => 'ID,post_modified_gmt',
				'limit'      => self::INDEX_PAGE_ROWS,
			);
			if ( null !== $cursor ) {
				$query['cursor'] = $cursor;
			}
			$page = Client::get_json( 'table', $query );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			if ( ! is_array( $page ) || ! isset( $page['rows'] ) || ! is_array( $page['rows'] ) ) {
				return new \WP_Error( 'delta_index_shape', "Source returned a malformed row index for {$source_table}." );
			}
			foreach ( $page['rows'] as $row ) {
				if ( ! isset( $row['ID'] ) ) {
					continue;
				}
				$map[ (int) $row['ID'] ] = (string) ( $row['post_modified_gmt'] ?? '' );
			}
			$done = ! empty( $page['done'] );
			if ( $done ) {
				break;
			}
			if ( empty( $page['next_cursor'] ) || ! is_string( $page['next_cursor'] ) ) {
				return new \WP_Error( 'delta_index_cursor', "Source omitted the next row-index cursor for {$source_table}." );
			}
			$cursor = $page['next_cursor'];
		}
		return $map;
	}

	/** @return true|\WP_Error */
	private static function assert_schema_compatible( $local_table, $manifest_table ) {
		global $wpdb;
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $local_table ), ARRAY_N );
		$sql    = is_array( $create ) && isset( $create[1] ) ? (string) $create[1] : '';
		if ( '' === $sql ) {
			return new \WP_Error( 'delta_schema', "Could not read local CREATE TABLE for {$local_table}." );
		}
		$manifest_hash = isset( $manifest_table['compat']['schema_sha256'] ) ? (string) $manifest_table['compat']['schema_sha256'] : '';
		if ( '' === $manifest_hash || ! hash_equals( $manifest_hash, DB_Exporter::schema_sha256( $sql ) ) ) {
			return new \WP_Error( 'delta_schema', "Local schema for {$local_table} does not match the live manifest; falling back to full import." );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function clone_table_to_shadow( $target, $shadow, $checkpoint_table ) {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $checkpoint_table, $shadow ) ) ) {
			return new \WP_Error( 'shadow_drop', 'Could not reset working tables for delta clone: ' . $wpdb->last_error );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $shadow, $target ) ) ) {
			return new \WP_Error( 'delta_clone', "Could not CREATE TABLE {$shadow} LIKE {$target}: " . $wpdb->last_error );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i SELECT * FROM %i', $shadow, $target ) ) ) {
			return new \WP_Error( 'delta_clone', "Could not copy rows into {$shadow}: " . $wpdb->last_error );
		}
		return true;
	}

	/**
	 * @param string[] $skip_sr_columns Columns to leave untouched during URL rewrite.
	 * @return int|\WP_Error Rows applied.
	 */
	private static function fetch_and_replace_rows( &$job, $source_table, $shadow, $ids, $pairs, $skip_sr_columns ) {
		$statement_limit = isset( $job['preflight']['statement_limit'] ) ? (int) $job['preflight']['statement_limit'] : Sync_Runner::database_statement_limit_public();
		$response        = Client::get_json(
			'table',
			array(
				'table'     => $source_table,
				'ids'       => implode( ',', array_map( 'intval', $ids ) ),
				'max_bytes' => $statement_limit,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return self::apply_insert_chunk_to_shadow( $source_table, $shadow, $response, $pairs, $skip_sr_columns, $ids, null );
	}

	/**
	 * @param int[]|null $sr_ids           Post IDs to rewrite after posts upsert.
	 * @param int[]|null $post_ids_for_meta Post IDs whose meta rows to rewrite after postmeta upsert.
	 * @return int|\WP_Error
	 */
	private static function apply_insert_chunk_to_shadow( $source_table, $shadow, $chunk, $pairs, $skip_sr_columns, $sr_ids = null, $post_ids_for_meta = null ) {
		global $wpdb;
		$statements = DB_Exporter::decode_statements( $chunk );
		if ( is_wp_error( $statements ) ) {
			return $statements;
		}
		$row_count = isset( $chunk['row_count'] ) ? (int) $chunk['row_count'] : 0;
		if ( empty( $statements ) ) {
			return $row_count;
		}
		foreach ( $statements as $statement ) {
			$head = "INSERT INTO `{$source_table}`";
			if ( 0 !== strpos( $statement, $head ) ) {
				return new \WP_Error( 'insert_format', "Unexpected INSERT format for delta on {$source_table}." );
			}
			$statement = 'REPLACE INTO `' . $shadow . '`' . substr( $statement, strlen( $head ) );
			if ( false === $wpdb->query( $statement ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Integrity-checked INSERT from source codec.
				return new \WP_Error( 'delta_replace', "REPLACE failed for {$shadow}: " . $wpdb->last_error );
			}
		}
		if ( ! empty( $pairs ) && null !== $sr_ids ) {
			$sr = Search_Replace::run_on_ids( $shadow, $pairs, $sr_ids, $skip_sr_columns );
			if ( is_wp_error( $sr ) ) {
				return $sr;
			}
		} elseif ( ! empty( $pairs ) && null !== $post_ids_for_meta ) {
			$sr = self::search_replace_postmeta_for_post_ids( $shadow, $pairs, $post_ids_for_meta );
			if ( is_wp_error( $sr ) ) {
				return $sr;
			}
		}
		return $row_count;
	}

	/**
	 * @param int[] $post_ids
	 * @return true|\WP_Error
	 */
	private static function search_replace_postmeta_for_post_ids( $shadow, $pairs, $post_ids ) {
		global $wpdb;
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $post_ids ) ) ) );
		if ( empty( $post_ids ) || empty( $pairs ) ) {
			return true;
		}
		$meta_ids = array();
		foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
			$id_list = implode( ',', $chunk );
			$found   = $wpdb->get_col( "SELECT meta_id FROM `{$shadow}` WHERE post_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs.
			if ( is_array( $found ) ) {
				foreach ( $found as $meta_id ) {
					$meta_ids[] = (int) $meta_id;
				}
			}
		}
		if ( empty( $meta_ids ) ) {
			return true;
		}
		$sr = Search_Replace::run_on_ids( $shadow, $pairs, $meta_ids, array() );
		return is_wp_error( $sr ) ? $sr : true;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return is_string( $found ) && $found === $table;
	}
}
