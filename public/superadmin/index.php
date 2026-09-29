<?php
require __DIR__ . '/../../src/bootstrap.php';

require_super_admin();

$nbEtab      = (int)db_value('SELECT COUNT(*) FROM etablissement WHERE actif = 1');
$nbAcademies = (int)db_value('SELECT COUNT(DISTINCT academie_id) FROM etablissement');
$parRole = array_column(db_all(
    'SELECT r.code, COUNT(*) AS n FROM utilisateur_role ur
       JOIN role r ON r.id = ur.role_id JOIN utilisateur u ON u.id = ur.utilisateur_id
      WHERE u.actif = 1 GROUP BY r.code'
), 'n', 'code');

$stats = stats_acces(null);

// Établissements sans administrateur actif : à traiter en priorité
$sansAdmin = db_all(
    'SELECT e.id, e.uai, e.nom, a.code AS academie
       FROM etablissement e JOIN academie a ON a.id = e.academie_id
      WHERE e.actif = 1 AND NOT EXISTS (
            SELECT 1 FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
             WHERE u.etablissement_id = e.id AND u.actif = 1 AND ur.role_id = ?)
      ORDER BY e.nom',
    [role_id(ROLE_ADMIN)]
);

$topEtab = db_all(
    'SELECT e.id, e.uai, e.nom, COUNT(*) AS n, COUNT(DISTINCT j.utilisateur_id) AS users
       FROM journal_connexion j JOIN etablissement e ON e.id = j.etablissement_id
      WHERE j.succes = 1 AND j.created_at > NOW() - INTERVAL 7 DAY
      GROUP BY e.id ORDER BY n DESC LIMIT 8'
);

$ipSuspectes = db_all(
    'SELECT ip, COUNT(*) AS n, MAX(created_at) AS derniere
       FROM journal_connexion
      WHERE succes = 0 AND created_at > NOW() - INTERVAL 1 DAY
      GROUP BY ip HAVING n >= 3 ORDER BY n DESC LIMIT 10'
);

$actions = db_all(
    'SELECT ja.*, u.prenom, u.nom, e.uai
       FROM journal_action ja
       LEFT JOIN utilisateur u ON u.id = ja.acteur_id
       LEFT JOIN etablissement e ON e.id = ja.etablissement_id
      ORDER BY ja.created_at DESC, ja.id DESC LIMIT 12'
);

render_header('Tableau de bord super admin');
?>
<div class="page-head">
    <h1>Tableau de bord</h1>
    <a class="btn btn-primary" href="<?= e(url('superadmin/etablissement_form.php')) ?>">+ Nouvel établissement</a>
</div>

<div class="stats">
    <?= stat_card('Établissements actifs', $nbEtab, $nbAcademies . ' académie(s) représentée(s)') ?>
    <?= stat_card('Étudiants', (int)($parRole[ROLE_STUDENT] ?? 0)) ?>
    <?= stat_card('Enseignants', (int)($parRole[ROLE_TEACHER] ?? 0), (int)($parRole[ROLE_ADMIN] ?? 0) . ' administrateur(s)') ?>
    <?= stat_card('Connexions 24 h', $stats['total_24h'], $stats['users_7j'] . ' utilisateurs actifs sur 7 j') ?>
    <?= stat_card('Échecs 24 h', $stats['echecs_24h'], $stats['bloques_24h'] . ' blocage(s)', $stats['echecs_24h'] > 20 ? 'warn' : '') ?>
</div>

<section class="card">
    <h2>Connexions sur 14 jours</h2>
    <?= chart_connexions($stats['par_jour']) ?>
</section>

<div class="grid-2">
    <section class="card">
        <h2>Établissements sans administrateur <span class="count"><?= count($sansAdmin) ?></span></h2>
        <?php if (!$sansAdmin): ?>
            <p class="muted">Tous les établissements actifs ont au moins un administrateur.</p>
        <?php else: ?>
            <ul class="list">
            <?php foreach ($sansAdmin as $et): ?>
                <li>
                    <span><span class="mono"><?= e($et['uai']) ?></span> <?= e($et['nom']) ?> <small class="muted"><?= e($et['academie']) ?></small></span>
                    <a class="btn btn-sm" href="<?= e(url('superadmin/etablissement_admins.php?id=' . $et['id'])) ?>">Affecter</a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Établissements les plus actifs (7 j)</h2>
        <table>
            <thead><tr><th>Établissement</th><th class="num">Connexions</th><th class="num">Utilisateurs</th></tr></thead>
            <tbody>
            <?php foreach ($topEtab as $t): ?>
                <tr>
                    <td><a href="<?= e(url('superadmin/acces.php?etab=' . $t['id'])) ?>"><span class="mono"><?= e($t['uai']) ?></span> <?= e($t['nom']) ?></a></td>
                    <td class="num"><?= (int)$t['n'] ?></td>
                    <td class="num"><?= (int)$t['users'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topEtab): ?><tr><td colspan="3" class="empty">Aucune connexion sur 7 jours.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<div class="grid-2">
    <section class="card">
        <h2>IP avec échecs répétés (24 h)</h2>
        <table>
            <thead><tr><th>IP</th><th class="num">Échecs</th><th>Dernier</th></tr></thead>
            <tbody>
            <?php foreach ($ipSuspectes as $ip): ?>
                <tr>
                    <td class="mono"><?= e($ip['ip']) ?></td>
                    <td class="num"><?= (int)$ip['n'] ?></td>
                    <td><?= e(fmt_date($ip['derniere'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ipSuspectes): ?><tr><td colspan="3" class="empty">Rien à signaler.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Dernières actions</h2>
        <table>
            <thead><tr><th>Date</th><th>Par</th><th>Action</th><th>Objet</th></tr></thead>
            <tbody>
            <?php foreach ($actions as $a): ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_date($a['created_at'])) ?></td>
                    <td><?= e(trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? '')) ?: '—') ?></td>
                    <td><code><?= e($a['action']) ?></code></td>
                    <td><?= e($a['cible']) ?><?= $a['uai'] ? ' <small class="muted">' . e($a['uai']) . '</small>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$actions): ?><tr><td colspan="4" class="empty">Aucune action.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
<?php render_footer();
