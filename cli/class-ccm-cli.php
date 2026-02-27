<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Comandos WP-CLI para o Cloudflare Cache Manager.
 *
 * ## EXEMPLOS
 *
 *     # Purga todo o cache (purge_everything)
 *     wp ccm purge
 *
 *     # Purga apenas as URLs relacionadas a um post
 *     wp ccm purge --post=123
 *
 *     # Lista URLs que seriam purgadas para um post (dry-run)
 *     wp ccm urls 123
 *
 *     # Exibe status da configuração
 *     wp ccm status
 */
class CCM_CLI extends WP_CLI_Command {

    /**
     * Purga o cache na Cloudflare.
     *
     * Sem argumentos, executa purge_everything.
     * Com --post=<id>, purga apenas as URLs relacionadas ao post.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : ID do post para purga granular. Se omitido, faz purge_everything.
     *
     * [--yes]
     * : Pula a confirmação antes do purge_everything.
     *
     * ## EXAMPLES
     *
     *     # Purga todo o cache
     *     wp ccm purge
     *
     *     # Purga URLs do post 42
     *     wp ccm purge --post=42
     *
     *     # Purga tudo sem confirmação (scripts/cron)
     *     wp ccm purge --yes
     *
     * @when after_wp_load
     */
    public function purge( $args, $assoc_args ) {
        if ( ! ccm_has_credentials() ) {
            WP_CLI::error( 'Credenciais não configuradas. Use "wp ccm status" para verificar.' );
        }

        $post_id = isset( $assoc_args['post'] ) ? absint( $assoc_args['post'] ) : 0;

        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                WP_CLI::error( "Post #{$post_id} não encontrado." );
            }

            WP_CLI::log( "Purgando URLs do post #{$post_id} ({$post->post_title})..." );

            $urls = ccm_get_post_related_urls( $post_id );

            if ( 'attachment' === $post->post_type ) {
                $urls = array_merge( $urls, ccm_get_attachment_urls( $post_id ) );
                $urls = array_values( array_unique( $urls ) );
            }

            if ( empty( $urls ) ) {
                WP_CLI::warning( 'Nenhuma URL coletada para este post.' );
                return;
            }

            WP_CLI::log( count( $urls ) . ' URLs coletadas. Enviando para Cloudflare...' );

            $zone_id  = get_option( 'ccm_cloudflare_zone_id', '' );
            $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
            $headers  = ccm_get_api_headers();
            $chunks   = array_chunk( $urls, 30 );
            $errors   = 0;

            foreach ( $chunks as $index => $chunk ) {
                $body     = wp_json_encode( array( 'files' => array_values( $chunk ) ) );
                $response = wp_remote_post( $endpoint, array(
                    'headers' => $headers,
                    'body'    => $body,
                    'timeout' => 30,
                ) );

                $batch = $index + 1;

                if ( is_wp_error( $response ) ) {
                    WP_CLI::warning( "Lote {$batch}: erro — " . $response->get_error_message() );
                    $errors++;
                    continue;
                }

                $data    = json_decode( wp_remote_retrieve_body( $response ), true );
                $success = isset( $data['success'] ) && $data['success'];

                if ( $success ) {
                    WP_CLI::log( "  Lote {$batch}/" . count( $chunks ) . ": OK (" . count( $chunk ) . " URLs)" );
                } else {
                    $msg = isset( $data['errors'][0]['message'] ) ? $data['errors'][0]['message'] : 'resposta inesperada';
                    WP_CLI::warning( "Lote {$batch}: falhou — {$msg}" );
                    $errors++;
                }
            }

            if ( $errors === 0 ) {
                WP_CLI::success( count( $urls ) . " URLs purgadas com sucesso." );
            } else {
                WP_CLI::error( "{$errors} lote(s) falharam. Verifique os logs." );
            }

