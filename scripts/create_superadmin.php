<?php
/**
 * Crée (ou réinitialise) un compte SUPER_ADMIN.
 * Usage : php scripts/create_superadmin.php email@exemple.fr Prénom Nom
 */
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI uniquement.');
}

[, $email, $prenom, $nom] = $argv + [null, null, null, null];
if (!$email || !$prenom || !$nom || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage : php scripts/create_superadmin.php email Prénom Nom\n");
    exit(1);
}

$email = strtolower($email);
$password = random_password(14);
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = db();
$pdo->beginTransaction();

$id = db_value('SELECT id FROM utilisateur WHERE email = ?', [$email]);
if ($id) {
    db_exec('UPDATE utilisateur SET password_hash = ?, doit_changer_mdp = 1, actif = 1 WHERE id = ?', [$hash, $id]);
    echo "Compte existant : mot de passe réinitialisé.\n";
} else {
    db_exec(
        'INSERT INTO utilisateur (prenom, nom, email, password_hash, doit_changer_mdp, etablissement_id)
         VALUES (?, ?, ?, ?, 1, NULL)',
        [$prenom, $nom, $email, $hash]
    );
    $id = $pdo->lastInsertId();
    echo "Compte créé.\n";
}
db_exec('INSERT IGNORE INTO utilisateur_role (utilisateur_id, role_id) VALUES (?, ?)', [$id, role_id(ROLE_SUPER_ADMIN)]);
$pdo->commit();

echo "Email : $email\nMot de passe temporaire : $password\n(à changer à la première connexion)\n";
