<?php
/**
 * Crée (ou réinitialise) un compte SUPER_ADMIN.
 * Usage : php scripts/create_superadmin.php [email@exemple.fr Prénom Nom]
 * Sans arguments, les variables SUPERADMIN_EMAIL, SUPERADMIN_FIRST_NAME,
 * SUPERADMIN_LAST_NAME et SUPERADMIN_PASSWORD sont utilisées.
 */
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI uniquement.');
}

[, $email, $prenom, $nom] = $argv + [null, null, null, null];
$email = $email ?: getenv('SUPERADMIN_EMAIL');
$prenom = $prenom ?: getenv('SUPERADMIN_FIRST_NAME');
$nom = $nom ?: getenv('SUPERADMIN_LAST_NAME');
$configuredPassword = getenv('SUPERADMIN_PASSWORD');
$passwordFromEnvironment = $configuredPassword !== false && $configuredPassword !== '';

if (!$email || !$prenom || !$nom || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage : php scripts/create_superadmin.php email Prénom Nom\n");
    fwrite(STDERR, "Ou définir SUPERADMIN_EMAIL, SUPERADMIN_FIRST_NAME, SUPERADMIN_LAST_NAME et SUPERADMIN_PASSWORD.\n");
    exit(1);
}

$email = strtolower($email);
if ($configuredPassword !== false && $configuredPassword !== '') {
    if (!password_is_strong($configuredPassword)) {
        fwrite(STDERR, "SUPERADMIN_PASSWORD doit faire au moins 10 caractères avec une minuscule, une majuscule et un chiffre.\n");
        exit(1);
    }
    $password = $configuredPassword;
} else {
    $password = random_password(14);
}
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = db();
$pdo->beginTransaction();

try {
    $id = db_value('SELECT id FROM utilisateur WHERE email = ?', [$email]);
    if ($id) {
        db_exec(
            'UPDATE utilisateur SET prenom = ?, nom = ?, password_hash = ?, doit_changer_mdp = 1, actif = 1, etablissement_id = NULL WHERE id = ?',
            [$prenom, $nom, $hash, $id]
        );
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
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo "Email : $email\n";
if ($passwordFromEnvironment) {
    echo "Mot de passe défini depuis SUPERADMIN_PASSWORD.\n";
} else {
    echo "Mot de passe temporaire : $password\n(à changer à la première connexion)\n";
}
