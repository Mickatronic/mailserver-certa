<?php
require __DIR__ . '/../src/bootstrap.php';

if ($u = current_user()) {
    redirect(home_path($u));
}

$email = '';
$erreur = null;

if (is_post()) {
    csrf_check();
    $email = post('email');
    $result = attempt_login($email, (string)($_POST['password'] ?? ''));
    if (is_array($result)) {
        redirect($result['doit_changer_mdp'] ? 'changer_mdp.php' : 'index.php');
    }
    $erreur = $result;
}

render_header('Connexion');
?>
<div class="login-box card">
    <h1>Connexion</h1>
    <p class="muted">Utilisez votre adresse <code>prenom.nom@UAI.reseaucerta.org</code></p>

    <?php if ($erreur): ?><div class="alert alert-error"><?= e($erreur) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <label>Adresse email
            <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
        </label>
        <label>Mot de passe
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
    </form>
</div>
<?php render_footer();
