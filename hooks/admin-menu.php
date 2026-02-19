<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Adiciona um submenu na área de configurações do WordPress
add_action( 'admin_menu', 'ccm_add_settings_submenu' );

function ccm_add_settings_submenu() {
    add_submenu_page(
        'options-general.php',
        __( 'Cloudflare Cache Manager', 'cloudflare-cache-manager' ),
        __( 'Cloudflare Cache Manager', 'cloudflare-cache-manager' ),
        'manage_options',
        'ccm-settings',
        'ccm_render_settings_page'
    );
}