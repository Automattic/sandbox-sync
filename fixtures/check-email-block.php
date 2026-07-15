<?php
/**
 * wp eval-file helper: verifies both email-blocking layers on a sandbox.
 * Usage: wp eval-file fixtures/check-email-block.php
 */

$sent = wp_mail( 'blocked@example.com', 'sandbox email block test', 'This must never be delivered.' );
echo 'wp_mail() returned: ' . var_export( $sent, true ) . "\n";

global $phpmailer;
echo 'PHPMailer class after wp_mail(): ' . ( $phpmailer ? get_class( $phpmailer ) : '(none)' ) . "\n";

$active = (array) get_option( 'active_plugins' );
echo 'stop-emails active: ' . var_export( in_array( 'stop-emails/stop-emails.php', $active, true ), true ) . "\n";
echo 'mu-plugin pre_wp_mail flag: ' . var_export( (bool) get_option( 'sandbox_sync_block_email' ), true ) . "\n";
