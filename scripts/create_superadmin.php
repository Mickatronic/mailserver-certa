<?php
/**
 * Crée (ou réinitialise) un compte SUPER_ADMIN.
 * Usage : php scripts/create_superadmin.php [email@exemple.fr Prénom Nom]
 * Sans arguments, les variables SUPERADMIN_EMAIL, SUPERADMIN_FIRST_NAME,
 * SUPERADMIN_LAST_NAME et SUPERADMIN_PASSWORD sont utilisées.
 * Avec --ensure, provisionne le compte sans réinitialiser son mot de passe à
 * chaque démarrage du conteneur.
 */
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit('CLI uniquement.');
}

$ensure = in_array('--ensure', $argv, true);
[, $email, $prenom, $nom] = $argv + [null, null, null, null];
$email = $ensure ? null : $email;
$prenom = $ensure ? null : $prenom;
$nom = $ensure ? null : $nom;
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
} elseif ($ensure) {
    fwrite(STDERR, "SUPERADMIN_PASSWORD est obligatoire pour le provisionnement automatique.\n");
    exit(1);
} else {
    $password = random_password(14);
}
$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo = db();
if ($ensure) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS superadmin_bootstrap (
            email VARCHAR(255) NOT NULL PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
$pdo->beginTransaction();

try {
    $id = db_value('SELECT id FROM utilisateur WHERE email = ?', [$email]);
    if ($id) {
        $alreadyProvisioned = $ensure
            && db_value('SELECT 1 FROM superadmin_bootstrap WHERE email = ?', [$email]);
        $currentHash = db_value('SELECT password_hash FROM utilisateur WHERE id = ?', [$id]);
        if ($ensure && $alreadyProvisioned) {
            echo "Compte existant conservé.\n";
        } elseif (!$ensure || !$currentHash || !password_verify($password, $currentHash)) {
            db_exec(
                'UPDATE utilisateur
                    SET prenom = ?, nom = ?, password_hash = ?, doit_changer_mdp = 1, actif = 1, etablissement_id = NULL
                  WHERE id = ?',
                [$prenom, $nom, $hash, $id]
            );
            echo $ensure
                ? "Compte existant synchronisé avec les variables d'environnement.\n"
                : "Compte existant : mot de passe réinitialisé.\n";
        } else {
            db_exec(
                'UPDATE utilisateur SET prenom = ?, nom = ?, actif = 1, etablissement_id = NULL WHERE id = ?',
                [$prenom, $nom, $id]
            );
            echo "Compte existant conservé.\n";
        }
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
    if ($ensure) {
        db_exec('INSERT IGNORE INTO superadmin_bootstrap (email) VALUES (?)', [$email]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo "Email : $email\n";
if ($ensure || $passwordFromEnvironment) {
    echo "Mot de passe défini depuis SUPERADMIN_PASSWORD.\n";
} else {
    echo "Mot de passe temporaire : $password\n(à changer à la première connexion)\n";
}
