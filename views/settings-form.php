<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Recupera as opções atuais do banco de dados
$zone_id = get_option('ccm_cloudflare_zone_id', '');
$api_token = get_option('ccm_cloudflare_api_token', '');
$debug_error_log = get_option('ccm_debug_error_log', false);
$debug_woocommerce = get_option('ccm_debug_woocommerce', false);
$purge_interval = get_option('ccm_purge_interval', 10);
$wc_installed = class_exists('WooCommerce');

// Variável para armazenar o resultado da limpeza manual
$manual_purge_result = null;

// Processa limpeza manual de cache
if (
    isset($_POST['ccm_manual_purge_nonce']) &&
    wp_verify_nonce($_POST['ccm_manual_purge_nonce'], 'ccm_manual_purge')
) {
    $manual_purge_result = ccm_purge_cloudflare_cache_manual();
}

// Salva as configurações se o formulário for enviado
if (
    isset($_POST['ccm_settings_nonce']) &&
    wp_verify_nonce($_POST['ccm_settings_nonce'], 'ccm_save_settings')
) {
    $zone_id = sanitize_text_field($_POST['ccm_cloudflare_zone_id']);
    $api_token = sanitize_text_field($_POST['ccm_cloudflare_api_token']);
    $debug_error_log = isset($_POST['ccm_debug_error_log']) ? 1 : 0;
    $debug_woocommerce = isset($_POST['ccm_debug_woocommerce']) ? 1 : 0;
    $purge_interval = absint($_POST['ccm_purge_interval']);

    update_option('ccm_cloudflare_zone_id', $zone_id);
    update_option('ccm_cloudflare_api_token', $api_token);
    update_option('ccm_debug_error_log', $debug_error_log);
    update_option('ccm_debug_woocommerce', $debug_woocommerce);
    update_option('ccm_purge_interval', $purge_interval);

    echo '<div class="updated notice"><p>Configurações salvas com sucesso.</p></div>';
}
?>

