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
		$nota    = isset( $filme['nota'] ) && $filme['nota'] !== null ? str_replace( '.', ',', (string) $filme['nota'] ) . ' de 10 (média do público no TMDB)' : 'não informada';
		$critica = ! empty( $filme['tem_critica'] ) && ! empty( $filme['link'] )
			? 'sim, publicada no Deveserisso: ' . $filme['link']
			: 'ainda não';
		$blocos[] = ( $i + 1 ) . ') Título: ' . $titulo . ( $ano ? ' (' . $ano . ')' : '' ) . "\n" .
			'Gênero: ' . $generos . "\n" .
			'Diretor: ' . $diretor . "\n" .
			'Elenco conhecido: ' . $atores . "\n" .
			'Sinopse: ' . $sinopse . "\n" .
			'Nota: ' . $nota . "\n" .
			'Crítica no site: ' . $critica;
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

// Negativa curta ("não", "nenhum", "tanto faz") como resposta a pergunta de
// filme de referencia, ator ou plataforma (achado com transcript real
// 2026-09-25: "não" respondido 3x a "tem algum filme parecido?" e a
// pergunta voltava igual, ate bater o limite de perguntas -- o LLM recebia
// a mensagem sem a pergunta, e um "não" solto nao diz de que campo e).
// Rede de seguranca deterministica por cima do LLM: so vale pra mensagem
// curta, e functions.php so aplica quando o LLM nao extraiu nada pro campo
// que acabou de ser perguntado. Nunca pra genero (decisao do gestor
// 2026-09-22: genero sempre insiste). /u obrigatorio, ver comentario acima.
const DSI_BILHETEIRO_CAMPOS_ACEITAM_NEGATIVA = [ 'q', 'atores', 'plataforma' ];
const DSI_BILHETEIRO_NEGATIVA_MAX_CHARS      = 60;
const DSI_BILHETEIRO_NEGATIVAS_REGEX         = [
	'/^\W*(?:(?:n[aã]o+|n|nop|nope|nem|nenhum|nenhuma|nada|ningu[eé]m)\W*)+$/iu',
	'/\bn[aã]o (tenho|sei|lembro|conhe[cç]o)\b/iu',
	'/\btanto faz\b/iu',
	'/\bqualquer (um|uma|coisa)\b/iu',
	'/\bsem prefer[eê]ncia\b/iu',
	'/\bj[aá] (disse|falei) que n[aã]o\b/iu',
	'/\bnenhum(a)? (em )?(especial|espec[ií]fic[oa])\b/iu',
];
function dsi_bilheteiro_eh_negativa( string $mensagem ): bool {
	$mensagem = trim( $mensagem );
	if ( $mensagem === '' || mb_strlen( $mensagem ) > DSI_BILHETEIRO_NEGATIVA_MAX_CHARS ) {
		return false;
	}
	foreach ( DSI_BILHETEIRO_NEGATIVAS_REGEX as $padrao ) {
		if ( preg_match( $padrao, $mensagem ) ) {
			return true;
		}
	}
	return false;
}

// Pedido de MAIS titulos depois da recomendacao (relatorio semanal
// 2026-09-28, sessao real: "tem outros?" caiu na rota de pergunta, o Curador
// respondeu "Não tenho outros títulos além dos já listados" e a pessoa deu
// 👎 logo depois). Diferente de dsi_bilheteiro_pede_nova_recomendacao: aqui
// as preferencias continuam as mesmas e so os titulos ja mostrados saem
// (excluir_filmes, que o widget ja acumula). Ancorado no fim da frase pra
// nao pegar "quero mais detalhes do segundo" nem "tem mais cenas de ação?".
const DSI_BILHETEIRO_PEDIDOS_MAIS_OPCOES_REGEX = [
	'/^\W*(mais|outr[oa]s)\W*$/iu',
	'/\b(quero|queria|gostaria de ver|mostra|mostre|manda|me d[aá]|d[aá]) (mais|outr[oa]s)( (op[cç][oõ]es|filmes|s[eé]ries|t[ií]tulos|sugest[oõ]es|indica[cç][oõ]es|recomenda[cç][oõ]es))?( (por favor|pf|pfv))?\W*$/iu',
	'/\b(tem|teria|h[aá]|existem?) (mais )?outr[oa]s( (op[cç][oõ]es|filmes|s[eé]ries|t[ií]tulos|sugest[oõ]es|indica[cç][oõ]es|recomenda[cç][oõ]es))?( (por favor|pf|pfv))?\W*$/iu',
	'/\b(mais|outr[oa]s) (op[cç][oõ]es|sugest[oõ]es|indica[cç][oõ]es|recomenda[cç][oõ]es)( (por favor|pf|pfv))?\W*$/iu',
];
function dsi_bilheteiro_pede_mais_opcoes( string $mensagem ): bool {
	foreach ( DSI_BILHETEIRO_PEDIDOS_MAIS_OPCOES_REGEX as $padrao ) {
		if ( preg_match( $padrao, $mensagem ) ) {
			return true;
		}
	}
	return false;
}

