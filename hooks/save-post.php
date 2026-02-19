<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks de conteúdo — disparam purge do Cloudflare quando posts, páginas,
 * custom post types ou comentários sofrem alterações.
 *
 * Inspirado no mapeamento de hooks do WP Rocket (inc/common/purge.php).
 */

// ─── Publicação imediata (post e page) ────────────────────────────────────────
add_action( 'publish_post', 'ccm_on_publish_post', 10, 2 );
add_action( 'publish_page', 'ccm_on_publish_post', 10, 2 );

// ─── Publicação de agendamento ─────────────────────────────────────────────────
add_action( 'future_to_publish', 'ccm_on_scheduled_to_publish', 10, 1 );

// ─── Post enviado para lixeira ─────────────────────────────────────────────────
add_action( 'wp_trash_post', 'ccm_on_trash_post' );

// ─── Post excluído permanentemente ────────────────────────────────────────────
add_action( 'delete_post', 'ccm_on_delete_post' );

// ─── Cache interno do WordPress limpo (cobre save_post, edit_post, etc.) ──────
add_action( 'clean_post_cache', 'ccm_on_clean_post_cache' );

// ─── Comentário adicionado/removido (altera contagem) ─────────────────────────
add_action( 'wp_update_comment_count', 'ccm_on_comment_count_update' );

// ─── Transição de status: publicado → rascunho ────────────────────────────────
add_action( 'pre_post_update', 'ccm_on_status_change_to_draft', 10, 2 );

// ─── Alteração de slug/permalink ──────────────────────────────────────────────
add_action( 'pre_post_update', 'ccm_on_slug_change', PHP_INT_MAX, 2 );

// ─── Transição genérica de status (qualquer → publish ou publish → qualquer) ──
add_action( 'transition_post_status', 'ccm_on_transition_post_status', 10, 3 );


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACKS
// ═══════════════════════════════════════════════════════════════════════════════

function ccm_on_publish_post( $ID, $post ) {
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_cloudflare_cache( $ID, 'publish_post' );
}

function ccm_on_scheduled_to_publish( $post ) {
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_cloudflare_cache( $post->ID, 'future_to_publish' );
}

function ccm_on_trash_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_cloudflare_cache( $post_id, 'wp_trash_post' );
}

function ccm_on_delete_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_cloudflare_cache( $post_id, 'delete_post' );
}

function ccm_on_clean_post_cache( $post_id ) {
    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    if ( in_array( get_post_status( $post_id ), array( 'auto-draft', 'draft', 'inherit' ), true ) ) return;
    ccm_purge_cloudflare_cache( $post_id, 'clean_post_cache' );
}

function ccm_on_comment_count_update( $post_id ) {
    ccm_purge_cloudflare_cache( $post_id, 'wp_update_comment_count' );
}

/**
 * Purga quando um post publicado é alterado para rascunho.
 */
function ccm_on_status_change_to_draft( $post_id, $post_data ) {
    if ( 'publish' !== get_post_field( 'post_status', $post_id ) ) return;
    if ( ! isset( $post_data['post_status'] ) || 'draft' !== $post_data['post_status'] ) return;

    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;

    ccm_purge_cloudflare_cache( $post_id, 'post_status_to_draft' );
}

/**
 * Purga quando o slug de um post publicado é alterado.
 */
function ccm_on_slug_change( $post_id, $post_data ) {
    $current_status = get_post_field( 'post_status', $post_id );
    if ( in_array( $current_status, array( 'draft', 'pending', 'auto-draft', 'trash' ), true ) ) return;

    $current_slug = get_post_field( 'post_name', $post_id );
    if ( empty( $current_slug ) ) return;
    if ( ! isset( $post_data['post_name'] ) || $current_slug === $post_data['post_name'] ) return;

    ccm_purge_cloudflare_cache( $post_id, 'post_slug_changed' );
}

/**
 * Purga em transições genéricas de status que envolvem 'publish'.
 * Cobre custom post types que não disparam publish_post/publish_page.
 */
function ccm_on_transition_post_status( $new_status, $old_status, $post ) {
    if ( 'publish' !== $new_status && 'publish' !== $old_status ) return;
    if ( $new_status === $old_status ) return;
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;

    ccm_purge_cloudflare_cache( $post->ID, 'transition_post_status' );
}


// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se o post type é público e elegível para purga.
 * Exclui revisões, auto-drafts, nav_menu_item e attachment.
 *
 * @param WP_Post|null $post
 * @return bool
 */
function ccm_is_purgeable_post_type( $post ) {
    if ( ! is_object( $post ) ) return false;
    if ( empty( $post->post_type ) ) return false;
    if ( in_array( $post->post_type, array( 'nav_menu_item', 'attachment', 'revision' ), true ) ) return false;

    $post_type_obj = get_post_type_object( $post->post_type );
    if ( ! is_object( $post_type_obj ) || true !== $post_type_obj->public ) return false;

    return true;
}
