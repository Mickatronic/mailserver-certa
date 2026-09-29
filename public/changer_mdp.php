<?php
require __DIR__ . '/../src/bootstrap.php';

$u = require_login();
$erreurs = [];

if (is_post()) {
    csrf_check();
    $actuel  = (string)($_POST['actuel'] ?? '');
    $nouveau = (string)($_POST['nouveau'] ?? '');
    $confirm = (string)($_POST['confirmation'] ?? '');

    if (!password_verify($actuel, (string)$u['password_hash'])) {
        $erreurs[] = 'Le mot de passe actuel est incorrect.';
    }
    if (!password_is_strong($nouveau)) {
        $erreurs[] = 'Le nouveau mot de passe doit faire au moins 10 caractères avec une minuscule, une majuscule et un chiffre.';
    }
    if ($nouveau !== $confirm) {
        $erreurs[] = 'La confirmation ne correspond pas.';
    }
    if ($nouveau === $actuel) {
        $erreurs[] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
    }

    if (!$erreurs) {
        db_exec(
            'UPDATE utilisateur SET password_hash = ?, doit_changer_mdp = 0 WHERE id = ?',
            [password_hash($nouveau, PASSWORD_DEFAULT), $u['id']]
        );
        session_regenerate_id(true);
        flash('success', 'Mot de passe modifié.');
        redirect(home_path($u));
    }
}

render_header('Changer de mot de passe');
?>
<div class="login-box card">
    <h1>Changer de mot de passe</h1>
    <?php if ($u['doit_changer_mdp']): ?>
        <div class="alert alert-info">Pour votre première connexion, vous devez choisir un mot de passe personnel.</div>
    <?php endif; ?>
    <?php foreach ($erreurs as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post">
        <?= csrf_field() ?>
        <label>Mot de passe actuel
            <input type="password" name="actuel" required autocomplete="current-password">
        </label>
        <label>Nouveau mot de passe
            <input type="password" name="nouveau" required minlength="10" autocomplete="new-password">
            <small class="muted">10 caractères min., avec minuscule, majuscule et chiffre.</small>
        </label>
        <label>Confirmation
            <input type="password" name="confirmation" required minlength="10" autocomplete="new-password">
        </label>
        <button type="submit" class="btn btn-primary btn-block">Enregistrer</button>
    </form>
</div>
<?php render_footer();
