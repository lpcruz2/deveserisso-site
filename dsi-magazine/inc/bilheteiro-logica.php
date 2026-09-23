<?php
/**
 * Logica pura do bilheteiro conversacional (Jornada do Espectador) --
 * separada de functions.php de proposito (2026-09-22) pra poder ser
 * coberta por testes automatizados sem precisar de um WordPress inteiro
 * de pe. So depende de uma funcao do WP (wp_strip_all_tags, usada em
 * dsi_bilheteiro_validar_reconhecimento) -- tests/bootstrap.php fornece
 * uma implementacao real equivalente pra rodar fora do WP.
 *
 * NAO adicionar nada aqui que precise de banco de dados, REST API, ou
 * qualquer outra dependencia do WordPress -- e exatamente essa ausencia
 * de dependencia que torna este arquivo testavel isoladamente. Logica
 * que precisa do WP (chamada a API externa, log no banco, registro de
 * rota) continua em functions.php.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DSI_BILHETEIRO_LOGICA_TESTE' ) ) {
	exit; // acesso direto via HTTP bloqueado fora de WP/testes
}

// Campos extraidos por turno via LLM (escalares). temas/subtemas NAO entram
// aqui de proposito -- via de regra so chegam do Corredor de Posteres
// (estado inicial vindo do cliente), nunca por extracao de texto livre
// nesta versao (escopo deliberadamente menor: extrair tema de frase solta e
// ruidoso demais pra confiar sem mais teste). "atores" e excecao desde
// 2026-09-21 (decisao do gestor): ator/atriz favorito virou uma das 4
// perguntas ativas do widget/LP -- ver DSI_BILHETEIRO_INSTRUCAO.
const DSI_BILHETEIRO_CAMPOS_ARRAY = [ 'temas', 'subtemas', 'atores', 'exclusoes' ];
// Jornada gamificada (Corredor + Escolha da Emocao): emocao e pilar central,
// tem minigame proprio.
const DSI_BILHETEIRO_CAMPOS_OBRIGATORIOS = [ 'genero', 'emocao', 'plataforma' ];
// Entradas sem minigame (widget flutuante + LP de chat puro, decisao do
// gestor 2026-09-21): sem Corredor/Emocao pra gerar sinal, entao pergunta
// direto por genero + filme/serie de referencia (substitui emocao aqui) +
// atores + plataforma. "Atores" e campo array (nunca fica "null", so vazio)
// -- dsi_bilheteiro_campos_faltando() e a trava do limite de perguntas
// tratam isso separado dos escalares (ver os dois abaixo), pra nao tentar
// gravar o sentinela DSI_BILHETEIRO_SEM_PREFERENCIA (string) num campo que
// o JS sempre espera como array.
const DSI_BILHETEIRO_CAMPOS_OBRIGATORIOS_CHAT = [ 'genero', 'q', 'atores', 'plataforma' ];

function dsi_bilheteiro_campos_obrigatorios( array $contexto ): array {
	return ! empty( $contexto['sem_minigames'] ) ? DSI_BILHETEIRO_CAMPOS_OBRIGATORIOS_CHAT : DSI_BILHETEIRO_CAMPOS_OBRIGATORIOS;
}

const DSI_BILHETEIRO_PERGUNTAS = [
	'plataforma'  => 'Onde você pode assistir? Pode ser mais de um: Netflix, Amazon Prime, Globoplay, Telecine ou Disney+.',
	'tipo'        => 'Filme ou série?',
	'emocao'      => 'Que emoção você quer sentir agora? Rir, ter medo, chorar, adrenalina ou se apaixonar?',
	'genero'      => 'Que gênero te chama mais atenção hoje? Ação, comédia, terror, romance, drama...?',
	// Perguntas novas do fluxo sem minigame (widget/LP, decisao 2026-09-21).
	'filmes_series' => 'Tem algum filme ou série que você curte bastante e queria ver algo parecido?',
	'atores'        => 'Tem algum ator ou atriz que você gosta muito?',
	'texto_livre' => 'Você gostaria de me dizer mais alguma coisa antes de eu escolher seus filmes?',
];

// RF4 revisado: so pergunta genero/emocao se o minigame correspondente foi
// pulado (senao ja veio do Corredor/Emocao, nao reper gunta -- PRD secao 3).
// Pra chat-so (sem_minigames): pergunta o que ainda falta, na ordem abaixo,
// quantas vezes for preciso ate DSI_BILHETEIRO_LIMITE_PERGUNTAS -- decisao
// do gestor 2026-09-21 ("como o LLM vai gerenciar isso pode ser em quantas
// perguntas forem necessarias, so precisa extrair o que quero"). "Atores" e
// array (nunca fica "null", so vazio) entao repete a mesma pergunta se a
// pessoa nao citar nenhum -- aceitavel, o limite de perguntas ja poe um teto
// baixo nisso.
function dsi_bilheteiro_proxima_pergunta( array $estado, array $contexto ): string {
	if ( ! empty( $contexto['sem_minigames'] ) ) {
		if ( $estado['genero'] === null ) {
			return DSI_BILHETEIRO_PERGUNTAS['genero'];
		}
		if ( $estado['q'] === null ) {
			return DSI_BILHETEIRO_PERGUNTAS['filmes_series'];
		}
		if ( empty( $estado['atores'] ) && empty( $estado['atores_sem_preferencia'] ) ) {
			return DSI_BILHETEIRO_PERGUNTAS['atores'];
		}
		if ( $estado['plataforma'] === null ) {
			return DSI_BILHETEIRO_PERGUNTAS['plataforma'];
		}
		return DSI_BILHETEIRO_PERGUNTAS['texto_livre'];
	}
	if ( ! empty( $contexto['corredor_pulado'] ) && $estado['genero'] === null ) {
		return DSI_BILHETEIRO_PERGUNTAS['genero'];
	}
	if ( ! empty( $contexto['emocao_pulada'] ) && $estado['emocao'] === null ) {
		return DSI_BILHETEIRO_PERGUNTAS['emocao'];
	}
	if ( $estado['plataforma'] === null ) {
		return DSI_BILHETEIRO_PERGUNTAS['plataforma'];
	}
	if ( $estado['tipo'] === null ) {
		return DSI_BILHETEIRO_PERGUNTAS['tipo'];
	}
	return DSI_BILHETEIRO_PERGUNTAS['texto_livre'];
}

function dsi_bilheteiro_campos_faltando( array $estado, array $contexto = [] ): array {
	$faltando = [];
	foreach ( dsi_bilheteiro_campos_obrigatorios( $contexto ) as $campo ) {
		// Campo array (ex: atores) nunca fica "null", so vazio -- checar
		// igual aos escalares sempre diria "preenchido" mesmo sem resposta.
		// "atores_sem_preferencia" e a saida graciosa quando a pessoa insiste
		// que nao tem ator favorito (equivalente ao sentinela dos escalares).
		$vazio = in_array( $campo, DSI_BILHETEIRO_CAMPOS_ARRAY, true )
			? ( empty( $estado[ $campo ] ) && empty( $estado[ $campo . '_sem_preferencia' ] ) )
			: $estado[ $campo ] === null;
		if ( $vazio ) {
			$faltando[] = $campo;
		}
	}
	return $faltando;
}

// "Reconhecimento" (decisao do gestor 2026-09-22): a UNICA parte da
// conversa gerada livremente pela IA -- a pergunta em si continua sempre
// vindo de DSI_BILHETEIRO_PERGUNTAS (texto fixo, decidido por
// dsi_bilheteiro_proxima_pergunta), o reconhecimento so vira uma frase de
// abertura colada na frente. Validado aqui antes de ir pro visitante; se
// reprovar em qualquer checagem, volta pro comportamento de sempre (so a
// pergunta, sem reconhecimento) -- nunca fica pior que o que ja existia.
const DSI_BILHETEIRO_RECONHECIMENTO_MAX_CHARS = 140;
const DSI_BILHETEIRO_RECONHECIMENTO_PADROES_SUSPEITOS = [
	'/https?:\/\//i',      // link
	'/<[a-z]/i',            // tag HTML
	'/instru[cç][aã]o/i',   // tentativa de falar sobre o proprio prompt
	'/system prompt/i',
	'/ignor[ae]/i',         // "ignore/ignora as regras"
	'/\bprompt\b/i',
	'/enquanto (ia|assistente|modelo)/i',
];
function dsi_bilheteiro_validar_reconhecimento( $texto ): string {
	if ( ! is_string( $texto ) || trim( $texto ) === '' ) {
		return '';
	}
	$texto = trim( wp_strip_all_tags( $texto ) );
	if ( $texto === '' || mb_strlen( $texto ) > DSI_BILHETEIRO_RECONHECIMENTO_MAX_CHARS ) {
		return '';
	}
	if ( strpos( $texto, '?' ) !== false ) {
		return ''; // reconhecimento nunca e pergunta -- isso e papel da pergunta fixa
	}
	foreach ( DSI_BILHETEIRO_RECONHECIMENTO_PADROES_SUSPEITOS as $padrao ) {
		if ( preg_match( $padrao, $texto ) ) {
			return '';
		}
	}
	return $texto;
}

// Deteccao por palavra-chave das 5 plataformas fixas (PRD secao 3), como
// rede de seguranca deterministica por cima da extracao via LLM -- ver
// achado do gestor 2026-09-20 no comentario de uso. "prime"/"globo"
// sozinhos ficam de fora de proposito (ambiguo com "primeiro"/"Rede
// Globo"); exige a frase mais especifica.
const DSI_BILHETEIRO_PLATAFORMAS_REGEX = [
	'Netflix'      => '/netflix/i',
	'Amazon Prime' => '/amazon|prime\s*video/i',
	'Globoplay'    => '/globo\s*play/i',
	'Telecine'     => '/telecine/i',
	'Disney+'      => '/disney/i',
];
function dsi_bilheteiro_detectar_plataformas_texto( string $mensagem ): array {
	$encontradas = [];
	foreach ( DSI_BILHETEIRO_PLATAFORMAS_REGEX as $nome => $padrao ) {
		if ( preg_match( $padrao, $mensagem ) ) {
			$encontradas[] = $nome;
		}
	}
	return $encontradas;
}

// Monta o "contexto grounded" pras perguntas livres feitas DEPOIS que a
// recomendacao ja foi mostrada (ex: "esses filmes tem o De Niro?" -- achado
// do relatorio semanal 2026-09-23: o bot nao tratava isso, so repetia a
// mensagem generica de "pronto"). Pura de proposito, mesma razao do resto
// deste arquivo: testar sem precisar de banco. NUNCA deixa a IA responder
// sem esses dados na frente -- mesma filosofia de "nunca inventar" ja usada
// pra genero/plataforma/titulo em pt-BR (ver dsi_catalogo_tmdb_tentar_titulo_pt
// em functions.php). Campo ausente vira "não informado" em vez de sumir,
// assim a IA sabe que nao tem certeza em vez de inferir a partir de um
// campo faltando.
function dsi_bilheteiro_montar_contexto_filmes( array $filmes ): string {
	if ( empty( $filmes ) ) {
		return '';
	}
	$blocos = [];
	foreach ( array_values( $filmes ) as $i => $filme ) {
		$titulo  = ( isset( $filme['titulo'] ) && $filme['titulo'] !== '' ) ? (string) $filme['titulo'] : 'não informado';
		$ano     = $filme['ano_lancamento'] ?? null;
		$generos = ! empty( $filme['generos'] ) ? implode( ', ', (array) $filme['generos'] ) : 'não informado';
		$diretor = ! empty( $filme['diretor'] ) ? (string) $filme['diretor'] : 'não informado';
		$atores  = ! empty( $filme['atores'] ) ? implode( ', ', (array) $filme['atores'] ) : 'não informado';
		$sinopse = ! empty( $filme['sinopse'] ) ? (string) $filme['sinopse'] : 'não informada';
		$blocos[] = ( $i + 1 ) . ') Título: ' . $titulo . ( $ano ? ' (' . $ano . ')' : '' ) . "\n" .
			'Gênero: ' . $generos . "\n" .
			'Diretor: ' . $diretor . "\n" .
			'Elenco conhecido: ' . $atores . "\n" .
			'Sinopse: ' . $sinopse;
	}
	return implode( "\n\n", $blocos );
}

// Normaliza um item vindo do cliente pro formato que
// dsi_bilheteiro_montar_contexto_filmes() espera. Existe porque /recomendar-filme
// usa nomes de campo DIFERENTES nos dois ramos que ja mostra na tela --
// itemListElement (com resenha) usa genero/elenco/ano; sem_resenha usa
// generos/atores/ano_lancamento (herda as colunas da tabela de catalogo) --
// e o widget manda de volta qualquer um dos dois conforme o item veio. Sem
// essa normalizacao, metade dos itens perderia elenco/genero silenciosamente
// so por causa do nome do campo, nao por falta do dado de verdade.
// Rede de seguranca deterministica (2026-09-23, achado do gestor: "se a
// pessoa tira duvidas e quer pedir outra recomendação ele parece ficar preso
// num looping") -- mesmo padrao ja usado em
// dsi_bilheteiro_detectar_plataformas_texto: uma vez que perguntasEncerradas
// vira true, TODA mensagem ia pro endpoint de pergunta grounded, que so sabe
// responder sobre os filmes ja mostrados -- "como escolho outro gênero?" ou
// "posso fazer uma nova simulação?" caiam numa recusa educada em loop, sem
// jeito de voltar pra extracao de preferencia digitando. Cobertura por
// palavra-chave e imperfeita de proposito (mesma ressalva da deteccao de
// plataforma) -- e um escape hatch pras frases mais obvias, nao um
// classificador completo de intencao.
// Modificador /u obrigatorio aqui: sem ele, PCRE trata a string como bytes
// crus, nao UTF-8 -- uma classe de caracteres com acento tipo [cç]/[eê]
// vira uma classe de BYTES soltos (cada acentuado UTF-8 tem 2 bytes) e para
// de casar com o texto de verdade. Achado ao vivo 2026-09-23: os 3 padroes
// com acento (simulação/gênero/recomeçar) falhavam no CI sem isso, apesar
// de parecerem corretos lendo o código.
const DSI_BILHETEIRO_PEDIDOS_NOVA_RECOMENDACAO_REGEX = [
	'/nova (recomenda[cç][aã]o|sugest[aã]o|simula[cç][aã]o|busca)/iu',
	'/outra (recomenda[cç][aã]o|sugest[aã]o)/iu',
	'/outro g[eê]nero/iu',
	'/trocar (de )?g[eê]nero/iu',
	'/mudar (de )?g[eê]nero/iu',
	'/recome[cç]ar/iu',
	'/come[cç]ar (de novo|outra vez)/iu',
];
function dsi_bilheteiro_pede_nova_recomendacao( string $mensagem ): bool {
	foreach ( DSI_BILHETEIRO_PEDIDOS_NOVA_RECOMENDACAO_REGEX as $padrao ) {
		if ( preg_match( $padrao, $mensagem ) ) {
			return true;
		}
	}
	return false;
}

function dsi_bilheteiro_normalizar_item_pergunta( array $item ): array {
	return [
		'titulo'         => (string) ( $item['titulo'] ?? '' ),
		'ano_lancamento' => $item['ano_lancamento'] ?? ( $item['ano'] ?? null ),
		'generos'        => (array) ( $item['generos'] ?? ( $item['genero'] ?? [] ) ),
		'diretor'        => is_array( $item['direcao'] ?? null ) ? implode( ', ', $item['direcao'] ) : ( $item['diretor'] ?? $item['direcao'] ?? null ),
		'atores'         => (array) ( $item['atores'] ?? ( $item['elenco'] ?? [] ) ),
		'sinopse'        => (string) ( $item['sinopse'] ?? '' ),
	];
}
