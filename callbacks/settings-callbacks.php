<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Renderiza a página de configurações do plugin
function ccm_render_settings_page() {
    require CCM_PLUGIN_PATH . 'views/settings-form.php';
}