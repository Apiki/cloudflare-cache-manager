# Cloudflare Cache Manager

Plugin WordPress para gerenciamento automático e manual de limpeza de cache na Cloudflare.

## O que faz

- **Purga automática** de todo o cache da Cloudflare em **30+ eventos** do WordPress (posts, páginas, CPTs, comentários, menus, widgets, taxonomias, temas, permalinks, usuários e mais).
- **Purga manual** via botão no painel administrativo, com feedback detalhado da operação.
- **Debounce inteligente** — evita múltiplas chamadas à API em operações em lote (importações, bulk edit, etc.).
- **Debug configurável** via `error_log()` do PHP e/ou logger do WooCommerce.

## Requisitos

- WordPress 5.0+
- PHP 7.4+
- Conta Cloudflare com **API Token** que possua permissão de **Zone > Cache Purge > Purge**
- *(Opcional)* WooCommerce — para usar o canal de debug via WC Logger

## Instalação

1. Clone ou copie a pasta `cloudflare-cache-manager` para `wp-content/plugins/`.
2. Ative o plugin no painel **Plugins** do WordPress.
3. Acesse **Configurações > Cloudflare Cache Manager**.
4. Preencha o **Zone ID** e o **API Token** da Cloudflare.
5. Salve as configurações.

## Configurações

| Campo | Descrição |
|---|---|
| **Zone ID** | Identificador da zona no Cloudflare. Encontrado em *Overview* do domínio no painel Cloudflare. |
| **API Token** | Token de API com permissão de purge. Crie em *My Profile > API Tokens* no painel Cloudflare. |
| **Intervalo mínimo entre purges** | Tempo em segundos de debounce entre chamadas à API (padrão: 10s). Valor `0` desativa. |
| **Debug: error_log()** | Grava logs de cada purge no `error_log` do PHP. |
| **Debug: WooCommerce** | Grava logs no WooCommerce Logger. Só funciona se o WooCommerce estiver ativo. |

## Hooks monitorados

O plugin monitora todos os eventos abaixo e dispara `purge_everything` na Cloudflare.

### Conteúdo (Posts / Páginas / Custom Post Types)

| Hook | Quando dispara |
|---|---|
| `publish_post` / `publish_page` | Post ou página publicado imediatamente |
| `future_to_publish` | Post agendado publicado pelo cron |
| `wp_trash_post` | Post enviado para a lixeira |
| `delete_post` | Post excluído permanentemente |
| `clean_post_cache` | Cache interno do WordPress limpo (cobre `save_post`, `edit_post`, etc.) |
| `wp_update_comment_count` | Comentário adicionado, aprovado ou removido |
| `pre_post_update` (status) | Post alterado de publicado para rascunho |
| `pre_post_update` (slug) | Slug/permalink do post alterado |
| `transition_post_status` | Qualquer transição de status envolvendo `publish` (cobre CPTs) |

> Apenas post types **públicos** disparam a purga. Revisões, auto-drafts, `nav_menu_item` e `attachment` são ignorados.

### Configurações do Site

| Hook | Quando dispara |
|---|---|
| `switch_theme` | Tema ativado |
| `upgrader_process_complete` | Tema ativo ou plugin atualizado |
| `wp_update_nav_menu` | Menu de navegação criado ou editado |
| `update_option_sidebars_widgets` | Ordem dos widgets alterada |
| `widget_update_callback` | Widget individual atualizado |
| `customize_save` | Customizer salvo |
| `update_option_theme_mods_{stylesheet}` | Localização de menus alterada |
| `permalink_structure_changed` | Estrutura de permalinks alterada |
| `update_option_category_base` | Base de URL de categorias alterada |
| `update_option_tag_base` | Base de URL de tags alterada |
| `update_option_blog_public` | Visibilidade do site alterada |
| `add_link` / `edit_link` / `delete_link` | Blogroll (links) alterado |

### Taxonomias / Termos

| Hook | Quando dispara |
|---|---|
| `create_term` | Termo criado em taxonomia pública |
| `edit_term` | Termo editado em taxonomia pública |
| `delete_term` | Termo excluído de taxonomia pública |

### Usuários

| Hook | Quando dispara |
|---|---|
| `profile_update` | Perfil de usuário atualizado |
| `delete_user` | Usuário excluído |
| `user_register` | Novo usuário registrado |

## Mecanismo de Debounce

Para evitar dezenas de chamadas à API em operações como importação de conteúdo ou edição em lote, o plugin utiliza um **transient do WordPress** como mecanismo de debounce:

1. Ao disparar a primeira purga, um transient `ccm_purge_throttle` é criado com TTL configurável (padrão: 10 segundos).
2. Qualquer purga subsequente dentro desse intervalo é ignorada (e logada no debug como "debounce ativo").
3. Após o TTL expirar, a próxima purga é executada normalmente.

O intervalo é configurável em **Configurações > Cloudflare Cache Manager > Intervalo mínimo entre purges**.

## Estrutura do projeto

```
cloudflare-cache-manager/
├── cloudflare-cache-manager.php   # Arquivo principal (bootstrap)
├── hooks/
│   ├── admin-menu.php             # Registro do submenu em Configurações
│   ├── save-post.php              # Hooks de conteúdo (post/page/CPT/comment)
│   └── site-changes.php           # Hooks globais (tema/menu/widget/permalink/term/user)
├── callbacks/
│   └── settings-callbacks.php     # Callback de renderização da página
├── logic/
│   └── cloudflare-cache.php       # API Cloudflare, debounce e debug
└── views/
    └── settings-form.php          # Template da página de configurações
```

## Criando o API Token na Cloudflare

1. Acesse [dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens).
2. Clique em **Create Token**.
3. Use o template **Custom token** com as seguintes permissões:
   - **Zone > Cache Purge > Purge**
4. Em **Zone Resources**, selecione a zona desejada.
5. Copie o token gerado e cole no campo **API Token** do plugin.

## Observações

- O plugin sempre faz **purge total** (`purge_everything`). Não há opção de purgar URLs específicas.
- A autenticação utiliza **Bearer Token** (API Token), não a Global API Key.
- As credenciais são armazenadas na tabela `wp_options` do WordPress.
- O plugin foi inspirado no mapeamento de hooks do **WP Rocket** para garantir cobertura completa dos eventos do WordPress.