            return;
        }

        // purge_everything
        WP_CLI::confirm( 'Tem certeza que deseja purgar TODO o cache da zona Cloudflare?', $assoc_args );

        WP_CLI::log( 'Executando purge_everything...' );

        $result = ccm_purge_cloudflare_cache_manual();

        if ( $result['success'] ) {
            WP_CLI::success( $result['message'] . ' (HTTP ' . $result['http_code'] . ')' );
        } else {
            WP_CLI::error( $result['message'] . ( $result['error'] ? ' — ' . $result['error'] : '' ) );
        }
    }

    /**
     * Lista as URLs que seriam purgadas para um post (dry-run).
     *
     * ## OPTIONS
     *
     * <post_id>
     * : ID do post.
     *
     * [--format=<format>]
     * : Formato de saída. Aceita: list, json, csv, yaml.
     * ---
     * default: list
     * options:
     *   - list
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # Lista URLs para o post 42
     *     wp ccm urls 42
     *
     *     # Exporta como JSON
     *     wp ccm urls 42 --format=json
     *
     * @when after_wp_load
     */
    public function urls( $args, $assoc_args ) {
        $post_id = absint( $args[0] );
        $post    = get_post( $post_id );

        if ( ! $post ) {
            WP_CLI::error( "Post #{$post_id} não encontrado." );
        }

        $urls = ccm_get_post_related_urls( $post_id );

        if ( 'attachment' === $post->post_type ) {
            $urls = array_merge( $urls, ccm_get_attachment_urls( $post_id ) );
            $urls = array_values( array_unique( $urls ) );
        }

        if ( empty( $urls ) ) {
            WP_CLI::warning( "Nenhuma URL coletada para o post #{$post_id}." );
            return;
        }

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'list';

        WP_CLI::log( "Post #{$post_id}: {$post->post_title} ({$post->post_type})" );
        WP_CLI::log( count( $urls ) . " URLs coletadas:\n" );

        if ( $format === 'json' ) {
            WP_CLI::log( wp_json_encode( $urls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        }

        if ( $format === 'csv' || $format === 'yaml' ) {
            $items = array();
            foreach ( $urls as $url ) {
                $items[] = array( 'url' => $url );
            }
            WP_CLI\Utils\format_items( $format, $items, array( 'url' ) );
            return;
        }

        foreach ( $urls as $i => $url ) {
            WP_CLI::log( sprintf( '  %3d. %s', $i + 1, $url ) );
        }
    }

    /**
     * Exibe o status da configuração do plugin.
     *
     * ## EXAMPLES
     *
     *     wp ccm status
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        $zone_id   = get_option( 'ccm_cloudflare_zone_id', '' );
        $api_token = get_option( 'ccm_cloudflare_api_token', '' );
        $interval  = get_option( 'ccm_purge_interval', 10 );
        $debug_log = get_option( 'ccm_debug_error_log', false );
        $debug_wc  = get_option( 'ccm_debug_woocommerce', false );

        $is_mu     = function_exists( 'ccm_is_mu_plugin' ) && ccm_is_mu_plugin();

        WP_CLI::log( '╔══════════════════════════════════════════════╗' );
        WP_CLI::log( '║   Cloudflare Cache Manager — Status          ║' );
        WP_CLI::log( '╚══════════════════════════════════════════════╝' );
        WP_CLI::log( '' );

        $items = array(
            array( 'Configuração'         , 'Valor' ),
            array( 'Modo de instalação'   , $is_mu ? 'Must-Use plugin' : 'Plugin normal' ),
            array( 'Zone ID'              , $zone_id ? self::mask( $zone_id ) : '⚠ NÃO CONFIGURADO' ),
            array( 'API Token'            , $api_token ? self::mask( $api_token ) : '⚠ NÃO CONFIGURADO' ),
            array( 'Debounce (segundos)'  , $interval ),
            array( 'Debug error_log'      , $debug_log ? 'Ativado' : 'Desativado' ),
            array( 'Debug WooCommerce'    , $debug_wc ? 'Ativado' : 'Desativado' ),
        );

        foreach ( $items as $i => $row ) {
            if ( $i === 0 ) {
                WP_CLI::log( sprintf( '  %-25s %s', $row[0], $row[1] ) );
                WP_CLI::log( '  ' . str_repeat( '─', 50 ) );
                continue;
            }
            WP_CLI::log( sprintf( '  %-25s %s', $row[0], $row[1] ) );
        }

        WP_CLI::log( '' );

        if ( empty( $zone_id ) || empty( $api_token ) ) {
            WP_CLI::warning( 'Credenciais incompletas. O plugin não purgará o cache até serem configuradas.' );
        }

        // Testar conectividade com a API
        if ( ccm_has_credentials() ) {
            WP_CLI::log( 'Testando conectividade com a API Cloudflare...' );

            $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zone_id}";
            $response = wp_remote_get( $endpoint, array(
                'headers' => ccm_get_api_headers(),
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                WP_CLI::warning( 'Falha na conexão: ' . $response->get_error_message() );
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $data = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( $code === 200 && isset( $data['success'] ) && $data['success'] ) {
                    $zone_name = isset( $data['result']['name'] ) ? $data['result']['name'] : '—';
                    $zone_status = isset( $data['result']['status'] ) ? $data['result']['status'] : '—';
                    WP_CLI::success( "Conectado! Zona: {$zone_name} (status: {$zone_status})" );
                } else {
                    $err = isset( $data['errors'][0]['message'] ) ? $data['errors'][0]['message'] : "HTTP {$code}";
                    WP_CLI::warning( "API retornou erro: {$err}" );
                }
            }
        }
    }

    /**
     * Gerencia as configurações do plugin.
     *
     * ## OPTIONS
     *
     * <action>
     * : Ação a executar.
     * ---
     * options:
     *   - get
     *   - set
     *   - list
     *   - reset
     * ---
     *
     * [<key>]
     * : Chave da configuração (obrigatória para get/set).
     * ---
     * options:
     *   - zone_id
     *   - api_token
     *   - purge_interval
     *   - debug_error_log
     *   - debug_woocommerce
     * ---
     *
     * [<value>]
     * : Valor a definir (obrigatório para set). Para booleanos: on/off, true/false, 1/0.
     *
     * [--reveal]
     * : Mostra valores sensíveis (zone_id, api_token) sem máscara.
     *
     * [--format=<format>]
     * : Formato de saída para "list". Aceita: table, json, csv, yaml.
     * ---
     * default: table
     * ---
     *
     * [--yes]
     * : Pula confirmação no reset.
     *
     * ## EXAMPLES
     *
     *     # Configurar zone_id e api_token
     *     wp ccm config set zone_id abc123def456
     *     wp ccm config set api_token cftoken_xxxxx
     *
     *     # Ajustar debounce para 30 segundos
     *     wp ccm config set purge_interval 30
     *
     *     # Ativar debug
     *     wp ccm config set debug_error_log on
     *     wp ccm config set debug_woocommerce off
     *
     *     # Ver um valor
     *     wp ccm config get zone_id
     *     wp ccm config get zone_id --reveal
     *
     *     # Listar tudo
     *     wp ccm config list
     *     wp ccm config list --reveal
     *     wp ccm config list --format=json
     *
     *     # Resetar para padrões
     *     wp ccm config reset
     *     wp ccm config reset --yes
     *
     * @when after_wp_load
     */
    public function config( $args, $assoc_args ) {
        $action = $args[0];
        $key    = isset( $args[1] ) ? $args[1] : null;
        $value  = isset( $args[2] ) ? $args[2] : null;
        $reveal = isset( $assoc_args['reveal'] );

        $options = self::get_option_map();

        switch ( $action ) {

            case 'list':
                $rows = array();
                foreach ( $options as $alias => $opt ) {
                    $raw = get_option( $opt['option'], $opt['default'] );
                    $display = self::format_display_value( $opt, $raw, $reveal );
                    $rows[] = array(
                        'key'     => $alias,
                        'value'   => $display,
                        'type'    => $opt['type'],
                        'default' => self::format_display_value( $opt, $opt['default'], true ),
                    );
                }

                $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
                WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'value', 'type', 'default' ) );
                break;

            case 'get':
                if ( ! $key ) {
                    WP_CLI::error( 'Informe a chave. Chaves disponíveis: ' . implode( ', ', array_keys( $options ) ) );
                }
                if ( ! isset( $options[ $key ] ) ) {
                    WP_CLI::error( "Chave \"{$key}\" não existe. Chaves disponíveis: " . implode( ', ', array_keys( $options ) ) );
                }

                $opt = $options[ $key ];
                $raw = get_option( $opt['option'], $opt['default'] );
                WP_CLI::log( self::format_display_value( $opt, $raw, $reveal ) );
                break;

            case 'set':
                if ( ! $key ) {
                    WP_CLI::error( 'Informe a chave. Chaves disponíveis: ' . implode( ', ', array_keys( $options ) ) );
                }
                if ( ! isset( $options[ $key ] ) ) {
                    WP_CLI::error( "Chave \"{$key}\" não existe. Chaves disponíveis: " . implode( ', ', array_keys( $options ) ) );
                }
                if ( $value === null ) {
                    WP_CLI::error( 'Informe o valor. Uso: wp ccm config set <key> <value>' );
                }

                $opt       = $options[ $key ];
                $sanitized = self::sanitize_value( $opt, $value );

                if ( $sanitized === null ) {
                    if ( $opt['type'] === 'bool' ) {
                        WP_CLI::error( "Valor inválido para \"{$key}\". Use: on, off, true, false, 1, 0." );
                    }
                    if ( $opt['type'] === 'int' ) {
                        WP_CLI::error( "Valor inválido para \"{$key}\". Informe um número inteiro." );
                    }
                }

                update_option( $opt['option'], $sanitized );

                $display = self::format_display_value( $opt, $sanitized, true );
                WP_CLI::success( "{$key} = {$display}" );
                break;

            case 'reset':
                WP_CLI::confirm( 'Resetar TODAS as configurações para os valores padrão?', $assoc_args );

                foreach ( $options as $alias => $opt ) {
                    update_option( $opt['option'], $opt['default'] );
                }

                WP_CLI::success( 'Todas as configurações foram restauradas para os valores padrão.' );
                break;

            default:
                WP_CLI::error( "Ação \"{$action}\" não reconhecida. Use: get, set, list, reset." );
        }
    }

    /**
     * Mapa de opções: alias CLI → option name, tipo, default e flag de sensível.
     */
    private static function get_option_map() {
        return array(
            'zone_id' => array(
                'option'    => 'ccm_cloudflare_zone_id',
                'type'      => 'string',
                'default'   => '',
                'sensitive' => true,
                'label'     => 'Zone ID do Cloudflare',
            ),
            'api_token' => array(
                'option'    => 'ccm_cloudflare_api_token',
                'type'      => 'string',
                'default'   => '',
                'sensitive' => true,
                'label'     => 'API Token do Cloudflare',
            ),
            'purge_interval' => array(
                'option'    => 'ccm_purge_interval',
                'type'      => 'int',
                'default'   => 10,
                'sensitive' => false,
                'label'     => 'Intervalo mínimo entre purges (segundos)',
            ),
            'debug_error_log' => array(
                'option'    => 'ccm_debug_error_log',
                'type'      => 'bool',
                'default'   => 0,
                'sensitive' => false,
                'label'     => 'Debug via error_log()',
            ),
            'debug_woocommerce' => array(
                'option'    => 'ccm_debug_woocommerce',
                'type'      => 'bool',
                'default'   => 0,
                'sensitive' => false,
                'label'     => 'Debug via WooCommerce Logger',
            ),
        );
    }

    /**
     * Sanitiza valor de entrada de acordo com o tipo.
     *
     * @return mixed|null null se inválido.
     */
    private static function sanitize_value( $opt, $raw ) {
        switch ( $opt['type'] ) {
            case 'string':
                return sanitize_text_field( $raw );

            case 'int':
                if ( ! is_numeric( $raw ) ) {
                    return null;
                }
                return absint( $raw );

            case 'bool':
                $truthy  = array( 'on', 'true', '1', 'yes', 'sim' );
                $falsy   = array( 'off', 'false', '0', 'no', 'nao', 'não' );
                $lower   = strtolower( $raw );

                if ( in_array( $lower, $truthy, true ) ) return 1;
                if ( in_array( $lower, $falsy, true ) )  return 0;
                return null;
        }

        return null;
    }

    /**
     * Formata o valor para exibição, aplicando máscara em campos sensíveis.
     */
    private static function format_display_value( $opt, $raw, $reveal = false ) {
        if ( $opt['type'] === 'bool' ) {
            return $raw ? 'on' : 'off';
        }

        if ( $opt['sensitive'] && ! $reveal ) {
            if ( empty( $raw ) ) {
                return '(vazio)';
            }
            return self::mask( $raw );
        }

        if ( $raw === '' || $raw === null ) {
            return '(vazio)';
        }

        return (string) $raw;
    }

    /**
     * Mascara uma string sensível para exibição segura.
     */
    private static function mask( $value ) {
        $len = strlen( $value );
        if ( $len <= 8 ) {
            return str_repeat( '•', $len );
        }
        return substr( $value, 0, 4 ) . str_repeat( '•', $len - 8 ) . substr( $value, -4 );
    }
}
