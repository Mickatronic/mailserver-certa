<?php
require __DIR__ . '/../src/bootstrap.php';

$u = require_login();
$etab = $u['etablissement_id'] ? etablissement((int)$u['etablissement_id']) : null;

$connexions = db_all(
    'SELECT created_at, succes, motif, ip FROM journal_connexion
      WHERE utilisateur_id = ? ORDER BY created_at DESC LIMIT 10',
    [$u['id']]
);

render_header('Mon espace');
?>
<h1>Bonjour <?= e($u['prenom']) ?></h1>

<div class="grid-2">
    <section class="card">
        <h2>Mon compte</h2>
        <dl class="dl">
            <dt>Nom</dt><dd><?= e($u['prenom'] . ' ' . $u['nom']) ?></dd>
            <dt>Email</dt><dd><?= e($u['email']) ?></dd>
            <dt>Rôle(s)</dt><dd><?= role_badges($u['roles']) ?></dd>
            <dt>Dernière connexion</dt><dd><?= e(fmt_date($u['derniere_connexion_at'])) ?></dd>
        </dl>
    </section>
    <?php if ($etab): ?>
    <section class="card">
        <h2>Mon établissement</h2>
        <dl class="dl">
            <dt>Nom</dt><dd><?= e($etab['nom']) ?></dd>
            <dt>UAI</dt><dd class="mono"><?= e($etab['uai']) ?></dd>
            <dt>Académie</dt><dd><?= e($etab['academie_nom']) ?> (<?= e($etab['academie_code']) ?>)</dd>
            <dt>Adresse</dt>
            <dd><?= e($etab['adresse_ligne1']) ?><br><?= e($etab['adresse_ligne2']) ?>
                <?= e($etab['code_postal'] . ' ' . $etab['ville']) ?></dd>
        </dl>
    </section>
    <?php endif; ?>
</div>

<section class="card">
    <h2>Mes 10 dernières connexions</h2>
    <table>
        <thead><tr><th>Date</th><th>Résultat</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($connexions as $c): ?>
            <tr>
                <td><?= e(fmt_date($c['created_at'])) ?></td>
                <td><span class="pill <?= $c['succes'] ? 'pill-ok' : 'pill-ko' ?>"><?= e(MOTIF_LIBELLES[$c['motif']] ?? $c['motif']) ?></span></td>
                <td class="mono"><?= e($c['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php render_footer();