// Qual pedido manda na lista quando ator e genero foram pedidos
// (relatorio semanal 2026-09-28). A regra de 2026-09-25 (ator no catalogo
// vira filtro e o genero so ordena) fez "comédia sem drama" + 5 atores de
// drama citados como gosto pessoal devolver O Advogado do Diabo e O Poderoso
// Chefão II. Regra nova:
// - ator + genero com titulo em comum: so esses (o caso Ben Stiller segue
//   sem filme generico pra completar a lista);
// - sem titulo em comum e ator PRINCIPAL (exigido pela pessoa, ver
//   dsi_curador_papel_ator): o ator manda (aviso sem_genero);
// - sem titulo em comum e ator so de gosto: o genero manda (aviso
//   genero_sem_ator);
// - sem ator no catalogo: genero pedido vira filtro quando existe titulo
//   dele, senao a lista sai so por pontuacao.
// "Principal" era "um ator so" ate o teste de 2026-09-28 com conversas reais
// (experimentos/jev-vs-deepseek no CineQuiz): essa aproximacao acertou 7 de
// 18, porque quem responde um nome a "tem algum ator que você gosta?" esta
// dando gosto, nao exigindo.
// Retorna 'ator_genero' | 'ator' | 'genero' | 'livre'.
function dsi_recomendacao_modo_filtro( bool $ator_no_catalogo, bool $ator_principal, bool $genero_pedido, bool $existe_ator_e_genero, bool $existe_genero ): string {
	if ( $ator_no_catalogo ) {
		if ( ! $genero_pedido ) {
			return 'ator';
		}
		if ( $existe_ator_e_genero ) {
			return 'ator_genero';
		}
		if ( $ator_principal || ! $existe_genero ) {
			return 'ator';
		}
		return 'genero';
	}
	return ( $genero_pedido && $existe_genero ) ? 'genero' : 'livre';
}

// Papel do ator citado, devolvido pela DeepSeek na mesma chamada da extracao
// (campo papel_atores). Qualquer coisa diferente de "principal" vira "gosto":
// na duvida o ator so soma pontos e o genero manda, que e o lado seguro
// (erro em "principal" esconde o genero pedido; erro em "gosto" so perde a
// preferencia forte pelo ator). Entre 28/09 e 29/09/2026 quem classificava
// era o Jev (TypeSafe); saiu do chat por decisao do gestor -- acertava o
// mesmo que a DeepSeek e custava uma chamada e uma empresa a mais.
function dsi_curador_papel_ator( $papel ): string {
	return $papel === 'principal' ? 'principal' : 'gosto';
}

