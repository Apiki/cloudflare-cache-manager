<?php
/*
Plugin Name: Cloudflare Cache Manager
Description: Gerencie configurações de limpeza de cache Cloudflare.
Version: 2.0
Author: alfredojry
Text Domain: cloudflare-cache-manager
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CCM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CCM_PLUGIN_FILE', __FILE__ );

// Hooks
require_once CCM_PLUGIN_PATH . 'hooks/admin-menu.php';
require_once CCM_PLUGIN_PATH . 'hooks/save-post.php';
require_once CCM_PLUGIN_PATH . 'hooks/site-changes.php';

// Callbacks
require_once CCM_PLUGIN_PATH . 'callbacks/settings-callbacks.php';

// Lógica
require_once CCM_PLUGIN_PATH . 'logic/cloudflare-cache.php';
require_once CCM_PLUGIN_PATH . 'logic/url-collector.php';

// WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once CCM_PLUGIN_PATH . 'cli/class-ccm-cli.php';
    WP_CLI::add_command( 'ccm', 'CCM_CLI' );
}

// Link de Settings — funciona em plugin normal e MU plugin
if ( ccm_is_mu_plugin() ) {
    add_action( 'network_admin_menu', 'ccm_add_mu_settings_notice' );
    add_action( 'admin_notices', 'ccm_mu_settings_notice' );
} else {
    add_filter( 'plugin_action_links_' . plugin_basename( CCM_PLUGIN_FILE ), 'ccm_add_settings_link' );
}

/**
 * Detecta se o plugin está rodando como Must-Use.
 *
 * @return bool
 */
function ccm_is_mu_plugin() {
    return 0 === strpos( CCM_PLUGIN_PATH, WPMU_PLUGIN_DIR )
        || 0 === strpos( CCM_PLUGIN_PATH, wp_normalize_path( WPMU_PLUGIN_DIR ) );
}

/**
 * Adiciona link "Settings" na listagem de plugins (modo plugin normal).
 */
function ccm_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=ccm-settings' ) . '">'
                   . __( 'Settings', 'cloudflare-cache-manager' )
                   . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Exibe aviso com link para Settings no admin (modo MU plugin).
 * Aparece apenas se as credenciais não estiverem configuradas.
 */
function ccm_mu_settings_notice() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $zone_id   = get_option( 'ccm_cloudflare_zone_id', '' );
    $api_token = get_option( 'ccm_cloudflare_api_token', '' );

    if ( ! empty( $zone_id ) && ! empty( $api_token ) ) return;

    $url = admin_url( 'options-general.php?page=ccm-settings' );
    echo '<div class="notice notice-warning"><p>';
    echo '<strong>Cloudflare Cache Manager</strong> — ';
    echo 'Configure o Zone ID e API Token em <a href="' . esc_url( $url ) . '">Configurações &rarr; Cloudflare Cache Manager</a>.';
    echo '</p></div>';
}

function ccm_add_mu_settings_notice() {
    // placeholder para network admin se necessário no futuro
}
