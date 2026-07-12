<?php
$blog = __DIR__ . '/blog';
$_SERVER['HTTP_HOST'] = 'deveserisso.com.br';
$_SERVER['REQUEST_URI'] = '/';
chdir($blog);
require_once($blog . '/wp-load.php');
if (class_exists('LiteSpeed\Purge')) {
    LiteSpeed\Purge::purge_all();
    echo 'LiteSpeed cache purged!';
} else {
    wp_cache_flush();
    echo 'WP cache flushed (LiteSpeed not found)';
}
echo ' Done at ' . date('H:i:s') . PHP_EOL;
