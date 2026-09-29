<?php
require __DIR__ . '/../src/bootstrap.php';

if (is_post()) {
    csrf_check();
    logout();
    flash('success', 'Vous êtes déconnecté.');
}
redirect('login.php');
