<?php
// Copier ce fichier en config.local.php pour surcharger les valeurs sans les versionner.

$config = [
    'db' => [
        'dsn'  => sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            (int)(getenv('DB_PORT') ?: 3306),
            getenv('DB_NAME') ?: 'reseau_certa'
        ),
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASSWORD') ?: '',
    ],

    // Domaine des adresses : prenom.nom@<uai>.reseaucerta.org
    'mail_domain' => getenv('MAIL_DOMAIN') ?: 'reseaucerta.org',

    // URL de base de l'application (sans slash final), ex : '/certa/public'
    'base_url' => getenv('BASE_URL') ?: '',

    // Vérifie la lettre-clé du code UAI (7 chiffres mod 23).
    'uai_verifier_cle' => filter_var(getenv('UAI_VERIFY_KEY') ?: 'true', FILTER_VALIDATE_BOOL),

    // Anti brute-force : nb d'échecs max sur la fenêtre (minutes) par email ou IP
    'login_max_echecs' => 5,
    'login_fenetre_min' => 15,

    // Taille max du fichier d'import (octets)
    'import_max_size' => (int)(getenv('IMPORT_MAX_SIZE') ?: 2 * 1024 * 1024),
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
