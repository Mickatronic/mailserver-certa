<?php
declare(strict_types=1);

/** Erreur de validation métier affichable telle quelle à l'utilisateur. */
class ValidationException extends RuntimeException
{
}

const ROLES_DE_BASE = [ROLE_STUDENT, ROLE_TEACHER];

function role_id(string $code): int
{
    static $cache = null;
    $cache ??= array_column(db_all('SELECT id, code FROM role'), 'id', 'code');
    if (!isset($cache[$code])) {
        throw new LogicException("Rôle inconnu : $code");
    }
    return (int)$cache[$code];
}

// ---------- Emails ----------

/** "Jean-Éric d'Artagnan" -> "jean-eric-dartagnan" */
function email_slug(string $s): string
{
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
    } else {
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'æ' => 'ae', 'œ' => 'oe',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Œ' => 'OE',
        ]);
    }
    $s = strtolower($s);
    $s = str_replace(["'", '’'], '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/** Génère prenom.nom@uai.reseaucerta.org en ajoutant un numéro si déjà pris. */
function email_generer(string $prenom, string $nom, string $uai, array $dejaReserves = [], ?int $saufId = null): string
{
    $base = email_slug($prenom) . '.' . email_slug($nom);
    $domaine = uai_domaine($uai);
    for ($i = 1; ; $i++) {
        $email = $base . ($i > 1 ? $i : '') . '@' . $domaine;
        if (!in_array($email, $dejaReserves, true) && !email_existe($email, $saufId)) {
            return $email;
        }
    }
}

function email_existe(string $email, ?int $saufId = null): bool
{
    return (bool)db_value(
        'SELECT 1 FROM utilisateur WHERE email = ? AND id <> ?',
        [strtolower($email), $saufId ?? 0]
    );
}

function valider_email_personnel(string $email, bool $obligatoire = false): ?string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        if ($obligatoire) {
            throw new ValidationException('Une adresse email personnelle est obligatoire pour un enseignant ou un administrateur.');
        }
        return null;
    }
    if (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ValidationException('Veuillez saisir une adresse email personnelle valide.');
    }
    return $email;
}

function valider_classe_et_annee(string $classe, string $anneeBts, string $role): array
{
    $classe = trim($classe);
    $anneeBts = trim($anneeBts);
    if ($role !== ROLE_STUDENT) {
        return ['classe' => null, 'annee_bts' => null];
    }
    if ($classe === '' || mb_strlen($classe) > 100) {
        throw new ValidationException('Le nom de la classe est obligatoire (100 caractères maximum).');
    }
    if (!preg_match('/^\d{4}$/', $anneeBts) || (int)$anneeBts < 1900 || (int)$anneeBts > 2200) {
        throw new ValidationException('L’année de passage du BTS est obligatoire et doit être une année valide à quatre chiffres.');
    }
    return ['classe' => $classe, 'annee_bts' => (int)$anneeBts];
}

// ---------- Validation ----------

function valider_nom(string $v, string $champ): string
{
    $v = preg_replace('/\s+/u', ' ', trim($v));
    if ($v === '' || mb_strlen($v) > 100) {
        throw new ValidationException("Le champ « $champ » est obligatoire (100 caractères max).");
    }
    if (!preg_match("/^\p{L}[\p{L}\s'’\-]*$/u", $v)) {
        throw new ValidationException("Le champ « $champ » contient des caractères non autorisés.");
    }
    return $v;
}

function valider_role_base(string $role): string
{
    $role = strtoupper(trim($role));
    $alias = ['ETUDIANT' => ROLE_STUDENT, 'ÉTUDIANT' => ROLE_STUDENT, 'ELEVE' => ROLE_STUDENT,
              'ENSEIGNANT' => ROLE_TEACHER, 'PROF' => ROLE_TEACHER, 'PROFESSEUR' => ROLE_TEACHER];
    $role = $alias[$role] ?? $role;
    if (!in_array($role, ROLES_DE_BASE, true)) {
        throw new ValidationException('Rôle invalide : STUDENT (étudiant) ou TEACHER (enseignant) attendu.');
    }
    return $role;
}

// ---------- Lecture ----------

function etablissement(int $id): ?array
{
    return db_one(
        'SELECT e.*, a.code AS academie_code, a.nom AS academie_nom
           FROM etablissement e JOIN academie a ON a.id = e.academie_id
          WHERE e.id = ?',
        [$id]
    );
}

/** Utilisateur appartenant à l'établissement donné (cloisonnement). */
function user_dans_etab(int $userId, int $etabId): ?array
{
    $u = db_one('SELECT * FROM utilisateur WHERE id = ? AND etablissement_id = ?', [$userId, $etabId]);
    if ($u) {
        $u['roles'] = user_roles((int)$u['id']);
    }
    return $u;
}

// ---------- Écriture ----------

/**
 * Crée un utilisateur. Retourne ['id', 'email', 'email_personnel', 'password'].
 * @throws ValidationException
 */