// Genero pedido x genero do titulo (2026-09-28, revisao das conversas):
// - comparacao por palavra inteira: por trecho, "acao" casava com
//   "animacao" e o pedido de Ação trazia animacao;
// - sinonimos: a extracao gravava "Thriller" pra quem pediu suspense, e as
//   criticas usam "Suspense"; o catalogo externo usa nomes da TMDB
//   ("Sci-Fi & Fantasy", "Action & Adventure", "War & Politics");
// - mais de um genero separado por virgula ("Comédia, Documentário") vale
//   qualquer um deles.
// Tudo ja normalizado (minusculas, sem acento -- dsi_dt_normalize_key).
const DSI_GENEROS_EQUIVALENTES = [
	[ 'suspense', 'thriller' ],
	[ 'ficcao cientifica', 'sci-fi' ],
	[ 'acao', 'action' ],
	[ 'guerra', 'war' ],
];
function dsi_generos_chaves_busca( string $genero_normalizado ): array {
	$chaves = [];
	foreach ( array_filter( array_map( 'trim', explode( ',', $genero_normalizado ) ), 'strlen' ) as $g ) {
		$chaves[] = $g;
		foreach ( DSI_GENEROS_EQUIVALENTES as $grupo ) {
			if ( in_array( $g, $grupo, true ) ) {
				$chaves = array_merge( $chaves, $grupo );
			}
		}
	}
	return array_values( array_unique( $chaves ) );
}
function dsi_genero_bate( string $genero_item_normalizado, array $chaves ): bool {
	foreach ( $chaves as $chave ) {
		if ( $chave !== '' && preg_match( '/(^|[^a-z0-9])' . preg_quote( $chave, '/' ) . '($|[^a-z0-9])/u', $genero_item_normalizado ) ) {
			return true;
		}
	}
	return false;
}

// E-mail nunca entra no log do Curador nem vai pra DeepSeek (regra
// permanente do log). Achado da revisao de 2026-09-28: "cadastra meu email
// fulano@..." digitado depois da lista foi gravado inteiro.
function dsi_bilheteiro_sem_email( string $texto ): string {
	return (string) preg_replace( '/[^\s@<>()"\',;:]+@[^\s@<>()"\',;:]+\.[^\s@<>()"\',;:]+/u', '[e-mail removido]', $texto );
}

// "Meryl Streep", "Meryl Streep e Gary Oldman", "Meryl Streep, Gary Oldman
// e mais 3" -- nome dos atores no aviso do widget.
function dsi_bilheteiro_lista_nomes( array $nomes, int $max = 2 ): string {
	$nomes = array_values( array_filter( array_map( 'trim', array_map( 'strval', $nomes ) ), 'strlen' ) );
	$total = count( $nomes );
	if ( $total === 0 ) {
		return '';
	}
	if ( $total === 1 ) {
		return $nomes[0];
	}
	if ( $total <= $max ) {
		return implode( ', ', array_slice( $nomes, 0, -1 ) ) . ' e ' . $nomes[ $total - 1 ];
	}
	return implode( ', ', array_slice( $nomes, 0, $max ) ) . ' e mais ' . ( $total - $max );
}

// nota/tem_critica/link (2026-09-28, relatorio semanal): "o maskara é bom?"
// era respondido com "Não tenho uma avaliação de qualidade". Link so do
// proprio site -- o item vem do cliente e o link vai pra resposta.
function dsi_bilheteiro_normalizar_item_pergunta( array $item ): array {
	$link = (string) ( $item['link'] ?? '' );
	if ( ! preg_match( '#^https://deveserisso\.com\.br/[A-Za-z0-9/_%.-]*$#', $link ) ) {
		$link = '';
	}
	$nota = $item['nota'] ?? null;
	return [
		'titulo'         => (string) ( $item['titulo'] ?? '' ),
		'ano_lancamento' => $item['ano_lancamento'] ?? ( $item['ano'] ?? null ),
		'generos'        => (array) ( $item['generos'] ?? ( $item['genero'] ?? [] ) ),
		'diretor'        => is_array( $item['direcao'] ?? null ) ? implode( ', ', $item['direcao'] ) : ( $item['diretor'] ?? $item['direcao'] ?? null ),
		'atores'         => (array) ( $item['atores'] ?? ( $item['elenco'] ?? [] ) ),
		'sinopse'        => (string) ( $item['sinopse'] ?? '' ),
		'nota'           => is_numeric( $nota ) ? round( (float) $nota, 1 ) : null,
		'tem_critica'    => ( $item['fonte'] ?? '' ) === 'catalogo' && $link !== '',
		'link'           => $link,
		'poster'         => is_string( $item['poster'] ?? null ) ? mb_substr( $item['poster'], 0, 500 ) : '',
	];
}

