<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks de conteúdo — disparam purga SELETIVA (por URLs) no Cloudflare
 * quando posts, páginas, CPTs, attachments ou comentários sofrem alterações.
 *
 * Diferente de purge_everything, aqui apenas as URLs relacionadas ao post
 * são invalidadas, preservando o cache do restante do site.
 *
 * Baseado nos hooks do WP Rocket e do plugin oficial Cloudflare (v4.14.2).
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

// ─── Attachment excluído ou re-uploadado ──────────────────────────────────────
add_action( 'delete_attachment', 'ccm_on_delete_attachment' );

// ─── Comentário adicionado/removido (altera contagem) ─────────────────────────
add_action( 'wp_update_comment_count', 'ccm_on_comment_count_update' );

// ─── Novo comentário aprovado ─────────────────────────────────────────────────
add_action( 'comment_post', 'ccm_on_new_comment', 10, 3 );

// ─── Transição de status de comentário (aprovado <-> pendente/spam/lixeira) ───
add_action( 'transition_comment_status', 'ccm_on_comment_status_change', 10, 3 );

// ─── Transição de status: publicado -> rascunho ───────────────────────────────
add_action( 'pre_post_update', 'ccm_on_status_change_to_draft', 10, 2 );

// ─── Alteração de slug/permalink ──────────────────────────────────────────────
add_action( 'pre_post_update', 'ccm_on_slug_change', PHP_INT_MAX, 2 );

// ─── Transição genérica de status (qualquer -> publish ou publish -> qualquer)
add_action( 'transition_post_status', 'ccm_on_transition_post_status', 10, 3 );


// ═══════════════════════════════════════════════════════════════════════════════
// CALLBACKS — todos chamam ccm_purge_post_urls() (purga granular)
// ═══════════════════════════════════════════════════════════════════════════════

function ccm_on_publish_post( $ID, $post ) {
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_post_urls( $ID, 'publish_post' );
}

function ccm_on_scheduled_to_publish( $post ) {
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_post_urls( $post->ID, 'future_to_publish' );
}

function ccm_on_trash_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_post_urls( $post_id, 'wp_trash_post' );
}

function ccm_on_delete_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    ccm_purge_post_urls( $post_id, 'delete_post' );
}

function ccm_on_clean_post_cache( $post_id ) {
    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;
    if ( in_array( get_post_status( $post_id ), array( 'auto-draft', 'draft', 'inherit' ), true ) ) return;
    ccm_purge_post_urls( $post_id, 'clean_post_cache' );
}

function ccm_on_delete_attachment( $post_id ) {
    ccm_purge_post_urls( $post_id, 'delete_attachment' );
}

function ccm_on_comment_count_update( $post_id ) {
    ccm_purge_post_urls( $post_id, 'wp_update_comment_count' );
}

/**
 * Purga quando um novo comentário aprovado é postado.
 */
function ccm_on_new_comment( $comment_id, $comment_approved, $comment_data ) {
    if ( 1 !== $comment_approved ) return;
    if ( ! is_array( $comment_data ) || ! isset( $comment_data['comment_post_ID'] ) ) return;

    ccm_purge_post_urls( $comment_data['comment_post_ID'], 'comment_post' );
}

/**
 * Purga quando o status de um comentário muda e envolve 'approved'.
 */
function ccm_on_comment_status_change( $new_status, $old_status, $comment ) {
    if ( ! isset( $comment->comment_post_ID ) || empty( $comment->comment_post_ID ) ) return;
    if ( $new_status === $old_status ) return;
    if ( 'approved' !== $new_status && 'approved' !== $old_status ) return;

    ccm_purge_post_urls( $comment->comment_post_ID, 'transition_comment_status' );
}

/**
 * Purga quando um post publicado é alterado para rascunho.
 */
function ccm_on_status_change_to_draft( $post_id, $post_data ) {
    if ( 'publish' !== get_post_field( 'post_status', $post_id ) ) return;
    if ( ! isset( $post_data['post_status'] ) || 'draft' !== $post_data['post_status'] ) return;

    $post = get_post( $post_id );
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;

    ccm_purge_post_urls( $post_id, 'post_status_to_draft' );
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

    ccm_purge_post_urls( $post_id, 'post_slug_changed' );
}

/**
 * Purga em transições genéricas de status que envolvem 'publish'.
 * Cobre CPTs que não disparam publish_post/publish_page.
 */
function ccm_on_transition_post_status( $new_status, $old_status, $post ) {
    if ( 'publish' !== $new_status && 'publish' !== $old_status ) return;
    if ( $new_status === $old_status ) return;
    if ( ! ccm_is_purgeable_post_type( $post ) ) return;

    ccm_purge_post_urls( $post->ID, 'transition_post_status' );
}


// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se o post type é público e elegível para purga.
 *
 * @param WP_Post|null $post
 * @return bool
 */
function ccm_is_purgeable_post_type( $post ) {
    if ( ! is_object( $post ) ) return false;
    if ( empty( $post->post_type ) ) return false;
    if ( in_array( $post->post_type, array( 'nav_menu_item', 'revision' ), true ) ) return false;

    $post_type_obj = get_post_type_object( $post->post_type );
    if ( ! is_object( $post_type_obj ) || true !== $post_type_obj->public ) return false;

    return true;
}
