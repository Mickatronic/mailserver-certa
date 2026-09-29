<?php
require __DIR__ . '/../../src/bootstrap.php';

$admin = require_etab_admin();
$etabId = (int)$admin['etablissement_id'];
$etab = etablissement($etabId);

$id = get_int('id');
$user = $id ? user_dans_etab($id, $etabId) : null;
if ($id && !$user) {
    flash('error', 'Utilisateur introuvable.');
    redirect('admin/utilisateurs.php');
}
if ($user && in_array(ROLE_ADMIN, $user['roles'], true)) {
    flash('error', 'Les comptes administrateurs sont gérés par le super administrateur.');
    redirect('admin/utilisateurs.php');
}

$data = [
    'prenom' => $user['prenom'] ?? '',
    'nom'    => $user['nom'] ?? '',
    'email_personnel' => $user['email_personnel'] ?? '',
    'classe' => $user['classe'] ?? '',
    'annee_bts' => $user['annee_bts'] ?? '',
    'role'   => $user ? (in_array(ROLE_TEACHER, $user['roles'], true) ? ROLE_TEACHER : ROLE_STUDENT) : ROLE_STUDENT,
    'actif'  => $user ? (bool)$user['actif'] : true,
];
$erreur = null;

if (is_post()) {
    csrf_check();
    $data = [
        'prenom' => post('prenom'),
        'nom'    => post('nom'),
        'email_personnel' => post('email_personnel'),
        'classe' => post('classe'),
        'annee_bts' => post('annee_bts'),
        'role'   => post('role'),
        'actif'  => isset($_POST['actif']),
    ];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($user) {
            user_update($user, $etab, $data['prenom'], $data['nom'], $data['email_personnel'], $data['role'], $data['actif'], $data['classe'], $data['annee_bts']);
            journal_action('USER_UPDATE', $etabId, $data['prenom'] . ' ' . $data['nom'] . ' <' . $user['email'] . '>');
            $pdo->commit();
            flash('success', 'Utilisateur mis à jour.');
        } else {
            $c = user_create($etab, $data['prenom'], $data['nom'], $data['email_personnel'], $data['role'], false, [], $data['classe'], $data['annee_bts']);
            if (!$data['actif']) {
                db_exec('UPDATE utilisateur SET actif = 0 WHERE id = ?', [$c['id']]);
            }
            journal_action('USER_CREATE', $etabId, $data['prenom'] . ' ' . $data['nom'] . ' <' . $c['email'] . '>');
            $pdo->commit();
            flash('success', "Utilisateur créé : {$c['email']} — mot de passe temporaire : {$c['password']}");
        }
        redirect('admin/utilisateurs.php');
    } catch (ValidationException $ex) {
        $pdo->rollBack();
        $erreur = $ex->getMessage();
    } catch (PDOException $ex) {
        $pdo->rollBack();
        db_log_exception($ex, $user ? 'Modification utilisateur' : 'Création utilisateur');
        $erreur = db_error_message($ex);
    }
}

render_header($user ? 'Modifier un utilisateur' : 'Nouvel utilisateur');
?>
<div class="page-head">
    <h1><?= $user ? 'Modifier ' . e($user['prenom'] . ' ' . $user['nom']) : 'Nouvel utilisateur' ?></h1>
    <a class="btn btn-ghost" href="<?= e(url('admin/utilisateurs.php')) ?>">← Retour</a>
</div>

<?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>

<form method="post" class="card narrow">
    <?= csrf_field() ?>
    <div class="row">
        <label>Prénom * <input type="text" name="prenom" value="<?= e($data['prenom']) ?>" required maxlength="100"></label>
        <label>Nom * <input type="text" name="nom" value="<?= e($data['nom']) ?>" required maxlength="100"></label>
    </div>
    <label>Email personnel <span class="muted" data-email-required><?= $data['role'] === ROLE_STUDENT ? '(facultatif)' : '*' ?></span>
        <input type="email" name="email_personnel" value="<?= e($data['email_personnel']) ?>" maxlength="255"
               <?= $data['role'] === ROLE_STUDENT ? '' : 'required' ?>
               placeholder="nom@exemple.fr">
        <small class="muted">Adresse de contact personnelle, obligatoire pour les enseignants. L'adresse de connexion @<?= e(uai_domaine($etab['uai'])) ?> sera générée automatiquement.</small>
    </label>
    <div data-etudiant-fields <?= $data['role'] === ROLE_STUDENT ? '' : 'hidden' ?>>
        <div class="row">
            <label>Nom de la classe <span class="muted" data-classe-required>*</span>
                <input type="text" name="classe" value="<?= e($data['classe']) ?>" maxlength="100"
                       <?= $data['role'] === ROLE_STUDENT ? 'required' : '' ?>>
            </label>
            <label>Année de passage du BTS <span class="muted" data-annee-required>*</span>
                <input type="number" name="annee_bts" value="<?= e($data['annee_bts']) ?>" min="1900" max="2200" step="1"
                       <?= $data['role'] === ROLE_STUDENT ? 'required' : '' ?>>
            </label>
        </div>
    </div>
    <fieldset class="radios">
        <legend>Rôle *</legend>
        <label><input type="radio" name="role" value="STUDENT" <?= $data['role'] === ROLE_STUDENT ? 'checked' : '' ?>> Étudiant</label>
        <label><input type="radio" name="role" value="TEACHER" <?= $data['role'] === ROLE_TEACHER ? 'checked' : '' ?>> Enseignant</label>
    </fieldset>
    <label class="checkbox"><input type="checkbox" name="actif" value="1" <?= $data['actif'] ? 'checked' : '' ?>> Compte actif</label>

    <?php if (!$user): ?>
        <p class="muted small">Un mot de passe temporaire sera généré et affiché une seule fois. L'utilisateur devra le changer à sa première connexion.</p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $user ? 'Enregistrer' : 'Créer' ?></button>
    </div>
</form>
<script>
document.querySelectorAll('input[name="role"]').forEach((radio) => {
    radio.addEventListener('change', () => {
        const email = document.querySelector('input[name="email_personnel"]');
        const indication = document.querySelector('[data-email-required]');
        const estEtudiant = radio.value === 'STUDENT' && radio.checked;
        const champsEtudiant = document.querySelector('[data-etudiant-fields]');
        const classe = document.querySelector('input[name="classe"]');
        const annee = document.querySelector('input[name="annee_bts"]');
        email.required = radio.value !== 'STUDENT' && radio.checked;
        if (radio.checked) {
            champsEtudiant.hidden = !estEtudiant;
            classe.required = estEtudiant;
            annee.required = estEtudiant;
        }
        if (radio.checked) indication.textContent = email.required ? '*' : '(facultatif)';
    });
});
</script>
<?php render_footer();
