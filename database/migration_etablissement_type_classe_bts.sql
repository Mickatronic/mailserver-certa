-- À exécuter une seule fois après migration_email_personnel.sql sur une base existante.
ALTER TABLE etablissement
    ADD COLUMN type_etablissement VARCHAR(30) NOT NULL DEFAULT 'PUBLIC' AFTER nom,
    ADD CONSTRAINT chk_etablissement_type
        CHECK (type_etablissement IN ('PUBLIC', 'PRIVE_SOUS_CONTRAT', 'PRIVE'));

ALTER TABLE utilisateur
    ADD COLUMN classe VARCHAR(100) NULL AFTER email_personnel,
    ADD COLUMN annee_bts SMALLINT UNSIGNED NULL AFTER classe;
