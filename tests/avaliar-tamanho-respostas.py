"""Mede o tamanho das respostas do Curador contra o site de verdade.

Uso:  python tests/avaliar-tamanho-respostas.py [https://deveserisso.com.br]

Faz perguntas fixas nas duas rotas que escrevem texto livre (conversa sobre o filme
da pagina e perguntas sobre a lista recomendada), com sessao `teste-` (fica fora dos
relatorios), e confere contra os limites abaixo. Respeita o limite do site (10
chamadas por minuto, 100 por dia por IP): sao 10 chamadas, com pausa entre elas.
Sai com codigo 1 se alguma resposta passar do limite.
"""
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'https://deveserisso.com.br').rstrip('/')
API = BASE + '/wp-json/dsi/v1/'
SESSAO = 'teste-tam-%d' % int(time.time())
POST_FILME = 65109  # Quebrando Regras 3 (critica real publicada)

# Limites de leitura facil (celular, balao de 85% da largura).
MAX_FRASES = 3
MAX_CHARS = 420
MAX_FRASES_POR_PARAGRAFO = 2
MAX_CHARS_POR_PARAGRAFO = 260

PERGUNTAS_CONVERSA = [
    'o que a critica achou do filme?',
    'quem sao os atores principais?',
    'vale a pena assistir?',
    'pela critica achei o filme meio ruim',
    'como e a parte das lutas?',
    'tem alguma curiosidade sobre a producao?',
]
PERGUNTAS_LISTA = [
    'qual desses e o melhor avaliado?',
    'me conta mais sobre o primeiro da lista',
    'quais desses sao de comedia?',
]


def chamar(rota, corpo=None, consulta=None):
    url = API + rota + (('?' + urllib.parse.urlencode(consulta)) if consulta else '')
    dados = json.dumps(corpo).encode() if corpo is not None else None
    cab = {'User-Agent': 'Mozilla/5.0'}
    if dados:
        cab['Content-Type'] = 'application/json'
    for tentativa in (1, 2, 3, 4):
        req = urllib.request.Request(url, dados, cab)
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.loads(r.read())
        except urllib.error.HTTPError as e:
            # 502 = o modelo falhou naquele instante; repete.
            if e.code != 502 or tentativa == 4:
                raise
            time.sleep(5)


def frases(texto):
    partes = re.split(r'(?<=[.!?])\s+(?=[A-ZÁÉÍÓÚÂÊÔÃÕÇ0-9"“¿¡])', texto.strip())
    return [p for p in partes if p.strip()]


def medir(texto):
    sem_link = re.sub(r'https?://\S+', '', texto)
    paragrafos = [p for p in re.split(r'\n\s*\n', texto.strip()) if p.strip()]
    por_par = [len(frases(re.sub(r'https?://\S+', '', p))) for p in paragrafos]
    return {
        'chars': len(sem_link.strip()),
        'frases': len(frases(sem_link)),
        'paragrafos': len(paragrafos),
        'maior_par_frases': max(por_par) if por_par else 0,
        'maior_par_chars': max((len(re.sub(r'https?://\S+', '', p)) for p in paragrafos), default=0),
    }


def problemas(m):
    ruins = []
    if m['frases'] > MAX_FRASES:
        ruins.append('%d frases (max %d)' % (m['frases'], MAX_FRASES))
    if m['chars'] > MAX_CHARS:
        ruins.append('%d caracteres (max %d)' % (m['chars'], MAX_CHARS))
    if m['maior_par_frases'] > MAX_FRASES_POR_PARAGRAFO:
        ruins.append('paragrafo com %d frases (max %d)' % (m['maior_par_frases'], MAX_FRASES_POR_PARAGRAFO))
    if m['maior_par_chars'] > MAX_CHARS_POR_PARAGRAFO:
        ruins.append('paragrafo com %d caracteres (max %d)' % (m['maior_par_chars'], MAX_CHARS_POR_PARAGRAFO))
    return ruins


def main():
    resultados = []
    historico = []

    def registrar(rota, pergunta, texto):
        m = medir(texto)
        ruins = problemas(m)
        resultados.append((rota, pergunta, m, ruins, texto))

    erros = []
    for p in PERGUNTAS_CONVERSA:
        try:
            r = chamar('bilheteiro-conversa-filme', {'post_id': POST_FILME, 'pergunta': p, 'historico': historico[-6:], 'sessao_id': SESSAO})
        except urllib.error.HTTPError as e:
            erros.append(('conversa', p, e.code))
            time.sleep(7)
            continue
        texto = r['resposta']
        historico += [{'papel': 'user', 'texto': p}, {'papel': 'bot', 'texto': texto}]
        registrar('conversa', p, texto)
        time.sleep(7)

    rec = chamar('recomendar-filme', consulta={'limite': 5, 'sessao_id': SESSAO, 'genero': 'Ação', 'rodada': 1})
    itens = rec.get('itemListElement', []) + rec.get('sem_resenha', [])
    for p in PERGUNTAS_LISTA:
        try:
            r = chamar('bilheteiro-perguntar', {'pergunta': p, 'itens': itens, 'sessao_id': SESSAO, 'rodada': 1})
        except urllib.error.HTTPError as e:
            erros.append(('lista', p, e.code))
            time.sleep(7)
            continue
        registrar('lista', p, r.get('resposta', ''))
        time.sleep(7)

    falhas = 0
    for rota, pergunta, m, ruins, texto in resultados:
        marca = 'FALHA' if ruins else 'ok   '
        falhas += bool(ruins)
        print('%s [%s] %-45s %3d car, %d frases, %d par.  %s' % (
            marca, rota, pergunta[:45], m['chars'], m['frases'], m['paragrafos'], '; '.join(ruins)))
    for rota, pergunta, codigo in erros:
        print('ERRO  [%s] %-45s HTTP %s (sem resposta para medir)' % (rota, pergunta[:45], codigo))
    total = len(resultados)
    media = sum(m['chars'] for _, _, m, _, _ in resultados) / total
    print('\n%d de %d dentro dos limites. Media: %d caracteres por resposta.' % (total - falhas, total, media))
    if '--texto' in sys.argv:
        for rota, pergunta, m, ruins, texto in resultados:
            print('\n--- %s | %s\n%s' % (rota, pergunta, texto))
    sys.exit(1 if (falhas or erros) else 0)


if __name__ == '__main__':
    main()
