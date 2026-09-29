-- =====================================================================
--  RESEAU CERTA - Gestion des établissements et des utilisateurs
--  Cible : MySQL 8.0.16+ (CHECK + REGEXP) / MariaDB 10.4+
--
--  Corrections par rapport à la version initiale :
--   1. "ine" -> "uai" : l'identifiant d'un établissement est le code UAI
--      (ex-RNE, ex : 0592222X). L'INE identifie un élève, pas un établissement.
--      Format contrôlé : 7 chiffres + 1 lettre.
--   2. utilisateur.etablissement_id devient NULLable : le Super Admin
--      n'appartient à aucun établissement.
--   3. Ajout du rôle SUPER_ADMIN.
--   4. Triggers : l'email est normalisé en minuscules et DOIT être de la
--      forme  prenom.nom@<uai>.reseaucerta.org  pour tout utilisateur
--      rattaché à un établissement.
--   5. Ajout des tables journal_connexion (tableaux de bord des accès,
--      anti brute-force) et journal_action (traçabilité du CRUD).
--   6. Ajout de colonnes utiles : actif / updated_at / derniere_connexion_at /
--      doit_changer_mdp, index sur les colonnes filtrées.
--   7. Référentiel des académies pré-rempli.
--   8. Suppression des ON UPDATE CASCADE sur les clés AUTO_INCREMENT
--      (inutiles : un id technique n'est jamais modifié).
-- =====================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS reseau_certa
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS mailserver
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE reseau_certa;

DROP TRIGGER IF EXISTS trg_utilisateur_bi;
DROP TRIGGER IF EXISTS trg_utilisateur_bu;
DROP TABLE IF EXISTS journal_action;
DROP TABLE IF EXISTS journal_connexion;
DROP TABLE IF EXISTS superadmin_bootstrap;
DROP TABLE IF EXISTS utilisateur_role;
DROP TABLE IF EXISTS utilisateur;
DROP TABLE IF EXISTS role;
DROP TABLE IF EXISTS etablissement;
DROP TABLE IF EXISTS academie;


-- =====================================================
-- ACADEMIES
-- =====================================================

CREATE TABLE academie (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(50)  NOT NULL,           -- ex : ac-lille
    nom         VARCHAR(255) NOT NULL,           -- ex : Lille
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_academie_code (code),
    CONSTRAINT chk_academie_code CHECK (code REGEXP '^ac-[a-z-]+$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- ETABLISSEMENTS
-- =====================================================

CREATE TABLE etablissement (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    uai             CHAR(8)      NOT NULL,       -- ex : 0592222X
    nom             VARCHAR(255) NOT NULL,
    type_etablissement VARCHAR(30) NOT NULL DEFAULT 'PUBLIC',

    adresse_ligne1  VARCHAR(255) NULL,
    adresse_ligne2  VARCHAR(255) NULL,
    code_postal     VARCHAR(10)  NULL,
    ville           VARCHAR(100) NULL,
    pays            VARCHAR(100) NOT NULL DEFAULT 'France',

    academie_id     BIGINT UNSIGNED NOT NULL,
    virtual_domain_id INT NULL,
    actif           BOOLEAN NOT NULL DEFAULT TRUE,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_etablissement_uai (uai),
    UNIQUE KEY uq_etablissement_virtual_domain (virtual_domain_id),
    KEY idx_etablissement_academie (academie_id),

    CONSTRAINT chk_etablissement_uai CHECK (uai REGEXP '^[0-9]{7}[A-Z]$'),
    CONSTRAINT chk_etablissement_type CHECK (type_etablissement IN ('PUBLIC', 'PRIVE_SOUS_CONTRAT', 'PRIVE')),

    CONSTRAINT fk_etablissement_academie
        FOREIGN KEY (academie_id) REFERENCES academie(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- ROLES
-- =====================================================

CREATE TABLE role (
    id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code     VARCHAR(50)  NOT NULL,
    libelle  VARCHAR(100) NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role (code, libelle) VALUES
    ('STUDENT',     'Etudiant'),
    ('TEACHER',     'Enseignant'),
    ('ADMIN',       'Administrateur d''établissement'),
    ('SUPER_ADMIN', 'Super administrateur');


-- =====================================================
-- UTILISATEURS
-- =====================================================

CREATE TABLE utilisateur (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    prenom                 VARCHAR(100) NOT NULL,
    nom                    VARCHAR(100) NOT NULL,

    email                  VARCHAR(255) NOT NULL,
    email_personnel        VARCHAR(255) NULL,
    classe                 VARCHAR(100) NULL,
    annee_bts              SMALLINT UNSIGNED NULL,
    password_hash          VARCHAR(255) NULL,     -- NULL = compte non encore activé
    doit_changer_mdp       BOOLEAN NOT NULL DEFAULT TRUE,

    actif                  BOOLEAN NOT NULL DEFAULT TRUE,

    etablissement_id       BIGINT UNSIGNED NULL,  -- NULL uniquement pour le SUPER_ADMIN
    virtual_user_id        INT NULL,

    derniere_connexion_at  TIMESTAMP NULL DEFAULT NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_utilisateur_email (email),
    UNIQUE KEY uq_utilisateur_virtual_user (virtual_user_id),
    KEY idx_utilisateur_etab_nom (etablissement_id, nom, prenom),

    CONSTRAINT fk_utilisateur_etablissement
        FOREIGN KEY (etablissement_id) REFERENCES etablissement(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- DOMAINES ET COMPTES VIRTUELS POSTFIX / DOVECOT
-- Les références applicatives sont stockées dans etablissement/utilisateur.
-- =====================================================

CREATE TABLE IF NOT EXISTS mailserver.virtual_domains (
    id      INT NOT NULL AUTO_INCREMENT,
    name    VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_virtual_domains_name (name)
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


-- =====================================================
-- ASSOCIATION UTILISATEUR / ROLE
-- =====================================================

CREATE TABLE utilisateur_role (
    utilisateur_id  BIGINT UNSIGNED   NOT NULL,
    role_id         SMALLINT UNSIGNED NOT NULL,

    PRIMARY KEY (utilisateur_id, role_id),
    KEY idx_utilisateur_role_role (role_id),

    CONSTRAINT fk_utilisateur_role_user
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_utilisateur_role_role
        FOREIGN KEY (role_id) REFERENCES role(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- MARQUEUR DE PROVISIONNEMENT DU SUPER ADMIN DOCKER
-- =====================================================

CREATE TABLE superadmin_bootstrap (
    email       VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- JOURNAL DES CONNEXIONS (tableaux de bord des accès)
-- =====================================================

CREATE TABLE journal_connexion (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    utilisateur_id    BIGINT UNSIGNED NULL,       -- NULL si email inconnu
    etablissement_id  BIGINT UNSIGNED NULL,       -- dénormalisé : filtrage des tableaux de bord
    email_saisi       VARCHAR(255) NOT NULL,

    succes            BOOLEAN NOT NULL,
    motif             VARCHAR(30) NULL,           -- OK, MDP_INVALIDE, INCONNU, INACTIF, BLOQUE
    ip                VARCHAR(45) NOT NULL,       -- IPv4 ou IPv6
    user_agent        VARCHAR(255) NULL,

    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_jc_date (created_at),
    KEY idx_jc_user (utilisateur_id, created_at),
    KEY idx_jc_etab (etablissement_id, created_at),
    KEY idx_jc_ip (ip, created_at),
    KEY idx_jc_email (email_saisi, created_at),

    CONSTRAINT fk_jc_utilisateur
        FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_jc_etablissement
        FOREIGN KEY (etablissement_id) REFERENCES etablissement(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- JOURNAL DES ACTIONS (qui a créé / modifié / supprimé quoi)
-- =====================================================

CREATE TABLE journal_action (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    acteur_id         BIGINT UNSIGNED NULL,
    etablissement_id  BIGINT UNSIGNED NULL,
    action            VARCHAR(50)  NOT NULL,      -- ex : USER_CREATE, ETAB_UPDATE, IMPORT
    cible             VARCHAR(255) NULL,          -- libellé lisible de l'objet concerné
    details           TEXT NULL,

    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_ja_date (created_at),
    KEY idx_ja_etab (etablissement_id, created_at),

    CONSTRAINT fk_ja_acteur
        FOREIGN KEY (acteur_id) REFERENCES utilisateur(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_ja_etablissement
        FOREIGN KEY (etablissement_id) REFERENCES etablissement(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- TRIGGERS : cohérence email <-> établissement
--   prenom.nom@0592222X.reseaucerta.org
-- =====================================================

DELIMITER $$

CREATE TRIGGER trg_utilisateur_bi
BEFORE INSERT ON utilisateur
FOR EACH ROW
BEGIN
    DECLARE v_uai CHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_suffix VARBINARY(100);

    SET NEW.email = LOWER(TRIM(NEW.email));

    IF NEW.etablissement_id IS NOT NULL THEN
        SELECT uai INTO v_uai FROM etablissement WHERE id = NEW.etablissement_id;
        SET v_suffix = CONCAT('@', LOWER(v_uai), '.reseaucerta.org');

        IF SUBSTRING_INDEX(NEW.email, '@', 1) NOT REGEXP '^[a-z0-9._-]+$'
           OR BINARY RIGHT(NEW.email, CHAR_LENGTH(v_suffix)) <> v_suffix THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Email invalide : attendu prenom.nom@<uai>.reseaucerta.org';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_utilisateur_bu
BEFORE UPDATE ON utilisateur
FOR EACH ROW
BEGIN
    DECLARE v_uai CHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_suffix VARBINARY(100);

    SET NEW.email = LOWER(TRIM(NEW.email));

    IF NEW.etablissement_id IS NOT NULL THEN
        SELECT uai INTO v_uai FROM etablissement WHERE id = NEW.etablissement_id;
        SET v_suffix = CONCAT('@', LOWER(v_uai), '.reseaucerta.org');

        IF SUBSTRING_INDEX(NEW.email, '@', 1) NOT REGEXP '^[a-z0-9._-]+$'
           OR BINARY RIGHT(NEW.email, CHAR_LENGTH(v_suffix)) <> v_suffix THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Email invalide : attendu prenom.nom@<uai>.reseaucerta.org';
        END IF;
    END IF;
END$$

DELIMITER ;


-- =====================================================
-- REFERENTIEL DES ACADEMIES
-- =====================================================

INSERT INTO academie (code, nom) VALUES
    ('ac-aix-marseille', 'Aix-Marseille'),
    ('ac-amiens',        'Amiens'),
    ('ac-besancon',      'Besançon'),
    ('ac-bordeaux',      'Bordeaux'),
    ('ac-clermont',      'Clermont-Ferrand'),
    ('ac-corse',         'Corse'),
    ('ac-creteil',       'Créteil'),
    ('ac-dijon',         'Dijon'),
    ('ac-grenoble',      'Grenoble'),
    ('ac-guadeloupe',    'Guadeloupe'),
    ('ac-guyane',        'Guyane'),
    ('ac-lille',         'Lille'),
    ('ac-limoges',       'Limoges'),
    ('ac-lyon',          'Lyon'),
    ('ac-martinique',    'Martinique'),
    ('ac-mayotte',       'Mayotte'),
    ('ac-montpellier',   'Montpellier'),
    ('ac-nancy-metz',    'Nancy-Metz'),
    ('ac-nantes',        'Nantes'),
    ('ac-nice',          'Nice'),
    ('ac-normandie',     'Normandie'),
    ('ac-orleans-tours', 'Orléans-Tours'),
    ('ac-paris',         'Paris'),
    ('ac-poitiers',      'Poitiers'),
    ('ac-reims',         'Reims'),
    ('ac-rennes',        'Rennes'),
    ('ac-reunion',       'La Réunion'),
    ('ac-strasbourg',    'Strasbourg'),
    ('ac-toulouse',      'Toulouse'),
    ('ac-versailles',    'Versailles');

-- Le compte SUPER_ADMIN se crée avec :  php scripts/create_superadmin.php
