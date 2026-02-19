<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Verifica se uma purga já foi executada recentemente (debounce).
 * Evita múltiplas chamadas à API da Cloudflare em operações em lote.
 *
 * @return bool true se deve pular a purga (já houve uma recente)
 */
function ccm_should_throttle_purge() {
    $interval = absint( get_option( 'ccm_purge_interval', 10 ) );

    if ( $interval <= 0 ) {
        return false;
    }

    $transient_key = 'ccm_purge_throttle';

    if ( get_transient( $transient_key ) ) {
        return true;
    }

    set_transient( $transient_key, time(), $interval );
    return false;
}

/**
 * Resolve o texto descritivo do trigger para uso em logs de debug.
 *
 * @param string $trigger Identificador do trigger.
 * @return string
 */
function ccm_get_trigger_label( $trigger ) {
    $labels = array(
        // Conteúdo (posts/pages)
        'publish_post'           => 'Publicação imediata',
        'future_to_publish'      => 'Agendado publicado',
        'wp_trash_post'          => 'Post enviado para lixeira',
        'delete_post'            => 'Post excluído permanentemente',
        'clean_post_cache'       => 'Cache de post limpo (WordPress interno)',
        'wp_update_comment_count'=> 'Contagem de comentários atualizada',
        'post_status_to_draft'   => 'Post alterado de publicado para rascunho',
        'post_slug_changed'      => 'Slug/permalink do post alterado',
        'transition_post_status' => 'Transição de status do post',

        // Alterações globais do site
        'switch_theme'                  => 'Tema alterado',
        'upgrader_process_complete'     => 'Tema/plugin atualizado',
        'wp_update_nav_menu'            => 'Menu de navegação atualizado',
        'update_option_sidebars_widgets'=> 'Ordem dos widgets alterada',
        'widget_update_callback'        => 'Widget atualizado',
        'customize_save'                => 'Customizer salvo',
        'update_option_theme_mods'      => 'Localização de menu alterada',
        'permalink_structure_changed'   => 'Estrutura de permalinks alterada',
        'update_option_category_base'   => 'Base de categoria alterada',
        'update_option_tag_base'        => 'Base de tag alterada',
        'update_option_blog_public'     => 'Visibilidade do site alterada',

        // Taxonomias / Termos
        'create_term'  => 'Termo/categoria criado',
        'edit_term'    => 'Termo/categoria editado',
        'delete_term'  => 'Termo/categoria excluído',

        // Blogroll (links)
        'add_link'    => 'Link adicionado',
        'edit_link'   => 'Link editado',
        'delete_link' => 'Link excluído',

        // Usuários
        'profile_update' => 'Perfil de usuário atualizado',
        'delete_user'    => 'Usuário excluído',
        'user_register'  => 'Novo usuário registrado',

        // Manual
        'manual_purge' => 'Limpeza manual via painel',
    );

    return isset( $labels[ $trigger ] ) ? $labels[ $trigger ] : $trigger;
}

/**
 * Purga o cache do Cloudflare e gera logs de debug se habilitado.
 * Inclui mecanismo de debounce para evitar chamadas excessivas à API.
 *
 * @param int|null $post_id   O ID do post relacionado à ação (opcional).
 * @param string   $trigger   Identificador do hook que disparou a purga.
 * @return bool
 */