<div class="wrap">
    <h1>Cloudflare Cache Manager</h1>
    
    <!-- Seção de Limpeza Manual de Cache -->
    <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">Limpeza Manual de Cache</h2>
        <p>Limpe todo o cache do Cloudflare manualmente e visualize o resultado da operação.</p>
        
        <form method="post" style="margin-bottom: 15px;">
            <?php wp_nonce_field('ccm_manual_purge', 'ccm_manual_purge_nonce'); ?>
            <?php submit_button('Limpar Cache Agora', 'secondary', 'submit', false); ?>
        </form>

        <?php if ( $manual_purge_result !== null ) : ?>
            <div style="background: <?php echo $manual_purge_result['success'] ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $manual_purge_result['success'] ? '#c3e6cb' : '#f5c6cb'; ?>; border-radius: 4px; padding: 15px; margin-top: 15px;">
                <h3 style="margin-top: 0; color: <?php echo $manual_purge_result['success'] ? '#155724' : '#721c24'; ?>;">
                    <?php echo $manual_purge_result['success'] ? '✓ Sucesso' : '✗ Erro'; ?>
                </h3>
                
                <div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; padding: 12px; font-family: monospace; font-size: 13px; overflow-x: auto;">
                    <strong>Timestamp:</strong> <?php echo esc_html($manual_purge_result['timestamp']); ?><br>
                    <strong>Código HTTP:</strong> <?php echo esc_html($manual_purge_result['http_code']); ?><br>
                    <strong>Mensagem:</strong> <?php echo esc_html($manual_purge_result['message']); ?><br><br>
                    
                    <?php if ( !empty($manual_purge_result['response_body']) ) : ?>
                        <strong>Resposta da API:</strong><br>
                        <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 3px; margin-top: 5px; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($manual_purge_result['response_body']); ?></pre>
                    <?php endif; ?>
                    
                    <?php if ( !empty($manual_purge_result['error']) ) : ?>
                        <strong style="color: #d32f2f;">Erro:</strong> <?php echo esc_html($manual_purge_result['error']); ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Formulário de Configurações -->
    <form method="post">
        <h2>Configurações da API</h2>
        <?php wp_nonce_field('ccm_save_settings', 'ccm_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="ccm_cloudflare_zone_id">Zone ID do Cloudflare</label></th>
                <td>
                    <input name="ccm_cloudflare_zone_id" type="text" id="ccm_cloudflare_zone_id" value="<?php echo esc_attr($zone_id); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ccm_cloudflare_api_token">API Token do Cloudflare</label></th>
                <td>
                    <input name="ccm_cloudflare_api_token" type="text" id="ccm_cloudflare_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" />
                </td>
            </tr>
        </table>

        <h2>Comportamento</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="ccm_purge_interval">Intervalo mínimo entre purges (segundos)</label></th>
                <td>
                    <input name="ccm_purge_interval" type="number" id="ccm_purge_interval" value="<?php echo esc_attr($purge_interval); ?>" class="small-text" min="0" max="300" step="1" />
                    <p class="description">Evita múltiplas chamadas à API em operações em lote (ex: importação, bulk edit). Valor <code>0</code> desativa o debounce. Padrão: <code>10</code> segundos.</p>
                </td>
            </tr>
        </table>

        <h2>Debug</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Debug: error_log()</th>
                <td>
                    <input type="checkbox" name="ccm_debug_error_log" id="ccm_debug_error_log" value="1" <?php checked($debug_error_log, 1); ?> />
                    <label for="ccm_debug_error_log">Ativar debug por <code>error_log()</code></label>
                </td>
            </tr>
            <tr>
                <th scope="row">Debug: WooCommerce</th>
                <td>
                    <input type="checkbox" name="ccm_debug_woocommerce" id="ccm_debug_woocommerce" value="1"
                        <?php checked($debug_woocommerce, 1); ?>
                        <?php disabled(!$wc_installed); ?>
                    />
                    <label for="ccm_debug_woocommerce">Ativar debug no log do WooCommerce</label>
                    <?php if ( !$wc_installed ) : ?>
                        <p style="color:#c00">WooCommerce não está instalado/ativo.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php submit_button('Salvar configurações'); ?>
    </form>

    <!-- Referência: Hooks monitorados -->
    <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-top: 20px;">
        <h2 style="margin-top: 0;">Hooks monitorados</h2>
        <p>O plugin monitora os seguintes eventos do WordPress para disparar a limpeza automática do cache:</p>

        <h3>Conteúdo (Posts / Páginas / CPTs)</h3>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>publish_post</code> / <code>publish_page</code> &mdash; Publicação imediata</li>
            <li><code>future_to_publish</code> &mdash; Agendamento publicado</li>
            <li><code>wp_trash_post</code> &mdash; Enviado para lixeira</li>
            <li><code>delete_post</code> &mdash; Excluído permanentemente</li>
            <li><code>delete_attachment</code> &mdash; Attachment excluído ou re-uploadado</li>
            <li><code>clean_post_cache</code> &mdash; Cache interno do WP limpo</li>
            <li><code>transition_post_status</code> &mdash; Transições genéricas de status</li>
            <li><code>pre_post_update</code> &mdash; Mudança de status (publish &rarr; draft) e slug alterado</li>
        </ul>

        <h3>Comentários</h3>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>comment_post</code> &mdash; Novo comentário aprovado</li>
            <li><code>transition_comment_status</code> &mdash; Status de comentário alterado (aprovado &harr; pendente/spam)</li>
            <li><code>wp_update_comment_count</code> &mdash; Contagem de comentários atualizada</li>
        </ul>

        <h3>Configurações do Site</h3>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>switch_theme</code> &mdash; Tema alterado</li>
            <li><code>upgrader_process_complete</code> &mdash; Tema/plugin atualizado</li>
            <li><code>wp_update_nav_menu</code> &mdash; Menu de navegação</li>
            <li><code>update_option_sidebars_widgets</code> / <code>widget_update_callback</code> &mdash; Widgets</li>
            <li><code>customize_save</code> / <code>customize_save_after</code> &mdash; Customizer salvo</li>
            <li><code>permalink_structure_changed</code> / <code>update_option_category_base</code> / <code>update_option_tag_base</code> &mdash; Permalinks</li>
            <li><code>update_option_blog_public</code> &mdash; Visibilidade do site</li>
        </ul>

        <h3>Taxonomias / Termos</h3>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>create_term</code> / <code>edit_term</code> / <code>delete_term</code> &mdash; Apenas taxonomias públicas</li>
        </ul>

        <h3>Usuários</h3>
        <ul style="list-style: disc; padding-left: 20px;">
            <li><code>profile_update</code> / <code>delete_user</code> / <code>user_register</code></li>
        </ul>
    </div>
</div>