<?php
/**
 * Row-delta helpers: plan budget and table suffix detection (no DB).
 */

use SandboxSync\Table_Delta;

ss_test_section( 'Table delta: suffix helpers' );

ss_assert_same( 'posts', Table_Delta::table_suffix( 'wp_posts', 'wp_' ), 'strips prefix for posts' );
ss_assert_same( 'postmeta', Table_Delta::table_suffix( 'wp_postmeta', 'wp_' ), 'strips prefix for postmeta' );
ss_assert( Table_Delta::is_posts_table( 'wp_posts', 'wp_' ), 'detects posts table' );
ss_assert( Table_Delta::is_postmeta_table( 'wp_postmeta', 'wp_' ), 'detects postmeta table' );
ss_assert( ! Table_Delta::is_posts_table( 'wp_postmeta', 'wp_' ), 'postmeta is not posts' );

ss_test_section( 'Table delta: plan budget' );

$small = array(
	'dirty'        => range( 1, 10 ),
	'local_count'  => 100000,
	'remote_count' => 100001,
);
ss_assert_same( true, Table_Delta::assert_plan_within_budget( $small ), 'small delta is within budget' );

$too_many = array(
	'dirty'        => range( 1, Table_Delta::MAX_CHANGED_ROWS + 1 ),
	'local_count'  => 1000000,
	'remote_count' => 1000000,
);
ss_assert( is_wp_error( Table_Delta::assert_plan_within_budget( $too_many ) ), 'absolute row cap rejects huge deltas' );

$too_ratio = array(
	'dirty'        => range( 1, 40 ),
	'local_count'  => 100,
	'remote_count' => 100,
);
ss_assert( is_wp_error( Table_Delta::assert_plan_within_budget( $too_ratio ) ), 'ratio cap rejects large relative deltas' );
