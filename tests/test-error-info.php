<?php
/** Operational error taxonomy tests. */

use SandboxSync\Error_Info;

ss_test_section( 'Errors: classification and remediation' );

ss_assert_same( 'transport', Error_Info::category( 'http_503' ), 'server errors are classified as transport failures' );
ss_assert_same( true, Error_Info::retryable( 'http_503' ), 'server errors are marked retryable' );
ss_assert_same( 'capacity', Error_Info::category( 'disk_space' ), 'disk preflight failures are capacity errors' );
ss_assert_same( false, Error_Info::retryable( 'disk_space' ), 'capacity errors do not invite blind retries' );
ss_assert_same( 'compatibility', Error_Info::category( 'protocol_mismatch' ), 'protocol mismatch is a compatibility error' );
ss_assert_same( 'integrity', Error_Info::category( 'file_resume_hash' ), 'content hash failure is an integrity error' );
ss_assert_same( 'safety', Error_Info::category( 'not_a_sandbox' ), 'direction guard failure is a safety error' );
ss_assert_same( 'database', Error_Info::category( 'rollback_failed' ), 'rollback failure is a database error' );
ss_assert_same( 'filesystem', Error_Info::category( 'batch_journal' ), 'batch recovery journal failures are filesystem errors' );

$record = Error_Info::from_wp_error( new WP_Error( 'http_429', 'Source throttled the request.' ) );
ss_assert_same( 'http_429', $record['code'], 'public error record retains the stable machine code' );
ss_assert_same( 'transport', $record['category'], 'public error record includes its category' );
ss_assert_same( true, $record['retryable'], 'public error record exposes retryability' );
ss_assert( '' !== $record['remediation'], 'public error record includes actionable remediation' );
