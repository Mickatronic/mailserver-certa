-- Appliquer une fois sur la base reseau_certa existante après une sauvegarde.
-- Remplace les comparaisons REGEXP entre collations implicites par une
-- validation du préfixe et une comparaison binaire du suffixe de domaine.

USE reseau_certa;

DROP TRIGGER IF EXISTS trg_utilisateur_bi;
DROP TRIGGER IF EXISTS trg_utilisateur_bu;

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
