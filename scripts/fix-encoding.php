<?php
ignore_user_abort(true);

$db = new mysqli('127.0.0.1', 'u635223132_deveserisso', '@Vitrio1!', 'u635223132_wp_yzx11');
if ($db->connect_error) { die(json_encode(['error' => $db->connect_error])); }

$r = $db->query("SELECT COUNT(*) n FROM UYVqiIqPf_postmeta WHERE meta_key='_yoast_wpseo_title' AND meta_value LIKE '%Ã%'");
$before = $r->fetch_assoc()['n'];

$db->query("UPDATE UYVqiIqPf_postmeta SET meta_value = CONVERT(CAST(CONVERT(meta_value USING latin1) AS BINARY) USING utf8mb4) WHERE meta_key='_yoast_wpseo_title' AND meta_value LIKE '%Ã%'");

header('Content-Type: application/json');
echo json_encode(['corrigidos_antes' => $before], JSON_UNESCAPED_UNICODE);

if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}
