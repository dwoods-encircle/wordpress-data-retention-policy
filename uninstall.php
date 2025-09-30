<?php
/**
 * Uninstall handler for Data Retention Policy Manager.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$option_key = 'drp_settings';

delete_option( $option_key );

delete_metadata( 'user', 0, 'drp_disabled', '', true );
delete_metadata( 'user', 0, 'drp_last_active', '', true );
delete_metadata( 'post', 0, 'drp_archived_at', '', true );
