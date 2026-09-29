<?php
require __DIR__ . '/../../src/bootstrap.php';

require_super_admin();

$id = get_int('id');
$etab = $id ? etablissement($id) : null;
if ($id && !$etab) {
    flash('error', 'Établissement introuvable.');
    redirect('superadmin/etablissements.php');
}

$nbUsers = $etab ? (int)db_value('SELECT COUNT(*) FROM utilisateur WHERE etablissement_id = ?', [$id]) : 0;
$academies = db_all('SELECT id, code, nom FROM academie ORDER BY nom');

$champs = ['uai', 'nom', 'type_etablissement', 'adresse_ligne1', 'adresse_ligne2', 'code_postal', 'ville', 'pays', 'academie_id'];
$data = $etab ? array_intersect_key($etab, array_flip($champs)) : array_fill_keys($champs, '');
$data['type_etablissement'] = $data['type_etablissement'] ?: 'PUBLIC';
$data['pays'] = $data['pays'] ?: 'France';
$admin = ['prenom' => '', 'nom' => '', 'email_personnel' => ''];
$erreurs = [];

if (is_post()) {
    csrf_check();
    foreach ($champs as $c) {
        $data[$c] = post($c);
    }
    $data['uai'] = uai_normaliser($data['uai']);
    $data['academie_id'] = (int)$data['academie_id'];

    // --- Validation ---
    if ($err = uai_erreur($data['uai'])) {
        $erreurs[] = $err;
    }
    if ($etab && $nbUsers > 0 && $data['uai'] !== $etab['uai']) {
        $erreurs[] = 'Le code UAI ne peut plus être modifié : il est utilisé dans les adresses email des utilisateurs.';
    }
    if ($data['nom'] === '' || mb_strlen($data['nom']) > 255) {
        $erreurs[] = 'Le nom de l\'établissement est obligatoire.';
    }
    if (!in_array($data['type_etablissement'], ['PUBLIC', 'PRIVE_SOUS_CONTRAT', 'PRIVE'], true)) {
        $erreurs[] = 'Veuillez choisir un type d’établissement valide.';
    }
    if (!in_array($data['academie_id'], array_map('intval', array_column($academies, 'id')), true)) {
        $erreurs[] = 'Veuillez choisir une académie.';
    }
    if ($data['code_postal'] !== '' && !preg_match('/^(\d{5}|97\d{3}|98\d{3})$/', $data['code_postal'])) {
        $erreurs[] = 'Code postal invalide (5 chiffres).';
    }
    if ($data['adresse_ligne1'] === '' || $data['ville'] === '') {
        $erreurs[] = 'L\'adresse (ligne 1) et la ville sont obligatoires.';
    }
    if (db_value('SELECT 1 FROM etablissement WHERE uai = ? AND id <> ?', [$data['uai'], $id])) {
        $erreurs[] = "Un établissement avec l'UAI {$data['uai']} existe déjà.";
    }

    // Premier administrateur (optionnel, uniquement à la création)
    if (!$etab) {
        $admin = ['prenom' => post('admin_prenom'), 'nom' => post('admin_nom'), 'email_personnel' => post('admin_email_personnel')];
        $avecAdmin = $admin['prenom'] !== '' || $admin['nom'] !== '' || $admin['email_personnel'] !== '';
    }

    if (!$erreurs) {
        $params = [
            $data['uai'], $data['nom'], $data['type_etablissement'], $data['adresse_ligne1'], $data['adresse_ligne2'] ?: null,
            $data['code_postal'] ?: null, $data['ville'], $data['pays'] ?: 'France', $data['academie_id'],
        ];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($etab) {
                db_exec(
                    'UPDATE etablissement SET uai = ?, nom = ?, type_etablissement = ?, adresse_ligne1 = ?, adresse_ligne2 = ?,
                            code_postal = ?, ville = ?, pays = ?, academie_id = ? WHERE id = ?',
                    [...$params, $id]
                );
                journal_action('ETAB_UPDATE', $id, $data['uai'] . ' ' . $data['nom']);
                $pdo->commit();
                flash('success', 'Établissement mis à jour.');
                redirect('superadmin/etablissements.php');
            }

            db_exec(
                'INSERT INTO etablissement (uai, nom, type_etablissement, adresse_ligne1, adresse_ligne2, code_postal, ville, pays, academie_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
            $newId = (int)$pdo->lastInsertId();
            journal_action('ETAB_CREATE', $newId, $data['uai'] . ' ' . $data['nom']);

            $msg = "Établissement « {$data['nom']} » créé.";
            if ($avecAdmin) {
                $created = user_create(etablissement($newId), $admin['prenom'], $admin['nom'], $admin['email_personnel'], ROLE_TEACHER, true);
                journal_action('ADMIN_ASSIGN', $newId, $created['email'], 'Création du premier administrateur');
                $msg .= " Administrateur : {$created['email']} — mot de passe temporaire : {$created['password']}";
            }
            $pdo->commit();
            flash('success', $msg);
            redirect('superadmin/etablissement_admins.php?id=' . $newId);
        } catch (ValidationException $ex) {
            $pdo->rollBack();
            $erreurs[] = 'Administrateur : ' . $ex->getMessage();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $erreurs[] = db_error_message($ex);
        }
    }
}

