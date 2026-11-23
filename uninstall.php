<?php
/**
 * Odinstalace: smaže nastavení a cache. Košíky ani objednávky se nemění.
 *
 * @package Bombora\CartPopup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bcp_settings' );
delete_site_transient( 'bcp_gh_release' );
delete_site_transient( 'bcp_gh_error' );

global $wpdb;
// Transienty doporučených produktů (bcp_rel_*).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bcp\\_rel\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcp\\_rel\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
