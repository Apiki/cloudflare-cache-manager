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

### Como plugin normal

1. Copie a pasta `cloudflare-cache-manager/` para `wp-content/plugins/`.
2. Ative o plugin no painel **Plugins** do WordPress.
3. Acesse **Configurações > Cloudflare Cache Manager**.
4. Preencha o **Zone ID** e o **API Token** da Cloudflare.
5. Salve as configurações.

### Como Must-Use plugin (mu-plugin)

1. Copie a pasta `cloudflare-cache-manager/` para `wp-content/mu-plugins/cloudflare-cache-manager/`.
2. Copie o arquivo `ccm-loader.php` para `wp-content/mu-plugins/ccm-loader.php`.
3. Acesse **Configurações > Cloudflare Cache Manager** para configurar.

```
wp-content/mu-plugins/
├── ccm-loader.php                         ← loader (carrega o plugin)
└── cloudflare-cache-manager/              ← pasta do plugin
    ├── cloudflare-cache-manager.php
    ├── hooks/
    ├── logic/
    ├── callbacks/
    └── views/
```

> MU plugins são carregados automaticamente e não podem ser desativados pelo painel.
> Se as credenciais não estiverem configuradas, um aviso aparecerá no admin.

## WP-CLI

O plugin registra o comando `wp ccm` com os seguintes subcomandos:

### `wp ccm purge`

Purga todo o cache da zona (purge_everything). Pede confirmação antes de executar.

```bash
wp ccm purge

# Sem confirmação (útil em scripts/cron)
wp ccm purge --yes
```

### `wp ccm purge --post=<id>`

Purga apenas as URLs relacionadas a um post específico (granular).

```bash
wp ccm purge --post=42
```

### `wp ccm urls <post_id>`

Lista todas as URLs que seriam purgadas para um post (**dry-run** — não envia nada à Cloudflare).

```bash
wp ccm urls 42

# Exportar como JSON
wp ccm urls 42 --format=json
```

### `wp ccm config`

Gerencia as configurações do plugin diretamente pelo terminal.

```bash
# Configurar credenciais
wp ccm config set zone_id abc123def456
wp ccm config set api_token cftoken_xxxxx

# Ajustar debounce
wp ccm config set purge_interval 30

# Ativar/desativar debug
wp ccm config set debug_error_log on
wp ccm config set debug_woocommerce off

# Ver um valor específico
wp ccm config get zone_id
wp ccm config get zone_id --reveal

# Listar todas as configurações
wp ccm config list
wp ccm config list --reveal
wp ccm config list --format=json

# Resetar tudo para os padrões
wp ccm config reset
```

Chaves disponíveis:

| Chave | Tipo | Padrão | Descrição |
|---|---|---|---|
| `zone_id` | string | (vazio) | Zone ID da Cloudflare |
| `api_token` | string | (vazio) | API Token da Cloudflare |
| `purge_interval` | int | 10 | Debounce em segundos |
| `debug_error_log` | bool | off | Debug via `error_log()` |
| `debug_woocommerce` | bool | off | Debug via WC Logger |

> Campos sensíveis (`zone_id`, `api_token`) são mascarados por padrão. Use `--reveal` para exibir o valor real.

### `wp ccm status`

Exibe a configuração atual do plugin e testa conectividade com a API da Cloudflare.

```bash
wp ccm status
```

Saída exemplo:

```
  Configuração              Valor
  ──────────────────────────────────────────────────
  Modo de instalação        Must-Use plugin
  Zone ID                   a1b2••••••••••••y3z4
  API Token                 cfpk••••••••••••abcd
  Debounce (segundos)       10
  Debug error_log           Ativado
  Debug WooCommerce         Desativado

  ✔ Conectado! Zona: meusite.com.br (status: active)
```

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
├── cloudflare-cache-manager.php   # Bootstrap, constantes e loader de módulos
├── ccm-loader.php                 # Loader para instalação como MU plugin
├── hooks/
│   ├── admin-menu.php             # Registro do submenu em Configurações
│   ├── save-post.php              # Hooks de conteúdo → purga seletiva por URLs
│   └── site-changes.php           # Hooks globais → purge_everything
├── callbacks/
│   └── settings-callbacks.php     # Callback de renderização da página
├── logic/
│   ├── cloudflare-cache.php       # API Cloudflare: purge_everything, purge por URLs, debug
│   └── url-collector.php          # Coleta URLs relacionadas a um post
├── cli/
│   └── class-ccm-cli.php         # Comandos WP-CLI (wp ccm purge/urls/status)
└── views/
    └── settings-form.php          # Página de configurações no admin
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
