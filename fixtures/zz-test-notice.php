<?php
/**
 * Plugin Name: ZZ Test Notice (Sandbox Sync fixture)
 * Description: Tiny mu-plugin used to verify that mu-plugins travel from live to sandbox. Leaves a marker in the admin footer and in the front-end HTML.
 * Version: 1.0
 */

add_filter(
	'admin_footer_text',
	function ( $text ) {
		return $text . ' — zz-test-notice active';
	}
);

add_action(
	'wp_footer',
	function () {
		echo "\n<!-- zz-test-notice active -->\n";
	}
);
