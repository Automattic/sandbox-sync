<?php
/**
 * Sandbox Sync test runner (no dependencies): php tests/run-tests.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/test-auth.php';
require __DIR__ . '/test-client.php';
require __DIR__ . '/test-search-replace.php';
require __DIR__ . '/test-tar.php';
require __DIR__ . '/test-env.php';
require __DIR__ . '/test-error-info.php';
require __DIR__ . '/test-source-state.php';
require __DIR__ . '/test-uploads-state.php';
require __DIR__ . '/test-files.php';
require __DIR__ . '/test-kept-entries.php';
require __DIR__ . '/test-db-codec.php';
require __DIR__ . '/test-db-shell.php';
require __DIR__ . '/test-plan.php';
require __DIR__ . '/test-job.php';
require __DIR__ . '/test-safety.php';
require __DIR__ . '/test-table-delta.php';

ss_test_summary();
