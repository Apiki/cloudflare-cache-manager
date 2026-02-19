<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Purga o cache do Cloudflare e gera logs de debug se habilitado.
 * 
 * @param int|null $post_id   O ID do post relacionado à ação (opcional).
 * @param string   $trigger   Origem do disparo: 'publish_post' ou 'future_to_publish' (opcional).
 * @return bool
 */
function ccm_purge_cloudflare_cache( $post_id = null, $trigger = '' ) {
    $zone_id = get_option('ccm_cloudflare_zone_id', '');
    $api_token = get_option('ccm_cloudflare_api_token', '');
    $debug_error_log = get_option('ccm_debug_error_log', false);
    $debug_woocommerce = get_option('ccm_debug_woocommerce', false);

    // Define contexto para debug
    $post_id = $post_id ? intval($post_id) : 0;
    $permalink = $post_id ? get_permalink($post_id) : '';
    $trigger_text = '';
    if ( $trigger === 'publish_post' ) {
        $trigger_text = 'Publicação imediata';
    } elseif ( $trigger === 'future_to_publish' ) {
        $trigger_text = 'Agendado publicado';
    }

    // Valida se as credenciais estão preenchidas
    if ( empty($zone_id) || empty($api_token) ) {
        return false;
    }

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $body = json_encode( array('purge_everything' => true) );

    // Faz a requisição para a API do Cloudflare
    $response = wp_remote_post( $endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ),
        'body' => $body,
        'timeout' => 20,
    ));

    // Monta mensagem de debug
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
        // Loga apenas a mensagem do erro se WP_Error
        $debug_message .= 'Erro WP_Error: ' . $response->get_error_message();
    } else {
        // Loga código de resposta e parte do corpo da resposta
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $debug_message .= 'Código: ' . $code . ' | Corpo: ' . mb_substr($body, 0, 300);
        if ( mb_strlen($body) > 300 ) {
            $debug_message .= '... [truncado]';
        }
    }

    // DEBUG: error_log()
    if ( $debug_error_log ) {
        error_log($debug_message);
    }

    // DEBUG: WooCommerce logger
    if ( $debug_woocommerce && class_exists('WC_Logger') && function_exists('wc_get_logger') ) {
        $logger = wc_get_logger();
        $logger->debug($debug_message, array( 'source' => 'cloudflare-cache-manager' ));
    }

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    return isset($data['success']) && $data['success'];
}

/**
 * Purga o cache do Cloudflare manualmente e retorna informações detalhadas para debug.
 * 
 * @return array Array com informações sobre o resultado da operação
 */
function ccm_purge_cloudflare_cache_manual() {
    $zone_id = get_option('ccm_cloudflare_zone_id', '');
    $api_token = get_option('ccm_cloudflare_api_token', '');

    $result = array(
        'success' => false,
        'timestamp' => current_time('Y-m-d H:i:s'),
        'http_code' => 'N/A',
        'message' => '',
        'response_body' => '',
        'error' => '',
    );

    // Valida se as credenciais estão preenchidas
    if ( empty($zone_id) || empty($api_token) ) {
        $result['message'] = 'Credenciais não configuradas. Por favor, configure o Zone ID e API Token.';
        $result['error'] = 'Configuração incompleta';
        return $result;
    }

    $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
    $body = json_encode( array('purge_everything' => true) );

    // Faz a requisição para a API do Cloudflare
    $response = wp_remote_post( $endpoint, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
        ),
        'body' => $body,
        'timeout' => 20,
    ));

    // Verifica se houve erro na requisição
    if ( is_wp_error( $response ) ) {
        $result['message'] = 'Erro ao conectar com a API do Cloudflare';
        $result['error'] = $response->get_error_message();
        return $result;
    }

    // Captura código HTTP e corpo da resposta
    $http_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    
    $result['http_code'] = $http_code;
    $result['response_body'] = $response_body;

    // Tenta decodificar a resposta JSON
    $data = json_decode( $response_body, true );

    if ( $http_code === 200 && isset($data['success']) && $data['success'] === true ) {
        $result['success'] = true;
        $result['message'] = 'Cache do Cloudflare limpo com sucesso!';
    } else {
        $result['message'] = 'Falha ao limpar o cache do Cloudflare';
        
        // Tenta extrair mensagem de erro da resposta
        if ( isset($data['errors']) && is_array($data['errors']) && !empty($data['errors']) ) {
            $errors = array();
            foreach ( $data['errors'] as $error ) {
                if ( isset($error['message']) ) {
                    $errors[] = $error['message'];
                }
            }
            if ( !empty($errors) ) {
                $result['error'] = implode(', ', $errors);
            }
        }
        
        if ( empty($result['error']) ) {
            $result['error'] = 'Código HTTP: ' . $http_code;
        }
    }

    return $result;
}