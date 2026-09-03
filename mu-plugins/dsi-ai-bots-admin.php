<?php
/**
 * Plugin Name: DSI — Painel de bots de IA
 * Description: Mostra em Ferramentas → Bots de IA quem está pedindo a versão Markdown do site.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dsi_agentmd_admin_menu' );

function dsi_agentmd_admin_menu(): void {
	add_management_page(
		'Bots de IA',
		'Bots de IA',
		'manage_options',
		'dsi-ai-bots',
		'dsi_agentmd_admin_page'
	);
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

	$detalhe = $wpdb->get_results(
		"SELECT requested_at, bot_label, post_id, url_path, client_ip
		 FROM {$table}
		 ORDER BY requested_at DESC
		 LIMIT 50"
	);

	echo '<div class="wrap"><h1>Bots de IA — acessos ao Markdown</h1>';

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

	echo '<h2 style="margin-top:32px;">Últimas 50 requisições</h2>';
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
	echo '</tbody></table></div>';
}
