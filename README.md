# Cloudflare Cache Manager

Plugin WordPress para gerenciamento automático e manual de limpeza de cache na Cloudflare.

## O que faz

- **Purga automática** de todo o cache da Cloudflare sempre que um post ou página é publicado (imediatamente ou via agendamento).
- **Purga manual** via botão no painel administrativo, com feedback detalhado da operação (código HTTP, resposta da API, erros).
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
| **Debug: error_log()** | Grava logs de cada purge no `error_log` do PHP (geralmente `debug.log` do WordPress). |
| **Debug: WooCommerce** | Grava logs no WooCommerce Logger (`WooCommerce > Status > Logs > cloudflare-cache-manager`). Só funciona se o WooCommerce estiver ativo. |

## Como funciona

### Purga automática

O plugin escuta os seguintes hooks do WordPress:

| Hook | Quando dispara |
|---|---|
| `publish_post` | Post publicado imediatamente |
| `publish_page` | Página publicada imediatamente |
| `future_to_publish` | Post agendado que acabou de ser publicado pelo cron |

Em todos os casos, é feita uma chamada `POST` para a API da Cloudflare com `purge_everything: true`, limpando **todo** o cache da zona.

### Purga manual

Na página de configurações há um botão **"Limpar Cache Agora"**. Ao clicar, o plugin executa a purga e exibe na tela:

- Timestamp da operação
- Código HTTP da resposta
- Mensagem de sucesso ou erro
- Corpo completo da resposta da API

### Logs de debug

Quando ativados, os logs registram para cada purga:

```
[CCM Cloudflare] Post ID: 123 | Permalink: https://exemplo.com/meu-post/ | Ação: Publicação imediata | Código: 200 | Corpo: {"success":true,...}
```

## Estrutura do projeto

```
cloudflare-cache-manager/
├── cloudflare-cache-manager.php   # Arquivo principal (bootstrap e link de settings)
├── hooks/
│   ├── admin-menu.php             # Registro do submenu em Configurações
│   └── save-post.php              # Hooks de publicação (post, page, agendado)
├── callbacks/
│   └── settings-callbacks.php     # Callback que renderiza a página de settings
├── logic/
│   └── cloudflare-cache.php       # Comunicação com a API da Cloudflare
└── views/
    └── settings-form.php          # Template HTML da página de configurações
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
