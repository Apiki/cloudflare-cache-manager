<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Dispara a limpeza de cache ao publicar post imediatamente
add_action( 'publish_post', 'ccm_on_publish_post', 10, 2 );
add_action( 'publish_page', 'ccm_on_publish_post', 10, 2 );

// Dispara a limpeza de cache ao publicar agendamento
add_action( 'future_to_publish', 'ccm_on_scheduled_to_publish', 10, 1 );

/**
 * Callback para publicação imediata de post
 */
function ccm_on_publish_post( $ID, $post ) {
    ccm_purge_cloudflare_cache( $ID, 'publish_post' );
}

/**
 * Callback para post agendado que foi publicado
 */
function ccm_on_scheduled_to_publish( $post ) {
    ccm_purge_cloudflare_cache( $post->ID, 'future_to_publish' );
}