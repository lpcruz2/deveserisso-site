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
