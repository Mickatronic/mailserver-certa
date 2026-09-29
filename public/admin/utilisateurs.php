<?php
require __DIR__ . '/../../src/bootstrap.php';

$admin = require_etab_admin();
$etabId = (int)$admin['etablissement_id'];

// ---------- Actions POST sur un utilisateur ----------
if (is_post()) {
    csrf_check();
    $back = 'admin/utilisateurs.php' . qs([]);
    if (post('action') === 'delete_selected') {
        $submittedIds = $_POST['user_ids'] ?? [];
        if (!is_array($submittedIds) || count($submittedIds) > 30) {
            flash('error', 'Sélection invalide : choisissez au maximum les utilisateurs affichés sur cette page.');
            redirect('admin/utilisateurs.php');
        }
        $ids = [];
        foreach ($submittedIds as $value) {
            if (!is_string($value) || !ctype_digit($value) || (int)$value < 1) {
                flash('error', 'La sélection contient un identifiant invalide.');
                redirect('admin/utilisateurs.php');
            }
            $ids[] = (int)$value;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            flash('error', 'Sélectionnez au moins un utilisateur à supprimer.');
            redirect('admin/utilisateurs.php');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $cibles = db_all(
                "SELECT u.id, u.prenom, u.nom FROM utilisateur u
                  WHERE u.etablissement_id = ? AND u.id IN ($placeholders)
                    AND NOT EXISTS (
                        SELECT 1 FROM utilisateur_role ur
                         WHERE ur.utilisateur_id = u.id AND ur.role_id = ?
                    )",
                [$etabId, ...$ids, role_id(ROLE_ADMIN)]
            );
            $idsSupprimables = array_map('intval', array_column($cibles, 'id'));
            if ($idsSupprimables) {
                $deletePlaceholders = implode(',', array_fill(0, count($idsSupprimables), '?'));
                db_exec(
                    "DELETE FROM utilisateur WHERE etablissement_id = ? AND id IN ($deletePlaceholders)",
                    [$etabId, ...$idsSupprimables]
                );
                journal_action(
                    'USER_BULK_DELETE',
                    $etabId,
                    count($idsSupprimables) . ' utilisateur(s)',
                    implode(', ', array_map(fn($u) => $u['prenom'] . ' ' . $u['nom'], $cibles))
                );
            }
            $pdo->commit();
            $proteges = count($ids) - count($idsSupprimables);
            flash('success', count($idsSupprimables) . ' utilisateur(s) supprimé(s).' . ($proteges ? " $proteges compte(s) administrateur ou hors établissement ignoré(s)." : ''));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        redirect($back);
    }

    $user = user_dans_etab((int)post('user_id'), $etabId);

    if (!$user) {
        flash('error', 'Utilisateur introuvable.');
        redirect($back);
    }
    // Les comptes administrateurs sont gérés par le super admin
    if (in_array(ROLE_ADMIN, $user['roles'], true)) {
        flash('error', 'Les comptes administrateurs sont gérés par le super administrateur.');
        redirect($back);
    }
    $cible = $user['prenom'] . ' ' . $user['nom'] . ' <' . $user['email'] . '>';

    switch (post('action')) {
        case 'toggle':
            db_exec('UPDATE utilisateur SET actif = NOT actif WHERE id = ?', [$user['id']]);
            journal_action($user['actif'] ? 'USER_DISABLE' : 'USER_ENABLE', $etabId, $cible);
            flash('success', $user['actif'] ? 'Compte désactivé.' : 'Compte réactivé.');
            break;

        case 'reset':
            $pwd = user_reset_password((int)$user['id']);
            journal_action('USER_RESET_PWD', $etabId, $cible);
            flash('success', "Nouveau mot de passe temporaire pour {$user['email']} : $pwd");
            break;

        case 'delete':
            db_exec('DELETE FROM utilisateur WHERE id = ?', [$user['id']]);
            journal_action('USER_DELETE', $etabId, $cible);
            flash('success', "Utilisateur {$user['email']} supprimé.");
            break;
    }
    redirect($back);
}

// ---------- Liste ----------
[$limit, $offset, $page] = paginate(30);
$where = ['u.etablissement_id = ?'];
$p = [$etabId];

if (($q = get('q')) !== '') {
    $where[] = '(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR u.email_personnel LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($p, $like, $like, $like, $like);
}
$role = get('role');
if (in_array($role, [ROLE_STUDENT, ROLE_TEACHER, ROLE_ADMIN], true)) {
    $where[] = 'EXISTS (SELECT 1 FROM utilisateur_role x WHERE x.utilisateur_id = u.id AND x.role_id = ?)';
    $p[] = role_id($role);
}
if (($classe = get('classe')) !== '') {
    $where[] = 'u.classe = ?';
    $p[] = $classe;
}
$anneeBts = get('annee_bts');
$erreurFiltre = null;
if ($anneeBts !== '') {
    if (!preg_match('/^\d{4}$/', $anneeBts) || (int)$anneeBts < 1900 || (int)$anneeBts > 2200) {
        $where[] = '1 = 0';
        $erreurFiltre = 'Saisissez une année BTS valide sur quatre chiffres (1900 à 2200).';
    } else {
        $where[] = 'u.annee_bts = ?';
        $p[] = (int)$anneeBts;
    }
}
match (get('statut')) {
    'actif'   => $where[] = 'u.actif = 1',
    'inactif' => $where[] = 'u.actif = 0',
    'jamais'  => $where[] = 'u.derniere_connexion_at IS NULL',
    default   => null,
};
$w = implode(' AND ', $where);

$total = (int)db_value("SELECT COUNT(*) FROM utilisateur u WHERE $w", $p);
$rows = db_all(
    "SELECT u.*, GROUP_CONCAT(r.code ORDER BY r.id) AS roles
       FROM utilisateur u
       LEFT JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
       LEFT JOIN role r ON r.id = ur.role_id
      WHERE $w
      GROUP BY u.id
      ORDER BY u.nom, u.prenom
      LIMIT $limit OFFSET $offset",
    $p
);
$classes = db_all(
    'SELECT DISTINCT classe FROM utilisateur WHERE etablissement_id = ? AND classe IS NOT NULL ORDER BY classe',
    [$etabId]
);

render_header('Utilisateurs');
?>
<div class="page-head">
    <h1>Utilisateurs <span class="count"><?= $total ?></span></h1>
    <div>
        <a class="btn btn-primary" href="<?= e(url('admin/utilisateur_form.php')) ?>">+ Nouvel utilisateur</a>
        <a class="btn" href="<?= e(url('admin/import.php')) ?>">Importer un fichier</a>
    </div>
</div>

<form class="filters card" method="get">
    <input type="search" name="q" value="<?= e(get('q')) ?>" placeholder="Nom, prénom ou email…">
    <select name="role">
        <option value="">Tous les rôles</option>
        <option value="STUDENT" <?= $role === 'STUDENT' ? 'selected' : '' ?>>Étudiants</option>
        <option value="TEACHER" <?= $role === 'TEACHER' ? 'selected' : '' ?>>Enseignants</option>
        <option value="ADMIN" <?= $role === 'ADMIN' ? 'selected' : '' ?>>Administrateurs</option>
    </select>
    <select name="statut">
        <option value="">Tous statuts</option>
        <option value="actif" <?= get('statut') === 'actif' ? 'selected' : '' ?>>Actifs</option>
        <option value="inactif" <?= get('statut') === 'inactif' ? 'selected' : '' ?>>Désactivés</option>
        <option value="jamais" <?= get('statut') === 'jamais' ? 'selected' : '' ?>>Jamais connectés</option>
    </select>
    <select name="classe">
        <option value="">Toutes les classes</option>
        <?php foreach ($classes as $c): ?>
            <option value="<?= e($c['classe']) ?>" <?= get('classe') === $c['classe'] ? 'selected' : '' ?>><?= e($c['classe']) ?></option>
        <?php endforeach; ?>
    </select>
    <label>Année BTS
        <input type="number" name="annee_bts" value="<?= e(get('annee_bts')) ?>" min="1900" max="2200" step="1" placeholder="2027">
    </label>
    <button class="btn">Filtrer</button>
    <a class="btn btn-ghost" href="?">Réinitialiser</a>
</form>

<div class="table-wrap card">
<table>
    <thead>
    <tr><th><input type="checkbox" aria-label="Sélectionner les utilisateurs supprimables de cette page" data-select-all></th><th>Nom</th><th>Prénom</th><th>Email de connexion</th><th>Email personnel</th><th>Classe</th><th>Année BTS</th><th>Rôle(s)</th><th>Dernière connexion</th><th>Statut</th><th class="actions">Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $isAdmin = in_array(ROLE_ADMIN, explode(',', (string)$r['roles']), true); ?>
        <tr class="<?= $r['actif'] ? '' : 'row-disabled' ?>">
            <td><?php if (!$isAdmin): ?><input type="checkbox" name="user_ids[]" value="<?= (int)$r['id'] ?>" form="bulk-delete-form" data-user-checkbox aria-label="Sélectionner <?= e($r['prenom'] . ' ' . $r['nom']) ?>"><?php endif; ?></td>
            <td><?= e($r['nom']) ?></td>
            <td><?= e($r['prenom']) ?></td>
            <td><?= e($r['email']) ?></td>
            <td><?= e($r['email_personnel'] ?: '—') ?></td>
            <td><?= e($r['classe'] ?: '—') ?></td>
            <td><?= e($r['annee_bts'] ?: '—') ?></td>
            <td><?= role_badges((string)$r['roles']) ?></td>
            <td><?= $r['derniere_connexion_at'] ? e(fmt_date($r['derniere_connexion_at'])) : '<span class="muted">Jamais</span>' ?></td>
            <td><span class="pill <?= $r['actif'] ? 'pill-ok' : 'pill-off' ?>"><?= $r['actif'] ? 'Actif' : 'Désactivé' ?></span></td>
            <td class="actions">
                <?php if ($isAdmin): ?>
                    <span class="muted small">Géré par le super admin</span>
                <?php else: ?>
                    <a class="btn btn-sm" href="<?= e(url('admin/utilisateur_form.php?id=' . $r['id'])) ?>">Modifier</a>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                        <button name="action" value="toggle" class="btn btn-sm btn-ghost"><?= $r['actif'] ? 'Désactiver' : 'Activer' ?></button>
                        <button name="action" value="reset" class="btn btn-sm btn-ghost"
                                onclick="return confirm('Générer un nouveau mot de passe temporaire ?')">Réinit. mdp</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger"
                                onclick="return confirm('Supprimer définitivement ce compte ?')">Supprimer</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="empty">Aucun utilisateur.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($erreurFiltre): ?><div class="alert alert-error"><?= e($erreurFiltre) ?></div><?php endif; ?>
<form method="post" id="bulk-delete-form" class="card form-actions"
      onsubmit="return confirm('Supprimer définitivement les utilisateurs sélectionnés ? Cette action est irréversible.')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_selected">
    <span data-selection-count class="muted">Aucun utilisateur sélectionné.</span>
    <button type="submit" class="btn btn-danger">Supprimer la sélection</button>
</form>
<script>
const selectAll = document.querySelector('[data-select-all]');
const userCheckboxes = [...document.querySelectorAll('[data-user-checkbox]')];
const selectionCount = document.querySelector('[data-selection-count]');
const updateSelectionCount = () => {
    const count = userCheckboxes.filter((checkbox) => checkbox.checked).length;
    selectionCount.textContent = count ? `${count} utilisateur(s) sélectionné(s).` : 'Aucun utilisateur sélectionné.';
};
selectAll?.addEventListener('change', () => {
    userCheckboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
    updateSelectionCount();
});
userCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelectionCount));
</script>
<?= pagination($total, $limit, $page) ?>
<?php render_footer();
