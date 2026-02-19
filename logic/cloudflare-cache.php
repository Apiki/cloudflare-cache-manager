<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Monta os headers padrão para chamadas à API da Cloudflare.
 *
 * @return array
 */
function ccm_get_api_headers() {
    $api_token = get_option( 'ccm_cloudflare_api_token', '' );

    global $wp_version;
    $plugin_version = '2.0';

    return array(
        'Authorization' => 'Bearer ' . $api_token,
        'Content-Type'  => 'application/json',
        'User-Agent'    => 'wordpress/' . $wp_version . '; cloudflare-cache-manager/' . $plugin_version,
    );
}

/**
 * Verifica se as credenciais da API estão configuradas.
 *
 * @return bool
 */
function ccm_has_credentials() {
    return ! empty( get_option( 'ccm_cloudflare_zone_id', '' ) )
        && ! empty( get_option( 'ccm_cloudflare_api_token', '' ) );
}

/**
 * Verifica se uma purga já foi executada recentemente (debounce).
 *
 * @param string $context 'everything' ou 'urls' — transients separados.
 * @return bool true se deve pular a purga.
 */
function ccm_should_throttle_purge( $context = 'everything' ) {
    $interval = absint( get_option( 'ccm_purge_interval', 10 ) );

    if ( $interval <= 0 ) {
        return false;
    }

    $transient_key = 'ccm_purge_throttle_' . $context;

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
        'publish_post'              => 'Publicação imediata',
        'future_to_publish'         => 'Agendado publicado',
        'wp_trash_post'             => 'Post enviado para lixeira',
        'delete_post'               => 'Post excluído permanentemente',
        'delete_attachment'         => 'Attachment excluído/re-uploadado',
        'clean_post_cache'          => 'Cache de post limpo (WordPress interno)',
        'wp_update_comment_count'   => 'Contagem de comentários atualizada',
        'comment_post'              => 'Novo comentário aprovado',
        'transition_comment_status' => 'Status de comentário alterado',
        'post_status_to_draft'      => 'Post alterado de publicado para rascunho',
        'post_slug_changed'         => 'Slug/permalink do post alterado',
        'transition_post_status'    => 'Transição de status do post',
        'switch_theme'                  => 'Tema alterado',
        'upgrader_process_complete'     => 'Tema/plugin atualizado',
        'wp_update_nav_menu'            => 'Menu de navegação atualizado',
        'update_option_sidebars_widgets'=> 'Ordem dos widgets alterada',
        'widget_update_callback'        => 'Widget atualizado',
        'customize_save'                => 'Customizer salvo',
        'customize_save_after'          => 'Customizer salvo (after)',
        'update_option_theme_mods'      => 'Localização de menu alterada',
        'permalink_structure_changed'   => 'Estrutura de permalinks alterada',
        'update_option_category_base'   => 'Base de categoria alterada',
        'update_option_tag_base'        => 'Base de tag alterada',
        'update_option_blog_public'     => 'Visibilidade do site alterada',
        'create_term'  => 'Termo/categoria criado',
        'edit_term'    => 'Termo/categoria editado',
        'delete_term'  => 'Termo/categoria excluído',
        'add_link'    => 'Link adicionado',
        'edit_link'   => 'Link editado',
        'delete_link' => 'Link excluído',
        'profile_update' => 'Perfil de usuário atualizado',
        'delete_user'    => 'Usuário excluído',
        'user_register'  => 'Novo usuário registrado',
        'manual_purge' => 'Limpeza manual via painel',
    );

    return isset( $labels[ $trigger ] ) ? $labels[ $trigger ] : $trigger;
}

/**
 * Loga mensagem nos canais de debug habilitados.
 *
 * @param string $message Mensagem de log.
 */
