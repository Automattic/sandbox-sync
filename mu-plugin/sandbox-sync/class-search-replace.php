<?php
/**
 * Serialized-aware search & replace, run on the sandbox against the shadow
 * tables before they are swapped in.
 *
 * PHP-serialized values are unserialized (objects preserved via
 * __PHP_Incomplete_Class round-trip), recursively walked, and reserialized so
 * string length prefixes stay correct — required here because live.local and
 * sandbox.local have different lengths.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Search_Replace {

	const BATCH_ROWS = 500;

	/**
	 * Runs all $pairs on text-ish columns of rows whose primary key is in $ids.
	 * Single-column integer PK only.
	 *
	 * @return array|\WP_Error { updated: int, skipped_overflow: int }
	 */
	public static function run_on_ids( $table, $pairs, $ids, $skip_columns = array() ) {
		global $wpdb;
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
		if ( empty( $ids ) || empty( $pairs ) ) {
			return array(
				'updated' => 0,
				'skipped_overflow' => 0,
			);
		}
		$column_meta  = self::get_text_column_meta( $table );
		$text_columns = array_values( array_diff( array_keys( $column_meta ), (array) $skip_columns ) );
		$pk_columns   = self::get_pk_columns( $table );
		if ( 1 !== count( $pk_columns ) || empty( $text_columns ) ) {
			return array(
				'updated' => 0,
				'skipped_overflow' => 0,
			);
		}
		$text_columns = array_values( array_diff( $text_columns, $pk_columns ) );
		if ( empty( $text_columns ) ) {
			return array(
				'updated' => 0,
				'skipped_overflow' => 0,
			);
		}
		$pk          = $pk_columns[0];
		$select_cols = array_values( array_unique( array_merge( $pk_columns, $text_columns ) ) );
		$select_sql  = '`' . implode( '`, `', $select_cols ) . '`';
		$updated          = 0;
		$skipped_overflow = 0;
		foreach ( array_chunk( $ids, self::BATCH_ROWS ) as $chunk ) {
			$id_list = implode( ',', $chunk );
			$rows    = $wpdb->get_results( "SELECT {$select_sql} FROM `{$table}` WHERE `{$pk}` IN ({$id_list})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer IDs and metadata identifiers.
			if ( ! is_array( $rows ) ) {
				return new \WP_Error( 'sr_ids', "Could not read rows for search-replace in {$table}: " . $wpdb->last_error );
			}
			foreach ( $rows as $row ) {
				$changes = array();
				foreach ( $text_columns as $col ) {
					$value = $row[ $col ];
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}
					$new_value = self::replace_value( $value, $pairs );
					if ( $new_value === $value ) {
						continue;
					}
					$max = $column_meta[ $col ]['max_bytes'] ?? null;
					if ( null !== $max && strlen( $new_value ) > (int) $max ) {
						++$skipped_overflow;
						continue;
					}
					$changes[ $col ] = $new_value;
				}
				if ( empty( $changes ) ) {
					continue;
				}
				$result = $wpdb->update( $table, $changes, array( $pk => $row[ $pk ] ) );
				if ( false === $result ) {
					if ( self::is_data_too_long_error( $wpdb->last_error ) ) {
						$skipped_overflow += count( $changes );
						continue;
					}
					return new \WP_Error( 'sr_update', "Failed updating a row in {$table}: " . $wpdb->last_error );
				}
				++$updated;
			}
		}
		return array(
			'updated'          => $updated,
			'skipped_overflow' => $skipped_overflow,
		);
	}

	/**
	 * Runs all $pairs (from => to) on every text-ish column of $table.
	 *
	 * Values that would overflow a bounded CHAR/VARCHAR column after rewrite
	 * are left untouched (never truncated): a sync that lengthens the site
	 * hostname (live.local → sandbox.local) must not fail the whole job on a
	 * single Yoast URL that was already near the column limit.
	 *
	 * @return array|\WP_Error { updated: int, skipped_overflow: int }
	 */
	public static function run_on_table( $table, $pairs, $skip_columns = array() ) {
		global $wpdb;

		$column_meta = self::get_text_column_meta( $table );
		$text_columns = array_values( array_diff( array_keys( $column_meta ), (array) $skip_columns ) );
		if ( empty( $text_columns ) ) {
			return array(
				'updated' => 0,
				'skipped_overflow' => 0,
			);
		}

		$pk_columns = self::get_pk_columns( $table );
		if ( empty( $pk_columns ) ) {
			$row_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( null === $row_count ) {
				return new \WP_Error( 'sr_count', "Could not verify whether primary-key-less table {$table} is empty: " . $wpdb->last_error );
			}
			if ( 0 === (int) $row_count ) {
				return array(
					'updated' => 0,
					'skipped_overflow' => 0,
				);
			}
			return new \WP_Error( 'sr_no_pk', "Cannot safely search-replace non-empty primary-key-less table {$table}; exclude it or add a key." );
		}
		// Never rewrite a primary key: moving the key while walking it can skip
		// or revisit rows and changes database identity rather than content.
		$text_columns = array_values( array_diff( $text_columns, $pk_columns ) );
		if ( empty( $text_columns ) ) {
			return array(
				'updated' => 0,
				'skipped_overflow' => 0,
			);
		}

		$select_cols = array_values( array_unique( array_merge( $pk_columns, $text_columns ) ) );
		$select_sql  = '`' . implode( '`, `', $select_cols ) . '`';
		$order_sql   = 'ORDER BY `' . implode( '`, `', $pk_columns ) . '`';

		$updated          = 0;
		$skipped_overflow = 0;
		$cursor           = null;

		while ( true ) {
			$where = '';
			if ( is_array( $cursor ) ) {
				$cols   = '(`' . implode( '`, `', $pk_columns ) . '`)';
				$values = array();
				foreach ( $pk_columns as $pk ) {
					$values[] = "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $cursor[ $pk ] ) ) . "'";
				}
				$where = "WHERE {$cols} > (" . implode( ', ', $values ) . ')';
			}
			// All identifiers come from schema metadata; cursor values are escaped.
			// phpcs:disable WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results(
				"SELECT {$select_sql} FROM `{$table}` {$where} {$order_sql} LIMIT " . self::BATCH_ROWS,
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$changes = array();
				foreach ( $text_columns as $col ) {
					$value = $row[ $col ];
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}
					$new_value = self::replace_value( $value, $pairs );
					if ( $new_value === $value ) {
						continue;
					}
					$max = $column_meta[ $col ]['max_bytes'] ?? null;
					if ( null !== $max && strlen( $new_value ) > (int) $max ) {
						++$skipped_overflow;
						continue;
					}
					$changes[ $col ] = $new_value;
				}

				if ( ! empty( $changes ) ) {
					$where = array();
					foreach ( $pk_columns as $pk ) {
						$where[ $pk ] = $row[ $pk ];
					}
					$result = $wpdb->update( $table, $changes, $where );
					if ( false === $result ) {
						// Belt-and-suspenders: a race with a tightened schema, or a
						// multi-byte charset edge the byte ceiling missed, must not
						// sink the sync. Leave the row as imported and continue.
						if ( self::is_data_too_long_error( $wpdb->last_error ) ) {
							$skipped_overflow += count( $changes );
							continue;
						}
						return new \WP_Error( 'sr_update', "Failed updating a row in {$table}: " . $wpdb->last_error );
					}
					++$updated;
				}
			}

			if ( count( $rows ) < self::BATCH_ROWS ) {
				break;
			}
			$last   = end( $rows );
			$cursor = array_intersect_key( $last, array_flip( $pk_columns ) );
		}

		return array(
			'updated'          => $updated,
			'skipped_overflow' => $skipped_overflow,
		);
	}

	/**
	 * True when MySQL/MariaDB refused a write because a value exceeded the
	 * column's declared length (errno 1406 / "Data too long" / WP's wrapped form).
	 */
	public static function is_data_too_long_error( $message ) {
		$message = (string) $message;
		return false !== stripos( $message, 'Data too long' )
			|| false !== stripos( $message, 'too long or contains invalid data' )
			|| (bool) preg_match( '/\b1406\b/', $message );
	}

	/**
	 * Byte ceiling for a rewritten value in $column_type, or null when the
	 * column is unbounded (TEXT/BLOB/JSON/ENUM/SET). CHAR/VARCHAR lengths are
	 * character counts; for utf8mb4 that is a safe upper bound on bytes only
	 * when we treat it as characters — strlen is bytes, so multi-byte content
	 * can still trip the engine. The update-error fallback covers that case.
	 *
	 * @return int|null
	 */
	public static function column_max_bytes( $column_type ) {
		$type = strtolower( (string) $column_type );
		if ( preg_match( '/^(?:var)?char\((\d+)\)/', $type, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Replaces $pairs inside a single value, handling PHP-serialized data.
	 */
	public static function replace_value( $value, $pairs ) {
		if ( is_serialized( $value ) ) {
			// Rewrite length-prefixed serialized string tokens directly. This
			// updates array keys and object properties without instantiating classes,
			// invoking magic methods, or losing references/private-property names.
			$rewritten = self::rewrite_serialized_strings( $value, $pairs );
			$data      = @unserialize( $rewritten, array( 'allowed_classes' => false ) );
			if ( false !== $data || 'b:0;' === $rewritten ) {
				return $rewritten;
			}
			// Broken serialized data: leave untouched rather than corrupt it.
			return $value;
		}

		// Include JSON-style escaped slashes without re-encoding the complete
		// value (which would otherwise alter formatting and number semantics).
		$expanded = $pairs;
		foreach ( $pairs as $from => $to ) {
			$escaped_from = str_replace( '/', '\\/', $from );
			$escaped_to   = str_replace( '/', '\\/', $to );
			if ( $escaped_from !== $from ) {
				$expanded[ $escaped_from ] = $escaped_to;
			}
		}
		return strtr( $value, $expanded );
	}

	private static function rewrite_serialized_strings( $serialized, $pairs ) {
		$out    = '';
		$length = strlen( $serialized );
		$i      = 0;
		while ( $i < $length ) {
			// Standard serialized string: s:<byte-length>:"<raw bytes>";
			if ( 's' === $serialized[ $i ] && preg_match( '/\Gs:(\d+):"/', $serialized, $m, 0, $i ) ) {
				$header_len = strlen( $m[0] );
				$data_len   = (int) $m[1];
				$start      = $i + $header_len;
				$end        = $start + $data_len;
				if ( $end + 2 <= $length && '";' === substr( $serialized, $end, 2 ) ) {
					$value = substr( $serialized, $start, $data_len );
					$value = strtr( $value, $pairs );
					$out  .= 's:' . strlen( $value ) . ':"' . $value . '";';
					$i     = $end + 2;
					continue;
				}
			}

			// Custom-serialized object payloads are opaque application bytes. Copy
			// the complete C token unchanged rather than interpreting accidental
			// "s:" sequences inside it.
			if ( 'C' === $serialized[ $i ] && preg_match( '/\GC:(\d+):"/', $serialized, $m, 0, $i ) ) {
				$class_start = $i + strlen( $m[0] );
				$class_end   = $class_start + (int) $m[1];
				$tail        = substr( $serialized, $class_end );
				if ( preg_match( '/^":(\d+):\{/', $tail, $dm ) ) {
					$data_start = $class_end + strlen( $dm[0] );
					$token_end  = $data_start + (int) $dm[1] + 1;
					if ( $token_end <= $length && '}' === $serialized[ $token_end - 1 ] ) {
						$out .= substr( $serialized, $i, $token_end - $i );
						$i    = $token_end;
						continue;
					}
				}
			}

			$out .= $serialized[ $i ];
			++$i;
		}
		return $out;
	}

	/**
	 * @return array<string,array{max_bytes:?int}> column => meta
	 */
	private static function get_text_column_meta( $table ) {
		global $wpdb;
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$meta    = array();
		foreach ( (array) $columns as $col ) {
			if ( preg_match( '/char|text|json|enum|set/i', $col['Type'] ) ) {
				$meta[ $col['Field'] ] = array(
					'max_bytes' => self::column_max_bytes( $col['Type'] ),
				);
			}
		}
		return $meta;
	}

	private static function get_pk_columns( $table ) {
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
}
