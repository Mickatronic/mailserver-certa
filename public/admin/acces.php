<?php
require __DIR__ . '/../../src/bootstrap.php';

$admin = require_etab_admin();

page_acces((int)$admin['etablissement_id']);
