<?php
require __DIR__ . '/../../src/bootstrap.php';

require_super_admin();

$erreur = null;
$form = ['id' => 0, 'code' => '', 'nom' => ''];

if (is_post()) {
    csrf_check();
    $form = ['id' => (int)post('id'), 'code' => strtolower(post('code')), 'nom' => post('nom')];

    if (post('action') === 'delete') {
        try {
            db_exec('DELETE FROM academie WHERE id = ?', [$form['id']]);
            journal_action('ACADEMIE_DELETE', null, $form['code']);
            flash('success', 'Académie supprimée.');
        } catch (PDOException $ex) {
            flash('error', db_error_message($ex));
        }
        redirect('superadmin/academies.php');
    }

    if (!preg_match('/^ac-[a-z-]+$/', $form['code'])) {
        $erreur = 'Le code doit être de la forme « ac-nom » (ex : ac-lille).';
    } elseif ($form['nom'] === '') {
        $erreur = 'Le nom est obligatoire.';
    } else {
        try {
            if ($form['id']) {
                db_exec('UPDATE academie SET code = ?, nom = ? WHERE id = ?', [$form['code'], $form['nom'], $form['id']]);
                journal_action('ACADEMIE_UPDATE', null, $form['code']);
            } else {
                db_exec('INSERT INTO academie (code, nom) VALUES (?, ?)', [$form['code'], $form['nom']]);
                journal_action('ACADEMIE_CREATE', null, $form['code']);
            }
            flash('success', 'Académie enregistrée.');
            redirect('superadmin/academies.php');
        } catch (PDOException $ex) {
            $erreur = db_error_message($ex);
        }
    }
} elseif ($editId = get_int('edit')) {
    $form = db_one('SELECT id, code, nom FROM academie WHERE id = ?', [$editId]) ?? $form;
}

$academies = db_all(
    'SELECT a.*, COUNT(e.id) AS nb_etab,
            (SELECT COUNT(*) FROM utilisateur u JOIN etablissement e2 ON e2.id = u.etablissement_id
              WHERE e2.academie_id = a.id) AS nb_users
       FROM academie a LEFT JOIN etablissement e ON e.academie_id = a.id
      GROUP BY a.id ORDER BY a.nom'
);

render_header('Académies');
?>
<h1>Académies</h1>

<div class="grid-2 grid-wide-left">
    <section class="card">
        <div class="table-wrap">
        <table>
            <thead><tr><th>Code</th><th>Nom</th><th class="num">Établissements</th><th class="num">Utilisateurs</th><th class="actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($academies as $a): ?>
                <tr>
                    <td class="mono"><?= e($a['code']) ?></td>
                    <td><?= e($a['nom']) ?></td>
                    <td class="num"><a href="<?= e(url('superadmin/etablissements.php?academie=' . $a['id'])) ?>"><?= (int)$a['nb_etab'] ?></a></td>
                    <td class="num"><?= (int)$a['nb_users'] ?></td>
                    <td class="actions">
                        <a class="btn btn-sm" href="?edit=<?= (int)$a['id'] ?>">Modifier</a>
                        <?php if (!$a['nb_etab']): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Supprimer cette académie ?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="code" value="<?= e($a['code']) ?>">
                            <button name="action" value="delete" class="btn btn-sm btn-danger">Supprimer</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card">
        <h2><?= $form['id'] ? 'Modifier l\'académie' : 'Ajouter une académie' ?></h2>
        <?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <label>Code * <input type="text" name="code" value="<?= e($form['code']) ?>" required pattern="ac-[a-z\-]+" placeholder="ac-lille" class="mono"></label>
            <label>Nom * <input type="text" name="nom" value="<?= e($form['nom']) ?>" required maxlength="255" placeholder="Lille"></label>
            <button type="submit" class="btn btn-primary btn-block">Enregistrer</button>
            <?php if ($form['id']): ?><a class="btn btn-ghost btn-block" href="?">Annuler</a><?php endif; ?>
        </form>
    </section>
</div>
<?php render_footer();
