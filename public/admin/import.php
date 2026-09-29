<?php
require __DIR__ . '/../../src/bootstrap.php';

$admin = require_etab_admin();
$etabId = (int)$admin['etablissement_id'];
$etab = etablissement($etabId);

const IMPORT_MAX_LIGNES = 5000;

// ---------- Téléchargement du modèle ----------
if (get('modele') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modele_import_utilisateurs.csv"');
    echo "\xEF\xBB\xBF",
        "nom;prenom;email_personnel;classe;annee_bts;role\n",
        "Dupont;Marie;;BTS SIO 1;2027;STUDENT\n",
        "Martin;Jean-Éric;jean.eric@example.fr;BTS SIO 2;2026;STUDENT\n",
        "Durand;Sophie;sophie.durand@example.fr;;;TEACHER\n";
    exit;
}

// ---------- Téléchargement des identifiants du dernier import (une seule fois) ----------
if (get('identifiants') === '1') {
    $creds = $_SESSION['import_identifiants'] ?? [];
    unset($_SESSION['import_identifiants']);
    if (!$creds) {
        flash('error', 'Les identifiants ont déjà été téléchargés ou ont expiré.');
        redirect('admin/import.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="identifiants_' . $etab['uai'] . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['nom', 'prenom', 'email_connexion', 'email_personnel', 'classe', 'annee_bts', 'role', 'mot_de_passe_temporaire'], ';', '"', '');
    foreach ($creds as $c) {
        fputcsv($out, [$c['nom'], $c['prenom'], $c['email'], $c['email_personnel'], $c['classe'], $c['annee_bts'], $c['role'], $c['password']], ';', '"', '');
    }
    exit;
}

/** Associe les en-têtes du fichier aux champs attendus. */
function import_colonnes(array $entetes): array
{
    $alias = [
        'nom'    => ['nom', 'name', 'lastname', 'nom_de_famille'],
        'prenom' => ['prenom', 'firstname', 'first_name'],
        'email_personnel' => ['email_personnel', 'email', 'mail', 'courriel', 'e-mail', 'adresse_email'],
        'classe' => ['classe', 'class', 'nom_classe'],
        'annee_bts' => ['annee_bts', 'annee_passage_bts', 'annee_bts_passage'],
        'role'   => ['role', 'profil', 'type', 'statut'],
    ];
    $map = [];
    foreach ($entetes as $i => $h) {
        $h = str_replace('-', '_', email_slug((string)$h));
        foreach ($alias as $champ => $noms) {
            if (in_array($h, array_map(fn($n) => str_replace('-', '_', $n), $noms), true) && !isset($map[$champ])) {
                $map[$champ] = $i;
            }
        }
    }
    return $map;
}

$resultat = null;
$erreur = null;
$simulation = true;
$roleDefaut = ROLE_STUDENT;

if (is_post()) {
    csrf_check();
    $simulation = isset($_POST['simulation']);
    $roleDefaut = post('role_defaut') === ROLE_TEACHER ? ROLE_TEACHER : ROLE_STUDENT;
    $f = $_FILES['fichier'] ?? null;

    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $erreur = 'Aucun fichier reçu (ou erreur de transfert).';
    } elseif ($f['size'] > config('import_max_size')) {
        $erreur = 'Fichier trop volumineux (max ' . round(config('import_max_size') / 1048576, 1) . ' Mo).';
    } elseif (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
        $erreur = 'Seuls les fichiers .csv sont acceptés (Excel : « Enregistrer sous » > CSV).';
    } else {
        $contenu = file_get_contents($f['tmp_name']);
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252'); // CSV Excel français
        }
        $lignes = preg_split('/\r\n|\r|\n/', trim($contenu));
        $premiere = $lignes[0] ?? '';
        // Séparateur = caractère le plus fréquent de la ligne d'en-têtes
        $compte = [';' => substr_count($premiere, ';'), ',' => substr_count($premiere, ','), "\t" => substr_count($premiere, "\t")];
        arsort($compte);
        $sep = (string)array_key_first($compte);

        $entetes = str_getcsv($premiere, $sep, '"', '');
        $cols = import_colonnes($entetes);

        if (!isset($cols['nom'], $cols['prenom'])) {
            $erreur = 'En-têtes attendus : nom ; prenom ; classe ; annee_bts ; email_personnel ; role. Classe et année BTS sont obligatoires pour les étudiants. Trouvés : ' . implode(', ', $entetes);
        } elseif (count($lignes) - 1 > IMPORT_MAX_LIGNES) {
            $erreur = 'Maximum ' . IMPORT_MAX_LIGNES . ' lignes par import.';
        } else {
            $resultat = ['crees' => [], 'ignores' => [], 'erreurs' => []];
            $pdo = db();
            $pdo->beginTransaction();

            foreach (array_slice($lignes, 1) as $i => $ligne) {
                $num = $i + 2; // numéro de ligne dans le fichier
                if (trim($ligne) === '') {
                    continue;
                }
                $v = str_getcsv($ligne, $sep, '"', '');
                $nom    = trim($v[$cols['nom']] ?? '');
                $prenom = trim($v[$cols['prenom']] ?? '');
                $emailPersonnel = strtolower(trim(isset($cols['email_personnel']) ? ($v[$cols['email_personnel']] ?? '') : ''));
                $classe = trim(isset($cols['classe']) ? ($v[$cols['classe']] ?? '') : '');
                $anneeBts = trim(isset($cols['annee_bts']) ? ($v[$cols['annee_bts']] ?? '') : '');
                $role   = trim(isset($cols['role']) ? ($v[$cols['role']] ?? '') : '') ?: $roleDefaut;

                try {
                    $c = user_create($etab, $prenom, $nom, $emailPersonnel, $role, false, [], $classe, $anneeBts);
                    $resultat['crees'][] = [
                        'nom' => $nom, 'prenom' => $prenom, 'email' => $c['email'],
                        'email_personnel' => $c['email_personnel'],
                        'classe' => $classe, 'annee_bts' => $anneeBts,
                        'role' => valider_role_base($role), 'password' => $c['password'],
                    ];
                } catch (ValidationException $ex) {
                    $resultat['erreurs'][] = ['ligne' => $num, 'nom' => "$prenom $nom", 'motif' => $ex->getMessage()];
                } catch (PDOException $ex) {
                    $resultat['erreurs'][] = ['ligne' => $num, 'nom' => "$prenom $nom", 'motif' => db_error_message($ex)];
                }
            }

            if ($simulation) {
                $pdo->rollBack();
            } else {
                journal_action('IMPORT', $etabId, $f['name'], sprintf(
                    '%d créé(s), %d ignoré(s), %d erreur(s)',
                    count($resultat['crees']), count($resultat['ignores']), count($resultat['erreurs'])
                ));
                $pdo->commit();
                $_SESSION['import_identifiants'] = $resultat['crees'];
            }
        }
    }
}

