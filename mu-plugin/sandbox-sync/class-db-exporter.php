<?php
/**
 * Source-side database export: table manifest + per-table chunked SQL export.
 *
 * Pure PHP (no mysqldump/exec) so the same code would run on WP Cloud/Atomic.
 * Chunk paging is keyset-based (WHERE pk > cursor) so exporting stays O(chunk)
 * regardless of table size; tables without a primary key fall back to
 * OFFSET paging. Statements travel gzipped+base64 (SQL text compresses
 * 5-15x, and gzip is binary-safe so blob values survive JSON transport).
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class DB_Exporter {

	// Default negotiated INSERT limit. The target can request a smaller/larger
	// value within the hard ceiling advertised by the endpoint.
	const MAX_STATEMENT_BYTES      = 819200;
	const HARD_MAX_STATEMENT_BYTES = 16777216;
	const MAX_DECODE_BYTES         = 33554432;

	// Joins statements inside the gzipped payload. _real_escape encodes NUL
	// bytes as the two characters \0, so generated SQL never contains raw
	// NULs — a NUL-containing marker cannot collide with data.
	const STMT_SEPARATOR = "\n\0/*SBS*/\0\n";

	/**
	 * Structural CREATE TABLE text for schema hashing/comparison.
	 *
	 * The hash compares a source table with its sandbox copy (different
	 * prefix), with a job's shadow table (different name again) and with
	 * the same table on a different server, so everything that SHOW CREATE
	 * TABLE prints without describing the structure is dropped:
	 *
	 * - the table name;
	 * - AUTO_INCREMENT=N, so a busy live table does not look like a schema
	 *   change mid-job when only the counter advanced;
	 * - a column CHARACTER SET equal to the table's DEFAULT CHARSET, and a
	 *   column COLLATE equal to the table's explicit COLLATE=. MySQL 8 prints
	 *   `text COLLATE x` for a dbDelta-created column but `text CHARACTER
	 *   SET utf8mb4 COLLATE x` once that statement is executed again, and
	 *   MariaDB prints neither; all three describe the same column. A column
	 *   whose charset or collation really differs from the table default
	 *   keeps its clause and still changes the hash.
	 *
	 * @param string $sql SHOW CREATE TABLE statement.
	 */
	public static function normalize_create_sql( $sql ) {
		$sql = (string) $sql;
		$sql = preg_replace( '/^CREATE TABLE `(?:[^`]|``)+`/', 'CREATE TABLE `_`', $sql, 1 );
		// Trailing table options: ENGINE=InnoDB AUTO_INCREMENT=12345 DEFAULT CHARSET=...
		$sql = preg_replace( '/\sAUTO_INCREMENT=\d+/i', '', $sql );

		$close = strrpos( $sql, ')' );
		if ( false === $close ) {
			return $sql;
		}
		$body    = substr( $sql, 0, $close );
		$options = substr( $sql, $close );
		if ( preg_match( '/\bDEFAULT CHARSET=(\w+)/i', $options, $m ) ) {
			$body = preg_replace( '/\sCHARACTER SET ' . preg_quote( $m[1], '/' ) . '(?=\s|,|$)/i', '', $body );
		}
		if ( preg_match( '/\bCOLLATE=(\w+)/i', $options, $m ) ) {
			$body = preg_replace( '/\sCOLLATE ' . preg_quote( $m[1], '/' ) . '(?=\s|,|$)/i', '', $body );
		}
		return $body . $options;
	}

	/**
	 * SHA-256 of the structural CREATE TABLE (AUTO_INCREMENT-insensitive).
	 *
	 * @param string $sql SHOW CREATE TABLE statement.
	 */
	public static function schema_sha256( $sql ) {
		return hash( 'sha256', self::normalize_create_sql( $sql ) );
	}

	/**
	 * All tables sharing this site's prefix, with size/row estimates, the
	 * average row length (drives adaptive chunk sizing on the sandbox) and,
	 * optionally, a CHECKSUM TABLE value (drives incremental table skipping).
	 *
	 * @param bool  $with_checksums     Include per-table checksums (extra cost).
	 * @param array $schema_only_tables Prefix-free table suffixes whose rows must not be read.
	 */
	public static function get_manifest( $with_checksums = false, $schema_only_tables = array() ) {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );

		$tables = array();
		foreach ( (array) $status as $row ) {
			$suffix      = 0 === strpos( $row['Name'], $wpdb->prefix ) ? substr( $row['Name'], strlen( $wpdb->prefix ) ) : $row['Name'];
			$schema_only = in_array( strtolower( $suffix ), (array) $schema_only_tables, true );
			$compat      = self::table_compatibility( $row, $schema_only );
			$table       = array(
				'name'      => $row['Name'],
				'rows'      => isset( $compat['exact_rows'] ) ? (int) $compat['exact_rows'] : (int) $row['Rows'],
				'size'      => (int) $row['Data_length'] + (int) $row['Index_length'],
				'avg_row'   => (int) $row['Avg_row_length'],
				'engine'    => isset( $row['Engine'] ) ? $row['Engine'] : null,
				'collation' => isset( $row['Collation'] ) ? $row['Collation'] : null,
				'compat'    => $compat,
			);
			if ( $with_checksums ) {
				$table['checksum'] = $schema_only ? null : self::table_checksum( $row['Name'] );
			}
			$tables[] = $table;
		}
		return $tables;
	}

	private static function table_compatibility( $status_row, $schema_only = false ) {
		global $wpdb;
		$table               = $status_row['Name'];
		$reasons             = array();
		$schema_only_reasons = array();
		$engine              = isset( $status_row['Engine'] ) ? strtoupper( (string) $status_row['Engine'] ) : '';
		if ( '' === $engine ) {
			$schema_only_reasons[] = 'views are not supported';
		} elseif ( 'INNODB' !== $engine ) {
			$schema_only_reasons[] = "storage engine {$engine} is not supported (InnoDB required)";
		}

		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		$sql    = is_array( $create ) && isset( $create[1] ) ? $create[1] : '';
		if ( '' === $sql ) {
			$schema_only_reasons[] = 'CREATE TABLE could not be read';
		} elseif ( preg_match( '/\bFOREIGN KEY\b/i', $sql ) ) {
			$schema_only_reasons[] = 'foreign-key tables require graph-aware remapping';
		}
		$reasons         = $schema_only_reasons;
		$has_primary_key = '' !== $sql && preg_match( '/\bPRIMARY KEY\b/i', $sql );
		$exact_rows       = null;
		if ( '' !== $sql && ! $has_primary_key ) {
			if ( $schema_only ) {
				$reasons[] = 'full sync requires a primary key; this manifest intentionally did not inspect schema-only table rows';
			} else {
				// An empty table needs only its exact CREATE TABLE statement, so there
				// are no rows to page, resume, or URL-rewrite. Preserve compatibility
				// with common queue/log tables that are empty on the source while still
				// rejecting ambiguous non-empty tables.
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
				if ( null === $count ) {
					$reasons[] = 'the exact row count could not be read for a primary-key-less table';
				} else {
					$exact_rows = (int) $count;
				}
				if ( null !== $exact_rows && $exact_rows > 0 ) {
					$reasons[] = 'a non-empty table requires a primary key for resumable transfer and safe URL rewriting';
				}
			}
		}

		$triggers = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = %s',
				$table
			)
		);
		if ( (int) $triggers > 0 ) {
			$schema_only_reasons[] = 'table triggers are not copied';
			$reasons[]             = 'table triggers are not copied';
		}

		return array(
			'supported'                 => empty( $reasons ),
			'reasons'                   => $reasons,
			'schema_only_supported'     => empty( $schema_only_reasons ),
			'schema_only_reasons'       => $schema_only_reasons,
			'schema_sha256'             => '' !== $sql ? self::schema_sha256( $sql ) : null,
			'has_primary_key'           => (bool) $has_primary_key,
			'empty_without_primary_key' => ! $has_primary_key && 0 === $exact_rows,
			'exact_rows'                => $exact_rows,
		);
	}

	/**
	 * Exact source CREATE TABLE statement for schema-only transfer.
	 *
	 * @return string|\WP_Error
	 */
	public static function export_schema( $table ) {
		global $wpdb;
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		if ( ! $create || ! isset( $create[1] ) || '' === (string) $create[1] ) {
			return new \WP_Error( 'no_create', "Could not read CREATE TABLE for {$table}." );
		}
		return (string) $create[1];
	}

	/**
	 * CHECKSUM TABLE value, or null when unavailable.
	 *
	 * @return string|null
	 */
	public static function table_checksum( $table ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'CHECKSUM TABLE %i', $table ), ARRAY_N );
		return ( is_array( $row ) && isset( $row[1] ) && null !== $row[1] ) ? (string) $row[1] : null;
	}

	public static function exact_row_count( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Is $table one of the tables this exporter is allowed to dump?
	 */
	public static function is_exportable( $table ) {
		global $wpdb;
		if ( ! is_string( $table ) || 0 !== strpos( $table, $wpdb->prefix ) ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (string) $found === $table;
	}

	/**
	 * Exports one chunk of a table.
	 *
	 * Paging: when the table has a primary key and $cursor is given (the
	 * next_cursor from the previous chunk), rows after the cursor are
	 * selected with a keyset WHERE — O(chunk) per request. The first chunk
	 * ($cursor = null, $offset = 0). Current compatibility preflight rejects
	 * PK-less tables; the OFFSET argument remains only for old direct callers.
	 *
	 * @param string     $table  Table name.
	 * @param int        $offset OFFSET fallback (PK-less tables / legacy clients).
	 * @param int        $limit  Max rows in this chunk.
	 * @param array|null $cursor PK values of the last row of the previous chunk.
	 * @return array|\WP_Error Chunk fields include create_sql, insert_sql,
	 *                         row_count, done and next_cursor.
	 */
	public static function export_chunk( $table, $offset, $limit, $cursor = null, $max_statement_bytes = null ) {
		global $wpdb;
		$max_statement_bytes = null === $max_statement_bytes ? self::MAX_STATEMENT_BYTES : (int) $max_statement_bytes;
		$max_statement_bytes = max( 65536, min( self::HARD_MAX_STATEMENT_BYTES, $max_statement_bytes ) );

		$result = array(
			'create_sql'  => '',
			'insert_sql'  => array(),
			'row_count'   => 0,
			'done'        => true,
			'next_cursor' => null,
		);

		$first_chunk = 0 === $offset && null === $cursor;
		if ( $first_chunk ) {
			$create = self::export_schema( $table );
			if ( is_wp_error( $create ) ) {
				return $create;
			}
			$result['create_sql'] = $create;
		}

		$pk_columns     = self::get_pk_columns( $table );
		$column_rows    = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		$select_columns = array();
		foreach ( (array) $column_rows as $column ) {
			if ( empty( $column['Extra'] ) || false === stripos( $column['Extra'], 'GENERATED' ) ) {
				$select_columns[] = $column['Field'];
			}
		}
		if ( empty( $select_columns ) ) {
			return new \WP_Error( 'no_columns', "No importable columns found for {$table}." );
		}
		$select_sql = '`' . implode( '`, `', $select_columns ) . '`';
		foreach ( $pk_columns as $pk ) {
			if ( ! in_array( $pk, $select_columns, true ) ) {
				return new \WP_Error( 'generated_pk', "Generated primary-key column {$pk} is not supported in {$table}." );
			}
		}

		if ( ! empty( $pk_columns ) ) {
			$order_by = 'ORDER BY `' . implode( '`, `', $pk_columns ) . '`';
			$where    = '';
			if ( is_array( $cursor ) ) {
				// Row-value comparison keeps composite PKs correct:
				// (a, b) > ('x', 'y') is true lexicographic ordering.
				$cols   = '(`' . implode( '`, `', $pk_columns ) . '`)';
				$values = array();
				foreach ( $pk_columns as $column ) {
					if ( ! array_key_exists( $column, $cursor ) ) {
						return new \WP_Error( 'bad_cursor', "Cursor is missing PK column {$column} for {$table}." );
					}
					$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $cursor[ $column ] ) ) . "'";
				}
				$where = "WHERE {$cols} > (" . implode( ', ', $values ) . ')';
			}
			// Identifiers are sourced from SHOW metadata; cursor values are escaped above.
			$rows = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` {$where} {$order_by} LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Metadata identifiers and bounded integers only.
		} else {
			// No PK: OFFSET paging (O(n²) overall, but PK-less WP tables are
			// rare and small).
			$rows = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` LIMIT {$offset}, {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Metadata identifiers and bounded integers only.
		}
		$rows = is_array( $rows ) ? $rows : array();

		$result['row_count'] = count( $rows );
		$result['done']      = count( $rows ) < $limit;

		if ( ! empty( $pk_columns ) && ! empty( $rows ) ) {
			$last                  = end( $rows );
			$result['next_cursor'] = array_intersect_key( $last, array_flip( $pk_columns ) );
		}

		if ( empty( $rows ) ) {
			return $result;
		}

		$columns      = array_keys( $rows[0] );
		$columns_sql  = '`' . implode( '`, `', $columns ) . '`';
		$insert_head  = "INSERT INTO `{$table}` ({$columns_sql}) VALUES\n";
		$statements   = array();
		$value_groups = array();
		$bytes        = strlen( $insert_head );

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $col ) {
				$value = $row[ $col ];
				if ( null === $value ) {
					$values[] = 'NULL';
				} else {
					// Quoting everything is safe: MySQL coerces quoted
					// numerics, and _real_escape is binary-safe.
					// remove_placeholder_escape is essential: _real_escape
					// replaces every literal '%' with a per-request hash that
					// is only stripped when the query runs on THIS site — but
					// these statements execute on the sandbox, so the hash
					// would leak into the data (e.g. '/%postname%/').
					$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
				}
			}
			$group       = '(' . implode( ',', $values ) . ')';
			$group_bytes = strlen( $group ) + 2;
			if ( strlen( $insert_head ) + $group_bytes > $max_statement_bytes ) {
				return new \WP_Error( 'row_too_large', sprintf( 'One encoded row in %s requires %d bytes, exceeding the negotiated %d-byte statement limit.', $table, strlen( $insert_head ) + $group_bytes, $max_statement_bytes ) );
			}
			if ( ! empty( $value_groups ) && $bytes + $group_bytes > $max_statement_bytes ) {
				$statements[] = $insert_head . implode( ",\n", $value_groups ) . ';';
				$value_groups = array();
				$bytes        = strlen( $insert_head );
			}
			$value_groups[] = $group;
			$bytes         += $group_bytes;
		}

		$statements[] = $insert_head . implode( ",\n", $value_groups ) . ';';

		$result['insert_sql'] = $statements;
		return $result;
	}

	/**
	 * Lightweight (pk, version) index page for row-delta planning.
	 *
	 * @param string     $table   Table name.
	 * @param string[]   $columns Columns to select (must include PK columns first-compatible).
	 * @param int        $limit   Max rows.
	 * @param array|null $cursor  Last PK map from previous page.
	 * @return array|\WP_Error { rows: array<array>, done: bool, next_cursor: ?array }
	 */
	public static function export_row_index( $table, $columns, $limit, $cursor = null ) {
		global $wpdb;
		$limit      = max( 1, min( 10000, (int) $limit ) );
		$pk_columns = self::get_pk_columns( $table );
		if ( empty( $pk_columns ) ) {
			return new \WP_Error( 'no_pk', "Row index requires a primary key on {$table}." );
		}
		$columns = array_values( array_unique( array_map( 'strval', (array) $columns ) ) );
		foreach ( array_merge( $pk_columns, $columns ) as $column ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
				return new \WP_Error( 'bad_column', "Invalid column name for row index on {$table}." );
			}
		}
		foreach ( $pk_columns as $pk ) {
			if ( ! in_array( $pk, $columns, true ) ) {
				array_unshift( $columns, $pk );
			}
		}
		$select_sql = '`' . implode( '`, `', $columns ) . '`';
		$order_by   = 'ORDER BY `' . implode( '`, `', $pk_columns ) . '`';
		$where      = '';
		if ( is_array( $cursor ) ) {
			$cols   = '(`' . implode( '`, `', $pk_columns ) . '`)';
			$values = array();
			foreach ( $pk_columns as $column ) {
				if ( ! array_key_exists( $column, $cursor ) ) {
					return new \WP_Error( 'bad_cursor', "Cursor is missing PK column {$column} for {$table}." );
				}
				$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $cursor[ $column ] ) ) . "'";
			}
			$where = "WHERE {$cols} > (" . implode( ', ', $values ) . ')';
		}
		$rows = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` {$where} {$order_by} LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Validated identifiers and escaped cursor.
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'row_index_query', "Could not read the row index for {$table}: " . $wpdb->last_error );
		}
		$next = null;
		if ( ! empty( $rows ) ) {
			$last = end( $rows );
			$next = array_intersect_key( $last, array_flip( $pk_columns ) );
		}
		return array(
			'rows'        => $rows,
			'done'        => count( $rows ) < $limit,
			'next_cursor' => $next,
		);
	}

	/**
	 * Exports full rows whose primary key is in $ids (integer PKs only).
	 *
	 * @param string $table Table name.
	 * @param int[]  $ids   Primary-key values (single-column numeric PK).
	 * @param int    $max_statement_bytes Statement size ceiling.
	 * @return array|\WP_Error Same shape as export_chunk() without create_sql.
	 */
	public static function export_rows_by_ids( $table, $ids, $max_statement_bytes = null ) {
		global $wpdb;
		$max_statement_bytes = null === $max_statement_bytes ? self::MAX_STATEMENT_BYTES : (int) $max_statement_bytes;
		$max_statement_bytes = max( 65536, min( self::HARD_MAX_STATEMENT_BYTES, $max_statement_bytes ) );
		$pk_columns          = self::get_pk_columns( $table );
		if ( 1 !== count( $pk_columns ) ) {
			return new \WP_Error( 'pk_shape', "export_rows_by_ids requires a single-column primary key on {$table}." );
		}
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $ids ),
					function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( empty( $ids ) ) {
			return array(
				'create_sql'  => '',
				'insert_sql'  => array(),
				'row_count'   => 0,
				'done'        => true,
				'next_cursor' => null,
			);
		}
		if ( count( $ids ) > 500 ) {
			return new \WP_Error( 'ids_limit', 'At most 500 primary keys may be requested per rows-by-id chunk.' );
		}

		$column_rows    = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		$select_columns = array();
		foreach ( (array) $column_rows as $column ) {
			if ( empty( $column['Extra'] ) || false === stripos( $column['Extra'], 'GENERATED' ) ) {
				$select_columns[] = $column['Field'];
			}
		}
		if ( empty( $select_columns ) ) {
			return new \WP_Error( 'no_columns', "No importable columns found for {$table}." );
		}
		$pk         = $pk_columns[0];
		$select_sql = '`' . implode( '`, `', $select_columns ) . '`';
		$id_list    = implode( ',', $ids );
		$rows       = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` WHERE `{$pk}` IN ({$id_list})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs and metadata identifiers.
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'rows_by_id_query', "Could not export selected rows from {$table}: " . $wpdb->last_error );
		}
		if ( empty( $rows ) ) {
			return array(
				'create_sql'  => '',
				'insert_sql'  => array(),
				'row_count'   => 0,
				'done'        => true,
				'next_cursor' => null,
			);
		}

		$columns      = array_keys( $rows[0] );
		$columns_sql  = '`' . implode( '`, `', $columns ) . '`';
		$insert_head  = "INSERT INTO `{$table}` ({$columns_sql}) VALUES\n";
		$statements   = array();
		$value_groups = array();
		$bytes        = strlen( $insert_head );
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $col ) {
				$value = $row[ $col ];
				if ( null === $value ) {
					$values[] = 'NULL';
				} else {
					$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
				}
			}
			$group       = '(' . implode( ',', $values ) . ')';
			$group_bytes = strlen( $group ) + 2;
			if ( strlen( $insert_head ) + $group_bytes > $max_statement_bytes ) {
				return new \WP_Error( 'row_too_large', sprintf( 'One encoded row in %s requires %d bytes, exceeding the negotiated %d-byte statement limit.', $table, strlen( $insert_head ) + $group_bytes, $max_statement_bytes ) );
			}
			if ( ! empty( $value_groups ) && $bytes + $group_bytes > $max_statement_bytes ) {
				$statements[] = $insert_head . implode( ",\n", $value_groups ) . ';';
				$value_groups = array();
				$bytes        = strlen( $insert_head );
			}
			$value_groups[] = $group;
			$bytes         += $group_bytes;
		}
		$statements[] = $insert_head . implode( ",\n", $value_groups ) . ';';
		return array(
			'create_sql'  => '',
			'insert_sql'  => $statements,
			'row_count'   => count( $rows ),
			'done'        => true,
			'next_cursor' => null,
		);
	}

	/**
	 * Exports postmeta (or similar) rows for a set of post_id values.
	 *
	 * @param string $table Table name (must have post_id column).
	 * @param int[]  $post_ids Parent post IDs.
	 * @param int    $max_statement_bytes Statement size ceiling.
	 * @return array|\WP_Error Same shape as export_chunk() without create_sql.
	 */
	public static function export_rows_by_post_ids( $table, $post_ids, $max_statement_bytes = null ) {
		global $wpdb;
		$max_statement_bytes = null === $max_statement_bytes ? self::MAX_STATEMENT_BYTES : (int) $max_statement_bytes;
		$max_statement_bytes = max( 65536, min( self::HARD_MAX_STATEMENT_BYTES, $max_statement_bytes ) );
		$post_ids            = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $post_ids ),
					function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( empty( $post_ids ) ) {
			return array(
				'create_sql'  => '',
				'insert_sql'  => array(),
				'row_count'   => 0,
				'done'        => true,
				'next_cursor' => null,
			);
		}
		if ( count( $post_ids ) > 100 ) {
			return new \WP_Error( 'ids_limit', 'At most 100 post_id values may be requested per postmeta chunk.' );
		}
		$column_rows    = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		$select_columns = array();
		$has_post_id    = false;
		foreach ( (array) $column_rows as $column ) {
			if ( empty( $column['Extra'] ) || false === stripos( $column['Extra'], 'GENERATED' ) ) {
				$select_columns[] = $column['Field'];
			}
			if ( 'post_id' === $column['Field'] ) {
				$has_post_id = true;
			}
		}
		if ( ! $has_post_id || empty( $select_columns ) ) {
			return new \WP_Error( 'no_post_id', "Table {$table} has no usable post_id column." );
		}
		$select_sql = '`' . implode( '`, `', $select_columns ) . '`';
		$id_list    = implode( ',', $post_ids );
		$rows       = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` WHERE `post_id` IN ({$id_list})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs and metadata identifiers.
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'rows_by_post_query', "Could not export selected post rows from {$table}: " . $wpdb->last_error );
		}
		if ( empty( $rows ) ) {
			return array(
				'create_sql'  => '',
				'insert_sql'  => array(),
				'row_count'   => 0,
				'done'        => true,
				'next_cursor' => null,
			);
		}
		$columns      = array_keys( $rows[0] );
		$columns_sql  = '`' . implode( '`, `', $columns ) . '`';
		$insert_head  = "INSERT INTO `{$table}` ({$columns_sql}) VALUES\n";
		$statements   = array();
		$value_groups = array();
		$bytes        = strlen( $insert_head );
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $col ) {
				$value = $row[ $col ];
				if ( null === $value ) {
					$values[] = 'NULL';
				} else {
					$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
				}
			}
			$group       = '(' . implode( ',', $values ) . ')';
			$group_bytes = strlen( $group ) + 2;
			if ( strlen( $insert_head ) + $group_bytes > $max_statement_bytes ) {
				return new \WP_Error( 'row_too_large', sprintf( 'One encoded row in %s requires %d bytes, exceeding the negotiated %d-byte statement limit.', $table, strlen( $insert_head ) + $group_bytes, $max_statement_bytes ) );
			}
			if ( ! empty( $value_groups ) && $bytes + $group_bytes > $max_statement_bytes ) {
				$statements[] = $insert_head . implode( ",\n", $value_groups ) . ';';
				$value_groups = array();
				$bytes        = strlen( $insert_head );
			}
			$value_groups[] = $group;
			$bytes         += $group_bytes;
		}
		$statements[] = $insert_head . implode( ",\n", $value_groups ) . ';';
		return array(
			'create_sql'  => '',
			'insert_sql'  => $statements,
			'row_count'   => count( $rows ),
			'done'        => true,
			'next_cursor' => null,
		);
	}

	/**
	 * Primary-key column names in index order (empty array = no PK).
	 *
	 * @return string[]
	 */
	public static function get_pk_columns( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), ARRAY_A );
		if ( empty( $keys ) ) {
			return array();
		}
		usort(
			$keys,
			function ( $a, $b ) {
				return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
			}
		);
		return array_map(
			function ( $k ) {
				return $k['Column_name'];
			},
			$keys
		);
	}

	// -- Wire codec ------------------------------------------------------------

	/**
	 * Packs SQL statements for JSON transport: gzip (binary-safe, SQL text
	 * compresses 5-15x) then base64. Falls back to plain base64 per
	 * statement when zlib is unavailable. Pure function (unit-tested).
	 *
	 * @param string[] $statements SQL statements.
	 * @return array One of [ 'sql_gz_b64' => string ] or [ 'sql_b64' => string[] ].
	 */
	public static function encode_statements( $statements ) {
		$sql  = implode( self::STMT_SEPARATOR, $statements );
		$hash = hash( 'sha256', $sql );
		if ( function_exists( 'gzencode' ) ) {
			return array(
				'sql_gz_b64' => base64_encode( gzencode( $sql, 6 ) ),
				'sql_sha256' => $hash,
			);
		}
		return array(
			'sql_b64'    => array_map( 'base64_encode', $statements ),
			'sql_sha256' => $hash,
		);
	}

	/**
	 * Unpacks statements produced by encode_statements() out of a decoded
	 * chunk response.
	 *
	 * @param array $payload Decoded JSON chunk (or any array containing the
	 *                       codec fields).
	 * @return string[]|\WP_Error
	 */
	public static function decode_statements( $payload ) {
		$max_decoded = (int) apply_filters( 'sandbox_sync_max_sql_decode_bytes', self::MAX_DECODE_BYTES );
		$max_decoded = max( 1048576, min( 268435456, $max_decoded ) );
		if ( empty( $payload['sql_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $payload['sql_sha256'] ) ) {
			return new \WP_Error( 'missing_payload_hash', 'SQL chunk is missing its required SHA-256 integrity digest.' );
		}
		if ( isset( $payload['sql_gz_b64'] ) && '' !== $payload['sql_gz_b64'] ) {
			if ( ! function_exists( 'gzdecode' ) ) {
				return new \WP_Error( 'no_zlib', 'Source sent a gzipped chunk but zlib is unavailable on this site.' );
			}
			if ( strlen( (string) $payload['sql_gz_b64'] ) > ( $max_decoded * 2 ) ) {
				return new \WP_Error( 'payload_too_large', 'Compressed SQL chunk exceeds the configured transfer limit.' );
			}
			$raw = base64_decode( (string) $payload['sql_gz_b64'], true );
			// @: corrupt input already surfaces as the WP_Error below.
			$sql = false === $raw ? false : @gzdecode( $raw, $max_decoded + 1 );
			if ( false === $sql ) {
				return new \WP_Error( 'bad_payload', 'Could not decode the gzipped SQL chunk.' );
			}
			if ( strlen( $sql ) > $max_decoded ) {
				return new \WP_Error( 'payload_too_large', 'Decoded SQL chunk exceeds the configured transfer limit.' );
			}
			if ( ! hash_equals( (string) $payload['sql_sha256'], hash( 'sha256', $sql ) ) ) {
				return new \WP_Error( 'bad_payload_hash', 'Gzipped SQL chunk failed its SHA-256 integrity check.' );
			}
			// Empty-statement guard (an empty statement list encodes to '').
			return array_values( array_filter( explode( self::STMT_SEPARATOR, $sql ), 'strlen' ) );
		}

		if ( isset( $payload['sql_b64'] ) && is_array( $payload['sql_b64'] ) ) {
			if ( count( $payload['sql_b64'] ) > 10000 ) {
				return new \WP_Error( 'payload_too_large', 'SQL chunk contains too many statements.' );
			}
			$decoded = array();
			$total   = 0;
			foreach ( $payload['sql_b64'] as $encoded ) {
				if ( ! is_string( $encoded ) || strlen( $encoded ) > ( $max_decoded * 2 ) ) {
					return new \WP_Error( 'payload_too_large', 'Encoded SQL statement exceeds the configured transfer limit.' );
				}
				$value = base64_decode( $encoded, true );
				if ( false === $value ) {
					return new \WP_Error( 'bad_payload', 'Could not decode a SQL statement.' );
				}
				$total += strlen( $value );
				if ( $total > $max_decoded ) {
					return new \WP_Error( 'payload_too_large', 'Decoded SQL chunk exceeds the configured transfer limit.' );
				}
				$decoded[] = $value;
			}
			$sql = implode( self::STMT_SEPARATOR, $decoded );
			if ( ! hash_equals( (string) $payload['sql_sha256'], hash( 'sha256', $sql ) ) ) {
				return new \WP_Error( 'bad_payload_hash', 'SQL chunk failed its SHA-256 integrity check.' );
			}
			return $decoded;
		}

		return new \WP_Error( 'missing_payload', 'SQL chunk contains no supported statement payload.' );
	}
}