function user_create(
    array $etab,
    string $prenom,
    string $nom,
    string $emailPersonnel,
    string $roleBase,
    bool $admin = false,
    array $reserves = [],
    string $classe = '',
    string $anneeBts = ''
): array
{
    $prenom   = valider_nom($prenom, 'Prénom');
    $nom      = valider_nom($nom, 'Nom');
    $roleBase = valider_role_base($roleBase);
    $emailPersonnel = valider_email_personnel($emailPersonnel, $roleBase !== ROLE_STUDENT);
    $etudes = valider_classe_et_annee($classe, $anneeBts, $roleBase);

    if ($admin && $roleBase !== ROLE_TEACHER) {
        throw new ValidationException('Seul un enseignant peut être administrateur d\'établissement.');
    }

    $email = email_generer($prenom, $nom, $etab['uai'], $reserves);
    $password = random_password();

    db_exec(
        'INSERT INTO utilisateur (prenom, nom, email, email_personnel, classe, annee_bts, password_hash, doit_changer_mdp, etablissement_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
        [$prenom, $nom, $email, $emailPersonnel, $etudes['classe'], $etudes['annee_bts'], password_hash($password, PASSWORD_DEFAULT), $etab['id']]
    );
    $id = (int)db()->lastInsertId();

    db_exec('INSERT INTO utilisateur_role (utilisateur_id, role_id) VALUES (?, ?)', [$id, role_id($roleBase)]);
    if ($admin) {
        user_set_admin($id, true);
    }

    return ['id' => $id, 'email' => $email, 'email_personnel' => $emailPersonnel, 'password' => $password];
}

/** @throws ValidationException */
function user_update(
    array $user,
    array $etab,
    string $prenom,
    string $nom,
    string $emailPersonnel,
    string $roleBase,
    bool $actif,
    string $classe = '',
    string $anneeBts = ''
): void
{
    $prenom   = valider_nom($prenom, 'Prénom');
    $nom      = valider_nom($nom, 'Nom');
    $roleBase = valider_role_base($roleBase);
    $emailPersonnel = valider_email_personnel($emailPersonnel, $roleBase !== ROLE_STUDENT);
    $etudes = valider_classe_et_annee($classe, $anneeBts, $roleBase);
    $email = $prenom === $user['prenom'] && $nom === $user['nom']
        ? $user['email']
        : email_generer($prenom, $nom, $etab['uai'], [], (int)$user['id']);

    if ($roleBase === ROLE_STUDENT && in_array(ROLE_ADMIN, $user['roles'], true)) {
        throw new ValidationException('Un administrateur d\'établissement doit rester enseignant.');
    }

    db_exec(
        'UPDATE utilisateur SET prenom = ?, nom = ?, email = ?, email_personnel = ?, classe = ?, annee_bts = ?, actif = ? WHERE id = ?',
        [$prenom, $nom, $email, $emailPersonnel, $etudes['classe'], $etudes['annee_bts'], $actif ? 1 : 0, $user['id']]
    );
    user_set_role_base((int)$user['id'], $roleBase);
}

function user_set_role_base(int $userId, string $roleBase): void
{
    db_exec(
        'DELETE FROM utilisateur_role WHERE utilisateur_id = ? AND role_id IN (?, ?)',
        [$userId, role_id(ROLE_STUDENT), role_id(ROLE_TEACHER)]
    );
    db_exec('INSERT INTO utilisateur_role (utilisateur_id, role_id) VALUES (?, ?)', [$userId, role_id($roleBase)]);
}

function user_set_admin(int $userId, bool $admin): void
{
    if ($admin) {
        $emailPersonnel = db_value('SELECT email_personnel FROM utilisateur WHERE id = ?', [$userId]);
        valider_email_personnel((string)$emailPersonnel, true);
        db_exec('INSERT IGNORE INTO utilisateur_role (utilisateur_id, role_id) VALUES (?, ?)', [$userId, role_id(ROLE_ADMIN)]);
    } else {
        db_exec('DELETE FROM utilisateur_role WHERE utilisateur_id = ? AND role_id = ?', [$userId, role_id(ROLE_ADMIN)]);
    }
}

/** Réinitialise le mot de passe et retourne le nouveau mot de passe temporaire. */
function user_reset_password(int $userId): string
{
    $password = random_password();
    db_exec(
        'UPDATE utilisateur SET password_hash = ?, doit_changer_mdp = 1 WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $userId]
    );
    return $password;
}

function nb_admins_etab(int $etabId): int
{
    return (int)db_value(
        'SELECT COUNT(*) FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
          WHERE u.etablissement_id = ? AND ur.role_id = ? AND u.actif = 1',
        [$etabId, role_id(ROLE_ADMIN)]
    );
}

function role_badges(array|string|null $roles): string
{
    if (is_string($roles)) {
        $roles = $roles === '' ? [] : explode(',', $roles);
    }
    $out = '';
    foreach ($roles ?? [] as $r) {
        $out .= '<span class="badge badge-' . e(strtolower($r)) . '">' . e(ROLE_LIBELLES[$r] ?? $r) . '</span> ';
    }
    return $out;
}
