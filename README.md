# Deveserisso — Tema WordPress

Tema WordPress filho customizado para [deveserisso.com.br](https://deveserisso.com.br). Portal de cultura pop, filmes, séries e entretenimento com foco em performance, SEO técnico e design responsivo.

## Stack

- **WordPress** 6.x com tema pai `cream-magazine`
- **PHP** 8.5.4 (Hostinger)
- **CSS** puro (minificado, sem Sass/Less)
- **JavaScript** vanilla (sem dependências)
- **Servidor** LiteSpeed com CDN edge
- **CI/CD** GitHub Actions (lint PHP + deploy FTP automático)

## Estrutura do Projeto

```
dsi-magazine/
├── dsi-magazine/              # Tema WordPress filho
│   ├── assets/
│   │   ├── css/main.css       # Estilos (minificado)
│   │   ├── js/                # Scripts (masthead, TOC, etc)
│   │   └── fonts/             # Fontes locais (sem Google Fonts)
│   ├── template-parts/        # Componentes reutilizáveis
│   ├── functions.php          # Setup, registros, hooks
│   ├── home.php               # Template da homepage
│   ├── single.php             # Template de artigo individual
│   ├── search.php, category.php, etc.
│   └── style.css              # Header do tema (min)
├── .github/workflows/
│   └── deploy.yml             # Pipeline CI/CD
├── scripts/
│   └── fix-encoding.php       # Utilitário de encoding UTF-8
├── .htaccess                  # Reescritas URL, WebP, 404 fixes
├── purge_ls.php               # Script de purga de cache LiteSpeed
├── wp-config-sample.php       # Template de configuração (sem credenciais)
└── README.md (este arquivo)
```

## Setup Local

### Pré-requisitos
- PHP 8.0+ 
- WordPress 6.0+
- Git

### Instalação

1. **Clone o repositório:**
   ```bash
   git clone https://github.com/lpcruz2/deveserisso-site.git
   cd deveserisso-site/dsi-magazine
   ```

2. **Configure o WordPress:**
   - Copie `wp-config-sample.php` para `wp-config.php`
   - Adicione credenciais do banco de dados
   - Ajuste `WP_HOME` e `WP_SITEURL` para sua URL local

3. **Ative o tema:**
   - Dashboard → Aparência → Temas
   - Ative "DSI Magazine"

4. **Estude o código:**
   - Comece por `functions.php` (setup do tema)
   - Depois `home.php` (homepage) e `single.php` (artigos)
   - Explore `template-parts/` para componentes

## Deploy

### Automático (Recomendado)

Qualquer push em `main` dispara GitHub Actions:

1. ✅ Lint de sintaxe PHP em todos os arquivos `.php`
2. 📤 Deploy via FTP para o servidor
3. 🗑️ Purga automática do cache LiteSpeed

```bash
git add .
git commit -m "Fix: descrição da mudança"
git push origin main
# Deploy acontece em ~40s
```

Acompanhe em: **GitHub** → **Actions** → escolha o workflow

### Manual (Se necessário)

```bash
# Upload via FTP
curl --user "$FTP_USER:$FTP_PASS" -Q "CWD /" --ftp-create-dirs \
  -T arquivo.php "ftp://45.151.121.68/caminho/arquivo.php"

# Purgar cache
curl -s "https://deveserisso.com.br/purge_ls.php"
```

## Credenciais & Secrets

⚠️ **Importantes:**

- `wp-config.php` **NÃO está versionado** (está no `.gitignore`)
- Database, FTP, API keys estão em:
  - `.env` local (nunca commit)
  - **GitHub Secrets** (Settings → Secrets and variables → Actions)

**Secrets necessários no GitHub:**
- `FTP_USER` - usuário FTP Hostinger
- `FTP_PASS` - senha FTP
- `DB_USER`, `DB_PASS` - credenciais do banco (se necessário)
- `MAILERLITE_API_KEY` - integração newsletter

## Características Principais

### Performance
- ✅ Lazy loading nativo em imagens
- ✅ WebP com fallback via `.htaccess`
- ✅ CSS crítico inline (evita render-blocking)
- ✅ Fontes locais (sem requisições externas)
- ✅ Cache LiteSpeed + CDN edge

### SEO
- ✅ Meta descriptions (83+ geradas)
- ✅ Titles reescritos (270 artigos)
- ✅ 404 fixes via `.htaccess`
- ✅ Redirect /blog/ para raiz
- ✅ Encoding UTF-8 automático

### Código
- ✅ Sem plugins pesados
- ✅ Sem bloat de CSS/JS
- ✅ Templating limpo (sem shortcodes)
- ✅ Funções customizadas bem organizadas

## Tamanhos de Imagem Registrados

O tema registra 7 tamanhos automáticos no `functions.php`:

| Tamanho | Dimensões | Uso |
|---------|-----------|-----|
| `dsi-hero` | 1920×1080 | Frontispício (single) |
| `dsi-poster` | 900×1200 | Destaque (home) |
| `dsi-wide` | 1200×750 | Cards largos |
| `dsi-square` | 600×600 | Cards quadrados |
| `dsi-4x3` | 800×600 | 4:3 ratio |
| `dsi-thumb` | 120×120 | Thumbnails |
| `dsi-author` | 720×900 | Retratos (4:5) |

> ⚠️ Imagens antigas precisam regenerar via plugin de regeneração se forem upadas antes do tamanho ser registrado.

## Troubleshooting

### Imagem não aparece na home/single
1. Verifique se post tem featured image definida
2. Regenere thumbnails (plugin "Regenerate Thumbnails")
3. Limpe cache: `curl -s "https://deveserisso.com.br/purge_ls.php"`

### Deploy falha no GitHub Actions
1. Verifique os **Secrets** estão configurados
2. Teste FTP manualmente: `curl --user "$USER:$PASS" -Q "CWD /" ftp://45.151.121.68`
3. Veja logs do workflow em GitHub → Actions

### CSS/JS não atualiza
- Força reload no navegador: `Ctrl+Shift+R` (Windows) ou `Cmd+Shift+R` (Mac)
- Ou purge cache: `curl -s "https://deveserisso.com.br/purge_ls.php"`

## URLs Úteis

| Recurso | URL |
|---------|-----|
| 🌐 Site | https://deveserisso.com.br |
| 📊 WordPress Admin | https://deveserisso.com.br/blog/wp-admin/ |
| 📡 REST API | https://deveserisso.com.br/wp-json/wp/v2/ |
| 🔄 Purge Cache | https://deveserisso.com.br/purge_ls.php |
| 🏗️ GitHub | https://github.com/lpcruz2/deveserisso-site |

## Contribuindo

1. Crie uma branch: `git checkout -b feature/sua-feature`
2. Faça commits descritivos em português
3. Push para origin: `git push origin feature/sua-feature`
4. Abra um PR (ou merge direto em main se autorizado)

**Padrão de commit:**
```
Fix: descrição breve do bug corrigido
Add: nova funcionalidade
Update: melhoria em código existente
```

## Licença

Privado — Deveserisso.com.br. Contato: lpcruz2@gmail.com

---

**Última atualização:** julho 2026 | **Versão do tema:** 3.0 (redesign DSI Magazine)