function ccm_debug_log( $message ) {
    if ( get_option( 'ccm_debug_error_log', false ) ) {
        error_log( $message );
    }

    if ( get_option( 'ccm_debug_woocommerce', false ) && class_exists( 'WC_Logger' ) && function_exists( 'wc_get_logger' ) ) {
        $logger = wc_get_logger();
        $logger->debug( $message, array( 'source' => 'cloudflare-cache-manager' ) );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PURGA SELETIVA POR URLs (granular)
// Cloudflare API: POST /zones/{zone_id}/purge_cache { "files": [...] }
// Limite: 30 URLs por request
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Purga URLs específicas no cache da Cloudflare.
 * Envia em lotes de 30 URLs (limite da API).
 *
 * @param int    $post_id ID do post.
 * @param string $trigger Identificador do hook.
 * @return bool true se todas as chamadas foram bem-sucedidas.
 */
function ccm_purge_post_urls( $post_id, $trigger = '' ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return false;
    }

    if ( $post_id && ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) ) {
        return false;
    }

    if ( ! ccm_has_credentials() ) {
        return false;
    }

    $trigger_text = ccm_get_trigger_label( $trigger );

    // Coleta URLs relacionadas ao post
    $urls = ccm_get_post_related_urls( $post_id );

    // Para attachments, inclui também as URLs dos thumbnails
    if ( 'attachment' === get_post_type( $post_id ) ) {
        $urls = array_merge( $urls, ccm_get_attachment_urls( $post_id ) );
        $urls = array_values( array_unique( $urls ) );
    }

    if ( empty( $urls ) ) {
        ccm_debug_log( "[CCM Cloudflare] Nenhuma URL para purgar | Post ID: {$post_id} | Ação: {$trigger_text}" );
        return false;
    }

    $zone_id  = get_option( 'ccm_cloudflare_zone_id', '' );
    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $headers  = ccm_get_api_headers();

    $chunks     = array_chunk( $urls, 30 );
    $all_ok     = true;
    $total_urls = count( $urls );

    ccm_debug_log( "[CCM Cloudflare] Purge seletiva | Post ID: {$post_id} | Ação: {$trigger_text} | {$total_urls} URLs em " . count( $chunks ) . " lote(s)" );

    foreach ( $chunks as $index => $chunk ) {
        $body = wp_json_encode( array( 'files' => array_values( $chunk ) ) );

        $response = wp_remote_post( $endpoint, array(
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30,
        ) );

        $batch_num = $index + 1;

        if ( is_wp_error( $response ) ) {
            ccm_debug_log( "[CCM Cloudflare] Lote {$batch_num} ERRO: " . $response->get_error_message() );
            $all_ok = false;
            continue;
        }

        $code          = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );
        $success       = isset( $data['success'] ) && $data['success'];

        if ( ! $success ) {
            $all_ok = false;
            $error_msg = '';
            if ( isset( $data['errors'][0]['message'] ) ) {
                $error_msg = $data['errors'][0]['message'];
            }
            ccm_debug_log( "[CCM Cloudflare] Lote {$batch_num} FALHOU | Código: {$code} | Erro: {$error_msg}" );
        } else {
            ccm_debug_log( "[CCM Cloudflare] Lote {$batch_num} OK | " . count( $chunk ) . " URLs purgadas" );
        }
    }

    return $all_ok;
}


// ═══════════════════════════════════════════════════════════════════════════════
// PURGA TOTAL (purge_everything)
// Usada apenas para alterações globais do site (tema, menu, permalink, etc.)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Purga TODO o cache da zona na Cloudflare (purge_everything).
 * Deve ser usado apenas para alterações que afetam o site inteiro.
 *
 * @param string $trigger Identificador do hook que disparou a purga.
 * @return bool
 */
function ccm_purge_everything( $trigger = '' ) {
    if ( ccm_should_throttle_purge( 'everything' ) ) {
        ccm_debug_log( '[CCM Cloudflare] Purge Everything ignorada (debounce ativo) | Trigger: ' . ccm_get_trigger_label( $trigger ) );
        return false;
    }

    if ( ! ccm_has_credentials() ) {
        return false;
    }

    $zone_id      = get_option( 'ccm_cloudflare_zone_id', '' );
    $trigger_text = ccm_get_trigger_label( $trigger );

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $body     = wp_json_encode( array( 'purge_everything' => true ) );

    $response = wp_remote_post( $endpoint, array(
        'headers' => ccm_get_api_headers(),
        'body'    => $body,
        'timeout' => 30,
    ) );

    $debug_message = "[CCM Cloudflare] Purge Everything | Ação: {$trigger_text} | ";

    if ( is_wp_error( $response ) ) {
        $debug_message .= 'Erro WP_Error: ' . $response->get_error_message();
        ccm_debug_log( $debug_message );
        return false;
    }

    $code          = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $debug_message .= 'Código: ' . $code . ' | Corpo: ' . mb_substr( $response_body, 0, 300 );
    if ( mb_strlen( $response_body ) > 300 ) {
        $debug_message .= '... [truncado]';
    }

    ccm_debug_log( $debug_message );

    $data = json_decode( $response_body, true );
    return isset( $data['success'] ) && $data['success'];
}


// ═══════════════════════════════════════════════════════════════════════════════
// PURGA MANUAL (painel admin)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Purga o cache manualmente e retorna informações detalhadas para exibição.
 * Sempre faz purge_everything. Ignora debounce.
 *
 * @return array
 */
function ccm_purge_cloudflare_cache_manual() {
    $zone_id = get_option( 'ccm_cloudflare_zone_id', '' );

    $result = array(
        'success'       => false,
        'timestamp'     => current_time( 'Y-m-d H:i:s' ),
        'http_code'     => 'N/A',
        'message'       => '',
        'response_body' => '',
        'error'         => '',
    );

    if ( ! ccm_has_credentials() ) {
        $result['message'] = 'Credenciais não configuradas. Por favor, configure o Zone ID e API Token.';
        $result['error']   = 'Configuração incompleta';
        return $result;
    }

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $body     = wp_json_encode( array( 'purge_everything' => true ) );

    $response = wp_remote_post( $endpoint, array(
        'headers' => ccm_get_api_headers(),
        'body'    => $body,
        'timeout' => 30,
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
