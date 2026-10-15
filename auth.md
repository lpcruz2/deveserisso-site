# Deveserisso auth.md

Este arquivo descreve como um agente de IA obtém acesso ao Deveserisso (https://deveserisso.com.br), portal brasileiro de críticas de filmes e séries.

**Resumo: não existe autenticação.** Todas as interfaces para agentes são públicas e somente leitura. Não há cadastro de agente, chave de API, token, login nem cobrança. Um agente pode chamar qualquer endereço abaixo diretamente, sem credencial.

## Interfaces abertas

| Interface | Endereço | Uso |
|---|---|---|
| Servidor MCP (Streamable HTTP) | https://deveserisso.com.br/mcp | Buscar críticas, mostrar filmografia, recomendar para hoje, abrir a newsletter |
| API REST de leitura | https://deveserisso.com.br/wp-json/webmcp/v1/ | Busca, artigos, categorias, posts recentes |
| Especificação OpenAPI | https://deveserisso.com.br/openapi.json | Descrição da API REST |
| Agente A2A | https://deveserisso.com.br/wp-json/a2a/v1/rpc | Recomendação de filmes e séries (JSON-RPC, método message/send) |
| Cartão do agente A2A | https://deveserisso.com.br/.well-known/agent-card.json | Descoberta do agente A2A |
| Índice para modelos | https://deveserisso.com.br/llms.txt | Mapa do site e quando usar cada interface |

## Como obter credenciais

Não se aplica. Não há `Authorization`, `WWW-Authenticate` nem endpoint de registro, e este site não emite credenciais para agentes. Nenhum metadado OAuth (RFC 8414, RFC 9728) é publicado, porque não existe servidor de autorização.

## O que o site não oferece

- Escrita de conteúdo por agentes, contas, perfis ou áreas logadas por API.
- Planos pagos, preços ou protocolos de pagamento.
- A única ação com efeito é a inscrição na newsletter, que só se completa quando a pessoa confirma o e-mail pelo link enviado a ela.

## Limites de uso

- API REST: 60 requisições por minuto por IP. Os cabeçalhos `RateLimit-*` vêm em toda resposta.
- Agente A2A: 20 chamadas por minuto por IP.
- Ao exceder, a resposta é HTTP 429 com `Retry-After` (segundos até poder tentar de novo).

## Erros

Erros da API REST vêm em JSON com `code`, `message`, `status`, `hint` (o que fazer) e `docs`. O agente A2A responde com erros JSON-RPC 2.0 padrão. Páginas inexistentes devolvem HTTP 404, e em Markdown quando o pedido inclui `Accept: text/markdown`.

## Contato

Para pedir acesso diferente do aberto, ou relatar abuso, use https://deveserisso.com.br/contato/. Documentação para desenvolvedores: https://deveserisso.com.br/desenvolvedores/.
