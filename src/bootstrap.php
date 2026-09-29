<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

require APP_ROOT . '/src/helpers.php';
require APP_ROOT . '/src/db.php';
require APP_ROOT . '/src/uai.php';
require APP_ROOT . '/src/auth.php';
require APP_ROOT . '/src/journal.php';
require APP_ROOT . '/src/users.php';
require APP_ROOT . '/src/layout.php';
require APP_ROOT . '/src/acces_page.php';

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CERTASESSID');
    session_start();

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
