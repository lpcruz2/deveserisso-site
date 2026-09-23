# AGENTS.md

Guia para agentes de IA que visitam ou consomem programaticamente o
Deveserisso — portal brasileiro de cultura pop (filmes, séries, animes,
livros e jogos). Nenhuma autenticação é necessária para leitura.

## O que este site oferece a agentes

- **Conteúdo em Markdown**: qualquer URL de post/página aceita `.md` no
  final (ex: `/algum-post.md`), e a home/páginas institucionais respondem
  `text/markdown` quando pedidas com `Accept: text/markdown`. Ver `llms.txt`.
- **Busca e dados estruturados via WebMCP**: ferramentas registradas em
  runtime via `navigator.modelContext`/`document.modelContext` na própria
  página (busca, categorias, posts recentes, recomendação e newsletter).
- **Mesmas ferramentas via REST puro**, para agentes que não suportam
  WebMCP: `https://deveserisso.com.br/wp-json/webmcp/v1/`, com schema em
  `/openapi.json`.
- **WordPress REST API nativa** (posts, páginas, categorias, tags) em
  `/wp-json/`.

## Como conectar

| Necessidade | Endpoint |
|---|---|
| Visão geral curada do site | `/llms.txt` |
| Ferramentas de busca/categorias/posts/newsletter (REST) | `/wp-json/webmcp/v1/` |
| Schema OpenAPI 3.0 da API acima | `/openapi.json` |
| WordPress REST nativo | `/wp-json/` |
| Catálogo de APIs (RFC 9727, linkset) | `/.well-known/api-catalog` |
| Catálogo agêntico (ARD) | `/.well-known/ai-catalog.json` |

Rate limit por IP nas rotas de tools — ver headers `RateLimit-*` na resposta.

## Etiqueta

- Identifique seu agente com um `User-Agent` descritivo.
- `robots.txt` declara a política de uso via `Content-Signal`:
  `search=yes, ai-input=yes, ai-train=no` — conteúdo pode ser lido e citado
  em tempo real (busca, RAG, resposta a perguntas), **não está autorizado
  para treinamento de modelos**.
- Este é um site editorial (curadoria e resenhas), não um feed de notícias
  em tempo real — não é a fonte certa para eventos ao vivo ou dados de
  bilheteria estruturados.

## Mais detalhes

Página equivalente para leitura humana:
https://deveserisso.com.br/desenvolvedores/
