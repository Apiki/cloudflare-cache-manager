# Cloudflare Cache Manager

Plugin WordPress para gerenciamento inteligente de limpeza de cache na Cloudflare, com **purga granular por URLs** para eventos de conteúdo e `purge_everything` apenas para alterações estruturais do site.

## O que faz

- **Purga seletiva por URLs** — ao editar/publicar um post, apenas as URLs relacionadas são invalidadas (permalink, home, feeds, taxonomias, autor, paginação, etc.). O restante do cache permanece intacto.
- **Purge Everything** somente para alterações globais (tema, menus, widgets, permalinks, etc.).
- **Purga manual** via botão no painel administrativo, com feedback detalhado.
- **Debounce inteligente** — evita chamadas excessivas à API em operações em lote.
- **Debug configurável** via `error_log()` e/ou WooCommerce Logger.
- **Filter hook** `ccm_post_purge_urls` para personalizar a lista de URLs purgadas.

## Por que purga granular?

Em portais com alto tráfego, um `purge_everything` a cada edição de post pode causar:

- **Indisponibilidade momentânea** — todas as páginas perdem cache simultaneamente
- **Sobrecarga no servidor** — milhares de visitantes simultâneos batem no origin
- **Tempo de re-cache elevado** — até o Cloudflare recachear, a performance cai

Com purga seletiva, apenas as URLs afetadas pelo post editado são invalidadas (~15-40 URLs), preservando o cache de todo o restante do site.

## Requisitos

- WordPress 5.0+
- PHP 7.4+
- Conta Cloudflare com **API Token** com permissão de **Zone > Cache Purge > Purge**
- *(Opcional)* WooCommerce — para debug via WC Logger

## Instalação

1. Clone ou copie a pasta `cloudflare-cache-manager` para `wp-content/plugins/`.
2. Ative o plugin no painel **Plugins** do WordPress.
3. Acesse **Configurações > Cloudflare Cache Manager**.
4. Preencha o **Zone ID** e o **API Token** da Cloudflare.
5. Salve as configurações.

## Configurações

| Campo | Descrição |
|---|---|
| **Zone ID** | Identificador da zona no Cloudflare. |
| **API Token** | Token de API com permissão de purge. |
| **Intervalo mínimo entre purges** | Debounce em segundos (padrão: 10s). Valor `0` desativa. |
| **Debug: error_log()** | Grava logs de cada purge no log do PHP. |
| **Debug: WooCommerce** | Grava logs no WooCommerce Logger. |

## Como funciona

### Purga seletiva (eventos de conteúdo)

Quando um post é publicado/editado/excluído, o plugin coleta todas as URLs relacionadas:

| URL coletada | Descrição |
|---|---|
| Permalink do post | URL principal |
| Home page | Página inicial |
| Página de posts | Se usar front page estática |
| Paginação | Até 3 páginas da home |
| Taxonomias | Links de categorias, tags e custom taxonomies (+ ancestrais) |
| Feeds de taxonomias | RSS/Atom de cada termo |
| Página do autor | URL + feed do autor |
| Archive do CPT | Se for custom post type |
| Feeds globais | RSS2, Atom, RDF, RSS, comentários |
| Feed do post | Feed de comentários do post |
| Posts adjacentes | Anterior e próximo |
| Posts ancestrais | Para páginas hierárquicas |
| Archives de datas | Ano, mês e dia |
| AMP | Se o plugin AMP estiver ativo |

As URLs são enviadas à API da Cloudflare em **lotes de 30** (limite da API por request).

### Purge Everything (eventos globais)

Apenas alterações estruturais do site disparam purge total:

- Tema alterado/atualizado
- Menu de navegação editado
- Widgets alterados
- Customizer salvo
- Estrutura de permalinks alterada
- Visibilidade do site alterada
- Termos de taxonomia criados/editados/excluídos
- Usuários criados/editados/excluídos

### Purga manual

O botão "Limpar Cache Agora" na página de configurações sempre faz `purge_everything`.

## Hooks monitorados

### Conteúdo → Purga seletiva por URLs

| Hook | Quando dispara |
|---|---|
| `publish_post` / `publish_page` | Post ou página publicado |
| `future_to_publish` | Post agendado publicado |
| `wp_trash_post` | Post enviado para lixeira |
| `delete_post` | Post excluído permanentemente |
| `delete_attachment` | Attachment excluído/re-uploadado |
| `clean_post_cache` | Cache interno do WP limpo |
| `transition_post_status` | Transições de status envolvendo publish |
| `pre_post_update` | Mudança publish→draft e alteração de slug |
| `comment_post` | Novo comentário aprovado |
| `transition_comment_status` | Status de comentário alterado |
| `wp_update_comment_count` | Contagem de comentários atualizada |

### Configurações do site → Purge Everything

| Hook | Quando dispara |
|---|---|
| `switch_theme` | Tema ativado |
| `upgrader_process_complete` | Tema/plugin atualizado |
| `wp_update_nav_menu` | Menu de navegação |
| `update_option_sidebars_widgets` / `widget_update_callback` | Widgets |
| `customize_save` / `customize_save_after` | Customizer |
| `permalink_structure_changed` / `update_option_category_base` / `update_option_tag_base` | Permalinks |
| `update_option_blog_public` | Visibilidade do site |
| `create_term` / `edit_term` / `delete_term` | Taxonomias públicas |
| `add_link` / `edit_link` / `delete_link` | Blogroll |
| `profile_update` / `delete_user` / `user_register` | Usuários |

## Personalizando URLs purgadas

Use o filter `ccm_post_purge_urls` para adicionar ou remover URLs da lista:

```php
add_filter( 'ccm_post_purge_urls', function( $urls, $post_id ) {
    // Adicionar URL customizada
    $urls[] = home_url( '/minha-pagina-especial/' );

    // Remover feeds da lista
    $urls = array_filter( $urls, function( $url ) {
        return strpos( $url, '/feed/' ) === false;
    } );

    return $urls;
}, 10, 2 );
```

## Estrutura do projeto

```
cloudflare-cache-manager/
├── cloudflare-cache-manager.php   # Bootstrap e link de settings
├── hooks/
│   ├── admin-menu.php             # Registro do submenu
│   ├── save-post.php              # Hooks de conteúdo → purga seletiva
│   └── site-changes.php           # Hooks globais → purge_everything
├── callbacks/
│   └── settings-callbacks.php     # Callback de renderização
├── logic/
│   ├── cloudflare-cache.php       # API: purge_everything, purge por URLs, debug
│   └── url-collector.php          # Coleta URLs relacionadas a um post
└── views/
    └── settings-form.php          # Página de configurações
```

## Criando o API Token na Cloudflare

1. Acesse [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens).
2. Clique em **Create Token**.
3. Use o template **Custom token**:
   - **Zone > Cache Purge > Purge**
4. Em **Zone Resources**, selecione a zona desejada.
5. Copie o token gerado e cole no campo **API Token** do plugin.

## Limites da API Cloudflare

| Operação | Limite |
|---|---|
| `purge_everything` | 1.000 requests / 5 min / zona |
| Purge por URLs (`files`) | 30 URLs por request |

Com o debounce de 10s, o máximo de `purge_everything` é ~30 req/5min.

## Observações

- A autenticação utiliza **Bearer Token** (API Token), não a Global API Key.
- As credenciais são armazenadas na tabela `wp_options`.
- Inspirado nos hooks do **WP Rocket** e do **plugin oficial Cloudflare** (v4.14.2).
