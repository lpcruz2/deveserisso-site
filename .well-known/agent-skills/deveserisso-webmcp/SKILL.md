---
name: deveserisso-webmcp
description: Buscar, listar e ler conteúdo do Deveserisso (filmes, séries, cultura pop, em português do Brasil) via API pública, sem autenticação. Use quando o usuário pedir para encontrar artigos do site, listar categorias, pegar posts recentes ou assinar a newsletter.
---

# Deveserisso — busca e conteúdo via WebMCP

API pública, somente leitura (exceto o cadastro de newsletter), sem custo
e sem autenticação — não há chave de API nem login.

Base: `https://deveserisso.com.br/wp-json/webmcp/v1/`

## Ferramentas disponíveis

- `GET /tools/search?q=<termo>` — busca artigos por palavra-chave.
- `GET /tools/get_post?slug=<slug>` — retorna um post/página específico pelo slug.
- `GET /tools/categories` — lista as categorias de conteúdo do site.
- `GET /tools/recent_posts?count=<n>` — posts mais recentes.
- `POST /tools/assinar-newsletter` — cadastra um e-mail na newsletter.

O schema completo de cada ferramenta (parâmetros, tipos, respostas de
erro) está em `https://deveserisso.com.br/openapi.json` (OpenAPI 3.0).

## Versão em Markdown

Qualquer post ou página do site tem uma versão em Markdown: adicione
`.md` ao final da URL (ex: `https://deveserisso.com.br/algum-post/.md`
→ `https://deveserisso.com.br/algum-post.md`). Útil pra ler o conteúdo
de um artigo sem parsear HTML.

## Erros

Erros vêm como JSON estruturado (`{"code": "...", "message": "...",
"data": {"status": N}}`), padrão WP REST API — não como página HTML de
erro.
