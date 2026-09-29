<?php
require __DIR__ . '/../../src/bootstrap.php';

require_super_admin();

$id = get_int('id');
$etab = etablissement($id);
if (!$etab) {
    flash('error', 'Établissement introuvable.');
    redirect('superadmin/etablissements.php');
}
$self = 'superadmin/etablissement_admins.php?id=' . $id;
$form = ['prenom' => '', 'nom' => '', 'email_personnel' => ''];
$erreur = null;

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $form = ['prenom' => post('prenom'), 'nom' => post('nom'), 'email_personnel' => post('email_personnel')];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $c = user_create($etab, $form['prenom'], $form['nom'], $form['email_personnel'], ROLE_TEACHER, true);
            journal_action('ADMIN_ASSIGN', $id, $c['email'], 'Nouveau compte administrateur');
            $pdo->commit();
            flash('success', "Administrateur créé : {$c['email']} — mot de passe temporaire : {$c['password']}");
            redirect($self);
        } catch (ValidationException $ex) {
            $pdo->rollBack();
            $erreur = $ex->getMessage();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $erreur = db_error_message($ex);
        }
    } else {
        $user = user_dans_etab((int)post('user_id'), $id);
        if (!$user) {
            flash('error', 'Utilisateur introuvable dans cet établissement.');
            redirect($self);
        }
        $cible = $user['prenom'] . ' ' . $user['nom'] . ' <' . $user['email'] . '>';

        switch ($action) {
            case 'update_email':
                try {
                    $emailPersonnel = valider_email_personnel(
                        post('email_personnel'),
                        in_array(ROLE_ADMIN, $user['roles'], true)
                    );
                    db_exec('UPDATE utilisateur SET email_personnel = ? WHERE id = ?', [$emailPersonnel, $user['id']]);
                    journal_action('USER_EMAIL_UPDATE', $id, $cible);
                    flash('success', 'Adresse email personnelle mise à jour.');
                } catch (ValidationException $ex) {
                    flash('error', $ex->getMessage());
                }
                break;

            case 'promote':
                if (!in_array(ROLE_TEACHER, $user['roles'], true)) {
                    flash('error', 'Seul un enseignant peut être administrateur.');
                    break;
                }
                try {
                    user_set_admin((int)$user['id'], true);
                } catch (ValidationException $ex) {
                    flash('error', $ex->getMessage());
                    break;
                }
                journal_action('ADMIN_ASSIGN', $id, $cible);
                flash('success', "{$user['prenom']} {$user['nom']} est maintenant administrateur.");
                break;

            case 'demote':
                user_set_admin((int)$user['id'], false);
                journal_action('ADMIN_REVOKE', $id, $cible);
                flash('success', "{$user['prenom']} {$user['nom']} n'est plus administrateur.");
                if (nb_admins_etab($id) === 0) {
                    flash('warning', 'Attention : cet établissement n\'a plus aucun administrateur actif.');
                }
                break;

            case 'reset':
                $pwd = user_reset_password((int)$user['id']);
                journal_action('USER_RESET_PWD', $id, $cible);
                flash('success', "Nouveau mot de passe temporaire pour {$user['email']} : $pwd");
                break;
        }
        redirect($self);
    }
}

$enseignants = db_all(
    'SELECT u.id, u.prenom, u.nom, u.email, u.email_personnel, u.actif, u.derniere_connexion_at,
            MAX(ur.role_id = :admin) AS is_admin
       FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
      WHERE u.etablissement_id = :etab
        AND u.id IN (SELECT utilisateur_id FROM utilisateur_role WHERE role_id = :teacher)
      GROUP BY u.id
      ORDER BY is_admin DESC, u.nom, u.prenom',
    ['admin' => role_id(ROLE_ADMIN), 'etab' => $id, 'teacher' => role_id(ROLE_TEACHER)]
);
$nbEtudiants = (int)db_value(
    'SELECT COUNT(*) FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
      WHERE u.etablissement_id = ? AND ur.role_id = ?',
    [$id, role_id(ROLE_STUDENT)]
);

