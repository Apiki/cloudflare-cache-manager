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

// Define o caminho do plugin como constante
define( 'CCM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Hooks
require_once CCM_PLUGIN_PATH . 'hooks/admin-menu.php';
require_once CCM_PLUGIN_PATH . 'hooks/save-post.php';
require_once CCM_PLUGIN_PATH . 'hooks/site-changes.php';

// Callbacks
require_once CCM_PLUGIN_PATH . 'callbacks/settings-callbacks.php';

// Lógica
require_once CCM_PLUGIN_PATH . 'logic/cloudflare-cache.php';
require_once CCM_PLUGIN_PATH . 'logic/url-collector.php';

// As views são carregadas via callbacks

// Adiciona link de configurações na lista de plugins
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ccm_add_settings_link' );

function ccm_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=ccm-settings' ) . '">' . __( 'Settings', 'cloudflare-cache-manager' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}