render_header('Import des utilisateurs');
?>
<div class="page-head">
    <h1>Import des utilisateurs</h1>
    <a class="btn btn-ghost" href="<?= e(url('admin/utilisateurs.php')) ?>">← Utilisateurs</a>
</div>

<?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>

<?php if ($resultat !== null): ?>
    <section class="card">
        <h2><?= $simulation ? 'Résultat de la simulation (rien n\'a été enregistré)' : 'Import terminé' ?></h2>
        <div class="stats">
            <?= stat_card($simulation ? 'Seraient créés' : 'Créés', count($resultat['crees']), '', 'ok') ?>
            <?= stat_card('Ignorés (déjà existants)', count($resultat['ignores'])) ?>
            <?= stat_card('Erreurs', count($resultat['erreurs']), '', $resultat['erreurs'] ? 'warn' : '') ?>
        </div>

        <?php if (!$simulation && $resultat['crees']): ?>
            <div class="alert alert-warning">
                Les mots de passe temporaires ne seront plus affichés après avoir quitté cette page.
                <a class="btn btn-primary btn-sm" href="?identifiants=1">Télécharger les identifiants (CSV)</a>
            </div>
        <?php endif; ?>

        <?php if ($resultat['erreurs'] || $resultat['ignores']): ?>
            <h3>Lignes non importées</h3>
            <table>
                <thead><tr><th>Ligne</th><th>Personne</th><th>Motif</th></tr></thead>
                <tbody>
                <?php foreach ($resultat['erreurs'] as $r): ?>
                    <tr><td><?= (int)$r['ligne'] ?></td><td><?= e($r['nom']) ?></td><td><span class="pill pill-ko">Erreur</span> <?= e($r['motif']) ?></td></tr>
                <?php endforeach; ?>
                <?php foreach ($resultat['ignores'] as $r): ?>
                    <tr><td><?= (int)$r['ligne'] ?></td><td><?= e($r['nom']) ?></td><td><span class="pill pill-off">Ignoré</span> <?= e($r['motif']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($resultat['crees']): ?>
            <h3><?= $simulation ? 'Comptes qui seraient créés' : 'Comptes créés' ?></h3>
            <div class="table-wrap">
            <table>
                <thead><tr><th>Nom</th><th>Prénom</th><th>Email de connexion</th><th>Email personnel</th><th>Classe</th><th>Année BTS</th><th>Rôle</th><?php if (!$simulation): ?><th>Mot de passe temporaire</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($resultat['crees'] as $c): ?>
                    <tr>
                        <td><?= e($c['nom']) ?></td><td><?= e($c['prenom']) ?></td><td><?= e($c['email']) ?></td><td><?= e($c['email_personnel'] ?: '—') ?></td><td><?= e($c['classe'] ?: '—') ?></td><td><?= e($c['annee_bts'] ?: '—') ?></td>
                        <td><?= role_badges([$c['role']]) ?></td>
                        <?php if (!$simulation): ?><td class="mono"><?= e($c['password']) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2>Importer un fichier CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>Fichier (.csv, séparateur « ; » ou « , »)
                <input type="file" name="fichier" accept=".csv,text/csv" required>
            </label>
            <label>Rôle par défaut (si la colonne « role » est absente ou vide)
                <select name="role_defaut">
                    <option value="STUDENT" <?= $roleDefaut === ROLE_STUDENT ? 'selected' : '' ?>>Étudiant</option>
                    <option value="TEACHER" <?= $roleDefaut === ROLE_TEACHER ? 'selected' : '' ?>>Enseignant</option>
                </select>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="simulation" value="1" <?= $simulation ? 'checked' : '' ?>>
                Simulation : vérifier le fichier sans rien enregistrer
            </label>
            <button type="submit" class="btn btn-primary btn-block">Lancer</button>
        </form>
    </section>

    <section class="card">
        <h2>Format attendu</h2>
        <p>Première ligne = en-têtes. Colonnes reconnues :</p>
        <ul>
            <li><code>nom</code> et <code>prenom</code> : obligatoires</li>
            <li><code>email_personnel</code> (ou l'ancien en-tête <code>email</code>) : adresse personnelle, obligatoire pour les enseignants et facultative pour les étudiants</li>
            <li><code>classe</code> et <code>annee_bts</code> : obligatoires pour chaque étudiant ; vides pour les enseignants</li>
            <li>L'adresse de connexion est toujours générée en <code>prenom.nom@<?= e(uai_domaine($etab['uai'])) ?></code>
                (suffixe numérique en cas d'homonyme)</li>
            <li><code>role</code> : <code>STUDENT</code> / <code>TEACHER</code> (ou étudiant / enseignant)</li>
        </ul>
        <p class="muted small">Encodage UTF-8 ou Windows (Excel) accepté.
            Les administrateurs ne peuvent pas être importés : ils sont nommés par le super admin.</p>
        <a class="btn" href="?modele=1">Télécharger le modèle</a>
    </section>
</div>
<?php render_footer();
