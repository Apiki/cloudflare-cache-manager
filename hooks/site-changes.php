<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks de alterações globais do site — disparam purge_everything no Cloudflare
 * quando configurações estruturais do WordPress são alteradas.
 *
 * Estes eventos afetam o site inteiro (tema, menu, widgets, permalink, etc.),
 * portanto purge_everything é justificado aqui.
 */

// ─── Tema ──────────────────────────────────────────────────────────────────────
add_action( 'switch_theme', 'ccm_on_site_change_generic' );
add_action( 'upgrader_process_complete', 'ccm_on_theme_or_plugin_update', 10, 2 );

// ─── Menus de navegação ────────────────────────────────────────────────────────
add_action( 'wp_update_nav_menu', 'ccm_on_site_change_generic' );

// ─── Widgets ───────────────────────────────────────────────────────────────────
add_action( 'update_option_sidebars_widgets', 'ccm_on_site_change_generic' );
add_filter( 'widget_update_callback', 'ccm_on_widget_update' );

// ─── Customizer ────────────────────────────────────────────────────────────────
add_action( 'customize_save', 'ccm_on_site_change_generic' );
add_action( 'customize_save_after', 'ccm_on_site_change_generic' );

// ─── Theme Mods (localização de menus, header, etc.) ──────────────────────────
add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), 'ccm_on_theme_mods_change' );

// ─── Permalinks ────────────────────────────────────────────────────────────────
add_action( 'permalink_structure_changed', 'ccm_on_site_change_generic' );
add_action( 'update_option_category_base', 'ccm_on_site_change_generic' );
add_action( 'update_option_tag_base', 'ccm_on_site_change_generic' );

// ─── Visibilidade do site ──────────────────────────────────────────────────────
add_action( 'update_option_blog_public', 'ccm_on_site_change_generic' );

// ─── Blogroll (links) ──────────────────────────────────────────────────────────
add_action( 'add_link', 'ccm_on_site_change_generic' );
add_action( 'edit_link', 'ccm_on_site_change_generic' );
add_action( 'delete_link', 'ccm_on_site_change_generic' );

// ─── Taxonomias / Termos ───────────────────────────────────────────────────────
add_action( 'create_term', 'ccm_on_term_change', 10, 3 );
add_action( 'edit_term', 'ccm_on_term_change', 10, 3 );
add_action( 'delete_term', 'ccm_on_term_change', 10, 3 );

// ─── Usuários ──────────────────────────────────────────────────────────────────
add_action( 'profile_update', 'ccm_on_user_change' );
add_action( 'delete_user', 'ccm_on_user_change' );
add_action( 'user_register', 'ccm_on_user_change' );


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACKS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Callback genérico para hooks que não precisam de lógica adicional.
 * Detecta automaticamente o nome do hook para usar como trigger.
 */
function ccm_on_site_change_generic() {
    $trigger = current_filter();
    ccm_purge_everything( $trigger );
}

/**
 * Purga quando um widget é atualizado (via filter, deve retornar a instância).
 *
 * @param array $instance Widget instance.
 * @return array
 */
function ccm_on_widget_update( $instance ) {
    ccm_purge_everything( 'widget_update_callback' );
    return $instance;
}

/**
 * Purga quando theme_mods são alterados (localização de menus, etc.).
 */
function ccm_on_theme_mods_change() {
    ccm_purge_everything( 'update_option_theme_mods' );
}

/**
 * Purga quando um termo de taxonomia pública é criado, editado ou excluído.
 *
 * @param int    $term_id  ID do termo.
 * @param int    $tt_id    ID da taxonomia do termo.
 * @param string $taxonomy Slug da taxonomia.
 */
function ccm_on_term_change( $term_id, $tt_id, $taxonomy ) {
    $tax_obj = get_taxonomy( $taxonomy );
    if ( false === $tax_obj || ! $tax_obj->public ) return;

    $trigger = current_filter();
    ccm_purge_everything( $trigger );
}

/**
 * Purga quando um perfil de usuário é atualizado ou excluído.
 */
function ccm_on_user_change() {
    $trigger = current_filter();
    ccm_purge_everything( $trigger );
}

/**
 * Purga quando o tema ativo ou seu tema-pai é atualizado via upgrader.
 *
 * @param WP_Upgrader $upgrader   Instância do upgrader.
 * @param array       $hook_extra Dados do update.
 */
function ccm_on_theme_or_plugin_update( $upgrader, $hook_extra ) {
    if ( ! isset( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) return;
    if ( ! isset( $hook_extra['type'] ) ) return;

    if ( 'theme' === $hook_extra['type'] ) {
        if ( ! isset( $hook_extra['themes'] ) || ! is_array( $hook_extra['themes'] ) ) return;

        $current_theme = wp_get_theme();
        $active_themes = array(
            $current_theme->get_template(),
            $current_theme->get_stylesheet(),
        );

        if ( empty( array_intersect( $hook_extra['themes'], $active_themes ) ) ) return;
    }

    if ( 'plugin' === $hook_extra['type'] ) {
        // Qualquer plugin atualizado pode afetar o front-end
    }

    ccm_purge_everything( 'upgrader_process_complete' );
}