function ccm_purge_cloudflare_cache( $post_id = null, $trigger = '' ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return false;
    }

    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return false;
    }

    if ( ccm_should_throttle_purge() ) {
        $debug_error_log = get_option( 'ccm_debug_error_log', false );
        if ( $debug_error_log ) {
            error_log( '[CCM Cloudflare] Purge ignorada (debounce ativo) | Trigger: ' . ccm_get_trigger_label( $trigger ) );
        }
        return false;
    }

    $zone_id           = get_option( 'ccm_cloudflare_zone_id', '' );
    $api_token         = get_option( 'ccm_cloudflare_api_token', '' );
    $debug_error_log   = get_option( 'ccm_debug_error_log', false );
    $debug_woocommerce = get_option( 'ccm_debug_woocommerce', false );

    $post_id      = $post_id ? intval( $post_id ) : 0;
    $permalink    = $post_id ? get_permalink( $post_id ) : '';
    $trigger_text = ccm_get_trigger_label( $trigger );

    if ( empty( $zone_id ) || empty( $api_token ) ) {
        return false;
    }

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $body     = wp_json_encode( array( 'purge_everything' => true ) );

    $response = wp_remote_post( $endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => $body,
        'timeout' => 20,
    ) );

    $debug_message = '[CCM Cloudflare] ';
    if ( $post_id ) {
        $debug_message .= "Post ID: {$post_id} | ";
    }
    if ( $permalink ) {
        $debug_message .= "Permalink: {$permalink} | ";
    }
    if ( $trigger_text ) {
        $debug_message .= "Ação: {$trigger_text} | ";
    }

    if ( is_wp_error( $response ) ) {
        $debug_message .= 'Erro WP_Error: ' . $response->get_error_message();
    } else {
        $code          = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $debug_message .= 'Código: ' . $code . ' | Corpo: ' . mb_substr( $response_body, 0, 300 );
        if ( mb_strlen( $response_body ) > 300 ) {
            $debug_message .= '... [truncado]';
        }
    }

    if ( $debug_error_log ) {
        error_log( $debug_message );
    }

    if ( $debug_woocommerce && class_exists( 'WC_Logger' ) && function_exists( 'wc_get_logger' ) ) {
        $logger = wc_get_logger();
        $logger->debug( $debug_message, array( 'source' => 'cloudflare-cache-manager' ) );
    }

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    return isset( $data['success'] ) && $data['success'];
}

/**
 * Purga o cache do Cloudflare manualmente e retorna informações detalhadas para debug.
 *
 * @return array
 */
function ccm_purge_cloudflare_cache_manual() {
    $zone_id   = get_option( 'ccm_cloudflare_zone_id', '' );
    $api_token = get_option( 'ccm_cloudflare_api_token', '' );

    $result = array(
        'success'       => false,
        'timestamp'     => current_time( 'Y-m-d H:i:s' ),
        'http_code'     => 'N/A',
        'message'       => '',
        'response_body' => '',
        'error'         => '',
    );

    if ( empty( $zone_id ) || empty( $api_token ) ) {
        $result['message'] = 'Credenciais não configuradas. Por favor, configure o Zone ID e API Token.';
        $result['error']   = 'Configuração incompleta';
        return $result;
    }

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $body     = wp_json_encode( array( 'purge_everything' => true ) );

    $response = wp_remote_post( $endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => $body,
        'timeout' => 20,
    ) );

    if ( is_wp_error( $response ) ) {
        $result['message'] = 'Erro ao conectar com a API do Cloudflare';
        $result['error']   = $response->get_error_message();
        return $result;
    }

    $http_code     = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    $result['http_code']     = $http_code;
    $result['response_body'] = $response_body;

    $data = json_decode( $response_body, true );

    if ( $http_code === 200 && isset( $data['success'] ) && $data['success'] === true ) {
        $result['success'] = true;
        $result['message'] = 'Cache do Cloudflare limpo com sucesso!';
    } else {
        $result['message'] = 'Falha ao limpar o cache do Cloudflare';

        if ( isset( $data['errors'] ) && is_array( $data['errors'] ) && ! empty( $data['errors'] ) ) {
            $errors = array();
            foreach ( $data['errors'] as $error ) {
                if ( isset( $error['message'] ) ) {
                    $errors[] = $error['message'];
                }
            }
            if ( ! empty( $errors ) ) {
                $result['error'] = implode( ', ', $errors );
            }
        }

        if ( empty( $result['error'] ) ) {
            $result['error'] = 'Código HTTP: ' . $http_code;
        }
    }

    return $result;
}
