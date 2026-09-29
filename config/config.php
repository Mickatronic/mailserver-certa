<?php
// Copier ce fichier en config.local.php pour surcharger les valeurs sans les versionner.

$config = [
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=reseau_certa;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],

    // Domaine des adresses : prenom.nom@<uai>.reseaucerta.org
    'mail_domain' => 'reseaucerta.org',

    // URL de base de l'application (sans slash final), ex : '/certa/public'
    'base_url' => '',

    // Vérifie la lettre-clé du code UAI (7 chiffres mod 23).
    'uai_verifier_cle' => true,

    // Anti brute-force : nb d'échecs max sur la fenêtre (minutes) par email ou IP
    'login_max_echecs' => 5,
    'login_fenetre_min' => 15,

    // Taille max du fichier d'import (octets)
    'import_max_size' => 2 * 1024 * 1024,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