render_header('Administrateurs · ' . $etab['nom']);
?>
<div class="page-head">
    <div>
        <h1><?= e($etab['nom']) ?></h1>
        <p class="muted">
            <span class="mono"><?= e($etab['uai']) ?></span> · <?= e($etab['academie_nom']) ?> (<?= e($etab['academie_code']) ?>)
            · <?= e(trim($etab['adresse_ligne1'] . ' ' . $etab['code_postal'] . ' ' . $etab['ville'])) ?>
            · domaine <code><?= e(uai_domaine($etab['uai'])) ?></code>
        </p>
    </div>
    <div>
        <a class="btn" href="<?= e(url('superadmin/etablissement_form.php?id=' . $id)) ?>">Modifier</a>
        <a class="btn" href="<?= e(url('superadmin/acces.php?etab=' . $id)) ?>">Accès</a>
        <a class="btn btn-ghost" href="<?= e(url('superadmin/etablissements.php')) ?>">← Liste</a>
    </div>
</div>

<?php if (!$etab['actif']): ?>
    <div class="alert alert-warning">Cet établissement est désactivé : aucun de ses utilisateurs ne peut se connecter.</div>
<?php endif; ?>

<div class="stats">
    <?= stat_card('Administrateurs', count(array_filter($enseignants, fn($t) => $t['is_admin']))) ?>
    <?= stat_card('Enseignants', count($enseignants)) ?>
    <?= stat_card('Étudiants', $nbEtudiants) ?>
</div>

<div class="grid-2 grid-wide-left">
    <section class="card">
        <h2>Enseignants et administrateurs</h2>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Nom</th><th>Email de connexion</th><th>Email personnel</th><th>Dernière connexion</th><th>Rôle</th><th class="actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($enseignants as $t): ?>
                <tr class="<?= $t['actif'] ? '' : 'row-disabled' ?>">
                    <td><?= e($t['nom'] . ' ' . $t['prenom']) ?></td>
                    <td><?= e($t['email']) ?></td>
                    <td>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <input type="email" name="email_personnel" value="<?= e($t['email_personnel']) ?>" maxlength="255"
                                   placeholder="nom@exemple.fr" <?= $t['is_admin'] ? 'required' : '' ?>>
                            <button name="action" value="update_email" class="btn btn-sm">Enregistrer</button>
                        </form>
                    </td>
                    <td><?= e(fmt_date($t['derniere_connexion_at'])) ?></td>
                    <td><?= $t['is_admin'] ? role_badges([ROLE_ADMIN]) : role_badges([ROLE_TEACHER]) ?></td>
                    <td class="actions">
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
                            <?php if ($t['is_admin']): ?>
                                <button name="action" value="demote" class="btn btn-sm btn-ghost">Retirer admin</button>
                                <button name="action" value="reset" class="btn btn-sm" onclick="return confirm('Générer un nouveau mot de passe temporaire ?')">Réinit. mdp</button>
                            <?php else: ?>
                                <button name="action" value="promote" class="btn btn-sm btn-primary">Nommer admin</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$enseignants): ?><tr><td colspan="6" class="empty">Aucun enseignant : créez le premier administrateur ci-contre.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card">
        <h2>Créer un administrateur</h2>
        <?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label>Prénom * <input type="text" name="prenom" value="<?= e($form['prenom']) ?>" required maxlength="100"></label>
            <label>Nom * <input type="text" name="nom" value="<?= e($form['nom']) ?>" required maxlength="100"></label>
            <label>Email personnel *
                <input type="email" name="email_personnel" value="<?= e($form['email_personnel']) ?>" maxlength="255" required
                       placeholder="nom@exemple.fr">
                <small class="muted">L'adresse de connexion @<?= e(uai_domaine($etab['uai'])) ?> sera générée automatiquement.</small>
            </label>
            <button type="submit" class="btn btn-primary btn-block">Créer l'administrateur</button>
        </form>
    </section>
</div>
<?php render_footer();
