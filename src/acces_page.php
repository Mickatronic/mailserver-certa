<?php
declare(strict_types=1);

/**
 * Page « Journal des accès », partagée entre super admin (tous établissements)
 * et admin d'établissement ($etabForce = son établissement).
 */
function page_acces(?int $etabForce): void
{
    // Export CSV de la sélection courante (10 000 lignes max)
    if (get('export') === 'csv') {
        [$rows] = query_connexions($etabForce, 10000, 0);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="acces_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['date', 'email_saisi', 'utilisateur', 'uai', 'etablissement', 'succes', 'motif', 'ip', 'user_agent'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['created_at'], $r['email_saisi'], trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')),
                $r['uai'], $r['etab_nom'], $r['succes'], $r['motif'], $r['ip'], $r['user_agent'],
            ], ';', '"', '');
        }
        exit;
    }

    [$limit, $offset, $page] = paginate(50);
    [$rows, $total] = query_connexions($etabForce, $limit, $offset);
    $stats = stats_acces($etabForce ?? (get_int('etab') ?: null));
    $etabs = $etabForce ? [] : db_all('SELECT id, uai, nom FROM etablissement ORDER BY nom');

    render_header('Journal des accès');
    ?>
    <div class="page-head">
        <h1>Journal des accès</h1>
        <a class="btn" href="<?= e(qs(['export' => 'csv', 'page' => null])) ?>">Exporter en CSV</a>
    </div>

    <div class="stats">
        <?= stat_card('Connexions 24 h', $stats['total_24h']) ?>
        <?= stat_card('Échecs 24 h', $stats['echecs_24h'], '', $stats['echecs_24h'] > 20 ? 'warn' : '') ?>
        <?= stat_card('Blocages 24 h', $stats['bloques_24h']) ?>
        <?= stat_card('Utilisateurs actifs (7 j)', $stats['users_7j']) ?>
    </div>

    <section class="card">
        <?= chart_connexions($stats['par_jour']) ?>
    </section>

    <form class="filters card" method="get">
        <input type="search" name="q" value="<?= e(get('q')) ?>" placeholder="Email…">
        <?php if (!$etabForce): ?>
            <select name="etab">
                <option value="">Tous les établissements</option>
                <?php foreach ($etabs as $et): ?>
                    <option value="<?= (int)$et['id'] ?>" <?= get_int('etab') === (int)$et['id'] ? 'selected' : '' ?>><?= e($et['uai'] . ' · ' . $et['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select name="resultat">
            <option value="">Tous résultats</option>
            <option value="ok" <?= get('resultat') === 'ok' ? 'selected' : '' ?>>Réussies</option>
            <option value="ko" <?= get('resultat') === 'ko' ? 'selected' : '' ?>>Échecs</option>
        </select>
        <label class="inline-label">Du <input type="date" name="du" value="<?= e(get('du')) ?>"></label>
        <label class="inline-label">au <input type="date" name="au" value="<?= e(get('au')) ?>"></label>
        <button class="btn">Filtrer</button>
        <a class="btn btn-ghost" href="?">Réinitialiser</a>
    </form>

    <section class="card">
        <?= table_connexions($rows, !$etabForce) ?>
        <?= pagination($total, $limit, $page) ?>
    </section>
    <?php
    render_footer();
}
