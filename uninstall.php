<?php
/**
 * Disinstallazione di solidaros.
 * Rimuove SOLO ciò che appartiene a questo plugin: le sue tabelle, le sue opzioni,
 * le sue capability. Nessun'altra tabella viene toccata.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

foreach ( array( 'solidaros_assessments', 'solidaros_results', 'solidaros_tokens', 'solidaros_producers', 'solidaros_standalone_users', 'solidaros_magic_links', 'solidaros_audit_log' ) as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB
}

foreach ( array(
    'solidaros_schema_version',
    'solidaros_active_country',
    'solidaros_balance_year',
    'solidaros_notify_access',
    'solidaros_notify_completed',
    'solidaros_notify_pending_eval',
    'solidaros_notify_reopened',
    'solidaros_sender_email',
    'solidaros_guide_users_url',
    'solidaros_guide_admin_url',
    'solidaros_page_id',
    'solidaros_pages',
    'solidaros_caps_version',
) as $option ) {
    delete_option( $option );
}

// Le capability sono nostre e vanno via; i ruoli restano: 'administrator' ed
// 'editor' sono ruoli core di WordPress e sm_producer può essere condiviso con
// sm-pgs / sm-products. Il ruolo sm_reviewer non esiste più (ritirato in
// Capabilities::install()).
foreach ( array( 'administrator', 'editor', 'sm_producer' ) as $roleName ) {
    $role = get_role( $roleName );
    if ( ! $role ) {
        continue;
    }
    foreach ( array( 'solidaros_submit_assessment', 'solidaros_review_assessment', 'solidaros_manage' ) as $cap ) {
        $role->remove_cap( $cap );
    }
}

// ---- Modulo Catalogo (ex Directorist Network): solo impostazioni e dati temporanei; tipi, schede, termini e media restano.
delete_option( 'solidaros_module_catalog' );
delete_option( 'solidaros_module_selfcert' );

delete_option( 'dirnet_applied' );
delete_option( 'dirnet_territory_page' );
delete_option( 'dirnet_register_page' );
delete_option( 'dirnet_entities_page' );
delete_option( 'dirnet_sheet_categories' );
delete_option( 'dirnet_social_report_shortcode' );
delete_option( 'dirnet_categories_managed' );
delete_option( 'dirnet_privacy_url' );
delete_option( 'dirnet_network_name' );
delete_option( 'dirnet_membership_url' );
delete_option( 'dirnet_cookie_url' );
delete_option( 'dirnet_terms_url' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dirnet\\_imp\\_%' OR option_name LIKE '\\_transient\\_dirnet\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dirnet\\_%'" ); // phpcs:ignore WordPress.DB

$up  = wp_upload_dir( null, false );
$tmp = trailingslashit( $up['basedir'] ) . 'directorist-network/tmp/';
foreach ( (array) glob( $tmp . '*' ) as $f ) {
	if ( is_file( $f ) ) {
		@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
delete_option( 'dirnet_geo_url' );
wp_clear_scheduled_hook( 'dirnet_geo_refresh' );