// Ambientacao/epoca pedida (2026-09-28, conversa real: "quero filme com tema
// idade média" virou genero Historia e a lista trouxe Vice e Snowden). A
// extracao escolhe um rotulo desta lista fechada; a busca reconhece a epoca
// do titulo por palavras. Aproximacao ate cada titulo ter a epoca
// classificada:
// - "fortes" bastam sozinhas, no titulo ou no texto (medieval, vikings...);
// - "fracas" so contam no texto (temas e sinopse, nunca no titulo) e so com
//   duas diferentes. No primeiro teste, palavra solta trouxe "Histórias
//   Cruzadas" e "Batman: O Cavaleiro das Trevas" pra Idade Media.
// Tudo normalizado (minusculas, sem acento).
const DSI_AMBIENTACOES = [
	'antiguidade'         => [
		'fortes' => [ 'antiguidade', 'roma antiga', 'imperio romano', 'gladiador', 'gladiadores', 'egito antigo', 'grecia antiga', 'esparta', 'espartanos', 'cleopatra', 'farao' ],
		'fracas' => [ 'troia', 'legiao', 'cesar', 'coliseu' ],
	],
	'idade media'         => [
		'fortes' => [ 'idade media', 'medieval', 'medievais', 'feudal', 'viking', 'vikings', 'rei arthur', 'templarios', 'excalibur', 'camelot', 'peste negra', 'joana d\'arc' ],
		'fracas' => [ 'cavaleiro', 'cavaleiros', 'castelo', 'cruzada', 'cruzadas', 'jerusalem', 'espada', 'saxoes', 'anglo-saxao', 'anglo-saxonica', 'feudo', 'escudeiro' ],
	],
	'seculos xvi a xviii' => [
		'fortes' => [ 'seculo xvi', 'seculo xvii', 'seculo xviii', 'mosqueteiro', 'mosqueteiros', 'revolucao francesa' ],
		'fracas' => [ 'pirata', 'piratas', 'renascimento', 'corte', 'navio' ],
	],
	'velho oeste'         => [
		'fortes' => [ 'velho oeste', 'faroeste', 'cowboy', 'cowboys', 'pistoleiro', 'pistoleiros' ],
		'fracas' => [ 'xerife', 'apache', 'apaches', 'rancho', 'diligencia' ],
	],
	'seculo xix'          => [
		'fortes' => [ 'seculo xix', 'seculo 19', 'era vitoriana', 'guerra civil americana', 'guerra de secessao' ],
		'fracas' => [ 'vitoriana', 'vitoriano', 'escravidao', 'abolicionista' ],
	],
	'primeira guerra'     => [
		'fortes' => [ 'primeira guerra', 'primeira guerra mundial' ],
		'fracas' => [ 'trincheira', 'trincheiras' ],
	],
	'segunda guerra'      => [
		'fortes' => [ 'segunda guerra', 'segunda guerra mundial', 'nazista', 'nazistas', 'nazismo', 'holocausto', 'hitler', 'auschwitz', 'campo de concentracao' ],
		'fracas' => [ 'resistencia francesa', 'dia d' ],
	],
	'guerra fria'         => [
		'fortes' => [ 'guerra fria', 'guerra do vietna', 'anos 60', 'anos 70', 'anos 80', 'ditadura militar' ],
		'fracas' => [ 'vietna', 'sovietico', 'kgb' ],
	],
	'futuro'              => [
		'fortes' => [ 'futurista', 'distopia', 'distopico', 'pos-apocaliptico', 'futuro distante' ],
		'fracas' => [ 'futuro', 'apocalipse', 'androide', 'robos' ],
	],
	'espaco'              => [
		'fortes' => [ 'nave espacial', 'estacao espacial', 'astronauta', 'astronautas', 'espaco sideral', 'galaxia' ],
		'fracas' => [ 'planeta', 'planetas', 'alienigena', 'alienigenas', 'marte' ],
	],
];
function dsi_ambientacao_existe( string $ambientacao_normalizada ): bool {
	return isset( DSI_AMBIENTACOES[ trim( $ambientacao_normalizada ) ] );
}
// Quantas palavras da epoca o titulo tem (0 = nao e da epoca).
function dsi_ambientacao_acertos( string $ambientacao_normalizada, string $titulo_normalizado, string $texto_normalizado ): int {
	$regras = DSI_AMBIENTACOES[ trim( $ambientacao_normalizada ) ] ?? null;
	if ( ! $regras ) {
		return 0;
	}
	$fortes = count( array_filter( $regras['fortes'], fn( $p ) => dsi_genero_bate( $titulo_normalizado . ' | ' . $texto_normalizado, [ $p ] ) ) );
	$fracas = count( array_filter( $regras['fracas'], fn( $p ) => dsi_genero_bate( $texto_normalizado, [ $p ] ) ) );
	if ( $fortes === 0 && $fracas < 2 ) {
		return 0;
	}
	return $fortes + $fracas;
}

