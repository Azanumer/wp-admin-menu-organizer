<?php
/**
 * Uninstall: remove the saved menu rules.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wamo_rules' );
