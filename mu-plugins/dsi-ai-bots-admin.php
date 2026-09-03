<?php
/**
 * Plugin Name: DSI — Painel de bots de IA
 * Description: Mostra em Ferramentas → Bots de IA quem está pedindo a versão Markdown do site.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dsi_agentmd_admin_menu' );
add_action( 'admin_post_dsi_agentmd_export_csv', 'dsi_agentmd_export_csv' );

function dsi_agentmd_admin_menu(): void {
	add_management_page(
		'Bots de IA',
		'Bots de IA',
		'manage_options',
		'dsi-ai-bots',
		'dsi_agentmd_admin_page'
	);
}

function dsi_agentmd_export_csv(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sem permissão.' );
	}
	check_admin_referer( 'dsi_agentmd_export_csv' );

	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';
	$rows  = $wpdb->get_results(
		"SELECT requested_at, bot_label, post_id, url_path, user_agent, client_ip
		 FROM {$table}
		 ORDER BY requested_at DESC"
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="ai-bot-requests-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'data', 'bot', 'post_id', 'post_titulo', 'url', 'user_agent', 'ip' ] );

	foreach ( $rows as $row ) {
		$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '';
		fputcsv( $out, [
			$row->requested_at,
			$row->bot_label,
			$row->post_id,
			$post_title,
			$row->url_path,
			$row->user_agent,
			$row->client_ip,
		] );
	}

	fclose( $out );
	exit;
}

function dsi_agentmd_admin_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';

	$resumo = $wpdb->get_results(
		"SELECT bot_label, COUNT(*) AS total, MAX(requested_at) AS ultima_vez
		 FROM {$table}
		 WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
		 GROUP BY bot_label
		 ORDER BY total DESC"
	);

	$per_page     = 50;
	$paged        = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$offset       = ( $paged - 1 ) * $per_page;
	$total_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$total_paginas = (int) max( 1, ceil( $total_rows / $per_page ) );

	$detalhe = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, bot_label, post_id, url_path, client_ip
			 FROM {$table}
			 ORDER BY requested_at DESC
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		)
	);

	$export_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=dsi_agentmd_export_csv' ),
		'dsi_agentmd_export_csv'
	);

	echo '<div class="wrap"><h1>Bots de IA — acessos ao Markdown</h1>';
	echo '<p><a href="' . esc_url( $export_url ) . '" class="button button-primary">Baixar CSV completo</a></p>';

	echo '<h2>Resumo (últimos 30 dias)</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Bot</th><th>Total</th><th>Última vez</th></tr></thead><tbody>';
	if ( $resumo ) {
		foreach ( $resumo as $row ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_html( $row->bot_label ),
				(int) $row->total,
				esc_html( $row->ultima_vez )
			);
		}
	} else {
		echo '<tr><td colspan="3">Nenhum acesso registrado ainda.</td></tr>';
	}
	echo '</tbody></table>';

	printf(
		'<h2 style="margin-top:32px;">Requisições — página %d de %d (%d no total)</h2>',
		$paged,
		$total_paginas,
		$total_rows
	);
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Bot</th><th>Post</th><th>URL</th><th>IP</th></tr></thead><tbody>';
	if ( $detalhe ) {
		foreach ( $detalhe as $row ) {
			$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '—';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row->requested_at ),
				esc_html( $row->bot_label ),
				esc_html( $post_title ),
				esc_html( $row->url_path ),
				esc_html( $row->client_ip )
			);
		}
	} else {
		echo '<tr><td colspan="5">Nenhuma requisição ainda.</td></tr>';
	}
	echo '</tbody></table>';

	dsi_agentmd_render_pagination( $paged, $total_paginas );

	echo '</div>';
}

function dsi_agentmd_render_pagination( int $paged, int $total_paginas ): void {
	if ( $total_paginas <= 1 ) {
		return;
	}

	$base_url = remove_query_arg( 'paged' );

	echo '<p class="tablenav-pages" style="margin-top:12px;">';

	if ( $paged > 1 ) {
		printf(
			'<a class="button" href="%s">&laquo; Anterior</a> ',
			esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) )
		);
	}

	if ( $paged < $total_paginas ) {
		printf(
			'<a class="button" href="%s">Próxima &raquo;</a>',
			esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) )
		);
	}

	echo '</p>';
}
