-- À exécuter une seule fois sur une base existante.
ALTER TABLE utilisateur
    ADD COLUMN email_personnel VARCHAR(255) NULL AFTER email;
