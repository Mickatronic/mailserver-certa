<?php
declare(strict_types=1);

function render_header(string $title): void
{
    $u = current_user();
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $nav = [];

    if ($u && has_role(ROLE_SUPER_ADMIN, $u)) {
        $nav = [
            'superadmin/index.php'          => 'Tableau de bord',
            'superadmin/etablissements.php' => 'Établissements',
            'superadmin/academies.php'      => 'Académies',
            'superadmin/acces.php'          => 'Journal des accès',
        ];
    } elseif ($u && has_role(ROLE_ADMIN, $u)) {
        $nav = [
            'admin/index.php'        => 'Tableau de bord',
            'admin/utilisateurs.php' => 'Utilisateurs',
            'admin/import.php'       => 'Import',
            'admin/acces.php'        => 'Journal des accès',
        ];
    } elseif ($u) {
        $nav = ['espace.php' => 'Mon espace'];
    }
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Réseau CERTA</title>
    <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= e(url('index.php')) ?>">Réseau <strong>CERTA</strong></a>
    <?php if ($nav): ?>
        <nav>
            <?php foreach ($nav as $href => $label): ?>
                <a href="<?= e(url($href)) ?>" class="<?= str_ends_with($script, $href) ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if ($u): ?>
        <div class="userbox">
            <span title="<?= e($u['email']) ?>"><?= e($u['prenom'] . ' ' . $u['nom']) ?></span>
            <?php if ($u['etablissement_nom']): ?><small><?= e($u['etablissement_nom']) ?></small><?php endif; ?>
            <a href="<?= e(url('changer_mdp.php')) ?>">Mot de passe</a>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="link">Déconnexion</button>
            </form>
        </div>
    <?php endif; ?>
</header>
<main class="container">
    <?php foreach (flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
<?php
}

function render_footer(): void
{
    ?>
</main>
<footer class="footer">Réseau CERTA · gestion des établissements</footer>
</body>
</html>
<?php
}

/** Petite carte statistique. */
function stat_card(string $label, int|string $value, string $hint = '', string $class = ''): string
{
    return '<div class="card stat ' . e($class) . '"><div class="stat-value">' . e($value) . '</div>'
        . '<div class="stat-label">' . e($label) . '</div>'
        . ($hint !== '' ? '<div class="stat-hint">' . e($hint) . '</div>' : '') . '</div>';
}

/** Histogramme CSS des connexions par jour (réussies / échouées). */
function chart_connexions(array $parJour): string
{
    $max = max(1, ...array_map(fn($d) => $d['ok'] + $d['ko'], $parJour));
    $html = '<div class="chart" role="img" aria-label="Connexions par jour">';
    foreach ($parJour as $d) {
        $hOk = round($d['ok'] / $max * 100, 1);
        $hKo = round($d['ko'] / $max * 100, 1);
        $tip = sprintf('%s : %d réussie(s), %d échec(s)', date('d/m', strtotime($d['jour'])), $d['ok'], $d['ko']);
        $html .= '<div class="chart-col" title="' . e($tip) . '">'
            . '<div class="chart-bar"><span class="ko" style="height:' . $hKo . '%"></span>'
            . '<span class="ok" style="height:' . $hOk . '%"></span></div>'
            . '<div class="chart-label">' . e(date('d/m', strtotime($d['jour']))) . '</div></div>';
    }
    return $html . '</div><div class="chart-legend"><span class="dot ok"></span> Réussies <span class="dot ko"></span> Échecs</div>';
}

function pagination(int $total, int $perPage, int $page): string
{
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination">';
    if ($page > 1) {
        $html .= '<a href="' . e(qs(['page' => $page - 1])) . '">‹ Préc.</a>';
    }
    $html .= '<span>Page ' . $page . ' / ' . $pages . ' (' . $total . ' résultats)</span>';
    if ($page < $pages) {
        $html .= '<a href="' . e(qs(['page' => $page + 1])) . '">Suiv. ›</a>';
    }
    return $html . '</nav>';
}

/** Tableau du journal des connexions (partagé super admin / admin). */
function table_connexions(array $rows, bool $avecEtab): string
{
    ob_start(); ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>Date</th><th>Email saisi</th><th>Utilisateur</th>
            <?php if ($avecEtab): ?><th>Établissement</th><?php endif; ?>
            <th>Résultat</th><th>IP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td>
                <td><?= e($r['email_saisi']) ?></td>
                <td><?= $r['utilisateur_id'] ? e($r['prenom'] . ' ' . $r['nom']) : '<span class="muted">—</span>' ?></td>
                <?php if ($avecEtab): ?><td><?= $r['uai'] ? e($r['uai'] . ' · ' . $r['etab_nom']) : '<span class="muted">—</span>' ?></td><?php endif; ?>
                <td><span class="pill <?= $r['succes'] ? 'pill-ok' : 'pill-ko' ?>"><?= e(MOTIF_LIBELLES[$r['motif']] ?? $r['motif']) ?></span></td>
                <td class="mono"><?= e($r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $avecEtab ? 6 : 5 ?>" class="empty">Aucune connexion enregistrée.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
    return (string)ob_get_clean();
}

/**
 * Requête filtrée du journal des connexions.
 * Filtres GET : q (email), resultat (ok|ko), du, au, etab (super admin uniquement).
 * @return array{0: array, 1: int} [lignes, total]
 */
function query_connexions(?int $etabForce, int $limit, int $offset): array
{
    $where = ['1 = 1'];
    $p = [];

    $etab = $etabForce ?? (get_int('etab') ?: null);
    if ($etab) {
        $where[] = 'j.etablissement_id = ?';
        $p[] = $etab;
    }
    if (($q = get('q')) !== '') {
        $where[] = 'j.email_saisi LIKE ?';
        $p[] = '%' . addcslashes(strtolower($q), '%_\\') . '%';
    }
    if (get('resultat') === 'ok') {
        $where[] = 'j.succes = 1';
    } elseif (get('resultat') === 'ko') {
        $where[] = 'j.succes = 0';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', get('du'))) {
        $where[] = 'j.created_at >= ?';
        $p[] = get('du') . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', get('au'))) {
        $where[] = 'j.created_at <= ?';
        $p[] = get('au') . ' 23:59:59';
    }
    $w = implode(' AND ', $where);

    $total = (int)db_value("SELECT COUNT(*) FROM journal_connexion j WHERE $w", $p);
    $rows = db_all(
        "SELECT j.*, u.prenom, u.nom, e.uai, e.nom AS etab_nom
           FROM journal_connexion j
           LEFT JOIN utilisateur u   ON u.id = j.utilisateur_id
           LEFT JOIN etablissement e ON e.id = j.etablissement_id
          WHERE $w
          ORDER BY j.created_at DESC, j.id DESC
          LIMIT $limit OFFSET $offset",
        $p
    );
    return [$rows, $total];
}
