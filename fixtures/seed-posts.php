<?php
/**
 * Seeds deterministic lorem ipsum content: 10 posts + 10 pages, all marked
 * with _sandbox_sync_seed meta so re-running replaces previous fixtures.
 *
 * Run via: wp eval-file fixtures/seed-posts.php
 */

$lorem = <<<TEXT
Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.

Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.
TEXT;

// Remove previously seeded fixtures so the script is idempotent.
$existing = get_posts(
	array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'meta_key'       => '_sandbox_sync_seed',
		'fields'         => 'ids',
	)
);
foreach ( $existing as $post_id ) {
	wp_delete_post( $post_id, true );
}
if ( $existing ) {
	WP_CLI::log( 'Deleted ' . count( $existing ) . ' previously seeded posts/pages.' );
}

foreach ( array( 'post' => 'Lorem Article', 'page' => 'Lorem Page' ) as $type => $title_base ) {
	for ( $i = 1; $i <= 10; $i++ ) {
		$title   = sprintf( '%s %02d', $title_base, $i );
		$post_id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $lorem,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( "Failed creating {$title}: " . $post_id->get_error_message() );
		}
		update_post_meta( $post_id, '_sandbox_sync_seed', 1 );
		WP_CLI::log( "Created {$type}: {$title} (ID {$post_id})" );
	}
}

WP_CLI::success( 'Seeded 10 posts and 10 pages.' );
