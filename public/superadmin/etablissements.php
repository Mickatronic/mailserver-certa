<?php
require __DIR__ . '/../../src/bootstrap.php';

require_super_admin();

// ---------- Actions POST : activer/désactiver, supprimer ----------
if (is_post()) {
    csrf_check();
    $id = (int)post('id');
    $etab = etablissement($id);
    if (!$etab) {
        flash('error', 'Établissement introuvable.');
        redirect('superadmin/etablissements.php');
    }

    switch (post('action')) {
        case 'toggle':
            db_exec('UPDATE etablissement SET actif = NOT actif WHERE id = ?', [$id]);
            journal_action($etab['actif'] ? 'ETAB_DISABLE' : 'ETAB_ENABLE', $id, $etab['uai'] . ' ' . $etab['nom']);
            flash('success', $etab['actif']
                ? "« {$etab['nom']} » est désactivé : ses utilisateurs ne peuvent plus se connecter."
                : "« {$etab['nom']} » est réactivé.");
            break;

        case 'delete':
            $nb = (int)db_value('SELECT COUNT(*) FROM utilisateur WHERE etablissement_id = ?', [$id]);
            if ($nb > 0) {
                flash('error', "Suppression impossible : $nb utilisateur(s) rattaché(s). Désactivez plutôt l'établissement.");
                break;
            }
            db_exec('DELETE FROM etablissement WHERE id = ?', [$id]);
            journal_action('ETAB_DELETE', null, $etab['uai'] . ' ' . $etab['nom']);
            flash('success', "Établissement « {$etab['nom']} » supprimé.");
            break;
    }
    redirect('superadmin/etablissements.php' . qs([]));
}

// ---------- Liste filtrée ----------
[$limit, $offset, $page] = paginate(25);
$where = ['1 = 1'];
$p = [];

if (($q = get('q')) !== '') {
    $where[] = '(e.nom LIKE ? OR e.uai LIKE ? OR e.ville LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($p, $like, $like, $like);
}
if ($acad = get_int('academie')) {
    $where[] = 'e.academie_id = ?';
    $p[] = $acad;
}
if (get('statut') === 'actif') {
    $where[] = 'e.actif = 1';
} elseif (get('statut') === 'inactif') {
    $where[] = 'e.actif = 0';
}
$w = implode(' AND ', $where);

$total = (int)db_value("SELECT COUNT(*) FROM etablissement e WHERE $w", $p);
$rows = db_all(
    "SELECT e.*, a.code AS academie_code,
            (SELECT COUNT(*) FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
              WHERE u.etablissement_id = e.id AND ur.role_id = ?) AS nb_etudiants,
            (SELECT COUNT(*) FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
              WHERE u.etablissement_id = e.id AND ur.role_id = ?) AS nb_enseignants,
            (SELECT GROUP_CONCAT(CONCAT(u.prenom, ' ', u.nom) SEPARATOR ', ')
               FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
              WHERE u.etablissement_id = e.id AND ur.role_id = ? AND u.actif = 1) AS admins
       FROM etablissement e JOIN academie a ON a.id = e.academie_id
      WHERE $w
      ORDER BY e.nom
      LIMIT $limit OFFSET $offset",
    array_merge([role_id(ROLE_STUDENT), role_id(ROLE_TEACHER), role_id(ROLE_ADMIN)], $p)
);

$academies = db_all('SELECT id, code, nom FROM academie ORDER BY nom');

render_header('Établissements');
?>
<div class="page-head">
    <h1>Établissements</h1>
    <a class="btn btn-primary" href="<?= e(url('superadmin/etablissement_form.php')) ?>">+ Nouvel établissement</a>
</div>

<form class="filters card" method="get">
    <input type="search" name="q" value="<?= e(get('q')) ?>" placeholder="Nom, UAI ou ville…">
    <select name="academie">
        <option value="">Toutes les académies</option>
        <?php foreach ($academies as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $acad === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="statut">
        <option value="">Tous statuts</option>
        <option value="actif" <?= get('statut') === 'actif' ? 'selected' : '' ?>>Actifs</option>
        <option value="inactif" <?= get('statut') === 'inactif' ? 'selected' : '' ?>>Désactivés</option>
    </select>
    <button class="btn">Filtrer</button>
    <a class="btn btn-ghost" href="?">Réinitialiser</a>
</form>

<div class="table-wrap card">
<table>
    <thead>
    <tr>
        <th>UAI</th><th>Nom</th><th>Académie</th><th>Ville</th>
        <th class="num">Étudiants</th><th class="num">Enseignants</th><th>Administrateur(s)</th>
        <th>Statut</th><th class="actions">Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['actif'] ? '' : 'row-disabled' ?>">
            <td class="mono"><?= e($r['uai']) ?></td>
            <td><?= e($r['nom']) ?><br><small class="muted"><?= e(match ($r['type_etablissement']) {
                'PRIVE_SOUS_CONTRAT' => 'Privé sous contrat',
                'PRIVE' => 'Privé',
                default => 'Public',
            }) ?></small></td>
            <td><?= e($r['academie_code']) ?></td>
            <td><?= e($r['ville']) ?></td>
            <td class="num"><?= (int)$r['nb_etudiants'] ?></td>
            <td class="num"><?= (int)$r['nb_enseignants'] ?></td>
            <td><?= $r['admins'] ? e($r['admins']) : '<span class="pill pill-ko">Aucun</span>' ?></td>
            <td><span class="pill <?= $r['actif'] ? 'pill-ok' : 'pill-off' ?>"><?= $r['actif'] ? 'Actif' : 'Désactivé' ?></span></td>
            <td class="actions">
                <a class="btn btn-sm" href="<?= e(url('superadmin/etablissement_form.php?id=' . $r['id'])) ?>">Modifier</a>
                <a class="btn btn-sm" href="<?= e(url('superadmin/etablissement_admins.php?id=' . $r['id'])) ?>">Admins</a>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button name="action" value="toggle" class="btn btn-sm btn-ghost"><?= $r['actif'] ? 'Désactiver' : 'Activer' ?></button>
                </form>
                <?php if ((int)$r['nb_etudiants'] + (int)$r['nb_enseignants'] === 0): ?>
                <form method="post" class="inline" onsubmit="return confirm('Supprimer définitivement cet établissement ?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button name="action" value="delete" class="btn btn-sm btn-danger">Supprimer</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Aucun établissement.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= pagination($total, $limit, $page) ?>
<?php render_footer();
