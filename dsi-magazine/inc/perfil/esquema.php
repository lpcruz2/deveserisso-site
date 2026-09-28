<?php
/**
 * Perfil de gosto -- esquema das tabelas (docs/erd-perfil-de-gosto.md).
 *
 * SQL comum, sem dbDelta nem nada do WordPress: as tabelas precisam poder ir
 * pra outro banco se o site sair do WP (premissa 4). Dois dialetos: mysql
 * (producao) e sqlite (testes). Sem chave estrangeira no banco: a exclusao
 * em cascata e feita pelo repositorio, dentro de transacao, igual nos dois.
 * Tudo idempotente (IF NOT EXISTS), pode rodar de novo sem erro.
 */

const DSI_PERFIL_ESQUEMA_VERSAO = '1.0';

/** Colunas e indices de cada tabela. {id} e {big} dependem do dialeto. */
function dsi_perfil_esquema_tabelas(): array {
	return [
		'conta' => [
			'colunas' => [
				'id {id}',
				'google_sub VARCHAR(64) NOT NULL',
				'email VARCHAR(190) NOT NULL',
				"nome_exibicao VARCHAR(190) NOT NULL DEFAULT ''",
				'apelido VARCHAR(40) NULL',
				"visibilidade VARCHAR(10) NOT NULL DEFAULT 'privado'",
				'link_social VARCHAR(300) NULL',
				"origem_cadastro VARCHAR(20) NOT NULL DEFAULT 'site'",
				'criado_em DATETIME NOT NULL',
				'ultimo_acesso_em DATETIME NOT NULL',
				'aviso_retencao_em DATETIME NULL',
				'UNIQUE (google_sub)',
				'UNIQUE (apelido)',
			],
			'indices' => [ 'acesso' => 'ultimo_acesso_em' ],
		],
		'consentimento' => [
			'colunas' => [
				'id {id}',
				'conta_id {big} NOT NULL',
				'tipo VARCHAR(40) NOT NULL',
				'versao VARCHAR(20) NOT NULL',
				'aceito_em DATETIME NOT NULL',
				'revogado_em DATETIME NULL',
			],
			'indices' => [ 'conta' => 'conta_id, tipo' ],
		],
		'marcacao' => [
			'colunas' => [
				'id {id}',
				'conta_id {big} NOT NULL',
				'tmdb_id {big} NOT NULL',
				'tipo VARCHAR(5) NOT NULL',
				'visto VARCHAR(10) NULL',
				'avaliacao VARCHAR(10) NULL',
				"canal VARCHAR(20) NOT NULL DEFAULT 'site'",
				'criado_em DATETIME NOT NULL',
				'atualizado_em DATETIME NOT NULL',
				'UNIQUE (conta_id, tmdb_id, tipo)',
			],
			'indices' => [],
		],
		'preferencias' => [
			'colunas' => [
				'conta_id {big} NOT NULL PRIMARY KEY',
				'plataformas TEXT NOT NULL',
				'atualizado_em DATETIME NOT NULL',
			],
			'indices' => [],
		],
		'resenha' => [
			'colunas' => [
				'id {id}',
				'conta_id {big} NOT NULL',
				'tmdb_id {big} NOT NULL',
				'tipo VARCHAR(5) NOT NULL',
				'versao_publicada_id {big} NULL',
				"canal VARCHAR(20) NOT NULL DEFAULT 'site'",
				'criada_em DATETIME NOT NULL',
				'UNIQUE (conta_id, tmdb_id, tipo)',
			],
			'indices' => [ 'titulo' => 'tmdb_id, tipo, versao_publicada_id' ],
		],
		'resenha_versao' => [
			'colunas' => [
				'id {id}',
				'resenha_id {big} NOT NULL',
				'texto TEXT NOT NULL',
				'link_social VARCHAR(300) NOT NULL',
				'rede VARCHAR(20) NOT NULL',
				'handle VARCHAR(80) NOT NULL',
				'spoiler TINYINT NOT NULL DEFAULT 0',
				'status VARCHAR(12) NOT NULL',
				'motivo_recusa VARCHAR(20) NULL',
				'sinais_filtro VARCHAR(100) NULL',
				"canal VARCHAR(20) NOT NULL DEFAULT 'site'",
				'enviada_em DATETIME NOT NULL',
				'decidida_em DATETIME NULL',
			],
			'indices' => [ 'resenha' => 'resenha_id, status', 'fila' => 'status, enviada_em' ],
		],
		// Etapa 1: critica publicada -> titulo no TMDB. Tabela de ligacao (e nao
		// metadado de post) pra ir junto numa migracao pra fora do WordPress.
		'titulo_post' => [
			'colunas' => [
				'post_id {big} NOT NULL PRIMARY KEY',
				'tmdb_id {big} NOT NULL',
				'tipo VARCHAR(5) NOT NULL',
				'fonte VARCHAR(20) NOT NULL',
				'atualizado_em DATETIME NOT NULL',
			],
			'indices' => [ 'tmdb' => 'tmdb_id, tipo' ],
		],
	];
}

/** @return string[] comandos, na ordem, idempotentes */
function dsi_perfil_esquema_sql( string $prefixo, string $dialeto = 'mysql' ): array {
	$mysql = $dialeto === 'mysql';
	$troca = [
		'{id}'  => $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
		'{big}' => $mysql ? 'BIGINT UNSIGNED' : 'INTEGER',
	];
	$sql = [];
	foreach ( dsi_perfil_esquema_tabelas() as $nome => $def ) {
		$tabela  = $prefixo . $nome;
		$colunas = array_map( fn( $c ) => strtr( $c, $troca ), $def['colunas'] );
		if ( $mysql ) {
			// MySQL nao tem CREATE INDEX IF NOT EXISTS: indice vai dentro da tabela.
			foreach ( $def['indices'] as $idx => $cols ) {
				$colunas[] = "KEY {$idx} ({$cols})";
			}
		}
		$sql[] = "CREATE TABLE IF NOT EXISTS {$tabela} (\n\t" . implode( ",\n\t", $colunas ) . "\n)"
			. ( $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '' );
		if ( ! $mysql ) {
			foreach ( $def['indices'] as $idx => $cols ) {
				$sql[] = "CREATE INDEX IF NOT EXISTS {$tabela}_{$idx} ON {$tabela} ({$cols})";
			}
		}
	}
	return $sql;
}

/** Nomes das tabelas, pra exclusao em testes e migracao. */
function dsi_perfil_tabelas( string $prefixo ): array {
	return array_map( fn( $t ) => $prefixo . $t, array_keys( dsi_perfil_esquema_tabelas() ) );
}
