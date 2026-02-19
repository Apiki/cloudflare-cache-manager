<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Coleta todas as URLs relacionadas a um post que precisam ser purgadas.
 * Combina a abordagem do plugin oficial Cloudflare com a do WP Rocket.
 *
 * @param int $post_id ID do post.
 * @return array Lista de URLs únicas.
 */
function ccm_get_post_related_urls( $post_id ) {
    $urls      = array();
    $post      = get_post( $post_id );
    $post_type = get_post_type( $post_id );

    if ( ! $post ) {
        return $urls;
    }

    // ─── Permalink do post ─────────────────────────────────────────────────
    $permalink = get_permalink( $post_id );
    if ( $permalink ) {
        $urls[] = $permalink;
    }

    // URL sem __trashed para posts na lixeira
    if ( get_post_status( $post_id ) === 'trash' && $permalink ) {
        $clean_url = str_replace( '__trashed', '', $permalink );
        $urls[]    = $clean_url;
        $urls[]    = trailingslashit( $clean_url ) . 'feed/';
    }

    // ─── Home page ─────────────────────────────────────────────────────────
    $urls[] = home_url( '/' );

    // Página de posts (se usar página estática como front page)
    if ( 'page' === get_option( 'show_on_front' ) ) {
        $posts_page_id = get_option( 'page_for_posts' );
        if ( $posts_page_id ) {
            $posts_page_url = get_permalink( $posts_page_id );
            if ( $posts_page_url ) {
                $urls[] = $posts_page_url;
            }
        }
    }

    // ─── Paginação da home (até 3 páginas) ─────────────────────────────────
    $total_posts   = wp_count_posts()->publish;
    $per_page      = get_option( 'posts_per_page', 10 );
    $max_pages     = min( 3, ceil( $total_posts / $per_page ) );

    for ( $i = 2; $i <= $max_pages; $i++ ) {
        $urls[] = home_url( sprintf( '/page/%d/', $i ) );
    }

    // ─── Taxonomias (categorias, tags, custom) ─────────────────────────────
    $taxonomies = get_object_taxonomies( $post_type, 'objects' );

    foreach ( $taxonomies as $taxonomy ) {
        if ( ! $taxonomy->public ) {
            continue;
        }

        $terms = get_the_terms( $post_id, $taxonomy->name );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            continue;
        }

        foreach ( $terms as $term ) {
            $term_link = get_term_link( $term );
            if ( ! is_wp_error( $term_link ) ) {
                $urls[] = $term_link;
            }

            $term_feed = get_term_feed_link( $term->term_id, $term->taxonomy );
            if ( ! is_wp_error( $term_feed ) ) {
                $urls[] = $term_feed;
            }

            // Termos ancestrais (taxonomias hierárquicas)
            if ( is_taxonomy_hierarchical( $taxonomy->name ) ) {
                $ancestors = get_ancestors( $term->term_id, $taxonomy->name );
                foreach ( $ancestors as $ancestor_id ) {
                    $ancestor_link = get_term_link( $ancestor_id, $taxonomy->name );
                    if ( ! is_wp_error( $ancestor_link ) ) {
                        $urls[] = $ancestor_link;
                    }
                }
            }
        }
    }

    // ─── Página do autor (com safeguard: ignorar se for igual à home) ─────
    $author_url = get_author_posts_url( $post->post_author );
    if ( $author_url
        && trailingslashit( $author_url ) !== trailingslashit( home_url() )
        && trailingslashit( $author_url ) !== trailingslashit( site_url() )
    ) {
        $urls[] = $author_url;

        $author_feed = get_author_feed_link( $post->post_author );
        if ( $author_feed ) {
            $urls[] = $author_feed;
        }
    }

    // ─── Archive do post type (CPTs) + base de paginação ──────────────────
    if ( 'post' !== $post_type ) {
        $archive_url = get_post_type_archive_link( $post_type );
        if ( $archive_url ) {
            $urls[] = trailingslashit( $archive_url );
            $archive_feed = get_post_type_archive_feed_link( $post_type );
            if ( $archive_feed ) {
                $urls[] = $archive_feed;
            }

            // Paginação do archive (ex: /noticias/page/2/)
            if ( isset( $GLOBALS['wp_rewrite']->pagination_base ) ) {
                $urls[] = trailingslashit( $archive_url ) . $GLOBALS['wp_rewrite']->pagination_base;
            }
        }
    }

    // ─── Feeds globais ─────────────────────────────────────────────────────
    $urls[] = get_bloginfo_rss( 'rss2_url' );
    $urls[] = get_bloginfo_rss( 'atom_url' );
    $urls[] = get_bloginfo_rss( 'rss_url' );
    $urls[] = get_bloginfo_rss( 'rdf_url' );
    $urls[] = get_bloginfo_rss( 'comments_rss2_url' );

    // Feed de comentários do post
    $post_comments_feed = get_post_comments_feed_link( $post_id );
    if ( $post_comments_feed ) {
        $urls[] = $post_comments_feed;
    }

    // ─── Posts adjacentes (anterior e próximo) ─────────────────────────────
    if ( 'post' === $post_type ) {
        $next_post = get_adjacent_post( false, '', false );
        if ( $next_post ) {
            $urls[] = get_permalink( $next_post );
        }

        $prev_post = get_adjacent_post( false, '', true );
        if ( $prev_post ) {
            $urls[] = get_permalink( $prev_post );
        }

        // Próximo/anterior na MESMA CATEGORIA (WP Rocket faz isso)
        $next_in_cat = get_adjacent_post( true, '', false );
        if ( $next_in_cat && ( ! $next_post || $next_in_cat->ID !== $next_post->ID ) ) {
            $urls[] = get_permalink( $next_in_cat );
        }

        $prev_in_cat = get_adjacent_post( true, '', true );
        if ( $prev_in_cat && ( ! $prev_post || $prev_in_cat->ID !== $prev_post->ID ) ) {
            $urls[] = get_permalink( $prev_in_cat );
        }
    }

    // ─── Posts ancestrais (páginas hierárquicas) ───────────────────────────
    $parents = get_post_ancestors( $post_id );
    foreach ( $parents as $parent_id ) {
        $parent_url = get_permalink( $parent_id );
        if ( $parent_url ) {
            $urls[] = $parent_url;
        }
    }

    // ─── Archives de datas ─────────────────────────────────────────────────
    $post_date = get_the_time( 'Y-m-d', $post );
    if ( $post_date ) {
        $date_parts = explode( '-', $post_date );
        $urls[]     = get_year_link( $date_parts[0] );
        $urls[]     = get_month_link( $date_parts[0], $date_parts[1] );
        $urls[]     = get_day_link( $date_parts[0], $date_parts[1], $date_parts[2] );
    }

    // ─── AMP (se existir) ──────────────────────────────────────────────────
    if ( $permalink && function_exists( 'amp_get_permalink' ) ) {
        $urls[] = amp_get_permalink( $post_id );
    }

    // ─── Limpa e deduplica ────────────────────────────────────────────────
    $urls = array_filter( $urls, function( $url ) {
        return is_string( $url ) && ! empty( $url );
    } );
    $urls = array_values( array_unique( $urls ) );

    // ─── HTTP/HTTPS dual purge (plugin oficial CF faz isso) ───────────────
    // Se o site força SSL em admin ou conteúdo, purgar ambos os protocolos
    if ( function_exists( 'force_ssl_admin' ) && force_ssl_admin() ) {
        $http_urls = str_replace( 'https://', 'http://', $urls );
        $urls      = array_merge( $urls, $http_urls );
    } elseif ( ! is_ssl() && function_exists( 'force_ssl_content' ) && force_ssl_content() ) {
        $https_urls = str_replace( 'http://', 'https://', $urls );
        $urls       = array_merge( $urls, $https_urls );
    }

    $urls = array_values( array_unique( $urls ) );

    /**
     * Permite adicionar/remover URLs da lista de purga de um post.
     *
     * @param array $urls    Lista de URLs a purgar.
     * @param int   $post_id ID do post.
     */
    return apply_filters( 'ccm_post_purge_urls', $urls, $post_id );
}

/**
 * Coleta URLs de imagens de um attachment em todos os tamanhos registrados.
 *
 * @param int $attachment_id ID do attachment.
 * @return array Lista de URLs.
 */
function ccm_get_attachment_urls( $attachment_id ) {
    $urls = array();

    $full_url = wp_get_attachment_url( $attachment_id );
    if ( $full_url ) {
        $urls[] = $full_url;
    }

    foreach ( get_intermediate_image_sizes() as $size ) {
        $src = wp_get_attachment_image_src( $attachment_id, $size );
        if ( is_array( $src ) && ! empty( $src[0] ) ) {
            $urls[] = $src[0];
        }
    }

    return array_values( array_unique( array_filter( $urls ) ) );
}