// Resposta a "Qual 'O Reino' você quis dizer?" (2026-09-28). $opcoes vem do
// estado: [ ['titulo','ano','tipo'], ... ]. Devolve o indice escolhido, -1 pra
// "nenhum desses" ou null quando a mensagem nao e uma escolha (a pessoa
// seguiu a conversa ou citou outro titulo). $mensagem ja normalizada.
function dsi_bilheteiro_escolher_opcao( string $mensagem, array $opcoes ) {
	$m = trim( $mensagem );
	if ( $m === '' || ! $opcoes ) {
		return null;
	}
	if ( preg_match( '/^\W*(nenhum|nenhuma|nenhum desses|nenhuma dessas|nao e nenhum|outro|outra)\b/u', $m ) ) {
		return -1;
	}
	if ( preg_match( '/^\W*(\d)\W*$/', $m, $n ) && (int) $n[1] >= 1 && (int) $n[1] <= count( $opcoes ) ) {
		return (int) $n[1] - 1;
	}
	// Rotulo do botao ("o reino (filme, 2007)") ou ano citado.
	if ( preg_match( '/\b(19|20)\d{2}\b/', $m, $ano ) ) {
		$com_ano = array_keys( array_filter( $opcoes, fn( $o ) => (string) ( $o['ano'] ?? '' ) === $ano[0] ) );
		if ( count( $com_ano ) === 1 ) {
			return $com_ano[0];
		}
	}
	foreach ( [ 'serie' => '/\bseries?\b/u', 'filme' => '/\bfilmes?\b/u' ] as $tipo => $padrao ) {
		if ( preg_match( $padrao, $m ) ) {
			$do_tipo = array_keys( array_filter( $opcoes, fn( $o ) => ( $o['tipo'] ?? '' ) === $tipo ) );
			if ( count( $do_tipo ) === 1 ) {
				return $do_tipo[0];
			}
		}
	}
	return null;
}

