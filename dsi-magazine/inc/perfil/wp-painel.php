<?php
/**
 * Perfil de gosto -- painel de acompanhamento de uso (wp-admin > Resenhas >
 * Uso do perfil). So agregados: nada de e-mail, nome ou texto de resenha.
 * Fontes: tabelas do perfil (contas, marcacoes, resenhas) e o contador de
 * chamadas do /mcp/conta (opcao `dsi_mcp_conta_uso_<prefixo>`, gravada por
 * mcp-conta.php). Visitas ao site vindas do MCP: GA4, utm_source=mcp_conta.
 */

add_action( 'admin_menu', function (): void {
	add_submenu_page( 'dsi-resenhas', 'Uso do perfil', 'Uso do perfil', 'manage_options', 'dsi-perfil-uso', 'dsi_perfil_uso_pagina' );
}, 20 );

function dsi_perfil_uso_pagina(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$dias = 30;
	try {
		$m = dsi_perfil_repo()->metricas( $dias );
	} catch ( Throwable $e ) {
		error_log( '[dsi-perfil] painel: ' . $e->getMessage() );
		echo '<div class="wrap"><h1>Uso do perfil</h1><p>Não consegui ler as métricas agora.</p></div>';
		return;
	}
	$uso = get_option( 'dsi_mcp_conta_uso_' . dsi_perfil_prefixo(), [] );
	$uso = is_array( $uso ) ? array_slice( $uso, -$dias, null, true ) : [];

	$rotulo_origem = [ 'mcp_claude' => 'Claude', 'mcp_chatgpt' => 'ChatGPT', 'mcp_outro' => 'Outro MCP', 'site' => 'Site', 'newsletter' => 'Newsletter', 'curador' => 'Curador', 'cinequiz' => 'CineQuiz' ];
	$rot = fn( $k ) => $rotulo_origem[ $k ] ?? (string) $k;

	echo '<div class="wrap"><h1>Uso do perfil</h1>';
	echo '<p>Ambiente: <code>' . esc_html( dsi_perfil_prefixo() ) . '</code>. Só números agregados, sem dados de pessoas. Últimos ' . (int) $dias . ' dias, em UTC.</p>';

	// Cartões
	$cartao = function ( string $titulo, $valor, string $nota = '' ): void {
		echo '<div style="flex:1 1 170px;background:#fff;border:1px solid #c3c4c7;padding:12px 16px">';
		echo '<div style="font-size:12px;color:#50575e">' . esc_html( $titulo ) . '</div>';
		echo '<div style="font-size:28px;font-weight:600;line-height:1.2">' . esc_html( (string) $valor ) . '</div>';
		if ( $nota !== '' ) {
			echo '<div style="font-size:12px;color:#50575e">' . esc_html( $nota ) . '</div>';
		}
		echo '</div>';
	};
	echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0">';
	$cartao( 'Contas', $m['contas_total'], '+' . array_sum( $m['contas_por_dia'] ) . ' nos ' . $dias . ' dias' );
	$cartao( 'Ativas (7 dias)', $m['ativas_7'], $m['ativas_30'] . ' em 30 dias' );
	$cartao( 'Marcações', $m['marcacoes_total'] );
	$cartao( 'Resenhas enviadas', $m['resenhas_total'], $m['fila_pendentes'] . ' esperando aprovação' );
	$cartao( 'Tempo médio de aprovação', $m['tempo_fila_horas'] === null ? '—' : $m['tempo_fila_horas'] . ' h', 'das resenhas já decididas' );
	echo '</div>';

	// Funil
	$f = $m['funil'];
	echo '<h2>Funil</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>Etapa</th><th>Contas</th><th>% das contas</th></tr></thead><tbody>';
	foreach ( [ 'Conta criada' => $f['contas'], 'Marcou 1 título ou mais' => $f['com_1'], 'Marcou 5 ou mais' => $f['com_5'], 'Curtiu ou não curtiu algum' => $f['com_avaliacao'], 'Enviou resenha' => $f['com_resenha'] ] as $etapa => $n ) {
		$pc = $f['contas'] ? round( 100 * $n / $f['contas'] ) : 0;
		echo '<tr><td>' . esc_html( $etapa ) . '</td><td>' . (int) $n . '</td><td>' . (int) $pc . '%</td></tr>';
	}
	echo '</tbody></table>';

	// Por dia
	$dias_lista = [];
	for ( $i = $dias - 1; $i >= 0; $i-- ) {
		$dias_lista[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
	}
	$chamadas_dia = [];
	foreach ( $uso as $dia => $contagens ) {
		$chamadas_dia[ $dia ] = array_sum( array_map( 'intval', (array) $contagens ) );
	}
	$max = max( 1, ...array_map( fn( $d ) => max( $m['contas_por_dia'][ $d ] ?? 0, $m['marcacoes_por_dia'][ $d ] ?? 0, $chamadas_dia[ $d ] ?? 0 ), $dias_lista ) );
	echo '<h2>Por dia</h2><table class="widefat striped"><thead><tr><th>Dia</th><th>Contas novas</th><th>Marcações</th><th>Chamadas ao MCP</th><th></th></tr></thead><tbody>';
	foreach ( array_reverse( $dias_lista ) as $d ) {
		$c = (int) ( $m['contas_por_dia'][ $d ] ?? 0 );
		$k = (int) ( $m['marcacoes_por_dia'][ $d ] ?? 0 );
		$h = (int) ( $chamadas_dia[ $d ] ?? 0 );
		if ( ! $c && ! $k && ! $h ) {
			continue;
		}
		echo '<tr><td>' . esc_html( $d ) . '</td><td>' . $c . '</td><td>' . $k . '</td><td>' . $h . '</td><td><div style="height:8px;background:#2271b1;width:' . (int) round( 100 * $h / $max ) . '%;min-width:' . ( $h ? 2 : 0 ) . 'px"></div></td></tr>';
	}
	echo '</tbody></table>';

	// Chamadas por tool e cliente
	$por_tool = [];
	foreach ( $uso as $contagens ) {
		foreach ( (array) $contagens as $chave => $n ) {
			[ $tool, $cliente ] = array_pad( explode( '|', (string) $chave, 2 ), 2, 'outro' );
			$cliente = in_array( $cliente, [ 'claude', 'chatgpt' ], true ) ? $cliente : 'outro';
			$por_tool[ $tool ][ $cliente ] = ( $por_tool[ $tool ][ $cliente ] ?? 0 ) + (int) $n;
		}
	}
	ksort( $por_tool );
	echo '<h2>Chamadas por ferramenta</h2>';
	if ( ! $por_tool ) {
		echo '<p>Nenhuma chamada registrada ainda.</p>';
	} else {
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>Ferramenta</th><th>Claude</th><th>ChatGPT</th><th>Outro</th></tr></thead><tbody>';
		foreach ( $por_tool as $tool => $c ) {
			echo '<tr><td><code>' . esc_html( $tool ) . '</code></td><td>' . (int) ( $c['claude'] ?? 0 ) . '</td><td>' . (int) ( $c['chatgpt'] ?? 0 ) . '</td><td>' . (int) ( $c['outro'] ?? 0 ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">"Outro" inclui testes por script. Cada clique nos botões da tela conta como uma chamada.</p>';
	}

	// O que as pessoas marcam
	$rot_marca = [ 'quero_ver' => 'Quero ver', 'ja_vi' => 'Já vi', 'curti' => 'Curti', 'nao_curti' => 'Não curti' ];
	echo '<h2>O que foi marcado</h2><table class="widefat striped" style="max-width:640px"><tbody>';
	foreach ( $m['marcacoes_por_tipo'] as $k => $n ) {
		echo '<tr><td>' . esc_html( $rot_marca[ $k ] ?? $k ) . '</td><td>' . (int) $n . '</td></tr>';
	}
	echo '</tbody></table>';

	// Origem
	echo '<h2>De onde vêm as contas e as marcações</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>Canal</th><th>Contas</th><th>Marcações</th></tr></thead><tbody>';
	foreach ( array_unique( array_merge( array_keys( $m['contas_por_origem'] ), array_keys( $m['marcacoes_por_canal'] ) ) ) as $o ) {
		echo '<tr><td>' . esc_html( $rot( $o ) ) . '</td><td>' . (int) ( $m['contas_por_origem'][ $o ] ?? 0 ) . '</td><td>' . (int) ( $m['marcacoes_por_canal'][ $o ] ?? 0 ) . '</td></tr>';
	}
	echo '</tbody></table>';

	// Resenhas
	$rot_status = [ 'pendente' => 'Esperando aprovação', 'aprovada' => 'Aprovadas', 'recusada' => 'Recusadas', 'substituida' => 'Substituídas por edição' ];
	echo '<h2>Resenhas</h2><table class="widefat striped" style="max-width:640px"><tbody>';
	foreach ( $m['resenhas_por_status'] as $k => $n ) {
		echo '<tr><td>' . esc_html( $rot_status[ $k ] ?? $k ) . '</td><td>' . (int) $n . '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<h2>Visitas ao site vindas do MCP</h2><p>Os links dos cards levam <code>utm_source=mcp_conta&amp;utm_medium=card&amp;utm_campaign=perfil</code>. No GA4: Relatórios → Aquisição → Aquisição de tráfego, filtrando a origem <code>mcp_conta</code>. Esse número não aparece aqui.</p>';
	echo '</div>';
}
