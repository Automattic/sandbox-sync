<?php
/**
 * Post-sync sandbox safety (keep/omit rules), ported from the Knife's clone
 * safety flow (inc/knife-functions.php + setup_clone_safety) and extended.
 *
 * Database activation and these mutations run as one guarded job step, so
 * ordinary requests cannot observe cloned data before safety completes.
 * Every rule is a checkbox in the sync options; all are
 * on by default except DISABLE_WP_CRON (it would idle our own background
 * worker). Pattern lists are filterable.
 *
 * Differences from the Knife worth knowing:
 * - No *_knife_backup copies of scrubbed options: a sandbox's source of
 *   truth is the live site, re-syncing restores anything.
 * - Outgoing email is blocked by the mu-plugin before activation. A
 *   preinstalled Stop Emails plugin is activated as an optional second
 *   layer; packages are never downloaded while a sync is running.
 * - wp-config.php constants are written into a managed marker block on the
 *   real, writable site wp-config.php (always realpath'd — never beside a
 *   read-only `/wordpress/core` symlink). WP-CLI is only a fallback if that
 *   direct write is impossible. Selected live constants are replicated the
 *   same way.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Safety {

	// When set, the loader short-circuits wp_mail() and shows an admin notice.
	const OPT_BLOCK_EMAIL = 'sandbox_sync_block_email';
	const OPT_BLOCK_HTTP  = 'sandbox_sync_block_http';

	private static $email_hook_added   = false;
	private static $http_hook_added    = false;
	private static $marker_hooks_added = false;

	// The Stop Emails plugin (wordpress.org), the Knife's email kill switch.
	const STOP_EMAILS_SLUG = 'stop-emails';
	const STOP_EMAILS_FILE = 'stop-emails/stop-emails.php';

	const CONFIG_BLOCK_BEGIN   = '// BEGIN Sandbox Sync safety constants (managed block, do not edit)';
	const CONFIG_BLOCK_END     = '// END Sandbox Sync safety constants';
	// Names last applied via `wp config set` so a later sync can delete them
	// when the constants/disable_cron rules (or replicates) are cleared.
	const OPT_APPLIED_CONFIG   = 'sandbox_sync_applied_config_constants';
	const ACTIVATION_GUARD_TTL = 3600;

	/**
	 * Constants never offered for replication from the live wp-config.php:
	 * environment-specific paths/credentials and anything secret-shaped.
	 * The sandbox_sync_constant_denylist filter may add patterns but cannot
	 * remove these built-ins.
	 */
	const CONSTANT_DENYLIST = array(
		'DB_NAME',
		'DB_USER',
		'DB_PASSWORD',
		'DB_HOST',
		'DB_CHARSET',
		'DB_COLLATE',
		'WP_HOME',
		'WP_SITEURL',
		'WP_CONTENT_DIR',
		'WP_CONTENT_URL',
		'WP_PLUGIN_DIR',
		'WP_PLUGIN_URL',
		'WPMU_PLUGIN_DIR',
		'WP_TEMP_DIR',
		'WP_CACHE',
		'ABSPATH',
		'COOKIE_DOMAIN',
		'*_KEY',
		'*_SALT',
		'*_SECRET',
		'*_TOKEN',
		'*PASSWORD*',
		'*APIKEY*',
		'*CREDENTIAL*',
		'*PRIVATE*',
		'*ACCESS_KEY_ID*',
		'*CLIENT_SECRET*',
		'*CERT*',
	);

	/**
	 * Plugin patterns to deactivate (fnmatch against the main plugin file
	 * name, case-insensitive). Taken from the Knife / Team 51 Safety Net
	 * denylist: email senders, payment gateways, remote-sync and publishing
	 * integrations.
	 */
	const DEACTIVATE_PLUGIN_PATTERNS = array(
		'ZeroBSCRM',
		'newspack-manager',
		'newspack-chartbeat',
		'*affirm*',
		'*afterpay*',
		'*algolia*',
		'*automator*',
		'*avatax*',
		'*console*',
		'*distributor*',
		'*hubspot*',
		'*in-stock-mailer-for-wc*',
		'*klaviyo*',
		'*leadin*',
		'*mail-bank*',
		'*mailchimp*',
		'*mailgun*',
		'*mailpoet*',
		'*mailster*',
		'*metorik*',
		'*microsoft-start*',
		'*paypal*',
		'*postmark*',
		'*postmatic*',
		'*publish-to-apple-news*',
		'*referralcandy*',
		'*sendgrid*',
		'*sendinblue*',
		'*shareasale*',
		'*shipstation*',
		'*smtp*',
		'*socketlabs*',
		'*sparkpost*',
		'*stripe*',
		'*webgility*',
		'*wp-remote-users-sync*',
		'*wp-ses*',
		'*wp-to-buffer-pro*',
		'*wpmandrill*',
		'*yotpo*',
		'*zapier*',
		'*zoho-mail*',
	);

	/**
	 * Option name patterns holding API keys/secrets, scrubbed to empty
	 * (or handled specially, see scrub_secrets). From the Knife scrublist.
	 */
	const SCRUB_OPTION_PATTERNS = array(
		'googlesitekit_analytics-4_settings',
		'googlesitekit_credentials',
		'klaviyo_api_key',
		'klaviyo_edd_license_key',
		'klaviyo_settings',
		'leadin_access_token',
		'novos_klaviyo_option_name',
		'shareasale_wc_tracker_options',
		'woocommerce-ppcp-settings',
		'woocommerce_afterpay_settings',
		'woocommerce_braintree_credit_card_settings',
		'woocommerce_braintree_paypal_settings',
		'woocommerce_paypal_settings',
		'woocommerce_ppcp-gateway_settings',
		'woocommerce_referralcandy_settings',
		'woocommerce_shipstation_auth_key',
		'woocommerce_stripe_settings',
		'woocommerce_stripe_account_settings',
		'woocommerce_stripe_api_settings',
		'woocommerce_woocommerce_payments_settings',
		'wpmandrill',
		'wprus',
		'yotpo_settings',
		'zmail_access_token',
		'zmail_auth_code',
		'zmail_integ_client_secret',
		'zmail_refresh_token',
		'passport_api_key',
		'passport_api_secret',
	);

	/**
	 * Newsletter / ESP option patterns (scrubbed or deleted) — separate rule
	 * so it can be kept while other secrets are scrubbed, like in the Knife.
	 */
	const NEWSLETTER_OPTION_PATTERNS = array(
		'mailchimp-woocommerce*',
		'newspack_mailchimp_*',
		'newspack_newsletters_*',
		'newspack_nl_*',
		'jetpack_mailchimp',
		'mailster_options',
		'mc4wp',
	);

	// Deleted outright (Knife deletes these instead of emptying them).
	const NEWSLETTER_OPTIONS_TO_DELETE = array(
		'newspack_mailchimp_api_key',
		'newspack_newsletters_active_campaign_key',
		'newspack_newsletters_active_campaign_url',
		'newspack_newsletters_campaign_monitor_api_key',
		'newspack_newsletters_constant_contact_api_key',
		'newspack_newsletters_constant_contact_api_secret',
		'newspack_newsletters_letterhead_api_key',
	);

	// Jetpack connection data (site + user tokens live in these).
	const JETPACK_OPTIONS_TO_DELETE = array(
		'jetpack_options',
		'jetpack_private_options',
		'jetpack_secrets',
	);

	const JETPACK_MODULES_TO_DISABLE = array( 'enhanced-distribution', 'publicize', 'subscriptions' );

	/**
	 * The keep/omit rule registry: key => [ label, description, default ].
	 * Order here is the order of application and of the UI checkboxes.
	 */
	public static function rules() {
		return array(
			'scrub_secrets'      => array(
				'label'   => 'Scrub API keys & payment secrets',
				'desc'    => 'Changes: empties matching wp_options credentials (payment gateways with Stripe forced to test mode, analytics keys, etc.). Does not touch users, posts, or comments.',
				'default' => true,
			),
			'newsletters'        => array(
				'label'   => 'Delete newsletter/ESP config, set provider to Manual',
				'desc'    => 'Changes: deletes newsletter/ESP options and sets newspack_newsletters_service_provider to manual. Does not rewrite subscriber content tables.',
				'default' => true,
			),
			'jetpack'            => array(
				'label'   => 'Delete Jetpack connection data',
				'desc'    => 'Changes: deletes Jetpack connection options and removes publicize, subscriptions, and enhanced-distribution from jetpack_active_modules.',
				'default' => true,
			),
			'deactivate_plugins' => array(
				'label'   => 'Deactivate risky plugins',
				'desc'    => 'Changes: removes matching entries from the active_plugins option only (email/SMTP, payment gateways, Distributor, Zapier, etc.). Plugin files stay on disk.',
				'default' => true,
			),
			'block_email'        => array(
				'label'   => 'Block outgoing email',
				'desc'    => 'Changes: sets a Sandbox Sync runtime flag that short-circuits wp_mail(), and activates a preinstalled Stop Emails plugin when available. No user or content rewrites.',
				'default' => true,
			),
			'block_http'         => array(
				'label'   => 'Block outbound HTTP requests',
				'desc'    => 'Changes: stores an allowlist option so WordPress HTTP API calls are denied except to the paired live source. No user or content rewrites.',
				'default' => true,
			),
			'woo_automations'    => array(
				'label'   => 'Disable WooCommerce webhooks & AutomateWoo',
				'desc'    => 'Changes: sets shop_webhook and AutomateWoo workflow post_status to disabled, clears AutomateWoo queue tables, and completes pending AutomateWoo Action Scheduler actions. Does not alter products, orders, or editorial posts.',
				'default' => true,
			),
			'discourage_search'  => array(
				'label'   => 'Discourage search engines',
				'desc'    => 'Changes: sets the blog_public option to 0 so the sandbox is not indexed.',
				'default' => true,
			),
			'constants'          => array(
				'label'   => 'Write safety constants to wp-config.php',
				'desc'    => 'Changes: writes JETPACK_DEV_DEBUG and WP_ENVIRONMENT_TYPE=staging (plus any selected live constants) into the managed wp-config.php block. Does not modify the database.',
				'default' => true,
			),
			'disable_cron'       => array(
				'label'   => 'Set DISABLE_WP_CRON in wp-config.php',
				'desc'    => 'Changes: adds DISABLE_WP_CRON to the managed wp-config.php block. Warning: background syncs then only progress while the Sandbox Sync page is open.',
				'default' => false,
			),
		);
	}

	public static function default_keys() {
		$keys = array();
		foreach ( self::rules() as $key => $rule ) {
			if ( $rule['default'] ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Applies the enabled rules. Returns log lines (also used as the step
	 * message), or WP_Error on a hard failure.
	 *
	 * @param string[] $enabled           Enabled rule keys.
	 * @param array    $replicated        Constants replicated from the live
	 *                                    wp-config.php: name => value.
	 * @return string[]|\WP_Error
	 */
	public static function apply( $enabled, $replicated = array(), $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $context reserved for callers/future rules.
		$log = array();
		try {

			if ( in_array( 'scrub_secrets', $enabled, true ) ) {
				$patterns = apply_filters( 'sandbox_sync_scrub_patterns', self::SCRUB_OPTION_PATTERNS );
				$scrubbed = self::scrub_options( $patterns );
				if ( is_wp_error( $scrubbed ) ) {
					return $scrubbed;
				}
				$log[] = sprintf( 'Scrubbed %d secret option(s)%s.', count( $scrubbed ), $scrubbed ? ': ' . implode( ', ', $scrubbed ) : '' );
			}

			if ( in_array( 'newsletters', $enabled, true ) ) {
				foreach ( self::NEWSLETTER_OPTIONS_TO_DELETE as $name ) {
					delete_option( $name );
				}
				$patterns = apply_filters( 'sandbox_sync_newsletter_scrub_patterns', self::NEWSLETTER_OPTION_PATTERNS );
				$scrubbed = self::scrub_options( $patterns );
				if ( is_wp_error( $scrubbed ) ) {
					return $scrubbed;
				}
				update_option( 'newspack_newsletters_service_provider', 'manual' );
				$log[] = sprintf( 'Newsletter/ESP config removed (%d option(s)), provider set to manual.', count( $scrubbed ) + count( self::NEWSLETTER_OPTIONS_TO_DELETE ) );
			}

			if ( in_array( 'jetpack', $enabled, true ) ) {
				foreach ( self::JETPACK_OPTIONS_TO_DELETE as $name ) {
					delete_option( $name );
				}
				$modules = get_option( 'jetpack_active_modules' );
				if ( is_array( $modules ) ) {
					if ( ! self::update_option_direct( 'jetpack_active_modules', array_values( array_diff( $modules, self::JETPACK_MODULES_TO_DISABLE ) ) ) ) {
						return new \WP_Error( 'safety_option', 'Could not update Jetpack modules safely.' );
					}
				}
				$log[] = 'Jetpack connection data deleted; publicize/subscriptions/enhanced-distribution modules disabled.';
			}

			if ( in_array( 'deactivate_plugins', $enabled, true ) ) {
				$patterns    = apply_filters( 'sandbox_sync_plugin_denylist', self::DEACTIVATE_PLUGIN_PATTERNS );
				$deactivated = self::deactivate_plugins( $patterns );
				if ( is_wp_error( $deactivated ) ) {
					return $deactivated;
				}
				$log[] = sprintf( 'Deactivated %d risky plugin(s)%s.', count( $deactivated ), $deactivated ? ': ' . implode( ', ', $deactivated ) : '' );
			}

			if ( in_array( 'woo_automations', $enabled, true ) ) {
				$woo = self::neutralize_woo_automations();
				if ( is_wp_error( $woo ) ) {
					return $woo;
				}
				$log[] = $woo;
			}

			if ( in_array( 'discourage_search', $enabled, true ) ) {
				update_option( 'blog_public', '0' );
				$log[] = 'Search engines discouraged (blog_public=0).';
			}

			// Email blocking is stateful: checked installs + activates Stop
			// Emails (and sets our belt-and-braces wp_mail short-circuit flag),
			// unchecked removes both — each sync's choices fully describe the
			// sandbox's state.
			if ( in_array( 'block_email', $enabled, true ) ) {
				$email_result = self::enable_email_block();
				if ( is_wp_error( $email_result ) ) {
					return $email_result;
				}
				update_option( self::OPT_BLOCK_EMAIL, 1, false );
				$log[] = $email_result;
			} else {
				$disabled = self::disable_email_block();
				if ( is_wp_error( $disabled ) ) {
					return $disabled;
				}
				delete_option( self::OPT_BLOCK_EMAIL );
			}

			// Safety constants first so they win over replicated live values when
			// both define the same name. Applied through WP-CLI, not direct edits.
			$constants = array();
			if ( in_array( 'constants', $enabled, true ) ) {
				$constants['JETPACK_DEV_DEBUG']   = true;
				$constants['WP_ENVIRONMENT_TYPE'] = 'staging';
			}
			if ( in_array( 'disable_cron', $enabled, true ) ) {
				$constants['DISABLE_WP_CRON'] = true;
			}
			foreach ( $replicated as $name => $value ) {
				if ( ! array_key_exists( $name, $constants ) ) {
					$constants[ $name ] = $value;
				}
			}
			$config_result = self::apply_config_constants( $constants );
			if ( is_wp_error( $config_result ) ) {
				return $config_result;
			}
			if ( '' !== $config_result ) {
				$log[] = $config_result;
			}

			// Keep the paired source reachable for future syncs, but deny every
			// other WordPress HTTP API destination once the safety step completes.
			if ( in_array( 'block_http', $enabled, true ) ) {
				$conn = Auth::get_connection();
				self::set_http_allowlist( $conn ? array( $conn['source_url'] ) : array() );
				self::add_http_hook();
				$log[] = 'Outbound WordPress HTTP requests blocked except for the paired source.';
			} else {
				delete_option( self::OPT_BLOCK_HTTP );
			}

			return $log;
		} finally {
			// Direct option writes intentionally bypass WordPress hooks. Never
			// leave a stale object-cache view behind, including on a hard failure.
			wp_cache_flush();
		}
	}

	// -- Option scrubbing (port of knife_scrub_wp_options) -------------------------

	/**
	 * fnmatch-style, case-insensitive pattern matching of a list of names.
	 * Pure function (unit-tested).
	 *
	 * @return string[] Names matching at least one pattern.
	 */
	public static function match_patterns( $patterns, $names ) {
		$matched = array();
		foreach ( $names as $name ) {
			foreach ( $patterns as $pattern ) {
				if ( fnmatch( $pattern, $name, FNM_CASEFOLD ) ) {
					$matched[] = $name;
					break;
				}
			}
		}
		return $matched;
	}

	/**
	 * Scrubs matching options, with the Knife's special cases: payment
	 * gateway options keep their structure (only credential keys emptied,
	 * Stripe forced into test mode), everything else becomes ''/[].
	 *
	 * @return string[]|\WP_Error Scrubbed option names.
	 */
	private static function scrub_options( $patterns ) {
		global $wpdb;

		$names    = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
		$to_scrub = self::match_patterns( $patterns, $names );

		foreach ( $to_scrub as $name ) {
			$current = get_option( $name );
			if ( ! $current ) {
				continue;
			}

			if ( in_array( $name, array( 'woocommerce_stripe_settings', 'woocommerce_ppcp-gateway_settings', 'woocommerce-ppcp-settings' ), true ) ) {
				$value = is_array( $current ) ? $current : array();
				if ( 'woocommerce_stripe_settings' === $name ) {
					$value['testmode'] = 'yes';
				}
				foreach ( array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' ) as $key ) {
					if ( array_key_exists( $key, $value ) ) {
						$value[ $key ] = '';
					}
				}
				if ( ! self::update_option_direct( $name, $value ) ) {
					return new \WP_Error( 'scrub_option', "Could not scrub safety-sensitive option {$name}." );
				}
			} elseif ( 'wprus' === $name && is_array( $current ) ) {
				// WP Remote Users Sync: empty only the connection keys.
				if ( isset( $current['encryption'] ) && is_array( $current['encryption'] ) ) {
					foreach ( array( 'aes_key', 'hmac_key' ) as $key ) {
						if ( array_key_exists( $key, $current['encryption'] ) ) {
							$current['encryption'][ $key ] = '';
						}
					}
				}
				if ( ! self::update_option_direct( $name, $current ) ) {
					return new \WP_Error( 'scrub_option', "Could not scrub safety-sensitive option {$name}." );
				}
			} else {
				if ( ! self::update_option_direct( $name, is_array( $current ) ? array() : '' ) ) {
					return new \WP_Error( 'scrub_option', "Could not scrub safety-sensitive option {$name}." );
				}
			}
		}

		return $to_scrub;
	}

	/**
	 * Writes an option straight to the DB (no hooks — some plugins send
	 * notifications from option updates). Same trick as safety-net/Knife.
	 */
	private static function update_option_direct( $name, $value ) {
		global $wpdb;
		if ( is_array( $value ) ) {
			$value = serialize( $value ); // phpcs:ignore -- matching WP options storage.
		}
		return false !== $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) );
	}

	// -- Plugin deactivation (port of knife_deactivate_plugins) ----------------------

	/**
	 * @return string[]|\WP_Error Deactivated plugin paths.
	 */
	private static function deactivate_plugins( $patterns ) {
		$active = get_option( 'active_plugins' );
		if ( ! is_array( $active ) || empty( $active ) ) {
			return array();
		}

		$to_deactivate = array();
		foreach ( $active as $plugin ) {
			$filename = basename( (string) $plugin );
			foreach ( $patterns as $pattern ) {
				if ( fnmatch( $pattern . '.php', $filename, FNM_CASEFOLD ) ) {
					$to_deactivate[] = $plugin;
					break;
				}
			}
		}

		if ( ! empty( $to_deactivate ) ) {
			// Direct option update on purpose: deactivation hooks of these
			// plugins could phone home or send email.
			if ( ! self::update_option_direct( 'active_plugins', array_values( array_diff( $active, $to_deactivate ) ) ) ) {
				return new \WP_Error( 'plugin_deactivation', 'Could not persist the safety plugin deactivation list.' );
			}
		}

		return $to_deactivate;
	}

	// -- Woo automations ------------------------------------------------------------

	private static function neutralize_woo_automations() {
		global $wpdb;
		$done = array();

		$webhooks = $wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'disabled' WHERE post_type = 'shop_webhook' AND post_status != 'disabled'" );
		if ( false === $webhooks ) {
			return new \WP_Error( 'woo_webhooks', 'Could not disable WooCommerce webhooks: ' . $wpdb->last_error );
		}
		$webhooks = (int) $webhooks;
		if ( $webhooks > 0 ) {
			$done[] = "{$webhooks} Woo webhook(s) disabled";
		}

		$workflows = $wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'aw-disabled' WHERE post_type = 'aw_workflow' AND post_status = 'publish'" );
		if ( false === $workflows ) {
			return new \WP_Error( 'woo_workflows', 'Could not disable AutomateWoo workflows: ' . $wpdb->last_error );
		}
		$workflows = (int) $workflows;
		if ( $workflows > 0 ) {
			$done[] = "{$workflows} AutomateWoo workflow(s) disabled";
		}

		$queue_table = $wpdb->prefix . 'automatewoo_queue';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $queue_table ) ) ) {
				return new \WP_Error( 'woo_queue', 'Could not clear the AutomateWoo queue: ' . $wpdb->last_error );
			}
			$queue_meta = $wpdb->prefix . 'automatewoo_queue_meta';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_meta ) ) === $queue_meta && false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $queue_meta ) ) ) {
				return new \WP_Error( 'woo_queue_meta', 'Could not clear AutomateWoo queue metadata: ' . $wpdb->last_error );
			}
			$done[] = 'AutomateWoo queue cleared';
		}

		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) === $as_table ) {
			$actions = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'complete' WHERE status = 'pending' AND hook LIKE %s", $as_table, '%' . $wpdb->esc_like( 'automatewoo' ) . '%' ) );
			if ( false === $actions ) {
				return new \WP_Error( 'woo_actions', 'Could not neutralize pending AutomateWoo actions: ' . $wpdb->last_error );
			}
			$actions = (int) $actions;
			if ( $actions > 0 ) {
				$done[] = "{$actions} pending AutomateWoo action(s) marked complete";
			}
		}

		return $done ? 'Woo automations: ' . implode( ', ', $done ) . '.' : 'Woo automations: nothing to disable.';
	}

	// -- Email blocking (Stop Emails plugin) --------------------------------------------

	/**
	 * Installs (if needed) and activates the Stop Emails plugin. It replaces
	 * the global PHPMailer instance, which also catches senders that bypass
	 * the wp_mail() filters.
	 *
	 * @return string|\WP_Error Log line.
	 */
	private static function enable_email_block() {
		$plugin_dir = WP_CONTENT_DIR . '/plugins/' . self::STOP_EMAILS_SLUG;

		$preinstalled = file_exists( $plugin_dir . '/stop-emails.php' );
		if ( $preinstalled ) {
			$active = get_option( 'active_plugins' );
			$active = is_array( $active ) ? $active : array();
			if ( ! in_array( self::STOP_EMAILS_FILE, $active, true ) ) {
				$active[] = self::STOP_EMAILS_FILE;
				sort( $active );
				if ( ! self::update_option_direct( 'active_plugins', $active ) ) {
					return new \WP_Error( 'email_plugin', 'Could not activate the preinstalled Stop Emails plugin.' );
				}
			}
		}

		return $preinstalled
			? 'Outgoing email blocked before activation; preinstalled Stop Emails plugin activated as a second layer.'
			: 'Outgoing email blocked before activation through pre_wp_mail. Stop Emails was not preinstalled, so no runtime package was downloaded.';
	}

	/**
	 * Deactivates Stop Emails (keeps the files around for the next enable).
	 */
	private static function disable_email_block() {
		$active = get_option( 'active_plugins' );
		if ( is_array( $active ) && in_array( self::STOP_EMAILS_FILE, $active, true ) ) {
			if ( ! self::update_option_direct( 'active_plugins', array_values( array_diff( $active, array( self::STOP_EMAILS_FILE ) ) ) ) ) {
				return new \WP_Error( 'email_plugin', 'Could not deactivate the Stop Emails plugin.' );
			}
		}
		return true;
	}

	// -- Live wp-config.php constants replication -----------------------------------------

	/**
	 * Parses define() statements out of wp-config.php contents. Pure
	 * function (unit-tested). Only simple scalar literals are replicable;
	 * expressions (paths built with dirname(), env lookups, …) are returned
	 * with replicable=false so the UI can show why they are not offered.
	 *
	 * @param string $contents wp-config.php contents.
	 * @return array[] Each entry: [ 'name', 'literal', 'value', 'replicable' ].
	 */
	public static function parse_config_constants( $contents ) {
		// Our own managed block must not show up as live constants.
		$contents = self::upsert_config_block( $contents, '' );

		preg_match_all(
			'/^\h*define\s*\(\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*,\s*(.+?)\s*\)\s*;/m',
			$contents,
			$matches,
			PREG_SET_ORDER
		);

		$constants = array();
		$seen      = array();
		foreach ( $matches as $match ) {
			$name    = $match[2];
			$literal = $match[3];
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			$value      = null;
			$replicable = true;

			if ( preg_match( '/^(true|false)$/i', $literal ) ) {
				$value = 'true' === strtolower( $literal );
			} elseif ( is_numeric( $literal ) ) {
				$value = false === strpos( $literal, '.' ) ? (int) $literal : (float) $literal;
			} elseif ( preg_match( '/^\'((?:[^\'\\\\]|\\\\.)*)\'$/s', $literal, $str ) ) {
				$value = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $str[1] );
			} elseif ( preg_match( '/^"([^"$\\\\]*)"$/s', $literal, $str ) ) {
				// Double-quoted accepted only without escapes/interpolation.
				$value = $str[1];
			} else {
				$replicable = false;
			}

			$constants[] = array(
				'name'       => $name,
				'literal'    => strlen( $literal ) > 100 ? substr( $literal, 0, 97 ) . '…' : $literal,
				'value'      => $value,
				'replicable' => $replicable,
			);
		}

		return $constants;
	}

	/**
	 * Is this constant allowed to be offered for replication?
	 */
	public static function is_offerable_constant( $name ) {
		$filtered = apply_filters( 'sandbox_sync_constant_denylist', self::CONSTANT_DENYLIST );
		$denylist = array_values( array_unique( array_merge( self::CONSTANT_DENYLIST, (array) $filtered ) ) );
		return array() === self::match_patterns( $denylist, array( $name ) );
	}

	/**
	 * Replicable scalar constants minus the non-bypassable environment/secret
	 * denylist. Discovery returns names only; passing requested names returns
	 * values only for that exact selection during an active transfer session.
	 *
	 * @param string[]|null $requested_names Exact selected names, or null for discovery.
	 */
	public static function offered_constants( $requested_names = null ) {
		$path = self::locate_wp_config();
		if ( null === $path ) {
			return array();
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array();
		}

		$include_values = is_array( $requested_names );
		$requested      = $include_values ? array_flip( array_values( array_unique( $requested_names ) ) ) : null;
		$offered        = array();
		foreach ( self::parse_config_constants( $contents ) as $constant ) {
			if ( ! $constant['replicable'] || ! self::is_offerable_constant( $constant['name'] )
				|| ( $include_values && ! isset( $requested[ $constant['name'] ] ) ) ) {
				continue;
			}
			$item = array( 'name' => $constant['name'] );
			if ( $include_values ) {
				$item['value'] = $constant['value'];
			}
			$offered[] = $item;
		}
		return $offered;
	}

	// -- wp-config.php constants -----------------------------------------------------

	/**
	 * Builds the managed constants block. Pure function (unit-tested).
	 *
	 * @param array $constants name => value (bool or string).
	 */
	public static function build_config_block( $constants ) {
		if ( empty( $constants ) ) {
			return '';
		}
		$lines = array( self::CONFIG_BLOCK_BEGIN );
		foreach ( $constants as $name => $value ) {
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name ) ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$literal = $value ? 'true' : 'false';
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$literal = (string) $value;
			} else {
				$literal = var_export( (string) $value, true );
			}
			$lines[] = "if ( ! defined( '{$name}' ) ) { define( '{$name}', {$literal} ); }";
		}
		$lines[] = self::CONFIG_BLOCK_END;
		return implode( "\n", $lines );
	}

	/**
	 * Removes bare define( 'NAME', … ) lines for the given names (e.g. leftovers
	 * from `wp config set`). Does not touch if-not-defined wrappers or the
	 * managed block. Pure function.
	 *
	 * @param string   $contents wp-config.php contents.
	 * @param string[] $names    Constant names to strip.
	 */
	public static function strip_config_defines( $contents, $names ) {
		foreach ( (array) $names as $name ) {
			$name = (string) $name;
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
				continue;
			}
			$quoted = preg_quote( $name, '/' );
			// WP-CLI typically writes: define( 'NAME', 'value' );
			$contents = preg_replace(
				'/\n?[ \t]*define\s*\(\s*[\'"]' . $quoted . '[\'"]\s*,\s*(?:true|false|-?\d+(?:\.\d+)?|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\)\s*;[ \t]*/i',
				'',
				$contents
			);
		}
		return $contents;
	}

	/**
	 * Inserts/replaces/removes the managed block in wp-config.php contents.
	 * Pure function (unit-tested). The block goes right before the
	 * "That's all, stop editing" line (or is appended) so any constants the
	 * site defines itself win — ours carry if-not-defined guards anyway.
	 *
	 * @param string $contents Current wp-config.php contents.
	 * @param string $block    From build_config_block(); '' removes the block.
	 */
	public static function upsert_config_block( $contents, $block ) {
		// Strip any existing managed block first.
		$pattern  = '/\n?' . preg_quote( self::CONFIG_BLOCK_BEGIN, '/' ) . '.*?' . preg_quote( self::CONFIG_BLOCK_END, '/' ) . '\n?/s';
		$contents = preg_replace( $pattern, "\n", $contents, 1 );

		if ( '' === $block ) {
			return $contents;
		}

		if ( preg_match( '/^.*That\'s all, stop editing.*$/m', $contents, $match ) ) {
			return str_replace( $match[0], $block . "\n\n" . $match[0], $contents );
		}
		return rtrim( $contents, "\n" ) . "\n\n" . $block . "\n";
	}

	/**
	 * Applies sandbox/live constants to wp-config.php.
	 *
	 * Primary: atomic write of the managed block beside the realpath'd,
	 * writable site config (never under read-only `/wordpress/core`).
	 * Fallback: WP-CLI `config set`/`delete` when the direct path is not
	 * writable — mid-job `proc_open` can fail after long imports, so CLI is
	 * not the default.
	 *
	 * @param array $constants name => value; empty removes the managed block.
	 * @return string|\WP_Error Log line ('' when nothing changed).
	 */
	private static function apply_config_constants( $constants ) {
		$desired = array();
		foreach ( (array) $constants as $name => $value ) {
			$name = (string) $name;
			if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
				return new \WP_Error( 'config_name', "Refusing to set unsafe wp-config constant name {$name}." );
			}
			$desired[ $name ] = $value;
		}

		// Names previously written by the CLI fallback can shadow the managed
		// block's if-not-defined guards. Prefer stripping them from the file
		// during the direct write — do not spawn WP-CLI here (mid-job proc_open
		// is what failed in the first place).
		$previous = get_option( self::OPT_APPLIED_CONFIG, array() );
		$previous = is_array( $previous ) ? array_values( array_filter( array_map( 'strval', $previous ) ) ) : array();

		$direct = self::write_config_constants_direct( $desired, $previous );
		if ( ! is_wp_error( $direct ) ) {
			delete_option( self::OPT_APPLIED_CONFIG );
			return $direct;
		}

		$cli = self::apply_config_constants_via_cli( $desired );
		if ( ! is_wp_error( $cli ) ) {
			$suffix = '' === $cli ? 'WP-CLI fallback applied no constant changes.' : $cli;
			return $suffix . ' [direct write failed: ' . $direct->get_error_message() . ']';
		}

		return new \WP_Error(
			'config_write',
			sprintf(
				'Could not update wp-config.php constants (direct: %s; WP-CLI: %s).',
				$direct->get_error_message(),
				$cli->get_error_message()
			)
		);
	}

	/**
	 * Atomic managed-block write on the real writable wp-config.php.
	 *
	 * @param array    $constants   name => value.
	 * @param string[] $strip_names Extra define() names to remove (e.g. prior CLI applies).
	 * @return string|\WP_Error
	 */
	private static function write_config_constants_direct( $constants, $strip_names = array() ) {
		$path = self::locate_wp_config();
		if ( null === $path ) {
			return empty( $constants )
				? ''
				: new \WP_Error( 'no_wp_config', 'Could not locate a writable wp-config.php to set safety constants.' );
		}
		$resolved = realpath( $path );
		if ( false === $resolved ) {
			return new \WP_Error( 'no_wp_config', "Could not resolve wp-config.php path {$path}." );
		}
		$path = $resolved;
		$dir  = dirname( $path );
		if ( ! is_writable( $path ) || ! is_writable( $dir ) ) {
			return new \WP_Error( 'config_write', "wp-config.php is not replaceable at {$path} (file or parent directory is not writable)." );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new \WP_Error( 'config_read', "Could not read {$path}." );
		}
		if ( ! empty( $strip_names ) ) {
			$contents = self::strip_config_defines( $contents, $strip_names );
		}
		$updated = self::upsert_config_block( $contents, self::build_config_block( $constants ) );
		if ( $updated === $contents ) {
			return '';
		}
		try {
			token_get_all( $updated, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new \WP_Error( 'config_syntax', 'Refusing to write wp-config.php because the generated file does not parse: ' . $error->getMessage() );
		}

		$mode   = @fileperms( $path );
		$owner  = @fileowner( $path );
		$group  = @filegroup( $path );
		$tmp    = $dir . '/.sandbox-sync-wp-config-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		$backup = $dir . '/.sandbox-sync-wp-config-backup.php';
		$written = file_put_contents( $tmp, $updated, LOCK_EX );
		if ( false === $written || strlen( $updated ) !== $written ) {
			@unlink( $tmp );
			return new \WP_Error( 'config_write', "Could not write a complete temporary config in {$dir}." );
		}
		if ( false !== $mode ) {
			@chmod( $tmp, $mode & 0777 );
		}
		if ( false !== $owner ) {
			@chown( $tmp, $owner );
		}
		if ( false !== $group ) {
			@chgrp( $tmp, $group );
		}
		$verify = file_get_contents( $tmp );
		if ( $verify !== $updated ) {
			@unlink( $tmp );
			return new \WP_Error( 'config_verify', 'Temporary wp-config.php verification failed.' );
		}
		$fh = @fopen( $tmp, 'rb' );
		if ( $fh ) {
			if ( function_exists( 'fsync' ) && ! @fsync( $fh ) ) {
				fclose( $fh );
				@unlink( $tmp );
				return new \WP_Error( 'config_fsync', 'Could not flush the temporary wp-config.php to stable storage.' );
			}
			fclose( $fh );
		}
		if ( ! @copy( $path, $backup ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'config_backup', "Could not create wp-config.php recovery copy {$backup}." );
		}
		@chmod( $backup, false !== $mode ? $mode & 0777 : 0600 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'config_rename', "Could not atomically activate the updated wp-config.php at {$path}." );
		}
		return empty( $constants )
			? 'Removed the safety constants block from wp-config.php.'
			: 'wp-config.php safety constants set: ' . implode( ', ', array_keys( $constants ) ) . '.';
	}

	/**
	 * WP-CLI fallback when the real wp-config parent directory is not writable.
	 *
	 * @param array $constants name => value.
	 * @return string|\WP_Error
	 */
	private static function apply_config_constants_via_cli( $constants ) {
		$previous = get_option( self::OPT_APPLIED_CONFIG, array() );
		if ( ! is_array( $previous ) ) {
			$previous = array();
		}

		$to_delete = array_values( array_diff( $previous, array_keys( $constants ) ) );
		foreach ( $to_delete as $name ) {
			$deleted = self::wp_config_cli_delete( $name );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		$applied = array();
		foreach ( $constants as $name => $value ) {
			$set = self::wp_config_cli_set( $name, $value );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			$applied[] = $name;
		}

		if ( empty( $applied ) ) {
			delete_option( self::OPT_APPLIED_CONFIG );
			return empty( $to_delete ) ? '' : sprintf( 'Deleted %d previously applied constant(s) via WP-CLI.', count( $to_delete ) );
		}

		update_option( self::OPT_APPLIED_CONFIG, $applied, false );
		$msg = 'wp-config constants set via WP-CLI: ' . implode( ', ', $applied ) . '.';
		if ( ! empty( $to_delete ) ) {
			$msg .= sprintf( ' Deleted %d stale constant(s).', count( $to_delete ) );
		}
		return $msg;
	}

	/** @return true|\WP_Error */
	private static function wp_config_cli_set( $name, $value ) {
		$args = array( 'config', 'set', $name );
		if ( is_bool( $value ) ) {
			$args[] = $value ? 'true' : 'false';
			$args[] = '--raw';
		} elseif ( is_int( $value ) || is_float( $value ) ) {
			$args[] = (string) $value;
			$args[] = '--raw';
		} else {
			$args[] = (string) $value;
		}
		$args[] = '--type=constant';
		return self::run_wp_cli( $args );
	}

	/** @return true|\WP_Error */
	private static function wp_config_cli_delete( $name ) {
		$result = self::run_wp_cli( array( 'config', 'delete', $name, '--type=constant' ) );
		if ( is_wp_error( $result ) && false !== stripos( $result->get_error_message(), 'not defined' ) ) {
			return true;
		}
		return $result;
	}

	/**
	 * Runs `wp --path=<install> …` as an argv array (no shell).
	 *
	 * @param string[] $args WP-CLI arguments after the binary/`--path`.
	 * @return true|\WP_Error
	 */
	private static function run_wp_cli( array $args ) {
		$binary = self::find_wp_binary();
		$path   = self::wp_cli_install_path();
		if ( ! $binary ) {
			return new \WP_Error( 'config_cli', 'WP-CLI (`wp`) is not available.' );
		}
		if ( ! $path ) {
			return new \WP_Error( 'config_cli', 'Could not determine the WordPress install path for WP-CLI.' );
		}

		$cmd         = array_merge( array( $binary, '--path=' . $path, '--skip-plugins', '--skip-themes' ), array_values( $args ) );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = @proc_open( $cmd, $descriptors, $pipes, $path, null, array( 'bypass_shell' => true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'config_cli', 'Could not start WP-CLI to update wp-config.php.' );
		}
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		if ( 0 !== (int) $code ) {
			$detail = trim( $stderr . "\n" . $stdout );
			return new \WP_Error( 'config_cli', 'WP-CLI config update failed: ' . ( '' !== $detail ? $detail : 'exit ' . $code ) );
		}
		return true;
	}

	/** @return string|null Absolute path to the `wp` binary. */
	private static function find_wp_binary() {
		$candidates = array();
		$env        = getenv( 'WP_CLI_BIN' );
		if ( is_string( $env ) && '' !== $env ) {
			$candidates[] = $env;
		}
		$candidates[] = '/usr/local/bin/wp';
		$candidates[] = '/usr/bin/wp';
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Docroot that contains the real wp-config.php (e.g. /srv/htdocs).
	 *
	 * @return string|null
	 */
	private static function wp_cli_install_path() {
		$config = self::locate_wp_config();
		if ( null === $config ) {
			return null;
		}
		$dir = dirname( $config );
		return is_dir( $dir ) ? $dir : null;
	}

	/**
	 * Locates wp-config.php the way WordPress does (ABSPATH, else one directory
	 * up when that parent is not another install), then resolves symlinks and
	 * prefers a path whose parent directory is writable.
	 *
	 * @return string|null Absolute real path when possible.
	 */
	public static function locate_wp_config() {
		$candidates = array();
		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-config.php' ) ) {
			$candidates[] = ABSPATH . 'wp-config.php';
		}
		if ( defined( 'ABSPATH' ) ) {
			$parent_dir = dirname( ABSPATH );
			$parent     = $parent_dir . '/wp-config.php';
			if ( file_exists( $parent ) && ! file_exists( $parent_dir . '/wp-settings.php' ) ) {
				$candidates[] = $parent;
			}
		}

		$fallback = null;
		foreach ( $candidates as $candidate ) {
			$real = realpath( $candidate );
			if ( false === $real || ! is_readable( $real ) ) {
				continue;
			}
			if ( null === $fallback ) {
				$fallback = $real;
			}
			if ( is_writable( $real ) && is_writable( dirname( $real ) ) ) {
				return $real;
			}
		}
		return $fallback;
	}

	// -- Email blocking (loader hook) ---------------------------------------------------

	private static function activation_guard_path() {
		return WP_CONTENT_DIR . '/.sandbox-sync-activation-guard.php';
	}

	private static function read_activation_guard() {
		$path   = self::activation_guard_path();
		$raw    = is_file( $path ) ? @file_get_contents( $path ) : false;
		$prefix = "<?php exit; ?>\n";
		$guard  = false === $raw || 0 !== strpos( $raw, $prefix ) ? null : json_decode( substr( $raw, strlen( $prefix ) ), true );
		if ( ! is_array( $guard ) || empty( $guard['job_hash'] ) || empty( $guard['expires_at'] ) || (int) $guard['expires_at'] <= time() ) {
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
			return null;
		}
		return $guard;
	}

	/** Establishes/renews the early-load activation guard for one job. */
	public static function begin_activation_guard( $job_id ) {
		$job_hash = hash( 'sha256', (string) $job_id );
		$active   = self::read_activation_guard();
		if ( $active && ! hash_equals( (string) $active['job_hash'], $job_hash ) ) {
			return new \WP_Error( 'activation_guard_busy', 'Another job owns the sandbox activation guard.' );
		}
		$path  = self::activation_guard_path();
		$tmp   = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$data  = "<?php exit; ?>\n" . wp_json_encode(
			array(
				'job_hash'   => $job_hash,
				'expires_at' => time() + self::ACTIVATION_GUARD_TTL,
			)
		);
		$wrote = file_put_contents( $tmp, $data, LOCK_EX ) === strlen( $data );
		@chmod( $tmp, 0600 );
		if ( ! $wrote || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'activation_guard_write', 'Could not establish the sandbox activation guard.' );
		}
		return true;
	}

	public static function end_activation_guard( $job_id ) {
		$active = self::read_activation_guard();
		if ( ! $active || ! hash_equals( (string) $active['job_hash'], hash( 'sha256', (string) $job_id ) ) ) {
			return false;
		}
		$path = self::activation_guard_path();
		return @unlink( $path ) || ! is_file( $path );
	}

	/**
	 * Called at mu-plugin load time. Cron, the authenticated AJAX controller,
	 * the Tools > Sandbox Sync admin screen, and WP-CLI may recover/monitor the
	 * guarded step; ordinary traffic receives 503.
	 */
	public static function enforce_activation_guard() {
		if ( ! self::read_activation_guard() ) {
			return;
		}
		$is_cli        = defined( 'WP_CLI' ) && WP_CLI;
		$is_cron       = wp_doing_cron();
		$is_job_ajax   = wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'sandbox_sync' === (string) $_REQUEST['action'];
		// Allow the operator status UI through so a long uploads-finalize →
		// database → file-activation window does not look like a dead site.
		$is_admin_page = is_admin()
			&& isset( $_GET['page'] ) && 'sandbox-sync' === (string) $_GET['page'];
		if ( $is_cli || $is_cron || $is_job_ajax || $is_admin_page ) {
			return;
		}
		http_response_code( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Retry-After: 30' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		echo 'Sandbox Sync is activating freshly synced data on this sandbox (the live site is unaffected). Frontend traffic is paused until the job finishes activation — open Tools → Sandbox Sync or retry shortly.';
		exit;
	}

	/**
	 * Called from the loader on every request: enforces the email block and
	 * surfaces it to admins.
	 */
	public static function init() {
		if ( get_option( self::OPT_BLOCK_EMAIL ) ) {
			self::add_email_hook();
		}
		if ( get_option( self::OPT_BLOCK_HTTP ) ) {
			self::add_http_hook();
		}
		if ( Auth::is_sandbox_site() ) {
			self::add_sandbox_marker_hooks();
		}
	}

	/** Adds defense-in-depth indexing headers plus an unmistakable admin bar marker. */
	private static function add_sandbox_marker_hooks() {
		if ( self::$marker_hooks_added ) {
			return;
		}
		self::$marker_hooks_added = true;
		add_filter(
			'wp_headers',
			function ( $headers ) {
				$headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
				return $headers;
			},
			PHP_INT_MAX
		);
		add_filter(
			'robots_txt',
			function () {
				return "User-agent: *\nDisallow: /\n";
			},
			PHP_INT_MAX
		);
		add_action(
			'admin_bar_menu',
			function ( $bar ) {
				if ( is_object( $bar ) && method_exists( $bar, 'add_node' ) ) {
					$bar->add_node(
						array(
							'id'    => 'sandbox-sync-environment',
							'title' => 'SANDBOX ENVIRONMENT',
							'href'  => admin_url( 'tools.php?page=sandbox-sync' ),
							'meta'  => array( 'class' => 'sandbox-sync-environment' ),
						)
					);
				}
			},
			1
		);
		add_action(
			'admin_head',
			function () {
				echo '<style>#wpadminbar .sandbox-sync-environment>.ab-item{background:#b32d2e!important;color:#fff!important;font-weight:700}</style>';
			}
		);
	}

	/**
	 * Enables process- and option-level guards before any cloned database or
	 * plugin code can become active. Returns the previous state for rollback.
	 */
	public static function engage_runtime_guard( $source_url ) {
		$previous = array(
			'email' => get_option( self::OPT_BLOCK_EMAIL, false ),
			'http'  => get_option( self::OPT_BLOCK_HTTP, false ),
		);
		update_option( self::OPT_BLOCK_EMAIL, 1, false );
		self::set_http_allowlist( array( $source_url ) );
		self::add_email_hook();
		self::add_http_hook();
		return $previous;
	}

	public static function restore_runtime_guard( $previous ) {
		if ( ! empty( $previous['email'] ) ) {
			update_option( self::OPT_BLOCK_EMAIL, $previous['email'], false );
		} else {
			delete_option( self::OPT_BLOCK_EMAIL );
		}
		if ( ! empty( $previous['http'] ) ) {
			update_option( self::OPT_BLOCK_HTTP, $previous['http'], false );
		} else {
			delete_option( self::OPT_BLOCK_HTTP );
		}
	}

	private static function set_http_allowlist( $urls ) {
		// WordPress uses its HTTP API to spawn wp-cron.php and run loopback health
		// checks. Keep both configured local origins reachable while continuing to
		// deny every unrelated external destination.
		$urls[] = home_url();
		$urls[] = site_url();
		update_option( self::OPT_BLOCK_HTTP, self::http_origins( $urls ), false );
	}

	private static function http_origins( $urls ) {
		$origins = array();
		foreach ( $urls as $url ) {
			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}
			$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
			if ( ! empty( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			$origins[] = $origin;
		}
		return array_values( array_unique( $origins ) );
	}

	private static function add_email_hook() {
		if ( self::$email_hook_added ) {
			return;
		}
		self::$email_hook_added = true;
		// WP >= 5.7: short-circuits wp_mail() entirely.
		add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );

		add_action(
			'admin_notices',
			function () {
				if ( current_user_can( 'manage_options' ) ) {
					echo '<div class="notice notice-warning"><p><strong>Sandbox Sync:</strong> outgoing email is blocked on this site (sync safety rule). Manage it under Tools &rsaquo; Sandbox Sync.</p></div>';
				}
			}
		);
	}

	private static function add_http_hook() {
		if ( self::$http_hook_added ) {
			return;
		}
		self::$http_hook_added = true;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				// Merge local origins at request time as well, so sites upgrading from
				// an older stored allowlist recover loopback behavior immediately.
				$allowed = array_values(
					array_unique(
						array_merge(
							(array) get_option( self::OPT_BLOCK_HTTP, array() ),
							self::http_origins( array( home_url(), site_url() ) )
						)
					)
				);
				$parts   = wp_parse_url( $url );
				if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
					$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
					if ( ! empty( $parts['port'] ) ) {
						$origin .= ':' . (int) $parts['port'];
					}
					if ( in_array( $origin, $allowed, true ) ) {
						return $preempt;
					}
				}
				return new \WP_Error( 'sandbox_sync_http_blocked', 'Outbound HTTP request blocked by Sandbox Sync safety policy.' );
			},
			PHP_INT_MAX,
			3
		);
	}
}