// Epoca classificada por titulo (2026-09-28): uma das chaves de
// DSI_AMBIENTACOES ou "nenhuma" (historia atual ou sem epoca marcada).
// Qualquer outra resposta do modelo vira null (nao grava, tenta de novo depois).
function dsi_epoca_valida( $valor ): ?string {
	if ( ! is_string( $valor ) ) {
		return null;
	}
	$chave = strtolower( trim( strtr( $valor, [ 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ç' => 'c', 'ã' => 'a', 'ê' => 'e', 'É' => 'e', 'Í' => 'i', 'Á' => 'a' ] ) ) );
	if ( $chave === 'nenhuma' || isset( DSI_AMBIENTACOES[ $chave ] ) ) {
		return $chave;
	}
	return null;
}

// =============================================================================
// A2UI no Curador -- fase 1: cartao de avaliacao (2026-09-29)
// =============================================================================
// Especificacao: CineQuiz-deveserisso/docs/prd-curador-a2ui-fase1.md e
// erd-curador-a2ui-fase1.md. O servidor descreve o cartao em JSON (protocolo
// A2UI v0.9, catalogo proprio do Curador, so componentes do catalogo basico:
// Card, Column, Row, Image, Text, Button) e o widget desenha. O modelo nunca
// escreve o JSON da tela: so diz a intencao e o titulo; tudo que vai no
// cartao vem dos dados do item ou da frase do modelo.
const DSI_A2UI_CATALOGO      = 'https://deveserisso.com.br/a2ui/curador/v1';
const DSI_A2UI_VERSAO        = 'v0.9';
const DSI_A2UI_HOSTS_IMAGEM  = [ 'deveserisso.com.br', 'image.tmdb.org' ];
const DSI_A2UI_HOSTS_LINK    = [ 'deveserisso.com.br' ];
const DSI_A2UI_ACOES         = [ 'mais_parecidos' ];
const DSI_A2UI_INTENCOES     = [ 'avaliar_titulo', 'outra' ];

// O widget declara o catalogo que sabe desenhar (a2uiClientCapabilities da
// especificacao). Sem declaracao, nada de cartao: widget antigo em cache
// continua recebendo so o texto.
function dsi_a2ui_cliente_suporta( $capacidades ): bool {
	if ( ! is_array( $capacidades ) ) {
		return false;
	}
	return in_array( DSI_A2UI_CATALOGO, (array) ( $capacidades['supportedCatalogIds'] ?? [] ), true );
}

// https, host exatamente na lista (ou subdominio dele), sem usuario/senha.
function dsi_a2ui_url_permitida( $url, array $hosts ): bool {
	if ( ! is_string( $url ) || $url === '' || strlen( $url ) > 500 ) {
		return false;
	}
	$partes = parse_url( $url );
	if ( ! is_array( $partes ) || ( $partes['scheme'] ?? '' ) !== 'https' || isset( $partes['user'] ) || isset( $partes['pass'] ) ) {
		return false;
	}
	$host = strtolower( (string) ( $partes['host'] ?? '' ) );
	foreach ( $hosts as $permitido ) {
		if ( $host === $permitido || substr( $host, -strlen( $permitido ) - 1 ) === '.' . $permitido ) {
			return true;
		}
	}
	return false;
}

// Resposta do modelo na rota de perguntas: {"resposta","intencao","titulo"}.
// $n_itens = quantos titulos foram enviados (o "titulo" e o numero da lista,
// a partir de 1). Qualquer coisa fora do combinado vira intencao "outra": a
// pessoa recebe o texto, como antes do A2UI.
function dsi_a2ui_interpretar_resposta( string $conteudo, int $n_itens ): array {
	$conteudo = trim( $conteudo );
	$json     = json_decode( $conteudo, true );
	if ( ! is_array( $json ) || ! isset( $json['resposta'] ) || ! is_string( $json['resposta'] ) || trim( $json['resposta'] ) === '' ) {
		// Nao veio JSON: usa o texto puro, sem cartao.
		return [ 'resposta' => mb_substr( $conteudo, 0, 600 ), 'intencao' => 'outra', 'titulo' => null ];
	}
	$intencao = in_array( $json['intencao'] ?? null, DSI_A2UI_INTENCOES, true ) ? $json['intencao'] : 'outra';
	$numero   = isset( $json['titulo'] ) && is_numeric( $json['titulo'] ) ? (int) $json['titulo'] : 0;
	if ( $numero < 1 || $numero > $n_itens ) {
		$intencao = 'outra';
		$numero   = null;
	}
	return [ 'resposta' => mb_substr( trim( $json['resposta'] ), 0, 600 ), 'intencao' => $intencao, 'titulo' => $numero ?: null ];
}

// Superficie do cartao de avaliacao: as 3 mensagens A2UI (criar, componentes,
// dados). $item = item normalizado (dsi_bilheteiro_normalizar_item_pergunta).
function dsi_a2ui_cartao_avaliacao( array $item, string $frase, string $surface_id ): array {
	$titulo = trim( (string) ( $item['titulo'] ?? '' ) );
	$ano    = $item['ano_lancamento'] ?? null;
	$nota   = ( isset( $item['nota'] ) && is_numeric( $item['nota'] ) ) ? str_replace( '.', ',', (string) round( (float) $item['nota'], 1 ) ) . ' · média do público no TMDB' : '';
	$poster = ( isset( $item['poster'] ) && dsi_a2ui_url_permitida( $item['poster'], DSI_A2UI_HOSTS_IMAGEM ) ) ? $item['poster'] : '';
	$link   = ( ! empty( $item['tem_critica'] ) && dsi_a2ui_url_permitida( $item['link'] ?? '', DSI_A2UI_HOSTS_LINK ) ) ? $item['link'] : '';

	$componentes = [];
	$topo        = [];
	if ( $poster !== '' ) {
		$componentes[] = [ 'id' => 'poster', 'component' => 'Image', 'url' => [ 'path' => '/poster' ], 'fit' => 'cover', 'variant' => 'smallFeature' ];
		$topo[]        = 'poster';
	}
	$textos        = [ 'titulo' ];
	$componentes[] = [ 'id' => 'titulo', 'component' => 'Text', 'text' => [ 'path' => '/titulo' ], 'variant' => 'h3' ];
	if ( $nota !== '' ) {
		$componentes[] = [ 'id' => 'nota', 'component' => 'Text', 'text' => [ 'path' => '/nota' ], 'variant' => 'caption' ];
		$textos[]      = 'nota';
	}
	$componentes[] = [ 'id' => 'frase', 'component' => 'Text', 'text' => [ 'path' => '/frase' ] ];
	$textos[]      = 'frase';
	$componentes[] = [ 'id' => 'textos', 'component' => 'Column', 'children' => $textos ];
	$topo[]        = 'textos';
	$componentes[] = [ 'id' => 'topo', 'component' => 'Row', 'children' => $topo ];

	$botoes = [];
	if ( $link !== '' ) {
		$componentes[] = [ 'id' => 'ler_critica', 'component' => 'Button', 'child' => 'ler_critica_txt', 'variant' => 'primary',
			'action' => [ 'functionCall' => [ 'call' => 'openUrl', 'args' => [ 'url' => $link ] ] ] ];
		$componentes[] = [ 'id' => 'ler_critica_txt', 'component' => 'Text', 'text' => 'Ler a crítica' ];
		$botoes[]      = 'ler_critica';
	}
	$componentes[] = [ 'id' => 'parecidos', 'component' => 'Button', 'child' => 'parecidos_txt',
		'action' => [ 'event' => [ 'name' => 'mais_parecidos', 'context' => [ 'titulo' => mb_substr( $titulo, 0, 120 ) ] ] ] ];
	$componentes[] = [ 'id' => 'parecidos_txt', 'component' => 'Text', 'text' => 'Ver parecidos' ];
	$botoes[]      = 'parecidos';
	$componentes[] = [ 'id' => 'botoes', 'component' => 'Row', 'children' => $botoes ];
	$componentes[] = [ 'id' => 'corpo', 'component' => 'Column', 'children' => [ 'topo', 'botoes' ] ];
	$componentes[] = [ 'id' => 'root', 'component' => 'Card', 'child' => 'corpo' ];

	return [
		[ 'version' => DSI_A2UI_VERSAO, 'createSurface' => [ 'surfaceId' => $surface_id, 'catalogId' => DSI_A2UI_CATALOGO ] ],
		[ 'version' => DSI_A2UI_VERSAO, 'updateComponents' => [ 'surfaceId' => $surface_id, 'components' => $componentes ] ],
		[ 'version' => DSI_A2UI_VERSAO, 'updateDataModel' => [ 'surfaceId' => $surface_id, 'path' => '/', 'value' => [
			'titulo' => $titulo . ( $ano ? ' (' . (int) $ano . ')' : '' ),
			'nota'   => $nota,
			'frase'  => $frase,
			'poster' => $poster,
		] ] ],
	];
}

// Acao vinda do cartao (mensagem "action" da especificacao). Devolve
// [ nome, titulo ] validados ou null.
function dsi_a2ui_validar_acao( $acao ): ?array {
	if ( ! is_array( $acao ) ) {
		return null;
	}
	$nome = (string) ( $acao['name'] ?? '' );
	if ( ! in_array( $nome, DSI_A2UI_ACOES, true ) ) {
		return null;
	}
	$titulo = trim( (string) ( ( (array) ( $acao['context'] ?? [] ) )['titulo'] ?? '' ) );
	if ( $titulo === '' || mb_strlen( $titulo ) > 120 ) {
		return null;
	}
	return [ $nome, $titulo ];
}

// A resposta em texto leva o link da critica; no cartao quem leva o leitor a
// ela e o botao, entao a frase sai sem endereco (e sem o "no link ..." que
// ficaria pendurado).
function dsi_a2ui_frase_sem_links( string $frase ): string {
	// Tira o endereco e o dois-pontos/travessao que o apresentava ("critica: https://...").
	$sem = preg_replace( '#\s*[:—–]?\s*https?://\S+#u', '', $frase );
	$sem = preg_replace( '/\s+([,.;:!?])/u', '$1', (string) $sem );
	$sem = preg_replace( '/\s{2,}/u', ' ', (string) $sem );
	$sem = trim( (string) $sem, " \t\n\r—–-:," );
	return $sem === '' ? trim( $frase ) : $sem;
}