$domaine = uai_format_valide((string)$data['uai']) ? uai_domaine((string)$data['uai']) : 'UAI.' . config('mail_domain');

render_header($etab ? 'Modifier un établissement' : 'Nouvel établissement');
?>
<div class="page-head">
    <h1><?= $etab ? 'Modifier « ' . e($etab['nom']) . ' »' : 'Nouvel établissement' ?></h1>
    <a class="btn btn-ghost" href="<?= e(url('superadmin/etablissements.php')) ?>">← Retour</a>
</div>

<?php foreach ($erreurs as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" class="card form-grid">
    <?= csrf_field() ?>

    <fieldset>
        <legend>Identification</legend>
        <label>Code UAI *
            <input type="text" name="uai" value="<?= e($data['uai']) ?>" required maxlength="8"
                   pattern="[0-9]{7}[A-Za-z]" placeholder="0592222X" class="mono"
                   <?= $etab && $nbUsers > 0 ? 'readonly' : '' ?>>
            <small class="muted">7 chiffres + lettre-clé. Sert de domaine email : <code><?= e($domaine) ?></code>
                <?= $etab && $nbUsers > 0 ? '<br>Non modifiable : ' . $nbUsers . ' utilisateur(s) rattaché(s).' : '' ?></small>
        </label>
        <label>Nom de l'établissement *
            <input type="text" name="nom" value="<?= e($data['nom']) ?>" required maxlength="255" placeholder="Lycée Gaston Berger">
        </label>
        <label>Type d'établissement *
            <select name="type_etablissement" required>
                <option value="PUBLIC" <?= $data['type_etablissement'] === 'PUBLIC' ? 'selected' : '' ?>>Public</option>
                <option value="PRIVE_SOUS_CONTRAT" <?= $data['type_etablissement'] === 'PRIVE_SOUS_CONTRAT' ? 'selected' : '' ?>>Privé sous contrat</option>
                <option value="PRIVE" <?= $data['type_etablissement'] === 'PRIVE' ? 'selected' : '' ?>>Privé</option>
            </select>
        </label>
        <label>Académie *
            <select name="academie_id" required>
                <option value="">— Choisir —</option>
                <?php foreach ($academies as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$data['academie_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= e($a['nom']) ?> (<?= e($a['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </fieldset>

    <fieldset>
        <legend>Adresse</legend>
        <label>Adresse *
            <input type="text" name="adresse_ligne1" value="<?= e($data['adresse_ligne1']) ?>" required maxlength="255">
        </label>
        <label>Complément
            <input type="text" name="adresse_ligne2" value="<?= e($data['adresse_ligne2']) ?>" maxlength="255">
        </label>
        <div class="row">
            <label>Code postal
                <input type="text" name="code_postal" value="<?= e($data['code_postal']) ?>" maxlength="5" pattern="\d{5}">
            </label>
            <label>Ville *
                <input type="text" name="ville" value="<?= e($data['ville']) ?>" required maxlength="100">
            </label>
        </div>
        <label>Pays
            <input type="text" name="pays" value="<?= e($data['pays']) ?>" maxlength="100">
        </label>
    </fieldset>

    <?php if (!$etab): ?>
    <fieldset>
        <legend>Premier administrateur (optionnel)</legend>
        <p class="muted">Un compte enseignant avec le rôle administrateur sera créé. Son email personnel est obligatoire ; son adresse de connexion sera générée automatiquement (prenom.nom@UAI.<?= e(config('mail_domain')) ?>).</p>
        <div class="row">
            <label>Prénom <input type="text" name="admin_prenom" value="<?= e($admin['prenom']) ?>" maxlength="100" data-premier-admin></label>
            <label>Nom <input type="text" name="admin_nom" value="<?= e($admin['nom']) ?>" maxlength="100" data-premier-admin></label>
        </div>
        <label>Email personnel (obligatoire si le compte est créé) <input type="email" name="admin_email_personnel" value="<?= e($admin['email_personnel']) ?>" maxlength="255" data-premier-admin
                <?= $admin['prenom'] !== '' || $admin['nom'] !== '' || $admin['email_personnel'] !== '' ? 'required' : '' ?>></label>
    </fieldset>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $etab ? 'Enregistrer' : 'Créer l\'établissement' ?></button>
    </div>
</form>
<?php if (!$etab): ?>
<script>
const premierAdminFields = document.querySelectorAll('[data-premier-admin]');
const premierAdminEmail = document.querySelector('input[name="admin_email_personnel"]');
const verifierEmailPremierAdmin = () => {
    premierAdminEmail.required = [...premierAdminFields].some((field) => field.value.trim() !== '');
};
premierAdminFields.forEach((field) => field.addEventListener('input', verifierEmailPremierAdmin));
</script>
<?php endif; ?>
<?php render_footer();
