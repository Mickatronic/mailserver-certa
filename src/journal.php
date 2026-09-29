<?php
declare(strict_types=1);

function journal_connexion(?int $userId, ?int $etabId, string $email, bool $succes, string $motif): void
{
    db_exec(
        'INSERT INTO journal_connexion (utilisateur_id, etablissement_id, email_saisi, succes, motif, ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $userId,
            $etabId,
            mb_substr($email, 0, 255),
            $succes ? 1 : 0,
            $motif,
            client_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );
}

function journal_action(string $action, ?int $etabId, ?string $cible = null, ?string $details = null): void
{
    $u = current_user();
    db_exec(
        'INSERT INTO journal_action (acteur_id, etablissement_id, action, cible, details) VALUES (?, ?, ?, ?, ?)',
        [$u ? (int)$u['id'] : null, $etabId, $action, $cible !== null ? mb_substr($cible, 0, 255) : null, $details]
    );
}

const MOTIF_LIBELLES = [
    'OK'           => 'Connexion réussie',
    'MDP_INVALIDE' => 'Mot de passe invalide',
    'INCONNU'      => 'Compte inconnu',
    'INACTIF'      => 'Compte désactivé',
    'BLOQUE'       => 'Bloqué (trop d\'essais)',
];

/**
 * Statistiques d'accès, globales ou pour un établissement.
 * @return array{total_24h:int, echecs_24h:int, users_7j:int, bloques_24h:int, par_jour:array}
 */
function stats_acces(?int $etabId, int $jours = 14): array
{
    $where = $etabId ? 'AND etablissement_id = ?' : '';
    $p = $etabId ? [$etabId] : [];

    $r = db_one(
        "SELECT
            SUM(created_at > NOW() - INTERVAL 1 DAY AND succes = 1)          AS total_24h,
            SUM(created_at > NOW() - INTERVAL 1 DAY AND succes = 0)          AS echecs_24h,
            SUM(created_at > NOW() - INTERVAL 1 DAY AND motif = 'BLOQUE')    AS bloques_24h,
            COUNT(DISTINCT CASE WHEN succes = 1 THEN utilisateur_id END)     AS users_7j
           FROM journal_connexion
          WHERE created_at > NOW() - INTERVAL 7 DAY $where",
        $p
    ) ?? [];

    $rows = db_all(
        "SELECT DATE(created_at) AS jour, SUM(succes = 1) AS ok, SUM(succes = 0) AS ko
           FROM journal_connexion
          WHERE created_at >= CURDATE() - INTERVAL ? DAY $where
          GROUP BY DATE(created_at)",
        array_merge([$jours - 1], $p)
    );
    $idx = array_column($rows, null, 'jour');

    $parJour = [];
    for ($i = $jours - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $parJour[] = [
            'jour' => $d,
            'ok'   => (int)($idx[$d]['ok'] ?? 0),
            'ko'   => (int)($idx[$d]['ko'] ?? 0),
        ];
    }

    return [
        'total_24h'   => (int)($r['total_24h'] ?? 0),
        'echecs_24h'  => (int)($r['echecs_24h'] ?? 0),
        'bloques_24h' => (int)($r['bloques_24h'] ?? 0),
        'users_7j'    => (int)($r['users_7j'] ?? 0),
        'par_jour'    => $parJour,
    ];
}
