-- À exécuter une fois sur une base reseau_certa existante.
-- Le compte SQL de l'application doit aussi avoir les droits CRUD sur mailserver.*.

CREATE DATABASE IF NOT EXISTS mailserver
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mailserver.virtual_domains (
    id      INT NOT NULL AUTO_INCREMENT,
    name    VARCHAR(50) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mailserver.virtual_users (
    id          INT NOT NULL AUTO_INCREMENT,
    domain_id   INT NOT NULL,
    email       VARCHAR(100) NOT NULL,
    password    VARCHAR(150) NOT NULL,
    quota       BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_virtual_users_email (email),
    KEY idx_virtual_users_domain (domain_id),
    CONSTRAINT fk_virtual_users_domain
        FOREIGN KEY (domain_id) REFERENCES mailserver.virtual_domains(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mailserver.virtual_aliases (
    id          INT NOT NULL AUTO_INCREMENT,
    domain_id   INT NOT NULL,
    source      VARCHAR(100) NOT NULL,
    destination VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_virtual_aliases_domain (domain_id),
    CONSTRAINT fk_virtual_aliases_domain
        FOREIGN KEY (domain_id) REFERENCES mailserver.virtual_domains(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE etablissement
    ADD COLUMN virtual_domain_id INT NULL AFTER academie_id,
    ADD UNIQUE KEY uq_etablissement_virtual_domain (virtual_domain_id);

ALTER TABLE utilisateur
    ADD COLUMN virtual_user_id INT NULL AFTER etablissement_id,
    ADD UNIQUE KEY uq_utilisateur_virtual_user (virtual_user_id);

-- Une fois les données en place, la contrainte empêche les doublons de domaine.
ALTER TABLE mailserver.virtual_domains
    ADD UNIQUE KEY uq_virtual_domains_name (name);

SET @mail_domain = 'reseaucerta.org';

INSERT IGNORE INTO mailserver.virtual_domains (name)
SELECT LOWER(CONCAT(e.uai, '.', @mail_domain))
  FROM etablissement e;

UPDATE etablissement e
JOIN mailserver.virtual_domains d
  ON d.name = LOWER(CONCAT(e.uai, '.', @mail_domain))
SET e.virtual_domain_id = d.id
WHERE e.virtual_domain_id IS NULL;

-- Les mots de passe existants ne peuvent pas être convertis depuis leur hash.
-- Chaque compte sera provisionné dans virtual_users lors de sa prochaine
-- connexion réussie, de sa réinitialisation ou de son changement de mot de passe.
