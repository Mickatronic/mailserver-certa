<?php
require __DIR__ . '/../../src/bootstrap.php';

$admin = require_etab_admin();
$etabId = (int)$admin['etablissement_id'];
$etab = etablissement($etabId);

$compte = db_one(
    'SELECT
        COUNT(DISTINCT CASE WHEN ur.role_id = :s THEN u.id END)                        AS etudiants,
        COUNT(DISTINCT CASE WHEN ur.role_id = :t THEN u.id END)                        AS enseignants,
        COUNT(DISTINCT CASE WHEN u.actif = 0 THEN u.id END)                            AS inactifs,
        COUNT(DISTINCT CASE WHEN u.derniere_connexion_at IS NULL THEN u.id END)        AS jamais
       FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id
      WHERE u.etablissement_id = :e',
    ['s' => role_id(ROLE_STUDENT), 't' => role_id(ROLE_TEACHER), 'e' => $etabId]
);
$stats = stats_acces($etabId);

[$dernieres] = query_connexions($etabId, 10, 0);

$actions = db_all(
    'SELECT ja.*, u.prenom, u.nom FROM journal_action ja LEFT JOIN utilisateur u ON u.id = ja.acteur_id
      WHERE ja.etablissement_id = ? ORDER BY ja.created_at DESC, ja.id DESC LIMIT 10',
    [$etabId]
);

render_header('Tableau de bord · ' . $etab['nom']);
?>
<div class="page-head">
    <div>
        <h1><?= e($etab['nom']) ?></h1>
        <p class="muted"><span class="mono"><?= e($etab['uai']) ?></span> · <?= e($etab['academie_nom']) ?>
            · domaine <code><?= e(uai_domaine($etab['uai'])) ?></code></p>
    </div>
    <div>
        <a class="btn btn-primary" href="<?= e(url('admin/utilisateur_form.php')) ?>">+ Utilisateur</a>
        <a class="btn" href="<?= e(url('admin/import.php')) ?>">Importer</a>
    </div>
</div>

<div class="stats">
    <?= stat_card('Étudiants', (int)$compte['etudiants']) ?>
    <?= stat_card('Enseignants', (int)$compte['enseignants']) ?>
    <?= stat_card('Jamais connectés', (int)$compte['jamais'], 'comptes à activer') ?>
    <?= stat_card('Comptes désactivés', (int)$compte['inactifs']) ?>
    <?= stat_card('Connexions 24 h', $stats['total_24h'], $stats['echecs_24h'] . ' échec(s)', $stats['echecs_24h'] > 20 ? 'warn' : '') ?>
</div>

<section class="card">
    <h2>Connexions sur 14 jours</h2>
    <?= chart_connexions($stats['par_jour']) ?>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Dernières connexions</h2>
            <a href="<?= e(url('admin/acces.php')) ?>">Tout voir →</a>
        </div>
        <?= table_connexions($dernieres, false) ?>
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
                    <td><?= e($a['cible']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$actions): ?><tr><td colspan="4" class="empty">Aucune action.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
<?php render_footer();
