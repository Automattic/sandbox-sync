<?php
/** Locked source-state persistence and expiry tests. */

use SandboxSync\Source_State;
use SandboxSync\Source_Endpoints;

ss_test_section( 'Source state: locked lifecycle and stale cleanup' );

ss_assert( Source_Endpoints::TRANSFER_TTL >= 43200, 'scoped transfer idle leases tolerate an extended missed-cron window' );

$state_path = WP_CONTENT_DIR . '/.sandbox-sync-source-freeze.php';
@unlink( $state_path );
$created = Source_State::transaction(
	$state_path,
	function ( $current ) {
		return array(
			'state' => array(
				'id_hash'    => hash( 'sha256', 'id' ),
				'token_hash' => hash( 'sha256', 'token' ),
				'expires_at' => time() + 60,
			),
			'value' => null === $current,
		);
	}
);
ss_assert_same( true, $created, 'locked transaction creates source state atomically' );
$state = Source_State::read( $state_path );
ss_assert_same( hash( 'sha256', 'id' ), $state['id_hash'], 'locked state read returns the complete record' );

$renewed = Source_State::transaction(
	$state_path,
	function ( $current ) {
		$current['expires_at'] += 60;
		return array(
			'state' => $current,
			'value' => $current['expires_at'],
		);
	}
);
ss_assert_same( $state['expires_at'] + 60, $renewed, 'read-modify-write runs under one state lock' );

$deleted = Source_State::transaction(
	$state_path,
	function () {
		return array(
			'state' => null,
			'value' => true,
		);
	}
);
ss_assert_same( true, $deleted, 'locked transaction deletes released state' );
ss_assert_same( null, Source_State::read( $state_path ), 'released state cannot be resurrected by a stale read' );

$transfer_path = WP_CONTENT_DIR . '/.sandbox-sync-transfer-' . str_repeat( 'a', 64 ) . '.php';
Source_State::transaction(
	$transfer_path,
	function () {
		return array(
			'state' => array(
				'id_hash'    => hash( 'sha256', 'transfer' ),
				'token_hash' => hash( 'sha256', 'token' ),
				'expires_at' => time() - 1,
			),
			'value' => true,
		);
	}
);
ss_assert_same( 1, Source_State::cleanup_expired_transfers(), 'new-session cleanup removes expired transfer artifacts' );
ss_assert( ! file_exists( $transfer_path ), 'expired transfer artifact is removed from disk' );
ss_assert( is_wp_error( Source_State::read( WP_CONTENT_DIR . '/outside.php' ) ), 'state store rejects paths outside its exact artifact namespace' );

$emergency_paths = array(
	WP_CONTENT_DIR . '/.sandbox-sync-transfer-' . str_repeat( 'b', 64 ) . '.php',
	WP_CONTENT_DIR . '/.sandbox-sync-transfer-' . str_repeat( 'c', 64 ) . '.php',
);
foreach ( $emergency_paths as $path ) {
	Source_State::transaction(
		$path,
		function () {
			return array(
				'state' => array(
					'id_hash'         => hash( 'sha256', 'emergency-transfer' ),
					'token_hash'      => hash( 'sha256', 'emergency-token' ),
					'progress'        => array(
						'dir'        => 'uploads',
						'done'       => false,
						'updated_at' => time(),
					),
					'cancel_requested' => false,
					'expires_at'       => time() + 3600,
				),
				'value' => true,
			);
		}
	);
}
$stopped = Source_Endpoints::emergency_stop();
ss_assert_same( 2, $stopped['transfers_cancelled'], 'source emergency stop signals every active transfer' );
foreach ( $emergency_paths as $path ) {
	$stopped_state = Source_State::read( $path );
	ss_assert_same( true, $stopped_state['cancel_requested'], 'source emergency stop persists a cooperative cancellation flag' );
	ss_assert_same( true, $stopped_state['progress']['done'], 'source emergency stop marks live progress complete' );
	Source_State::transaction(
		$path,
		function () {
			return array(
				'state' => null,
				'value' => true,
			);
		}
	);
}

@unlink( WP_CONTENT_DIR . '/.sandbox-sync-source-state.lock' );
