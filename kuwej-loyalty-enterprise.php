<?php
/**
 * Plugin Name: Kuwej Loyalty Enterprise
 * Plugin URI:  https://github.com/mbayokapit4/kuwej-loyalty-enterprise
 * Description: Suite Marketing WooCommerce : Fidelite + Parrainage + Stats. (Senior Edition).
 * Version:     11.0.0
 * Author:      Kuwej Senior Dev
 * Author URI:  https://kuwej.com
 * Text Domain: kuwej-loyalty
 * Domain Path: /languages
 * License:     GPL-2.0+
 *
 * GitHub Plugin URI: mbayokapit4/kuwej-loyalty-enterprise
 * Primary Branch:    main
 * Release Asset:     true
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Compatibilite HPOS
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Auto-updater integre (verifie les releases GitHub)
add_filter( 'pre_set_site_transient_update_plugins', 'kuwej_check_github_update' );
function kuwej_check_github_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $plugin_slug = 'kuwej-loyalty-enterprise/kuwej-loyalty-enterprise.php';
    $current_version = $transient->checked[ $plugin_slug ] ?? '0.0.0';

    $cache_key = 'kuwej_loyalty_gh_update';
    $cached    = get_transient( $cache_key );

    if ( false === $cached ) {
        $response = wp_remote_get(
            'https://api.github.com/repos/mbayokapit4/kuwej-loyalty-enterprise/releases/latest',
            [ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ], 'timeout' => 10 ]
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return $transient;
        }

        $cached = json_decode( wp_remote_retrieve_body( $response ) );
        set_transient( $cache_key, $cached, HOUR_IN_SECONDS * 6 );
    }

    if ( empty( $cached->tag_name ) ) return $transient;

    $latest_version = ltrim( $cached->tag_name, 'v' );

    if ( version_compare( $latest_version, $current_version, '>' ) ) {
        $transient->response[ $plugin_slug ] = (object) [
            'slug'        => 'kuwej-loyalty-enterprise',
            'plugin'      => $plugin_slug,
            'new_version' => $latest_version,
            'url'         => 'https://github.com/mbayokapit4/kuwej-loyalty-enterprise',
            'package'     => $cached->zipball_url,
        ];
    }

    return $transient;
}

// Nettoyage du cache lors d'une mise a jour manuelle
add_action( 'upgrader_process_complete', function() {
    delete_transient( 'kuwej_loyalty_gh_update' );
}, 10, 0 );

// Chargement de la classe principale
require_once plugin_dir_path( __FILE__ ) . 'includes/class-kuwej-loyalty.php';
new Kuwej_Loyalty_Enterprise();
