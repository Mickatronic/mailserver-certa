<?php
declare(strict_types=1);

const ROLE_STUDENT     = 'STUDENT';
const ROLE_TEACHER     = 'TEACHER';
const ROLE_ADMIN       = 'ADMIN';
const ROLE_SUPER_ADMIN = 'SUPER_ADMIN';

const ROLE_LIBELLES = [
    ROLE_STUDENT     => 'Étudiant',
    ROLE_TEACHER     => 'Enseignant',
    ROLE_ADMIN       => 'Admin établissement',
    ROLE_SUPER_ADMIN => 'Super admin',
];

/** Utilisateur connecté (rechargé à chaque requête pour refléter une désactivation). */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    $row = db_one(
        'SELECT u.*, e.uai, e.nom AS etablissement_nom, e.actif AS etablissement_actif
           FROM utilisateur u
           LEFT JOIN etablissement e ON e.id = u.etablissement_id
          WHERE u.id = ?',
        [$id]
    );
    if (!$row || !$row['actif'] || ($row['etablissement_id'] && !$row['etablissement_actif'])) {
        logout();
        return null;
    }
    $row['roles'] = user_roles((int)$row['id']);
    return $user = $row;
}

function user_roles(int $userId): array
{
    return array_column(db_all(
        'SELECT r.code FROM utilisateur_role ur JOIN role r ON r.id = ur.role_id WHERE ur.utilisateur_id = ?',
        [$userId]
    ), 'code');
}

function has_role(string $role, ?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && in_array($role, $user['roles'], true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('info', 'Veuillez vous connecter.');
        redirect('login.php');
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($u['doit_changer_mdp'] && !in_array($script, ['changer_mdp.php', 'logout.php'], true)) {
        redirect('changer_mdp.php');
    }
    return $u;
}

function require_role(string $role): array
{
    $u = require_login();
    if (!has_role($role, $u)) {
        http_response_code(403);
        render_header('Accès refusé');
        echo '<div class="alert alert-error">Vous n\'avez pas les droits nécessaires pour accéder à cette page.</div>';
        render_footer();
        exit;
    }
    return $u;
}

function require_super_admin(): array
{
    return require_role(ROLE_SUPER_ADMIN);
}

/** Admin d'établissement : doit avoir le rôle ADMIN ET un établissement. */
function require_etab_admin(): array
{
    $u = require_role(ROLE_ADMIN);
    if (!$u['etablissement_id']) {
        http_response_code(403);
        exit('Aucun établissement rattaché à ce compte.');
    }
    return $u;
}

function home_path(array $user): string
{
    return match (true) {
        has_role(ROLE_SUPER_ADMIN, $user) => 'superadmin/index.php',
        has_role(ROLE_ADMIN, $user)       => 'admin/index.php',
        default                           => 'espace.php',
    };
}

// ---------- Connexion ----------

function login_bloque(string $email, string $ip): bool
{
    $n = (int)db_value(
        'SELECT COUNT(*) FROM journal_connexion
          WHERE succes = 0
            AND (email_saisi = ? OR ip = ?)
            AND created_at > NOW() - INTERVAL ? MINUTE',
        [$email, $ip, (int)config('login_fenetre_min')]
    );
    return $n >= (int)config('login_max_echecs');
}

/**
 * Tente une connexion. Retourne l'utilisateur ou un message d'erreur (string).
 */
function attempt_login(string $email, string $password): array|string
{
    $email = strtolower(trim($email));
    $ip = client_ip();

    if (login_bloque($email, $ip)) {
        journal_connexion(null, null, $email, false, 'BLOQUE');
        return sprintf('Trop de tentatives. Réessayez dans %d minutes.', config('login_fenetre_min'));
    }

    $u = db_one(
        'SELECT u.*, e.actif AS etablissement_actif
           FROM utilisateur u LEFT JOIN etablissement e ON e.id = u.etablissement_id
          WHERE u.email = ?',
        [$email]
    );

    $generic = 'Identifiants incorrects.';

    if (!$u) {
        password_verify($password, password_hash('leurre', PASSWORD_DEFAULT)); // même durée qu'un vrai essai
        journal_connexion(null, null, $email, false, 'INCONNU');
        return $generic;
    }

    $uid = (int)$u['id'];
    $eid = $u['etablissement_id'] ? (int)$u['etablissement_id'] : null;

    if (!$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
        journal_connexion($uid, $eid, $email, false, 'MDP_INVALIDE');
        return $generic;
    }

    if (!$u['actif'] || ($eid && !$u['etablissement_actif'])) {
        journal_connexion($uid, $eid, $email, false, 'INACTIF');
        return 'Ce compte est désactivé. Contactez l\'administrateur de votre établissement.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db_exec('UPDATE utilisateur SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $uid]);
        }
        user_mail_sync($uid, $password);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $uid;
    unset($_SESSION['csrf']);

    db_exec('UPDATE utilisateur SET derniere_connexion_at = NOW() WHERE id = ?', [$uid]);
    journal_connexion($uid, $eid, $email, true, 'OK');

    return $u;
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
