<?php
define( 'WP_CACHE', true );
//Begin Really Simple SSL key
define('RSSSL_KEY', 'put-your-unique-key-here');
//END Really Simple SSL key


//Begin Really Simple SSL session cookie settings
@ini_set('session.cookie_httponly', true);
@ini_set('session.cookie_secure', true);
@ini_set('session.use_only_cookies', true);
//END Really Simple SSL

/**
 * As configurações básicas do WordPress
 *
 * Copie este arquivo para wp-config.php e preencha os valores reais.
 * wp-config.php nunca é versionado (ver .gitignore).
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 * @package WordPress
 */

// ** Configurações do MySQL ** //
define( 'DB_NAME', 'database_name_here' );
define( 'DB_USER', 'username_here' );
define( 'DB_PASSWORD', 'password_here' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

/**#@+
 * Chaves únicas de autenticação e salts.
 * Gere valores reais em https://api.wordpress.org/secret-key/1.1/salt/
 */
define( 'AUTH_KEY',         'put-your-unique-phrase-here' );
define( 'SECURE_AUTH_KEY',  'put-your-unique-phrase-here' );
define( 'LOGGED_IN_KEY',    'put-your-unique-phrase-here' );
define( 'NONCE_KEY',        'put-your-unique-phrase-here' );
define( 'AUTH_SALT',        'put-your-unique-phrase-here' );
define( 'SECURE_AUTH_SALT', 'put-your-unique-phrase-here' );
define( 'LOGGED_IN_SALT',   'put-your-unique-phrase-here' );
define( 'NONCE_SALT',       'put-your-unique-phrase-here' );
/**#@-*/

$table_prefix = 'UYVqiIqPf_';

define( 'WP_DEBUG', false );

define( 'WP_HOME', 'https://deveserisso.com.br' );
define( 'WP_SITEURL', 'https://deveserisso.com.br/blog' );

// FilmBox — chaves de API (nunca expor no codigo do plugin)
define( 'FILMBOX_TMDB_KEY', 'your-tmdb-key-here' );
define( 'FILMBOX_OMDB_KEY', 'your-omdb-key-here' );

// Deveserisso WebMCP — chave MailerLite (nunca expor no codigo do plugin)
define( 'DSI_MAILERLITE_KEY', 'your-mailerlite-key-here' );

/* Isto é tudo, pode parar de editar! :) */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
