-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db
-- Generation Time: Mar 08, 2026 at 01:17 AM
-- Server version: 8.0.44
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `csk_services`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`%` PROCEDURE `changer_statut_echantillon` (IN `p_code_echantillon` VARCHAR(50), IN `p_nouveau_statut` VARCHAR(50), IN `p_idutilisateur` INT, IN `p_observation` TEXT, IN `p_action` VARCHAR(100))   BEGIN
    DECLARE v_idechantillon INT;
    DECLARE v_ancien_statut VARCHAR(50);
    
    -- Récupérer ID et ancien statut
    SELECT idechantillon, statut 
    INTO v_idechantillon, v_ancien_statut
    FROM labo_echantillons 
    WHERE code_echantillon = p_code_echantillon;
    
    -- Mettre à jour statut
    UPDATE labo_echantillons 
    SET statut = p_nouveau_statut,
        updated_at = NOW()
    WHERE idechantillon = v_idechantillon;
    
    -- Ajouter à l'historique
    INSERT INTO labo_workflow_history (
        idechantillon,
        ancien_statut,
        nouveau_statut,
        action,
        idutilisateur,
        observation
    ) VALUES (
        v_idechantillon,
        v_ancien_statut,
        p_nouveau_statut,
        p_action,
        p_idutilisateur,
        p_observation
    );
    
    -- Retourner succès
    SELECT CONCAT('Statut changé de ', v_ancien_statut, ' à ', p_nouveau_statut) as message;
END$$

CREATE DEFINER=`root`@`%` PROCEDURE `generer_rapport_quotidien_labo` (IN `p_date` DATE)   BEGIN
    SELECT 
        p_date as date_rapport,
        COUNT(*) as total_echantillons,
        SUM(CASE WHEN statut = 'resultat_transmis' THEN 1 ELSE 0 END) as termines,
        SUM(CASE WHEN statut IN ('rejete', 'perdu') THEN 1 ELSE 0 END) as rejetes,
        SUM(CASE WHEN urgence = 1 THEN 1 ELSE 0 END) as urgents,
        AVG(TIMESTAMPDIFF(MINUTE, date_reception, date_fin_analyse)) as delai_moyen_analyse_min,
        GROUP_CONCAT(DISTINCT type_prelevement) as types_prelevements
    FROM labo_echantillons
    WHERE DATE(created_at) = p_date;
END$$

CREATE DEFINER=`root`@`%` PROCEDURE `sp_creer_token_resultat` (IN `p_code_echantillon` VARCHAR(50), IN `p_idresultat` INT, IN `p_email_destinataire` VARCHAR(255), IN `p_duree_validite_heures` INT, IN `p_created_by` INT, OUT `p_token` VARCHAR(64), OUT `p_success` BOOLEAN, OUT `p_message` VARCHAR(255))   BEGIN
    DECLARE v_token VARCHAR(64);
    DECLARE v_date_expiration DATETIME;
    
    -- Générer le token
    SET v_token = fn_generer_token_resultat();
    
    -- Calculer date expiration
    IF p_duree_validite_heures IS NOT NULL THEN
        SET v_date_expiration = DATE_ADD(NOW(), INTERVAL p_duree_validite_heures HOUR);
    ELSE
        SET v_date_expiration = NULL; -- Pas d'expiration
    END IF;
    
    -- Insérer le token
    INSERT INTO labo_resultats_tokens
        (token, code_echantillon, idresultat, email_destinataire, 
         date_creation, date_expiration, created_by, actif)
    VALUES
        (v_token, p_code_echantillon, p_idresultat, p_email_destinataire,
         NOW(), v_date_expiration, p_created_by, 1);
    
    SET p_token = v_token;
    SET p_success = TRUE;
    SET p_message = 'Token créé avec succès';
    
END$$

CREATE DEFINER=`root`@`%` PROCEDURE `sp_marquer_token_consulte` (IN `p_token` VARCHAR(64), IN `p_ip` VARCHAR(45))   BEGIN
    UPDATE labo_resultats_tokens
    SET nb_consultations = nb_consultations + 1,
        vu_le = COALESCE(vu_le, NOW()),
        ip_derniere_consultation = p_ip
    WHERE token = p_token
    AND actif = 1;
END$$

CREATE DEFINER=`root`@`%` PROCEDURE `sp_nettoyer_tokens_expires` ()   BEGIN
    DECLARE v_nb_desactives INT;
    DECLARE v_nb_supprimes INT;
    
    -- Désactiver les tokens expirés
    UPDATE labo_resultats_tokens
    SET actif = 0
    WHERE date_expiration < NOW()
    AND actif = 1;
    
    SET v_nb_desactives = ROW_COUNT();
    
    -- Supprimer les tokens de plus de 30 jours (optionnel)
    DELETE FROM labo_resultats_tokens
    WHERE date_creation < DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND (vu_le IS NOT NULL OR date_expiration < NOW());
    
    SET v_nb_supprimes = ROW_COUNT();
    
    SELECT 
        v_nb_desactives as tokens_desactives,
        v_nb_supprimes as tokens_supprimes,
        NOW() as date_nettoyage;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`%` FUNCTION `calculer_delai_traitement` (`p_code_echantillon` VARCHAR(50)) RETURNS INT DETERMINISTIC BEGIN
    DECLARE v_delai_min INT;
    
    SELECT TIMESTAMPDIFF(MINUTE, created_at, 
        COALESCE(date_fin_analyse, NOW()))
    INTO v_delai_min
    FROM labo_echantillons
    WHERE code_echantillon = p_code_echantillon;
    
    RETURN v_delai_min;
END$$

CREATE DEFINER=`root`@`%` FUNCTION `fn_generer_token_resultat` () RETURNS VARCHAR(64) CHARSET utf8mb4 DETERMINISTIC BEGIN
    -- Génère un token aléatoire de 64 caractères (SHA256)
    RETURN SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_examens`
--

CREATE TABLE `imagerie_examens` (
  `idexamen` int NOT NULL,
  `code_examen` varchar(50) NOT NULL,
  `idactes_presc` int NOT NULL,
  `idpatient` int NOT NULL,
  `idsejour` int DEFAULT NULL,
  `idsous_sejour` int DEFAULT NULL,
  `statut` enum('programme','accueil','en_preparation','en_acquisition','acquisition_terminee','en_reconstruction','en_interpretation','compte_rendu_fait','validation_radiologue','validation_chef','transmis','annule') DEFAULT 'programme',
  `date_examen` datetime DEFAULT NULL,
  `date_rdv` datetime DEFAULT NULL,
  `date_debut_examen` datetime DEFAULT NULL,
  `date_fin_examen` datetime DEFAULT NULL,
  `date_debut_cr` datetime DEFAULT NULL,
  `date_fin_cr` datetime DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `motif_annulation` text,
  `salle` varchar(50) DEFAULT NULL,
  `equipement` varchar(100) DEFAULT NULL,
  `duree_estimee_min` int DEFAULT NULL,
  `secretaire_accueil` int DEFAULT NULL,
  `manipulateur` int DEFAULT NULL,
  `radiologue` int DEFAULT NULL,
  `radiologue_validateur` int DEFAULT NULL,
  `type_examen` varchar(100) DEFAULT NULL,
  `protocole_utilise` varchar(100) DEFAULT NULL,
  `parametres_acquisition` json DEFAULT NULL,
  `produits_contraste` text,
  `dose_contraste` varchar(50) DEFAULT NULL,
  `idresultat_imagerie` int DEFAULT NULL,
  `compte_rendu_text` text,
  `conclusion` text,
  `recommandations` text,
  `chemin_images` json DEFAULT NULL,
  `fichier_pdf` varchar(255) DEFAULT NULL,
  `taille_fichier_mo` int DEFAULT NULL,
  `qualite_images` enum('excellente','bonne','moyenne','mauvaise') DEFAULT NULL,
  `artefacts` text,
  `reprise_acquisition` tinyint(1) DEFAULT '0',
  `motif_reprise` text,
  `urgence` tinyint(1) DEFAULT '0',
  `priorite` enum('programme','urgence','extreme_urgence') DEFAULT 'programme',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `imagerie_examens`
--

INSERT INTO `imagerie_examens` (`idexamen`, `code_examen`, `idactes_presc`, `idpatient`, `idsejour`, `idsous_sejour`, `statut`, `date_examen`, `date_rdv`, `date_debut_examen`, `date_fin_examen`, `date_debut_cr`, `date_fin_cr`, `date_validation`, `motif_annulation`, `salle`, `equipement`, `duree_estimee_min`, `secretaire_accueil`, `manipulateur`, `radiologue`, `radiologue_validateur`, `type_examen`, `protocole_utilise`, `parametres_acquisition`, `produits_contraste`, `dose_contraste`, `idresultat_imagerie`, `compte_rendu_text`, `conclusion`, `recommandations`, `chemin_images`, `fichier_pdf`, `taille_fichier_mo`, `qualite_images`, `artefacts`, `reprise_acquisition`, `motif_reprise`, `urgence`, `priorite`, `created_at`, `updated_at`, `deleted_at`, `deleted_by`) VALUES
(1, 'IMG-20250211-0001', 52, 4, 1, 41, 'transmis', NULL, '2026-01-21 14:00:00', NULL, NULL, NULL, NULL, NULL, NULL, 'Salle Radio 1', NULL, NULL, NULL, NULL, NULL, NULL, 'Radiographie Abdomen sans Préparation', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-02-11 09:46:55', '2026-02-24 07:35:42', NULL, NULL),
(2, 'IMG-20250211-0002', 44, 4, 1, 41, 'validation_radiologue', NULL, '2026-01-20 10:30:00', NULL, '2026-02-24 16:54:34', '2026-02-24 16:54:39', '2026-02-24 16:55:24', '2026-02-24 17:16:34', NULL, 'Salle Ophtalmo', NULL, NULL, NULL, NULL, 1, 1, 'Réfraction', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-01-20 09:00:00', '2026-02-24 16:16:34', NULL, NULL),
(3, 'IMG-20260218-0001', 61, 22, 71, 67, 'validation_radiologue', NULL, NULL, NULL, NULL, '2026-02-24 14:13:44', '2026-02-24 17:05:03', '2026-02-24 17:05:39', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, 'Échographie Pelvienne', NULL, NULL, NULL, NULL, NULL, 'zarbi', 'ok', 'bien', NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-02-18 11:50:47', '2026-02-24 16:05:39', NULL, NULL),
(4, 'IMG-20260224-0001', 72, 22, 71, 67, 'validation_radiologue', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Échographie Abdominale', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-02-24 03:12:10', '2026-02-24 12:30:09', NULL, NULL),
(5, 'IMG-20260224-0002', 82, 4, 42, 42, 'validation_radiologue', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Échographie Mammaire', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-02-24 07:36:19', '2026-02-24 08:00:37', NULL, NULL),
(6, 'IMG-20260224-0003', 83, 4, 65, 65, 'validation_radiologue', NULL, NULL, '2026-02-24 14:14:14', '2026-02-24 14:14:20', '2026-02-24 15:35:43', '2026-02-24 16:02:49', '2026-02-24 16:04:01', NULL, NULL, NULL, NULL, NULL, 1, 1, 1, 'Échographie Mammaire', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-02-24 09:15:42', '2026-02-24 15:04:01', NULL, NULL),
(7, 'IMG-20260224-0004', 84, 22, 71, 67, 'validation_radiologue', NULL, NULL, '2026-02-24 17:21:22', '2026-02-24 17:21:46', '2026-02-24 17:24:04', '2026-02-24 17:24:14', '2026-02-24 17:25:22', NULL, 'utpr', NULL, NULL, NULL, 1, 1, 1, 'Échographie Abdominale', NULL, NULL, NULL, NULL, NULL, 'on s\'en sort', 'pas mal du tout', 'y croire jusqu\'à la fin', NULL, NULL, NULL, 'bonne', 'non', 0, NULL, 0, 'programme', '2026-02-24 09:25:04', '2026-02-24 16:25:22', NULL, NULL),
(8, 'IMG-20260224-0005', 85, 22, 71, 67, 'validation_radiologue', NULL, '2026-02-24 11:23:47', '2026-02-24 13:41:57', '2026-02-24 13:42:06', '2026-02-24 13:42:18', '2026-02-24 17:24:42', '2026-02-24 17:25:03', NULL, NULL, NULL, NULL, NULL, 1, 1, 1, 'Échographie Abdominale', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-02-24 10:23:47', '2026-02-24 16:25:03', NULL, NULL),
(9, 'IMG-20260224-0006', 85, 22, NULL, NULL, 'validation_radiologue', NULL, '2026-02-25 09:30:00', '2026-02-25 07:46:47', '2026-02-25 07:47:04', '2026-02-25 07:47:17', '2026-02-25 07:48:19', '2026-02-25 07:49:10', NULL, 'box2', NULL, NULL, NULL, 1, 1, 1, 'radiographie', NULL, NULL, NULL, NULL, NULL, 'Essai', 'Technique appliquée', 'Chez vous!!!', NULL, NULL, NULL, 'excellente', 'oui', 0, NULL, 0, 'programme', '2026-02-24 10:23:48', '2026-02-26 02:13:12', NULL, NULL),
(10, 'IMG-20260225-0001', 86, 4, 44, 44, 'validation_radiologue', NULL, '2026-02-25 00:59:30', '2026-02-25 02:00:15', '2026-02-25 02:00:29', '2026-02-25 02:00:39', '2026-02-25 02:01:49', '2026-02-25 02:18:40', NULL, 'box3', NULL, NULL, NULL, 1, 1, 1, 'Échographie Obstétricale', NULL, NULL, NULL, NULL, NULL, '1234567', 'bon', 'ok', NULL, NULL, NULL, 'bonne', NULL, 0, NULL, 0, 'programme', '2026-02-25 00:59:30', '2026-02-25 05:18:45', NULL, NULL),
(11, 'IMG-20260226-0001', 91, 1, 23, 23, 'validation_radiologue', NULL, '2026-02-26 19:22:01', '2026-02-26 20:46:31', '2026-02-26 20:46:43', '2026-02-26 20:46:53', '2026-02-26 20:47:09', '2026-02-26 20:47:21', NULL, NULL, NULL, NULL, NULL, 1, 1, 1, 'Échographie Thyroïdienne', NULL, NULL, NULL, NULL, NULL, 'WFSDFJKL', 'VJBKKLNL', 'VFJKBLN', NULL, NULL, NULL, 'bonne', NULL, 0, NULL, 0, 'programme', '2026-02-26 18:22:01', '2026-02-26 19:47:21', NULL, NULL),
(12, 'IMG-20260226-0002', 91, 1, NULL, NULL, 'validation_radiologue', NULL, '2026-02-27 09:30:00', '2026-02-26 19:23:30', '2026-02-26 19:23:58', '2026-02-26 19:24:03', '2026-02-26 19:24:54', '2026-02-26 19:25:03', NULL, NULL, NULL, NULL, NULL, 1, 1, 1, 'radiographie', NULL, NULL, NULL, NULL, NULL, 'lmljpi^p', 'xfjfvblk', 'bfjkl', NULL, NULL, NULL, 'bonne', NULL, 0, NULL, 0, 'programme', '2026-02-26 18:22:01', '2026-02-26 18:25:03', NULL, NULL),
(13, 'IMG-20260304-0001', 119, 1, 23, 23, 'programme', NULL, '2026-03-04 13:06:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Échographie Testiculaire', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-03-04 12:06:28', '2026-03-04 12:06:28', NULL, NULL),
(14, 'IMG-20260304-0002', 119, 1, NULL, NULL, 'programme', NULL, '2026-03-04 14:00:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'radiographie', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-03-04 12:06:28', '2026-03-04 12:06:28', NULL, NULL),
(15, 'IMG-20260304-0003', 120, 1, 23, 23, 'programme', NULL, '2026-03-04 13:08:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Radiographie Abdomen sans Préparation', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-03-04 12:08:20', '2026-03-04 12:08:20', NULL, NULL),
(16, 'IMG-20260304-0004', 120, 1, NULL, NULL, 'programme', NULL, '2026-03-04 14:30:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'radiographie', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-03-04 12:08:20', '2026-03-04 12:08:20', NULL, NULL),
(17, 'IMG-20260304-0005', 122, 2, 36, 36, 'programme', NULL, '2026-03-04 14:31:27', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Radiographie Thorax Face/Profil', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-03-04 13:31:27', '2026-03-04 13:31:27', NULL, NULL),
(18, 'IMG-20260305-0001', 140, 5, 52, 57, 'programme', NULL, '2026-03-05 20:38:13', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Échographie Pelvienne', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 0, 'programme', '2026-03-05 19:38:13', '2026-03-05 19:38:13', NULL, NULL);

--
-- Triggers `imagerie_examens`
--
DELIMITER $$
CREATE TRIGGER `set_date_rdv_before_insert` BEFORE INSERT ON `imagerie_examens` FOR EACH ROW BEGIN
    IF NEW.statut = 'programme' AND NEW.date_rdv IS NULL THEN
        SET NEW.date_rdv = NOW();
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_fichiers`
--

CREATE TABLE `imagerie_fichiers` (
  `idfichier` int NOT NULL,
  `idexamen` int NOT NULL,
  `nom_fichier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nom original du fichier uploade',
  `chemin_fichier` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Chemin relatif sur le serveur',
  `type_fichier` enum('image','video','pdf','dicom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `taille_octets` bigint UNSIGNED NOT NULL DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `ordre` int NOT NULL DEFAULT '0' COMMENT 'Ordre d affichage dans la galerie',
  `uploaded_by` int NOT NULL COMMENT 'idutilisateur (csk_base)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `imagerie_fichiers`
--

INSERT INTO `imagerie_fichiers` (`idfichier`, `idexamen`, `nom_fichier`, `chemin_fichier`, `type_fichier`, `mime_type`, `taille_octets`, `description`, `ordre`, `uploaded_by`, `created_at`) VALUES
(1, 10, 'Capture d\'écran 2026-02-17 154243 (1).png', 'uploads/imagerie/10/1771983031_0_Capture_d___cran_2026-02-17_154243__1_.png', 'image', 'image/png', 48749, NULL, 1, 1, '2026-02-25 01:30:31'),
(3, 10, 'Monkole flyer (4).pdf', 'uploads/imagerie/10/1771995764_0_Monkole_flyer__4_.pdf', 'pdf', 'application/pdf', 263920, NULL, 2, 1, '2026-02-25 05:02:45'),
(7, 9, 'Capture d\'écran 2026-02-17 154243.png', 'uploads/imagerie/9/1772004102_0_Capture_d___cran_2026-02-17_154243.png', 'image', 'image/png', 48749, NULL, 1, 1, '2026-02-25 07:21:42'),
(8, 9, 'Monkole flyer (4).pdf', 'uploads/imagerie/9/1772004102_1_Monkole_flyer__4_.pdf', 'pdf', 'application/pdf', 263920, NULL, 2, 1, '2026-02-25 07:21:42'),
(10, 9, '1772073070_0_Carin_Le__n_-_Aunque_t___no_lo_sepas__LETRA______.mp4', 'uploads/imagerie/9/1772073070_0_Carin_Le__n_-_Aunque_t___no_lo_sepas__LETRA______.mp4', 'video', 'video/mp4', 3331941, NULL, 3, 1, '2026-02-26 02:31:11'),
(11, 12, 'Carin León - Aunque tú no lo sepas (LETRA) 🎵.mp4', 'uploads/imagerie/12/1772130360_0_Carin_Le__n_-_Aunque_t___no_lo_sepas__LETRA______.mp4', 'video', 'video/mp4', 3331941, NULL, 1, 1, '2026-02-26 18:26:01'),
(12, 12, 'Capture d\'écran 2026-02-17 154243 (1).png', 'uploads/imagerie/12/1772130361_1_Capture_d___cran_2026-02-17_154243__1_.png', 'image', 'image/png', 48749, NULL, 2, 1, '2026-02-26 18:26:01'),
(13, 12, 'Monkole flyer (4).pdf', 'uploads/imagerie/12/1772130361_2_Monkole_flyer__4_.pdf', 'pdf', 'application/pdf', 263920, NULL, 3, 1, '2026-02-26 18:26:01'),
(14, 11, 'Carin León - Aunque tú no lo sepas (LETRA) 🎵.mp4', 'uploads/imagerie/11/1772135287_0_Carin_Le__n_-_Aunque_t___no_lo_sepas__LETRA______.mp4', 'video', 'video/mp4', 3331941, NULL, 1, 1, '2026-02-26 19:48:07'),
(15, 11, 'Capture d\'écran 2026-02-17 154243 (1).png', 'uploads/imagerie/11/1772135287_1_Capture_d___cran_2026-02-17_154243__1_.png', 'image', 'image/png', 48749, NULL, 2, 1, '2026-02-26 19:48:07'),
(16, 11, 'Monkole flyer (4).pdf', 'uploads/imagerie/11/1772135287_2_Monkole_flyer__4_.pdf', 'pdf', 'application/pdf', 263920, NULL, 3, 1, '2026-02-26 19:48:07');

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_planning`
--

CREATE TABLE `imagerie_planning` (
  `idplanning` int NOT NULL,
  `idexamen` int NOT NULL,
  `date_planification` date NOT NULL COMMENT 'Date de l examen',
  `heure_debut` time NOT NULL COMMENT 'Heure de début prévue',
  `heure_fin` time NOT NULL COMMENT 'Heure de fin prévue',
  `idsalle` int DEFAULT NULL COMMENT 'FK vers salle_imagerie',
  `idequipement` int DEFAULT NULL COMMENT 'FK vers equipements_imagerie',
  `priorite` enum('normale','haute','urgente') DEFAULT 'normale',
  `statut` enum('planifie','confirme','en_cours','termine','annule','reporte') DEFAULT 'planifie',
  `notes` text,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_salles`
--

CREATE TABLE `imagerie_salles` (
  `idsalle` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `type` enum('radio','echo','scanner','irm','mammo','dentaire','hybride') NOT NULL,
  `equipements` text COMMENT 'Liste des équipements disponibles',
  `capacite_journaliere` int DEFAULT '10' COMMENT 'Nombre max d examens par jour',
  `temps_standard_min` int DEFAULT '30' COMMENT 'Durée standard en minutes',
  `actif` tinyint(1) DEFAULT '1',
  `observations` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `imagerie_salles`
--

INSERT INTO `imagerie_salles` (`idsalle`, `nom`, `code`, `type`, `equipements`, `capacite_journaliere`, `temps_standard_min`, `actif`, `observations`, `created_at`) VALUES
(1, 'Salle Radio 1', 'RAD-01', 'radio', NULL, 10, 30, 1, NULL, '2026-02-24 01:35:54'),
(2, 'Salle Radio 2', 'RAD-02', 'radio', NULL, 10, 30, 1, NULL, '2026-02-24 01:35:54'),
(3, 'Salle Échographie', 'ECH-01', 'echo', NULL, 10, 30, 1, NULL, '2026-02-24 01:35:54'),
(4, 'Salle Scanner', 'SCAN-01', 'scanner', NULL, 10, 30, 1, NULL, '2026-02-24 01:35:54'),
(5, 'Salle IRM', 'IRM-01', 'irm', NULL, 10, 30, 1, NULL, '2026-02-24 01:35:54');

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_salle_equipements`
--

CREATE TABLE `imagerie_salle_equipements` (
  `idsalle` int NOT NULL,
  `idequipement` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_tokens`
--

CREATE TABLE `imagerie_tokens` (
  `id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `code_examen` varchar(50) NOT NULL,
  `idfichier` int DEFAULT NULL,
  `email_destinataire` varchar(255) DEFAULT NULL,
  `date_creation` datetime NOT NULL,
  `date_expiration` datetime DEFAULT NULL,
  `actif` tinyint DEFAULT '1',
  `vu_le` datetime DEFAULT NULL,
  `nb_consultations` int DEFAULT '0',
  `created_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `imagerie_tokens`
--

INSERT INTO `imagerie_tokens` (`id`, `token`, `code_examen`, `idfichier`, `email_destinataire`, `date_creation`, `date_expiration`, `actif`, `vu_le`, `nb_consultations`, `created_by`) VALUES
(1, 'd098d90f4d6c49c31410023b61387ebe', 'IMG-20260225-0001', NULL, 'admin@monkole.cd', '2026-02-25 04:47:18', '2026-03-04 04:47:18', 0, NULL, 0, 1),
(2, '3d565c696a5fbf5bd076347b856de3c0', 'IMG-20260225-0001', NULL, 'admin@monkole.cd', '2026-02-25 04:53:46', '2026-03-04 04:53:46', 0, '2026-02-25 05:59:07', 8, 1),
(3, 'b21e790dad20dcb3e12355cc73517192', 'IMG-20260224-0004', NULL, 'admin@monkole.cd', '2026-02-25 05:09:00', '2026-03-04 05:09:00', 1, '2026-02-25 05:13:44', 1, 1),
(4, '5df98da777d5a9bb448d8db5e5a4a385', 'IMG-20260224-0004', NULL, 'admin@monkole.cd', '2026-02-25 05:09:00', '2026-03-04 05:09:00', 1, '2026-02-25 05:12:48', 1, 1),
(5, '671c7205e595cd462dcc65a6cdd82825', 'IMG-20260225-0001', NULL, NULL, '2026-02-25 06:03:32', '2026-03-04 06:03:32', 0, NULL, 0, 1),
(6, 'c3eca7675de710589c20030328b139fc', 'IMG-20260225-0001', NULL, 'admin@monkole.cd', '2026-02-25 06:03:39', '2026-03-04 06:03:39', 0, NULL, 0, 1),
(7, 'b9c73a51b2fab9288d7c9cc4b6bcb38e', 'IMG-20260225-0001', NULL, 'admin@monkole.cd', '2026-02-25 06:03:39', '2026-03-04 06:03:39', 0, '2026-02-25 06:04:22', 1, 1),
(8, 'a6bed76fc059454c86d4978a161e793d', 'IMG-20260225-0001', NULL, NULL, '2026-02-25 06:18:45', '2026-03-04 06:18:45', 1, NULL, 0, 1),
(9, '6df86b1c064cf72c3e77ee75376d2d2a', 'IMG-20260225-0001', NULL, 'admin@monkole.cd', '2026-02-25 06:18:55', '2026-03-04 06:18:55', 1, NULL, 0, 1),
(10, '41012c0f2fa092b92ce1ee0bf78645b7', 'IMG-20260225-0001', NULL, 'admin@monkole.cd', '2026-02-25 06:18:55', '2026-03-04 06:18:55', 1, '2026-02-25 07:45:52', 6, 1),
(11, '16864b17457fc7e7f5698ee1c9e0122b', 'IMG-20260224-0006', NULL, NULL, '2026-02-25 07:50:20', '2026-03-04 07:50:20', 0, NULL, 0, 1),
(12, '00805465541b5ac656c4cb0d6021b3c7', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-25 13:02:09', '2026-03-04 13:02:09', 0, NULL, 0, 1),
(13, '2f2581fa52216e681697e80872da82c5', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-25 13:02:10', '2026-03-04 13:02:10', 0, '2026-02-25 13:02:41', 1, 1),
(14, '821ebfcfb97e48360284c450232b5f30', 'IMG-20260224-0006', NULL, NULL, '2026-02-25 13:04:33', '2026-03-04 13:04:33', 0, NULL, 0, 1),
(15, '26830d27e1128f2f0a8d1b9d92c5808f', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-25 13:38:00', '2026-03-04 13:38:00', 0, '2026-02-25 13:38:52', 1, 1),
(16, '081ff5e6f9f62abda93e317e66f5e0d5', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-25 17:41:46', '2026-03-04 17:41:46', 0, NULL, 0, 1),
(17, '7ab1f3147ebcdc0c44d6a7b4d3042475', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:13:13', '2026-03-05 03:13:13', 0, NULL, 0, 1),
(18, '96c733d514af78758a9918a26c0bbdb9', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:13:18', '2026-03-05 03:13:18', 0, NULL, 0, 1),
(19, 'd6c5eb6a1d61b394df6ac4fb625d9ca9', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:16:27', '2026-03-05 03:16:27', 0, NULL, 0, 1),
(20, 'f71bebda76127ef985bb8551ad3fa67a', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:16:31', '2026-03-05 03:16:31', 0, NULL, 0, 1),
(21, 'e9f72e02679259453158c3752e5231d7', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:18:01', '2026-03-05 03:18:01', 0, NULL, 0, 1),
(22, '63f6df75a49bad3ed7118ee6b2c20acd', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:18:59', '2026-03-05 03:18:59', 0, NULL, 0, 1),
(23, '3320d58517b2008001a51913e8cd0e18', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:19:03', '2026-03-05 03:19:03', 0, NULL, 0, 1),
(24, '58a762657956fa2446e7845e45be24df', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:21:20', '2026-03-05 03:21:20', 0, NULL, 0, 1),
(25, 'ea84697eabcd997a01dfbc2f7c667817', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:21:24', '2026-03-05 03:21:24', 0, NULL, 0, 1),
(26, 'f26be9ffbfa67bbb59bb0508ee0f8738', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:28:15', '2026-03-05 03:28:15', 0, NULL, 0, 1),
(27, 'a7b078708cc07ced7598e1102fe02372', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:30:42', '2026-03-05 03:30:42', 0, NULL, 0, 1),
(28, 'cc0e46e29ed685e34e321894bca0eea8', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:30:53', '2026-03-05 03:30:53', 0, NULL, 0, 1),
(29, '42f3e6f947be859a7da286f9ddf936ea', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:33:39', '2026-03-05 03:33:39', 0, NULL, 0, 1),
(30, 'b895516a1cc3c073845e5b995c7b54d2', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:33:53', '2026-03-05 03:33:53', 0, NULL, 0, 1),
(31, '1d83bba60bcaf0d67ab90855ccdebcbc', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:34:16', '2026-03-05 03:34:16', 0, NULL, 0, 1),
(32, '69afbf15b0f074f3577811022da7a1fe', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:34:20', '2026-03-05 03:34:20', 0, NULL, 0, 1),
(33, '5e63787d0eaa808f68773e5030c96843', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:35:49', '2026-03-05 03:35:49', 0, NULL, 0, 1),
(34, 'cbb452471ae9808e2dbdf6a868055bd7', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:35:53', '2026-03-05 03:35:53', 0, NULL, 0, 1),
(35, '967ebcfabd920303c1eba57edb0cd214', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:37:10', '2026-03-05 03:37:10', 0, NULL, 0, 1),
(36, 'd7c2be00149535bb5eb7dc4a44dd1a54', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:37:13', '2026-03-05 03:37:13', 0, NULL, 0, 1),
(37, 'd4963675ef2c1af1fc030f8e7598d235', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:38:20', '2026-03-05 03:38:20', 0, NULL, 0, 1),
(38, 'af147ae46f6fc878293e72dbb1eabf39', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:38:26', '2026-03-05 03:38:26', 0, NULL, 0, 1),
(39, '4d3b2f76feac3e010a3abdff03dde3e1', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:40:00', '2026-03-05 03:40:00', 0, NULL, 0, 1),
(40, '00a044abafdfbc3ce11e43af941e4372', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:40:05', '2026-03-05 03:40:05', 0, NULL, 0, 1),
(41, 'eabf7681c52541d8cfe5b83a57569635', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:40:42', '2026-03-05 03:40:42', 0, NULL, 0, 1),
(42, 'a755c662cdaa615a4e83969ffa49b9f7', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:40:49', '2026-03-05 03:40:49', 0, NULL, 0, 1),
(43, '028a045a072d84ee8dc94b160d65f049', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:43:16', '2026-03-05 03:43:16', 0, NULL, 0, 1),
(44, '7b0a2ed57048afdcdad58a3c60484990', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:43:19', '2026-03-05 03:43:19', 0, NULL, 0, 1),
(45, 'd762c1aa6bd12ed473e8e2c6013e16bd', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 03:44:18', '2026-03-05 03:44:18', 0, NULL, 0, 1),
(46, 'a97ab15e1e2673d38ae237bedfcb835f', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:44:22', '2026-03-05 03:44:22', 0, NULL, 0, 1),
(47, 'b84244845f43d9e1d9ef6964b861686d', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 03:50:41', '2026-03-05 03:50:41', 0, '2026-02-26 15:32:15', 9, 1),
(48, '9a16b331d48a311e7762dc911b69d964', 'IMG-20260224-0006', NULL, NULL, '2026-02-26 15:50:22', '2026-03-05 15:50:22', 1, '2026-02-26 16:14:50', 7, 1),
(49, '7ffdefb24af35c445267b320c7f805d8', 'IMG-20260224-0006', NULL, 'admin@monkole.cd', '2026-02-26 15:50:34', '2026-03-05 15:50:34', 1, NULL, 0, 1),
(50, 'ff7f59f39001e0aad09f5681571ff126', 'IMG-20260226-0002', NULL, NULL, '2026-02-26 19:25:11', '2026-03-05 19:25:11', 1, '2026-03-04 09:23:22', 3, 1),
(51, '3f9b4a5034345d57d9e318eaa4f5976d', 'IMG-20260226-0002', NULL, 'admin@monkole.cd', '2026-02-26 19:26:27', '2026-03-05 19:26:27', 1, '2026-02-26 20:42:43', 1, 1),
(52, 'fc10cbfb7eac6394348930d0711ba02b', 'IMG-20260226-0001', NULL, NULL, '2026-02-26 20:47:28', '2026-03-05 20:47:28', 0, NULL, 0, 1),
(53, 'a6ca994a0f61a143e0cbee7393eae1d9', 'IMG-20260226-0001', NULL, NULL, '2026-02-26 20:48:20', '2026-03-05 20:48:20', 1, '2026-02-26 20:49:31', 1, 1),
(54, '9b51998774de61ef5effa6154db45de2', 'IMG-20260226-0001', NULL, 'admin@monkole.cd', '2026-02-26 20:48:24', '2026-03-05 20:48:24', 1, '2026-03-04 09:23:20', 3, 1);

-- --------------------------------------------------------

--
-- Table structure for table `imagerie_workflow_history`
--

CREATE TABLE `imagerie_workflow_history` (
  `idhistory` int NOT NULL,
  `idexamen` int NOT NULL,
  `ancien_statut` varchar(50) DEFAULT NULL,
  `nouveau_statut` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `idutilisateur` int NOT NULL,
  `observation` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `imagerie_workflow_history`
--

INSERT INTO `imagerie_workflow_history` (`idhistory`, `idexamen`, `ancien_statut`, `nouveau_statut`, `action`, `idutilisateur`, `observation`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, NULL, 'programme', 'Programmation examen', 1, 'Prescription recue - Radiographie Abdomen sans Preparation', NULL, NULL, '2026-02-11 09:50:00'),
(2, 2, NULL, 'programme', 'Programmation examen', 1, 'Prescription recue - Refraction ophtalmologie', NULL, NULL, '2026-01-20 09:00:00'),
(3, 2, 'programme', 'accueil', 'Accueil patient', 1, 'Patient accueilli au service imagerie', NULL, NULL, '2026-01-20 09:15:00'),
(4, 2, 'accueil', 'en_preparation', 'Preparation salle', 1, 'Salle Ophtalmo preparee', NULL, NULL, '2026-01-20 09:30:00'),
(5, 2, 'en_preparation', 'en_acquisition', 'Debut acquisition', 1, 'Acquisition en cours', NULL, NULL, '2026-01-20 10:00:00'),
(6, 1, NULL, 'programme', 'Programmation examen', 1, 'Prescription recue - Radiographie Abdomen sans Preparation', NULL, NULL, '2026-02-11 09:50:00'),
(7, 2, NULL, 'programme', 'Programmation examen', 1, 'Prescription recue - Refraction ophtalmologie', NULL, NULL, '2026-01-20 09:00:00'),
(8, 2, 'programme', 'accueil', 'Accueil patient', 1, 'Patient accueilli au service imagerie', NULL, NULL, '2026-01-20 09:15:00'),
(9, 2, 'accueil', 'en_preparation', 'Preparation salle', 1, 'Salle Ophtalmo preparee', NULL, NULL, '2026-01-20 09:30:00'),
(10, 2, 'en_preparation', 'en_acquisition', 'Debut acquisition', 1, 'Acquisition en cours', NULL, NULL, '2026-01-20 10:00:00'),
(11, 1, 'programme', 'accueil', 'Accueillir patient', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:11:11'),
(12, 1, 'accueil', 'en_preparation', 'Preparer salle', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:12:17'),
(13, 1, 'en_preparation', 'en_acquisition', 'Demarrer acquisition', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:12:45'),
(14, 1, 'en_acquisition', 'acquisition_terminee', 'Terminer acquisition', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:12:52'),
(15, 1, 'acquisition_terminee', 'en_reconstruction', 'Envoyer en reconstruction', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:13:04'),
(16, 1, 'en_reconstruction', 'en_interpretation', 'Envoyer en interpretation', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:13:12'),
(17, 1, 'en_interpretation', 'compte_rendu_fait', 'Finaliser compte-rendu', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:13:18'),
(18, 1, 'compte_rendu_fait', 'validation_radiologue', 'Valider (radiologue)', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:13:24'),
(19, 1, 'validation_radiologue', 'validation_chef', 'Valider (chef service)', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:13:50'),
(20, 1, 'validation_chef', 'transmis', 'Transmettre resultats', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:13:57'),
(21, 3, 'programme', 'accueil', 'Accueillir patient', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 01:59:58'),
(22, 3, 'accueil', 'en_preparation', 'Preparer salle', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 02:00:08'),
(23, 3, 'en_preparation', 'en_acquisition', 'Demarrer acquisition', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 02:00:15'),
(24, 3, 'en_acquisition', 'acquisition_terminee', 'Terminer acquisition', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 02:00:31'),
(25, 5, 'programme', 'accueil', 'Démarrer examen', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 08:00:00'),
(26, 5, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 08:00:07'),
(27, 5, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 08:00:17'),
(28, 5, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 08:00:25'),
(29, 5, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, '', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 08:00:37'),
(30, 9, NULL, 'programme', 'Création avec planification le 25/02/2026 09:30', 1, 'Prescription créée depuis app services', NULL, NULL, '2026-02-24 10:23:48'),
(31, 4, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-24 12:29:19'),
(32, 4, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-24 12:29:30'),
(33, 4, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 12:29:34'),
(34, 4, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 12:29:53'),
(35, 4, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 12:30:09'),
(36, 8, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-24 12:41:57'),
(37, 8, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-24 12:42:06'),
(38, 8, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 12:42:18'),
(39, 3, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 13:13:44'),
(40, 6, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-24 13:14:14'),
(41, 6, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-24 13:14:20'),
(42, 6, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 14:35:43'),
(43, 6, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 15:02:49'),
(44, 6, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 15:04:01'),
(45, 2, 'en_acquisition', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-24 15:54:34'),
(46, 2, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 15:54:39'),
(47, 2, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 15:55:24'),
(48, 3, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 16:05:03'),
(49, 3, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 16:05:39'),
(50, 2, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 16:16:34'),
(51, 7, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-24 16:21:22'),
(52, 7, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, 'dfj', NULL, NULL, '2026-02-24 16:21:46'),
(53, 7, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 16:22:52'),
(54, 7, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 16:23:41'),
(55, 7, 'compte_rendu_fait', 'en_interpretation', 'Retourner en rédaction', 1, NULL, NULL, NULL, '2026-02-24 16:24:04'),
(56, 7, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 16:24:14'),
(57, 8, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-24 16:24:42'),
(58, 8, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 16:25:03'),
(59, 7, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-24 16:25:22'),
(60, 10, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-25 01:00:15'),
(61, 10, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-25 01:00:29'),
(62, 10, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 01:00:39'),
(63, 10, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-25 01:01:49'),
(64, 10, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 01:18:40'),
(65, 10, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 02:23:12'),
(66, 10, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 02:23:36'),
(67, 7, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 04:08:49'),
(68, 10, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 05:03:32'),
(69, 10, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 05:18:45'),
(70, 9, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-25 06:46:47'),
(71, 9, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-25 06:47:04'),
(72, 9, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 06:47:17'),
(73, 9, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-25 06:48:19'),
(74, 9, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 06:49:10'),
(75, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-25 12:04:33'),
(76, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:13:12'),
(77, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:16:27'),
(78, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:18:59'),
(79, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:21:19'),
(80, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:30:41'),
(81, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:33:39'),
(82, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:34:16'),
(83, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:35:48'),
(84, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:37:09'),
(85, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:38:17'),
(86, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:40:00'),
(87, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:40:42'),
(88, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:43:16'),
(89, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 02:44:17'),
(90, 9, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 14:50:21'),
(91, 12, NULL, 'programme', 'Création avec planification le 27/02/2026 09:30', 1, 'Prescription créée depuis app services', NULL, NULL, '2026-02-26 18:22:01'),
(92, 12, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-26 18:23:30'),
(93, 12, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-26 18:23:58'),
(94, 12, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 18:24:03'),
(95, 12, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-26 18:24:54'),
(96, 12, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 18:25:03'),
(97, 11, 'programme', 'accueil', 'Démarrer examen', 1, NULL, NULL, NULL, '2026-02-26 19:46:31'),
(98, 11, 'accueil', 'acquisition_terminee', 'Terminer acquisition', 1, NULL, NULL, NULL, '2026-02-26 19:46:43'),
(99, 11, 'acquisition_terminee', 'en_interpretation', 'Démarrer compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 19:46:53'),
(100, 11, 'en_interpretation', 'compte_rendu_fait', 'Soumettre à validation', 1, NULL, NULL, NULL, '2026-02-26 19:47:09'),
(101, 11, 'compte_rendu_fait', 'validation_radiologue', 'Valider compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 19:47:21'),
(102, 11, 'validation_radiologue', 'validation_radiologue', 'Modification du compte-rendu', 1, NULL, NULL, NULL, '2026-02-26 19:48:19'),
(103, 14, NULL, 'programme', 'Création avec planification le 04/03/2026 14:00', 1, 'Prescription créée depuis app services', NULL, NULL, '2026-03-04 12:06:28'),
(104, 16, NULL, 'programme', 'Création avec planification le 04/03/2026 14:30', 1, 'Prescription créée depuis app services', NULL, NULL, '2026-03-04 12:08:20');

-- --------------------------------------------------------

--
-- Table structure for table `labo_echantillons`
--

CREATE TABLE `labo_echantillons` (
  `idechantillon` int NOT NULL,
  `code_echantillon` varchar(50) NOT NULL,
  `idgroupe` int DEFAULT NULL,
  `sous_numero` int DEFAULT NULL,
  `idactes_presc` int NOT NULL,
  `idpatient` int NOT NULL,
  `idsejour` int DEFAULT NULL,
  `idsous_sejour` int DEFAULT NULL,
  `type_prelevement` varchar(50) DEFAULT NULL,
  `tube_type` varchar(30) DEFAULT NULL,
  `couleur_tube` varchar(20) DEFAULT NULL,
  `volume_ml` decimal(5,2) DEFAULT NULL,
  `anticoagulant` varchar(30) DEFAULT NULL,
  `statut` enum('attente_prelevement','preleve','transit','receptionne','controle_qualite','en_analyse','analyse_terminee','validation_technique','validation_biologiste','resultat_transmis','rejete','perdu','annule') DEFAULT 'attente_prelevement',
  `date_prelevement` datetime DEFAULT NULL,
  `preleveur` int DEFAULT NULL,
  `site_prelevement` varchar(100) DEFAULT NULL,
  `heure_jeune` time DEFAULT NULL,
  `conditions_particulieres` text,
  `date_reception` datetime DEFAULT NULL,
  `receveur_labo` int DEFAULT NULL,
  `temperature_reception` decimal(5,2) DEFAULT NULL,
  `qualite_echantillon` enum('excellente','bonne','moyenne','hemolyse','coagule','insuffisant','contamine','tube_casse') DEFAULT NULL,
  `commentaire_qualite` text,
  `date_debut_analyse` datetime DEFAULT NULL,
  `date_fin_analyse` datetime DEFAULT NULL,
  `technicien_analyse` int DEFAULT NULL,
  `idmachinelabo` int DEFAULT NULL,
  `numero_series_machine` varchar(50) DEFAULT NULL,
  `idresultat` int DEFAULT NULL,
  `biologiste_validateur` int DEFAULT NULL,
  `date_validation` datetime DEFAULT NULL,
  `urgence` tinyint(1) DEFAULT '0',
  `priorite` enum('normale','urgente','stat') DEFAULT 'normale',
  `delai_theorique_min` int DEFAULT '120',
  `observations` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `labo_echantillons`
--

INSERT INTO `labo_echantillons` (`idechantillon`, `code_echantillon`, `idgroupe`, `sous_numero`, `idactes_presc`, `idpatient`, `idsejour`, `idsous_sejour`, `type_prelevement`, `tube_type`, `couleur_tube`, `volume_ml`, `anticoagulant`, `statut`, `date_prelevement`, `preleveur`, `site_prelevement`, `heure_jeune`, `conditions_particulieres`, `date_reception`, `receveur_labo`, `temperature_reception`, `qualite_echantillon`, `commentaire_qualite`, `date_debut_analyse`, `date_fin_analyse`, `technicien_analyse`, `idmachinelabo`, `numero_series_machine`, `idresultat`, `biologiste_validateur`, `date_validation`, `urgence`, `priorite`, `delai_theorique_min`, `observations`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'LAB-20250211-0001', NULL, NULL, 47, 4, 1, 41, 'sang_veineux', 'tube_EDTA', 'violet', NULL, NULL, 'resultat_transmis', '2026-01-20 08:30:00', 1, NULL, NULL, NULL, '2026-01-20 09:00:00', 3, NULL, 'bonne', NULL, NULL, '2026-02-17 12:19:03', 28, NULL, NULL, 6, 1, '2026-02-21 08:40:38', 0, 'normale', 120, NULL, '2026-01-20 08:00:00', '2026-02-21 07:40:43', NULL),
(2, 'LAB-20250211-0002', NULL, NULL, 48, 4, 1, 41, 'sang_veineux', 'tube_separateur', 'rouge', NULL, NULL, 'resultat_transmis', '2026-01-20 10:15:00', 1, NULL, NULL, NULL, '2026-01-20 10:30:00', 3, NULL, 'excellente', NULL, '2026-02-17 08:06:33', '2026-02-17 08:06:41', 28, 1, NULL, 4, 1, '2026-02-21 08:41:10', 1, 'urgente', 30, NULL, '2026-01-20 10:00:00', '2026-02-21 07:41:13', NULL),
(3, 'LAB-20250211-0003', NULL, NULL, 49, 4, 1, 41, 'sang_veineux', 'tube_heparine', 'vert', NULL, NULL, 'rejete', '2026-01-20 11:00:00', 1, NULL, NULL, NULL, '2026-01-20 11:20:00', 3, NULL, 'hemolyse', 'Hémolyse importante, échantillon inutilisable', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-01-20 11:00:00', '2026-02-11 09:46:55', NULL),
(4, 'LAB-20250210-0015', NULL, NULL, 1, 1, 1, 1, 'sang_veineux', 'tube_EDTA', 'violet', NULL, NULL, 'resultat_transmis', '2025-12-09 08:00:00', 2, NULL, NULL, NULL, '2025-12-09 08:15:00', 3, NULL, NULL, NULL, '2025-12-09 09:00:00', '2025-12-09 09:30:00', 3, 1, NULL, NULL, 4, '2025-12-09 10:00:00', 1, 'urgente', 120, NULL, '2025-12-09 07:30:00', '2025-12-09 10:00:00', NULL),
(5, 'LAB-20260211-0001', NULL, NULL, 53, 4, 41, 41, NULL, NULL, NULL, NULL, NULL, 'validation_technique', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-17 08:03:15', '2026-02-17 08:03:24', 28, 2, NULL, 2, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-11 09:50:33', '2026-02-17 08:05:26', NULL),
(6, 'LAB-20260211-0002', NULL, NULL, 54, 4, 41, 41, NULL, NULL, NULL, NULL, NULL, 'analyse_terminee', '2026-02-21 09:17:55', 1, NULL, NULL, NULL, '2026-02-21 09:18:03', 1, NULL, NULL, NULL, NULL, '2026-02-21 09:18:15', NULL, NULL, NULL, 7, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-11 17:45:35', '2026-02-21 08:18:32', NULL),
(7, 'LAB-20260217-0001', NULL, NULL, 55, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-17 00:33:42', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(8, 'LAB-20260217-0002', NULL, NULL, 56, 4, 44, 44, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-17 01:32:39', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(9, 'LAB-20260217-0003', NULL, NULL, 57, 4, 65, 65, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-21 09:46:47', 1, NULL, NULL, NULL, '2026-02-21 09:47:03', 1, NULL, 'excellente', NULL, NULL, '2026-02-21 09:47:21', 1, 2, NULL, 8, NULL, '2026-02-21 09:47:34', 1, 'urgente', 30, NULL, '2026-02-17 02:07:05', '2026-02-21 08:48:47', NULL),
(10, 'TEST-20260217-5579', NULL, NULL, 47, 4, NULL, NULL, 'sang_veineux', 'edta', 'violet', NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-17 02:31:24', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(11, 'LAB-20260217-0005', NULL, NULL, 58, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 02:55:54', 1, NULL, NULL, NULL, '2026-02-23 02:56:02', 1, NULL, 'bonne', NULL, '2026-02-23 02:56:02', '2026-02-23 02:56:09', 1, 1, NULL, 22, NULL, '2026-02-23 02:56:19', 0, 'normale', 120, NULL, '2026-02-17 02:32:34', '2026-02-23 01:57:07', NULL),
(12, 'LAB-20260217-3414', NULL, NULL, 58, 22, NULL, NULL, 'lch', 'flacon_sterile', 'jaune', NULL, NULL, 'validation_technique', '2026-02-22 08:38:49', 1, NULL, NULL, NULL, '2026-02-22 08:38:59', 1, NULL, 'bonne', NULL, '2026-02-22 08:38:59', '2026-02-22 08:39:10', 1, 2, NULL, 21, NULL, '2026-02-22 08:39:16', 0, 'normale', 120, NULL, '2026-02-17 02:32:34', '2026-02-22 07:39:30', NULL),
(13, 'LAB-20260217-0007', NULL, NULL, 59, 4, 65, 65, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-19 18:32:46', 28, NULL, NULL, NULL, '2026-02-19 18:33:13', 28, NULL, NULL, NULL, '2026-02-19 18:33:27', '2026-02-19 18:33:38', 28, 2, NULL, 5, NULL, NULL, 1, 'urgente', 30, NULL, '2026-02-17 02:55:36', '2026-02-19 18:35:54', NULL),
(14, 'LAB-20260217-3415', NULL, NULL, 59, 4, NULL, NULL, 'ecouvillonnage', 'serum', 'bleu', 6.00, 'Citrate', 'validation_technique', NULL, NULL, 'autre', NULL, 'oui', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 16, NULL, NULL, 1, 'urgente', 120, NULL, '2026-02-17 02:55:36', '2026-02-21 10:41:55', NULL),
(15, 'LAB-20260217-0009', NULL, NULL, 60, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-21 12:13:12', 1, NULL, NULL, NULL, '2026-02-21 12:13:15', 1, NULL, NULL, NULL, '2026-02-21 12:13:15', '2026-02-21 12:13:18', 1, 3, NULL, 18, NULL, '2026-02-21 12:13:24', 0, 'normale', 120, NULL, '2026-02-17 03:03:29', '2026-02-21 11:13:59', NULL),
(16, 'LAB-20260217-3416', NULL, NULL, 60, 22, NULL, NULL, 'ecouvillonnage', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-02-22 08:23:41', 1, 'autre', NULL, NULL, '2026-02-22 08:23:52', 1, NULL, 'moyenne', NULL, '2026-02-22 08:23:52', '2026-02-22 08:24:01', 1, 3, NULL, 20, NULL, '2026-02-22 08:24:11', 0, 'normale', 120, NULL, '2026-02-17 03:03:29', '2026-02-22 07:24:45', NULL),
(17, 'LAB-20260218-0001', NULL, NULL, 62, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-21 11:49:07', 1, NULL, NULL, NULL, '2026-02-21 11:49:12', 1, NULL, NULL, NULL, '2026-02-21 11:49:12', '2026-02-21 11:49:16', 1, 2, NULL, 17, NULL, '2026-02-21 11:49:24', 0, 'normale', 120, NULL, '2026-02-18 12:19:30', '2026-02-21 10:49:43', NULL),
(18, 'LAB-20260218-0002', NULL, NULL, 62, 22, NULL, NULL, 'liquide_ascite', 'serum', 'noir', NULL, 'Citrate (VS)', 'validation_technique', '2026-02-21 11:18:09', 1, 'autre', NULL, NULL, '2026-02-21 11:18:16', 1, NULL, NULL, NULL, '2026-02-21 11:18:16', '2026-02-21 11:18:19', 1, NULL, NULL, 15, NULL, '2026-02-21 11:26:32', 0, 'normale', 120, NULL, '2026-02-18 12:19:30', '2026-02-21 10:39:48', NULL),
(19, 'LAB-20260218-0003', NULL, NULL, 63, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'analyse_terminee', '2026-02-21 11:06:44', 1, NULL, NULL, NULL, '2026-02-21 11:06:50', 1, NULL, NULL, NULL, '2026-02-21 11:06:50', '2026-02-21 11:06:53', 1, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-18 12:19:30', '2026-02-21 10:06:53', NULL),
(20, 'LAB-20260218-0004', NULL, NULL, 63, 22, NULL, NULL, 'autre', 'ecouvillon', 'violet', NULL, 'EDTA', 'validation_technique', '2026-02-21 11:04:55', 1, 'autre', NULL, NULL, '2026-02-21 11:05:01', 1, NULL, 'bonne', NULL, '2026-02-21 11:05:01', '2026-02-21 11:05:05', 1, 1, NULL, 19, NULL, '2026-02-22 07:26:55', 0, 'normale', 120, NULL, '2026-02-18 12:19:30', '2026-02-22 06:27:54', NULL),
(21, 'LAB-20260219-0001', NULL, NULL, 64, 4, 65, 65, NULL, NULL, NULL, NULL, NULL, 'analyse_terminee', '2026-02-21 10:36:25', 1, NULL, NULL, NULL, '2026-02-21 10:36:33', 1, NULL, 'bonne', NULL, '2026-02-21 10:36:33', '2026-02-21 10:36:37', 1, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-19 18:30:50', '2026-02-21 09:36:37', NULL),
(22, 'LAB-20260219-0002', NULL, NULL, 64, 4, NULL, NULL, 'sang_veineux', 'pot_selles', 'jaune', NULL, NULL, 'analyse_terminee', '2026-02-21 10:26:20', 1, 'autre', NULL, NULL, '2026-02-21 10:26:30', 1, NULL, 'bonne', NULL, '2026-02-21 10:26:30', '2026-02-21 10:26:43', 1, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-19 18:30:50', '2026-02-21 09:26:43', NULL),
(23, 'LAB-20260219-0003', NULL, NULL, 65, 4, 65, 65, NULL, NULL, NULL, NULL, NULL, 'analyse_terminee', '2026-02-21 10:11:06', 1, NULL, NULL, NULL, '2026-02-21 10:11:16', 1, NULL, 'excellente', NULL, '2026-02-21 10:11:16', '2026-02-21 10:11:23', 1, 2, NULL, 10, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-19 18:30:50', '2026-02-21 09:11:59', NULL),
(24, 'LAB-20260219-0004', NULL, NULL, 65, 4, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-02-21 09:53:11', 1, 'bras_droit', NULL, NULL, '2026-02-21 09:53:18', 1, NULL, 'excellente', NULL, NULL, '2026-02-21 09:53:35', 1, NULL, NULL, 9, NULL, '2026-02-21 09:53:42', 0, 'normale', 120, NULL, '2026-02-19 18:30:51', '2026-02-21 08:54:53', NULL),
(25, 'LAB-20260223-0001', NULL, NULL, 66, 4, 44, 44, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 02:58:47', 1, NULL, NULL, NULL, '2026-02-23 02:58:53', 1, NULL, 'excellente', NULL, '2026-02-23 02:58:53', '2026-02-23 02:59:00', 1, 2, NULL, 23, NULL, '2026-02-23 02:59:03', 0, 'normale', 120, NULL, '2026-02-23 01:58:20', '2026-02-23 01:59:53', NULL),
(26, 'LAB-20260223-0002', NULL, NULL, 67, 4, 44, 44, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 03:21:06', 1, NULL, NULL, NULL, '2026-02-23 03:21:14', 1, NULL, 'excellente', NULL, '2026-02-23 03:21:14', '2026-02-23 03:21:17', 1, 3, NULL, 24, NULL, '2026-02-23 03:21:19', 0, 'normale', 120, NULL, '2026-02-23 02:20:48', '2026-02-23 02:21:30', NULL),
(27, 'LAB-20260223-0003', NULL, NULL, 68, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 03:35:24', 1, NULL, NULL, NULL, '2026-02-23 03:35:30', 1, NULL, 'excellente', NULL, '2026-02-23 03:35:30', '2026-02-23 03:35:33', 1, 1, NULL, 25, NULL, '2026-02-23 03:35:36', 0, 'normale', 120, NULL, '2026-02-23 02:35:12', '2026-02-23 02:35:56', NULL),
(28, 'LAB-20260223-0004', NULL, NULL, 68, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, 'autre', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-23 02:35:13', '2026-02-23 02:35:13', NULL),
(29, 'LAB-20260223-0005', NULL, NULL, 69, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 04:21:27', 1, NULL, NULL, NULL, '2026-02-23 04:21:33', 1, NULL, 'bonne', NULL, '2026-02-23 04:21:33', '2026-02-23 04:21:37', 1, 2, NULL, 26, NULL, '2026-02-23 04:21:40', 0, 'normale', 120, NULL, '2026-02-23 03:21:10', '2026-02-23 03:22:15', NULL),
(30, 'LAB-20260223-0006', NULL, NULL, 69, 22, NULL, NULL, 'autre', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, 'autre', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-23 03:21:17', '2026-02-23 03:21:17', NULL),
(31, 'LAB-20260223-0007', NULL, NULL, 70, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 05:29:55', 1, NULL, NULL, NULL, '2026-02-23 05:29:57', 1, NULL, NULL, NULL, '2026-02-23 05:29:57', '2026-02-23 05:30:00', 1, NULL, NULL, 27, NULL, '2026-02-23 05:30:02', 0, 'normale', 120, NULL, '2026-02-23 04:29:48', '2026-02-23 04:30:04', NULL),
(32, 'LAB-20260223-0008', NULL, NULL, 70, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-23 04:29:48', '2026-02-23 04:29:48', NULL),
(33, 'LAB-20260223-0009', NULL, NULL, 71, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-23 05:40:25', 1, NULL, NULL, NULL, '2026-02-23 05:40:28', 1, NULL, NULL, NULL, '2026-02-23 05:40:28', '2026-02-23 05:40:31', 1, NULL, NULL, 28, NULL, '2026-02-23 05:40:34', 0, 'normale', 120, NULL, '2026-02-23 04:40:19', '2026-02-23 04:40:50', NULL),
(34, 'LAB-20260223-0010', NULL, NULL, 71, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-23 04:40:19', '2026-02-23 04:40:19', NULL),
(35, 'LAB-20260224-0001', NULL, NULL, 77, 4, 44, 44, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-24 03:48:24', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(36, 'LAB-20260225-0001', NULL, NULL, 87, 4, 44, 44, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-25 16:50:32', 1, NULL, NULL, NULL, '2026-02-25 16:50:42', 1, NULL, 'bonne', NULL, '2026-02-25 16:50:42', '2026-02-25 16:50:53', 1, 2, NULL, 29, NULL, '2026-02-25 16:51:04', 0, 'normale', 120, NULL, '2026-02-25 15:49:59', '2026-02-25 15:51:44', NULL),
(37, 'LAB-20260225-0002', NULL, NULL, 88, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-26 19:08:47', 28, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 41, NULL, '2026-03-05 11:10:57', 1, 'urgente', 30, NULL, '2026-02-25 15:55:13', '2026-03-05 10:10:57', NULL),
(38, 'LAB-20260225-0003', NULL, NULL, 88, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-02-25 16:56:36', 1, NULL, NULL, NULL, '2026-02-25 16:56:38', 1, NULL, NULL, NULL, '2026-02-25 16:56:38', '2026-02-25 16:56:40', 1, NULL, NULL, 30, NULL, '2026-02-25 16:56:43', 1, 'urgente', 120, NULL, '2026-02-25 15:55:13', '2026-02-25 15:56:46', NULL),
(39, 'LAB-20260226-0001', NULL, NULL, 89, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-26 20:40:54', 28, NULL, NULL, NULL, '2026-02-26 20:41:06', 28, NULL, NULL, NULL, '2026-02-26 20:41:06', '2026-02-26 20:41:57', 28, NULL, NULL, 34, NULL, '2026-02-26 20:41:59', 1, 'urgente', 30, NULL, '2026-02-26 17:58:47', '2026-02-26 19:42:07', NULL),
(40, 'LAB-20260226-0002', NULL, NULL, 89, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'validation_technique', '2026-02-26 20:24:21', 28, 'bras_gauche', NULL, NULL, '2026-02-26 20:25:06', 28, NULL, 'bonne', NULL, '2026-02-26 20:25:06', '2026-02-26 20:25:28', 28, 2, NULL, 32, NULL, '2026-02-26 20:25:35', 1, 'urgente', 120, NULL, '2026-02-26 17:58:47', '2026-02-26 19:26:53', NULL),
(41, 'LAB-20260226-0003', NULL, NULL, 90, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-03-05 10:51:56', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 40, NULL, '2026-03-05 10:53:02', 1, 'urgente', 30, NULL, '2026-02-26 17:58:47', '2026-03-05 09:53:02', NULL),
(42, 'LAB-20260226-0004', NULL, NULL, 90, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-02-26 18:59:42', 28, 'autre', NULL, NULL, '2026-02-26 19:00:19', 28, NULL, 'bonne', NULL, '2026-02-26 19:00:19', '2026-02-26 19:00:45', 28, 2, NULL, 31, NULL, '2026-02-26 19:00:57', 1, 'urgente', 120, NULL, '2026-02-26 17:58:47', '2026-02-26 18:05:56', NULL),
(43, 'LAB-20260226-0005', NULL, NULL, 92, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:22:05', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(44, 'LAB-20260226-0006', NULL, NULL, 92, 22, NULL, NULL, 'sang_arteriel', 'serum', 'rouge', NULL, NULL, 'attente_prelevement', NULL, NULL, 'main_droite', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:22:05', '2026-02-26 19:22:05', NULL),
(45, 'LAB-20260226-0007', NULL, NULL, 93, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:22:05', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(46, 'LAB-20260226-0008', NULL, NULL, 93, 22, NULL, NULL, 'sang_arteriel', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, 'main_droite', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:22:05', '2026-02-26 19:22:05', NULL),
(47, 'LAB-20260226-0009', NULL, NULL, 94, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:29:40', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(48, 'LAB-20260226-0010', NULL, NULL, 94, 1, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'attente_prelevement', NULL, NULL, 'main_gauche', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:29:40', '2026-02-26 19:29:40', NULL),
(49, 'LAB-20260226-0011', NULL, NULL, 95, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:25', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(50, 'LAB-20260226-0012', NULL, NULL, 95, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'attente_prelevement', NULL, NULL, 'main_droite', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:25', '2026-02-26 19:32:25', NULL),
(51, 'LAB-20260226-0013', NULL, NULL, 96, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:25', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(52, 'LAB-20260226-0014', NULL, NULL, 96, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:26', '2026-02-26 19:32:26', NULL),
(53, 'LAB-20260226-0015', NULL, NULL, 97, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:26', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(54, 'LAB-20260226-0016', NULL, NULL, 97, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:26', '2026-02-26 19:32:26', NULL),
(55, 'LAB-20260226-0017', NULL, NULL, 98, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-02-26 20:33:04', 28, NULL, NULL, NULL, '2026-02-26 20:33:13', 28, NULL, 'excellente', NULL, '2026-02-26 20:33:13', '2026-02-26 20:34:09', 28, 2, NULL, 33, NULL, '2026-02-26 20:34:18', 0, 'normale', 120, NULL, '2026-02-26 19:32:26', '2026-02-26 19:35:12', NULL),
(56, 'LAB-20260226-0018', NULL, NULL, 98, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-26 19:32:26', '2026-02-26 19:32:26', NULL),
(57, 'LAB-20260227-0001', NULL, NULL, 99, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:53', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(58, 'LAB-20260227-0001-01', 1, 1, 99, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'receptionne', '2026-02-27 16:28:42', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:54', '2026-02-27 15:29:07', NULL),
(59, 'LAB-20260227-0003', NULL, NULL, 100, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:54', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(60, 'LAB-20260227-0001-02', 1, 2, 100, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'preleve', '2026-02-27 16:28:43', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 59, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:55', '2026-03-06 13:09:54', NULL),
(61, 'LAB-20260227-0005', NULL, NULL, 101, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:55', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(62, 'LAB-20260227-0001-03', 1, 3, 101, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'preleve', '2026-02-27 16:28:43', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 60, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:55', '2026-03-06 13:09:54', NULL),
(63, 'LAB-20260227-0007', NULL, NULL, 102, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:56', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(64, 'LAB-20260227-0001-04', 1, 4, 102, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'preleve', '2026-02-27 16:28:43', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 61, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:56', '2026-03-06 13:09:54', NULL),
(65, 'LAB-20260227-0009', NULL, NULL, 103, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:56', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(66, 'LAB-20260227-0001-05', 1, 5, 103, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'preleve', '2026-02-27 16:28:43', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 62, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 04:13:56', '2026-03-06 13:09:54', NULL),
(67, 'LAB-20260227-0011', NULL, NULL, 104, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(68, 'LAB-20260227-0012', NULL, NULL, 104, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-02-27 05:38:01', NULL),
(69, 'LAB-20260227-0013', NULL, NULL, 105, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(70, 'LAB-20260227-0014', NULL, NULL, 105, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-02-27 05:38:01', NULL),
(71, 'LAB-20260227-0015', NULL, NULL, 106, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(72, 'LAB-20260227-0016', NULL, NULL, 106, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-02-27 05:38:01', NULL),
(73, 'LAB-20260227-0002-01', 2, 1, 104, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-02-27 08:31:41', 1, NULL, NULL, NULL, '2026-02-27 08:32:05', 1, NULL, NULL, NULL, '2026-02-27 08:32:05', '2026-02-27 08:32:11', 1, NULL, NULL, 35, NULL, '2026-02-27 08:32:15', 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-03-04 13:57:18', NULL),
(74, 'LAB-20260227-0002-02', 2, 2, 105, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-03-04 14:51:42', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 36, NULL, '2026-03-04 14:52:35', 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-03-04 13:57:18', NULL),
(75, 'LAB-20260227-0002-03', 2, 3, 106, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-03-04 14:52:23', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 37, NULL, '2026-03-04 14:55:12', 0, 'normale', 120, NULL, '2026-02-27 05:38:01', '2026-03-04 13:57:18', NULL),
(76, 'LAB-20260227-0020', NULL, NULL, 107, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 10:24:12', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(77, 'LAB-20260227-0021', NULL, NULL, 108, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 10:24:12', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(78, 'LAB-20260227-0022', NULL, NULL, 109, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 10:24:12', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(79, 'LAB-20260227-0023', NULL, NULL, 110, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 13:33:14', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(80, 'LAB-20260227-0024', NULL, NULL, 111, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 13:33:14', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(81, 'LAB-20260227-0025', NULL, NULL, 112, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 15:30:26', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(82, 'LAB-20260227-0026', NULL, NULL, 112, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-02-27 15:30:28', '2026-02-27 15:30:28', NULL),
(83, 'LAB-20260304-0001', NULL, NULL, 113, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 08:56:40', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(84, 'LAB-20260304-0002', NULL, NULL, 113, 1, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, 'autre', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 08:56:40', '2026-03-04 08:56:40', NULL),
(85, 'LAB-20260304-0003', NULL, NULL, 114, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 08:56:40', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(86, 'LAB-20260304-0004', NULL, NULL, 114, 1, NULL, NULL, 'sang_veineux', 'vacutainer', 'jaune', NULL, NULL, 'attente_prelevement', NULL, NULL, 'autre', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 08:56:40', '2026-03-04 08:56:40', NULL),
(87, 'LAB-20260304-0005', NULL, NULL, 115, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 09:13:18', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(88, 'LAB-20260304-0001-01', 3, 1, 115, 1, NULL, NULL, 'ecouvillonnage', 'vacutainer', 'gris', NULL, 'Fluorure/Oxalate', 'receptionne', '2026-03-04 10:14:10', 1, 'bras_droit', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 09:13:18', '2026-03-04 09:14:22', NULL),
(89, 'LAB-20260304-0007', NULL, NULL, 116, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 09:13:18', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(90, 'LAB-20260304-0001-02', 3, 2, 116, 1, NULL, NULL, 'sang_veineux', 'vacutainer', 'jaune', NULL, NULL, 'validation_technique', '2026-03-04 10:14:10', 1, 'autre', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 38, NULL, '2026-03-04 15:16:39', 0, 'normale', 120, NULL, '2026-03-04 09:13:18', '2026-03-04 14:16:39', NULL),
(91, 'LAB-20260304-0009', NULL, NULL, 117, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 09:26:08', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(92, 'LAB-20260304-0001-03', 3, 3, 117, 1, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'validation_technique', '2026-03-04 15:01:56', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 39, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 09:26:08', '2026-03-04 15:37:43', NULL),
(93, 'LAB-20260304-0011', NULL, NULL, 118, 4, 65, 65, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 10:59:47', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(94, 'LAB-20260304-0012', NULL, NULL, 118, 4, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, 'EDTA', 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 10:59:47', '2026-03-04 10:59:47', NULL),
(95, 'LAB-20260304-0013', NULL, NULL, 121, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-04 13:29:23', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(96, 'LAB-20260304-0014', NULL, NULL, 123, 1, 23, 23, NULL, NULL, NULL, NULL, NULL, 'validation_technique', '2026-03-05 11:17:37', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 42, NULL, '2026-03-05 11:18:17', 0, 'normale', 120, NULL, '2026-03-04 14:59:01', '2026-03-05 10:18:17', NULL),
(97, 'LAB-20260305-0001', NULL, NULL, 124, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:22:07', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(98, 'LAB-20260305-0002', NULL, NULL, 125, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:22:07', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(99, 'LAB-20260305-0003', NULL, NULL, 126, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:34:12', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(100, 'LAB-20260305-0002-01', 5, 1, 126, 2, NULL, NULL, 'sang_arteriel', 'flacon_sterile', 'jaune', NULL, NULL, 'preleve', '2026-03-06 11:12:39', 1, 'bras_droit', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 48, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:34:12', '2026-03-06 10:13:16', NULL),
(101, 'LAB-20260305-0005', NULL, NULL, 127, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:34:12', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(102, 'LAB-20260305-0002-02', 5, 2, 127, 2, NULL, NULL, 'sang_veineux', 'edta', 'rouge', NULL, NULL, 'preleve', '2026-03-06 11:12:41', 1, 'bras_gauche', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 49, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:34:12', '2026-03-06 10:13:17', NULL),
(103, 'LAB-20260305-0007', NULL, NULL, 128, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:48:20', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(104, 'LAB-20260305-0003-01', 6, 1, 128, 22, NULL, NULL, 'sang_arteriel', 'serum', 'rouge', NULL, NULL, 'preleve', '2026-03-06 01:25:22', 1, 'bras_gauche', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 56, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:48:20', '2026-03-06 12:58:23', NULL),
(105, 'LAB-20260305-0009', NULL, NULL, 129, 22, 71, 67, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:48:20', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(106, 'LAB-20260305-0003-02', 6, 2, 129, 22, NULL, NULL, 'sang_arteriel', 'serum', 'rouge', NULL, NULL, 'preleve', '2026-03-06 13:57:12', 1, 'bras_gauche', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 57, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 10:48:20', '2026-03-06 12:58:24', NULL),
(107, 'LAB-20260305-0011', NULL, NULL, 130, 2, 36, 36, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 12:05:17', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(108, 'LAB-20260305-0002-03', 5, 3, 130, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'preleve', '2026-03-06 11:12:44', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 50, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 12:05:17', '2026-03-06 10:13:17', NULL),
(109, 'LAB-20260305-0013', NULL, NULL, 131, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 13:46:41', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(110, 'LAB-20260305-0001-01', 4, 1, 131, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-06 00:43:47', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 44, NULL, '2026-03-06 01:28:13', 0, 'normale', 120, NULL, '2026-03-05 13:46:41', '2026-03-06 00:28:13', NULL),
(111, 'LAB-20260305-0015', NULL, NULL, 132, 21, 66, 66, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 13:46:41', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(112, 'LAB-20260305-0001-02', 4, 2, 132, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-06 00:43:49', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 45, NULL, '2026-03-06 01:28:14', 0, 'normale', 120, NULL, '2026-03-05 13:46:41', '2026-03-06 00:28:14', NULL),
(113, 'LAB-20260305-0017', NULL, NULL, 133, 3, 33, 33, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 14:42:01', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(114, 'LAB-20260305-0004-01', 7, 1, 133, 3, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-08 02:12:41', 1, 'main_gauche', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, 68, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 14:42:01', '2026-03-08 01:12:54', NULL),
(115, 'LAB-20260305-0019', NULL, NULL, 134, 3, 33, 33, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 14:42:01', '2026-03-05 15:05:46', '2026-03-05 15:05:46'),
(116, 'LAB-20260305-0004-02', 7, 2, 134, 3, NULL, NULL, 'sang_veineux', 'vacutainer', 'jaune', NULL, NULL, 'attente_prelevement', NULL, NULL, 'autre', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 14:42:01', '2026-03-05 14:42:01', NULL),
(117, 'LAB-20260305-0002-04', 5, 4, 135, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'preleve', '2026-03-06 11:12:47', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 51, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 18:45:51', '2026-03-06 10:13:17', NULL),
(118, 'LAB-20260305-0004-03', 7, 3, 136, 3, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 18:50:28', '2026-03-05 18:50:28', NULL),
(119, 'LAB-20260305-0004-04', 7, 4, 137, 3, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 18:50:28', '2026-03-05 18:50:28', NULL),
(121, 'LAB-20260305-0024', NULL, NULL, 138, 5, 52, 57, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 19:36:43', '2026-03-05 19:36:43', NULL),
(122, 'LAB-20260305-0005-01', 8, 1, 138, 5, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'preleve', '2026-03-05 22:00:25', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, 53, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 19:36:43', '2026-03-06 12:16:07', NULL),
(123, 'LAB-20260305-0026', NULL, NULL, 139, 5, 52, 57, NULL, NULL, NULL, NULL, NULL, 'attente_prelevement', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 19:36:43', '2026-03-05 19:36:43', NULL),
(124, 'LAB-20260305-0005-02', 8, 2, 139, 5, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'preleve', '2026-03-05 22:00:28', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, 54, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 19:36:43', '2026-03-06 12:16:07', NULL),
(125, 'LAB-20260305-0001-03', 4, 3, 141, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'gris', NULL, NULL, 'validation_technique', '2026-03-06 00:43:52', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 46, NULL, '2026-03-06 01:28:14', 0, 'normale', 120, NULL, '2026-03-05 19:54:13', '2026-03-06 00:28:14', NULL),
(129, 'LAB-20260305-0006-01', 9, 1, 149, 4, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-06 14:09:20', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 63, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 20:36:25', '2026-03-07 08:36:29', NULL),
(130, 'LAB-20260305-0006-02', 9, 2, 150, 4, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-06 14:09:22', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 64, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 20:36:25', '2026-03-07 08:36:29', NULL),
(131, 'LAB-20260305-0006-03', 9, 3, 151, 4, NULL, NULL, 'sang_veineux', 'vacutainer', 'jaune', NULL, NULL, 'validation_technique', '2026-03-06 14:09:25', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 65, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 20:42:43', '2026-03-07 08:36:29', NULL),
(132, 'LAB-20260305-0003-03', 6, 3, 152, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'jaune', NULL, NULL, 'preleve', '2026-03-06 13:57:16', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, 58, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 20:53:48', '2026-03-06 12:58:24', NULL),
(133, 'LAB-20260305-0007-01', 10, 1, 153, 20, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'validation_technique', '2026-03-05 21:58:21', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 43, NULL, '2026-03-06 00:13:21', 0, 'normale', 120, NULL, '2026-03-05 20:56:54', '2026-03-05 23:13:21', NULL),
(134, 'LAB-20260305-0001-04', 4, 4, 154, 21, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-06 00:43:54', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 47, NULL, '2026-03-06 01:28:14', 0, 'normale', 120, NULL, '2026-03-05 22:35:37', '2026-03-06 00:28:14', NULL),
(135, 'LAB-20260305-0005-03', 8, 3, 155, 5, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'preleve', '2026-03-06 13:14:00', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, 55, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 22:36:54', '2026-03-06 12:16:07', NULL),
(136, 'LAB-20260305-0002-05', 5, 5, 156, 2, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'preleve', '2026-03-06 11:12:50', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL, 52, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-05 22:38:05', '2026-03-06 10:13:17', NULL),
(137, 'LAB-20260308-0001-01', 11, 1, 157, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'violet', NULL, NULL, 'validation_technique', '2026-03-08 01:59:23', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, 66, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-08 00:59:06', '2026-03-08 01:00:33', NULL),
(138, 'LAB-20260308-0001-02', 11, 2, 158, 22, NULL, NULL, 'sang_veineux', 'vacutainer', 'rouge', NULL, NULL, 'validation_technique', '2026-03-08 01:59:25', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, 67, NULL, NULL, 0, 'normale', 120, NULL, '2026-03-08 00:59:06', '2026-03-08 01:00:33', NULL);

--
-- Triggers `labo_echantillons`
--
DELIMITER $$
CREATE TRIGGER `after_labo_echantillon_update` AFTER UPDATE ON `labo_echantillons` FOR EACH ROW BEGIN
    DECLARE v_nouveau_statut_base VARCHAR(20);
    
    -- Déterminer le statut dans csk_base.actes_presc
    IF NEW.statut IN ('resultat_transmis') THEN
        SET v_nouveau_statut_base = 'termine';
    ELSEIF NEW.statut IN ('en_analyse', 'validation_technique', 'validation_biologiste') THEN
        SET v_nouveau_statut_base = 'en_cours';
    ELSE
        SET v_nouveau_statut_base = 'en_attente';
    END IF;
    
    -- Mettre à jour csk_base
    UPDATE csk_base.actes_presc
    SET statut_execution = v_nouveau_statut_base,
        date_execution = CASE 
            WHEN v_nouveau_statut_base = 'termine' THEN NOW()
            ELSE date_execution
        END,
        executeur = CASE 
            WHEN NEW.technicien_analyse IS NOT NULL THEN NEW.technicien_analyse
            ELSE executeur
        END
    WHERE idactes_presc = OLD.idactes_presc;
    
    -- Si résultat transmis, créer une notification pour le prescripteur
    IF NEW.statut = 'resultat_transmis' AND OLD.statut != 'resultat_transmis' THEN
        INSERT INTO csk_services.services_notifications (
            service,
            type_notification,
            id_reference,
            table_reference,
            code_reference,
            titre,
            message,
            id_destinataire,
            priorite,
            created_at
        )
        SELECT 
            'labo',
            'info',
            NEW.idactes_presc,
            'labo_echantillons',
            NEW.code_echantillon,
            'Résultats disponibles',
            CONCAT('Les résultats pour l'échantillon ', NEW.code_echantillon, ' sont disponibles'),
            ap.prescripteur,
            'haute',
            NOW()
        FROM csk_base.actes_presc ap
        WHERE ap.idactes_presc = NEW.idactes_presc;
    END IF;
END
$$
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `labo_echantillon_resultats`
--

CREATE TABLE `labo_echantillon_resultats` (
  `idechantillon` int NOT NULL,
  `idresultat` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `labo_groupes_echantillons`
--

CREATE TABLE `labo_groupes_echantillons` (
  `idgroupe` int NOT NULL,
  `code_groupe` varchar(50) NOT NULL,
  `idpatient` int NOT NULL,
  `idsejour` int DEFAULT NULL,
  `idsous_sejour` int DEFAULT NULL,
  `date_creation` datetime NOT NULL,
  `created_by` int DEFAULT NULL,
  `observation_generale` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `labo_groupes_echantillons`
--

INSERT INTO `labo_groupes_echantillons` (`idgroupe`, `code_groupe`, `idpatient`, `idsejour`, `idsous_sejour`, `date_creation`, `created_by`, `observation_generale`) VALUES
(1, 'LAB-20260227-0001', 21, NULL, 66, '2026-02-27 05:13:52', 1, ''),
(2, 'LAB-20260227-0002', 21, NULL, 66, '2026-02-27 06:38:01', 1, ''),
(3, 'LAB-20260304-0001', 1, NULL, 23, '2026-03-04 10:13:18', 1, NULL),
(4, 'LAB-20260305-0001', 21, NULL, NULL, '2026-03-05 11:22:07', NULL, NULL),
(5, 'LAB-20260305-0002', 2, NULL, NULL, '2026-03-05 11:34:12', NULL, NULL),
(6, 'LAB-20260305-0003', 22, NULL, NULL, '2026-03-05 11:48:20', NULL, NULL),
(7, 'LAB-20260305-0004', 3, NULL, NULL, '2026-03-05 15:42:01', NULL, NULL),
(8, 'LAB-20260305-0005', 5, NULL, NULL, '2026-03-05 20:36:43', NULL, NULL),
(9, 'LAB-20260305-0006', 4, NULL, NULL, '2026-03-05 21:36:25', NULL, NULL),
(10, 'LAB-20260305-0007', 20, NULL, NULL, '2026-03-05 21:56:54', NULL, NULL),
(11, 'LAB-20260308-0001', 22, NULL, NULL, '2026-03-08 01:59:06', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `labo_groupes_tokens`
--

CREATE TABLE `labo_groupes_tokens` (
  `id` int NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `code_groupe` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `email_destinataire` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_creation` datetime NOT NULL,
  `date_expiration` datetime DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `vu_le` datetime DEFAULT NULL,
  `nb_consultations` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `ip_derniere_consultation` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `labo_resultats_tokens`
--

CREATE TABLE `labo_resultats_tokens` (
  `id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `code_echantillon` varchar(50) NOT NULL,
  `idresultat` int NOT NULL,
  `email_destinataire` varchar(255) DEFAULT NULL,
  `date_creation` datetime NOT NULL,
  `date_expiration` datetime DEFAULT NULL,
  `actif` tinyint DEFAULT '1',
  `vu_le` datetime DEFAULT NULL,
  `nb_consultations` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `ip_derniere_consultation` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `labo_resultats_tokens`
--

INSERT INTO `labo_resultats_tokens` (`id`, `token`, `code_echantillon`, `idresultat`, `email_destinataire`, `date_creation`, `date_expiration`, `actif`, `vu_le`, `nb_consultations`, `created_by`, `ip_derniere_consultation`) VALUES
(1, '0985bc913ba3a554c3f7d70d97e5048e4744d7e6a77f0b0cb168af3acbc1f13b', 'LAB-20260217-0009', 18, 'papykibete@csk.com', '2026-02-21 12:13:59', '2026-02-28 12:13:59', 1, NULL, 0, 1, NULL),
(4, 'ec52b85537fb237808788b5b301c5e3cef98ad71746145458448745a3517c97f', 'LAB-20260217-3414', 21, 'papykibete@csk.com', '2026-02-22 08:39:30', '2026-03-01 08:39:30', 1, NULL, 0, 1, NULL),
(5, '7b524944e9e0124f09dbbf780010fbfd4be6a833ba55cc6ef10b451c20451935', 'LAB-20260217-0005', 22, 'papykibete@csk.com', '2026-02-23 02:57:07', '2026-03-02 02:57:07', 1, NULL, 0, 1, NULL),
(6, 'c24f18b1526c1de30d2436f3bfdd9239d084462588e3e41e6724134ea687078c', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-23 02:59:54', '2026-03-02 02:59:54', 1, NULL, 0, 1, NULL),
(7, 'a48903643b79abef8786d1a2537577bba6898db2de12b508481517278de5a945', 'LAB-20260223-0002', 24, 'admin@monkole.cd', '2026-02-23 03:21:31', '2026-03-02 03:21:31', 1, '2026-02-23 03:21:57', 1, 1, NULL),
(8, '0334eea7d7d06cd37fb2e92c05df81b69c8e25132db47735b3e46447721dbd65', 'LAB-20260223-0003', 25, 'admin@monkole.cd', '2026-02-23 03:35:56', '2026-03-02 03:35:56', 1, '2026-02-23 03:36:29', 1, 1, NULL),
(9, '9daa54a415f8ac904c636aaaf960c4ca729416b6c2b4e5c88b6cb220704a44e5', 'LAB-20260223-0005', 26, 'admin@monkole.cd', '2026-02-23 04:22:15', '2026-03-02 04:22:15', 1, '2026-02-23 05:19:49', 16, 1, NULL),
(10, 'cd6aa1e5b595d5bfcb5c1d5edcb7898de07e831bc32b2b383411856da8cd4445', 'LAB-20260223-0007', 27, 'admin@monkole.cd', '2026-02-23 05:30:04', '2026-03-02 05:30:04', 1, '2026-02-23 05:30:23', 1, 1, NULL),
(11, 'abe3941f2f0683f2a61cb956ff9f0a092c7f2835b415480ed090b68eb25f524b', 'LAB-20260223-0009', 28, 'admin@monkole.cd', '2026-02-23 05:40:50', '2026-03-02 05:40:50', 1, '2026-02-23 05:44:54', 2, 1, NULL),
(12, 'c3763a78f7024d49e645f440653b0baa516e446bec130b15d32c357700005e2f', 'LAB-20260217-3415', 16, 'papykibete@csk.com', '2026-02-23 06:05:06', '2026-03-02 06:05:06', 1, NULL, 0, 1, NULL),
(13, '4fff5a9d3e917509150be1225a8c882247427816c0cb34f7539e5869ec798622', 'LAB-20260223-0009', 28, 'admin@monkole.cd', '2026-02-23 06:08:42', '2026-03-02 06:08:42', 1, NULL, 0, 1, NULL),
(14, '0289752ea9e22e8a3a540feddb426652c74047800212034312f542e28846c1a4', 'LAB-20260223-0009', 28, 'admin@monkole.cd', '2026-02-23 06:10:17', '2026-03-02 06:10:17', 1, NULL, 0, 1, NULL),
(15, '3ecebae04a2401091a81cb735fe2d0104d636607605b8c79cded3f894f6d4436', 'LAB-20260223-0009', 28, 'admin@monkole.cd', '2026-02-23 06:15:08', '2026-03-02 06:15:08', 1, '2026-02-23 06:21:43', 1, 1, NULL),
(16, '3ee89a48b0154de43d6d87f4960ae0f5f606ef63ac0c3c66fe35eac1a61b467b', 'LAB-20260223-0009', 28, 'admin@monkole.cd', '2026-02-23 06:22:52', '2026-03-02 06:22:52', 1, '2026-02-25 13:38:35', 6, 1, NULL),
(17, '7776101356c0a34c39cabb261334862cc04ce651e81bf6ec5c551a42097af9af', 'LAB-20260217-0003', 8, 'papykibete@csk.com', '2026-02-25 04:35:41', '2026-03-04 04:35:41', 1, NULL, 0, 1, NULL),
(18, 'd7b0818c01b32dd561ac1aa1ee36a75b141f5e9a88c9f2d0e37c74b4c75ad27d', 'LAB-20260217-3415', 16, 'papykibete@csk.com', '2026-02-25 04:57:46', '2026-03-04 04:57:46', 1, NULL, 0, 1, NULL),
(19, 'dc4a43b382f86b7e445da8f74b1223b05db31418e6afedad3fc924bfe5e19e0a', 'LAB-20260225-0001', 29, 'admin@monkole.cd', '2026-02-25 16:51:44', '2026-03-04 16:51:44', 1, '2026-02-26 16:04:26', 7, 1, NULL),
(20, '0492043952c83520c9aa5d0617bfe5e0891a3e8864ca8c2ea52e327465cc8aa3', 'LAB-20260225-0003', 30, 'admin@monkole.cd', '2026-02-25 16:56:46', '2026-03-04 16:56:46', 1, '2026-02-26 19:37:19', 1, 1, NULL),
(21, '5e7dec715d9679460d8d4e72e9454a13ba66c76df5d0cac96e808c86fb8add09', 'LAB-20260226-0004', 31, 'papykibete@csk.com', '2026-02-26 19:05:56', '2026-03-05 19:05:56', 1, '2026-02-26 19:07:37', 1, 28, NULL),
(22, 'f746968bed27a192fccd24521aad85f4ab80a47be75ccce5c7b281f0b358f2df', 'LAB-20260226-0002', 32, 'papykibete@csk.com', '2026-02-26 20:26:54', '2026-03-05 20:26:54', 1, NULL, 0, 28, NULL),
(23, '8d87c2dd7494b22146d258c633c38b2429742016d3c8617396d3bbcd3cec6e7c', 'LAB-20260226-0017', 33, 'papykibete@csk.com', '2026-02-26 20:35:12', '2026-03-05 20:35:12', 1, NULL, 0, 28, NULL),
(24, 'b5ec1e5073078802677f8769ef42a64639775a00b8d3b0f54da1ce7531db9985', 'LAB-20260226-0001', 34, 'papykibete@csk.com', '2026-02-26 20:42:07', '2026-03-05 20:42:07', 1, '2026-02-26 20:43:06', 2, 28, NULL),
(25, '9f953f06d56bca3bd107ab7a1b767224059e6c2cc12b818634f1295f53c1328d', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-27 05:34:43', '2026-03-06 05:34:43', 1, '2026-02-27 05:35:14', 1, 1, NULL),
(26, '59230a1714819722fbec749efb73a6316a4ae620b80ea610d15664265810dd17', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-27 05:34:46', '2026-03-06 05:34:46', 1, NULL, 0, 1, NULL),
(27, 'ecb6fa1575ab9b4e6012aab46b5b4e61e5ddf0a47c4aafc59e3a66fe8135e2c7', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-27 05:39:25', '2026-03-06 05:39:25', 1, '2026-02-27 05:39:38', 1, 1, NULL),
(28, '99b021ba3838a2621f4060959598a594cc850c533b75492c1c86dc60db6e442b', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-27 05:53:12', '2026-03-06 05:53:12', 1, NULL, 0, 1, NULL),
(29, 'ddee6610b5c1451fdfc7f9f97c427bef977fffd86ba6c7f3ae617b8a8b84ab62', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-27 05:53:16', '2026-03-06 05:53:16', 1, '2026-03-04 16:44:50', 7, 1, NULL),
(30, '23526f7f5e8aa6283312b16c3cd702a221bfae4821368f67e3ea1d657811b1b6', 'LAB-20260226-0001', 34, 'papykibete@csk.com', '2026-02-27 05:53:33', '2026-03-06 05:53:33', 1, NULL, 0, 1, NULL),
(31, '08700f0a2a2153bd0725dc81a5fb72eec28869c4fd41238876dd05a0c86a78c3', 'LAB-20260223-0001', 23, 'admin@monkole.cd', '2026-02-27 05:55:15', '2026-03-06 05:55:15', 1, NULL, 0, 1, NULL),
(32, 'd09defaeac089492c8bb69e512754ae273cdc3b9a924d094a105d8074f00c9ba', 'LAB-20260226-0002', 32, 'papykibete@csk.com', '2026-02-27 14:38:13', '2026-03-06 14:38:13', 1, NULL, 0, 1, NULL),
(33, '502edfa9e142a5ee49cf0deda778d056623ebe4614ffdf672f890bcd5dce57e3', 'LAB-20260226-0002', 32, 'papykibete@csk.com', '2026-02-27 18:53:02', '2026-03-06 18:53:02', 1, NULL, 0, 1, NULL),
(34, '6fdca4c37f47483b0c5643b7af7cfae068f395ae53beae5903b6be4bb1340846', 'LAB-20260304-0001-02', 38, 'admin@monkole.cd', '2026-03-04 15:16:40', '2026-03-11 15:16:40', 1, '2026-03-05 11:08:58', 1, 1, NULL),
(35, '7922920e6d29ad06ac3d3e2607c7c2aeb96dd9a16fad5145ed931cc14bdfc184', 'LAB-20260226-0003', 40, 'papykibete@csk.com', '2026-03-05 10:53:04', '2026-03-12 10:53:04', 1, NULL, 0, 1, NULL),
(36, '0f096e4ad21e36315cff06b7ca5cbce473ba45688dfdaf2ba88d66c67fcb74fb', 'LAB-20260225-0002', 41, 'admin@monkole.cd', '2026-03-05 11:10:57', '2026-03-12 11:10:57', 1, '2026-03-05 11:13:04', 3, 1, NULL),
(37, '020edb7291124392e31f38232b67670ffd25cbcbc021f245c7864efe268a42e9', 'LAB-20260304-0014', 42, 'admin@monkole.cd', '2026-03-05 11:18:17', '2026-03-12 11:18:17', 1, '2026-03-05 11:18:40', 1, 1, NULL),
(38, '7a79537fa3f16fe9f324e2a0f75d3bfc2878ea4e8780a21b13fe2e6213e22a67', 'LAB-20260305-0007-01', 43, 'admin@monkole.cd', '2026-03-06 00:13:21', '2026-03-13 00:13:21', 1, NULL, 0, 1, NULL),
(39, '698cce8a8c357aefaac36d8f42171b502ab6fbdaa12ae60fba9c85b05cd1d7f8', 'LAB-20260305-0001-01', 44, 'admin@monkole.cd', '2026-03-06 01:28:13', '2026-03-13 01:28:13', 1, NULL, 0, 1, NULL),
(40, 'b767c65af761edf396f6ff60a71aad7879af3e938c27c9a37669b3afd3f5c172', 'LAB-20260305-0001-02', 45, 'admin@monkole.cd', '2026-03-06 01:28:14', '2026-03-13 01:28:14', 1, NULL, 0, 1, NULL),
(41, 'f5a8a0822289fe1d099a384b64010a5ce2ba19a78a1a50e22a73b44848cabfbe', 'LAB-20260305-0001-03', 46, 'admin@monkole.cd', '2026-03-06 01:28:14', '2026-03-13 01:28:14', 1, NULL, 0, 1, NULL),
(42, '8b20a743cca19e876e3b816c502d06f10dbbbbebe38c7cbd797803451013c835', 'LAB-20260305-0001-04', 47, 'admin@monkole.cd', '2026-03-06 01:28:14', '2026-03-13 01:28:14', 1, '2026-03-06 01:29:17', 1, 1, NULL),
(43, 'eddaf0a980270d03c6c50fad00a497f5320450edc1985dc6288665bb627a7d50', 'LAB-20260305-0002-01', 48, 'admin@monkole.cd', '2026-03-06 11:13:16', '2026-03-13 11:13:16', 1, NULL, 0, 1, NULL),
(44, 'c7464d547913dfe5ce541611f11aeeab0f4c94c7d20c7c61a4c84014759e61a7', 'LAB-20260305-0002-02', 49, 'admin@monkole.cd', '2026-03-06 11:13:17', '2026-03-13 11:13:17', 1, NULL, 0, 1, NULL),
(45, 'c797d7b08f5e451ef9d7e80a46723fe93613439d16bcf3fdb6d93efa4f489cb3', 'LAB-20260305-0002-03', 50, 'admin@monkole.cd', '2026-03-06 11:13:17', '2026-03-13 11:13:17', 1, NULL, 0, 1, NULL),
(46, '75803e121ee79c918f8295bf8764326125f36c732b639910c565f619816194c4', 'LAB-20260305-0002-04', 51, 'admin@monkole.cd', '2026-03-06 11:13:17', '2026-03-13 11:13:17', 1, '2026-03-06 11:35:18', 1, 1, NULL),
(47, 'b774335944d4e39826b46ad7c5f2ffd2549889b80233a5a5839558711cefdb7b', 'LAB-20260305-0002-05', 52, 'admin@monkole.cd', '2026-03-06 11:13:17', '2026-03-13 11:13:17', 1, '2026-03-08 01:56:29', 5, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `labo_workflow_history`
--

CREATE TABLE `labo_workflow_history` (
  `idhistory` int NOT NULL,
  `idechantillon` int NOT NULL,
  `ancien_statut` varchar(50) DEFAULT NULL,
  `nouveau_statut` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `idutilisateur` int NOT NULL,
  `observation` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `labo_workflow_history`
--

INSERT INTO `labo_workflow_history` (`idhistory`, `idechantillon`, `ancien_statut`, `nouveau_statut`, `action`, `idutilisateur`, `observation`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, NULL, 'attente_prelevement', 'Création échantillon', 1, 'Prescription reçue de l\'app principale', NULL, NULL, '2026-02-11 09:46:55'),
(2, 1, 'attente_prelevement', 'preleve', 'Prélèvement effectué', 2, 'Prélèvement veineux au pli du coude', NULL, NULL, '2026-02-11 09:46:55'),
(3, 1, 'preleve', 'receptionne', 'Réception au laboratoire', 3, 'Échantillon reçu, qualité bonne', NULL, NULL, '2026-02-11 09:46:55'),
(4, 1, 'receptionne', 'en_analyse', 'Début analyse', 3, 'Analyse sur Sysmex XN-1000', NULL, NULL, '2026-02-11 09:46:55'),
(5, 2, NULL, 'attente_prelevement', 'Création échantillon', 1, 'URGENT - Prescription prioritaire', NULL, NULL, '2026-02-11 09:46:55'),
(6, 2, 'attente_prelevement', 'preleve', 'Prélèvement urgent', 2, 'Prélèvement effectué en urgence', NULL, NULL, '2026-02-11 09:46:55'),
(7, 2, 'preleve', 'receptionne', 'Réception prioritaire', 3, 'Échantillon traité en priorité', NULL, NULL, '2026-02-11 09:46:55'),
(8, 12, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-17 02:32:34'),
(9, 14, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-17 02:55:36'),
(10, 14, 'attente_prelevement', 'preleve', 'Marquer preleve', 28, 'RAS', NULL, NULL, '2026-02-17 02:57:33'),
(11, 14, 'preleve', 'receptionne', 'Receptionner directement', 28, NULL, NULL, NULL, '2026-02-17 02:57:55'),
(12, 14, 'receptionne', 'controle_qualite', 'Controle qualite', 28, NULL, NULL, NULL, '2026-02-17 02:58:21'),
(13, 14, 'controle_qualite', 'en_analyse', 'Demarrer analyse', 28, NULL, NULL, NULL, '2026-02-17 02:58:31'),
(14, 14, 'en_analyse', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-17 02:58:46'),
(15, 14, 'analyse_terminee', 'validation_technique', 'Valider (technique)', 28, NULL, NULL, NULL, '2026-02-17 02:58:53'),
(16, 16, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-17 03:03:29'),
(17, 1, 'en_analyse', 'analyse_terminee', 'Terminer analyse', 28, 'bon', NULL, NULL, '2026-02-17 07:50:37'),
(18, 5, 'attente_prelevement', 'preleve', 'Marquer preleve', 28, 'Ok', NULL, NULL, '2026-02-17 07:56:30'),
(19, 5, 'preleve', 'receptionne', 'Receptionner directement', 28, NULL, NULL, NULL, '2026-02-17 07:57:06'),
(20, 5, 'receptionne', 'en_analyse', 'Demarrer analyse', 28, NULL, NULL, NULL, '2026-02-17 08:03:15'),
(21, 5, 'en_analyse', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-17 08:03:24'),
(22, 5, 'analyse_terminee', 'validation_technique', 'Valider (technique)', 28, NULL, NULL, NULL, '2026-02-17 08:03:36'),
(23, 2, 'receptionne', 'controle_qualite', 'Controle qualite', 28, NULL, NULL, NULL, '2026-02-17 08:06:19'),
(24, 2, 'controle_qualite', 'en_analyse', 'Demarrer analyse', 28, NULL, NULL, NULL, '2026-02-17 08:06:33'),
(25, 2, 'en_analyse', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-17 08:06:41'),
(26, 2, 'analyse_terminee', 'validation_technique', 'Valider (technique)', 28, NULL, NULL, NULL, '2026-02-17 08:06:47'),
(27, 1, 'analyse_terminee', 'validation_technique', 'Valider (technique)', 28, NULL, NULL, NULL, '2026-02-17 12:19:03'),
(28, 18, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-18 12:19:30'),
(29, 20, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-18 12:19:30'),
(30, 22, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-19 18:30:50'),
(31, 24, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-19 18:30:51'),
(32, 13, 'attente_prelevement', 'preleve', 'Marquer preleve', 28, NULL, NULL, NULL, '2026-02-19 18:32:46'),
(33, 13, 'preleve', 'receptionne', 'Receptionner directement', 28, NULL, NULL, NULL, '2026-02-19 18:33:13'),
(34, 13, 'receptionne', 'en_analyse', 'Demarrer analyse', 28, NULL, NULL, NULL, '2026-02-19 18:33:27'),
(35, 13, 'en_analyse', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-19 18:33:38'),
(36, 13, 'analyse_terminee', 'validation_technique', 'Valider (technique)', 28, NULL, NULL, NULL, '2026-02-19 18:33:43'),
(37, 1, 'validation_technique', 'validation_biologiste', 'Valider (biologiste)', 1, NULL, NULL, NULL, '2026-02-21 07:40:38'),
(38, 1, 'validation_biologiste', 'resultat_transmis', 'Transmettre resultats', 1, NULL, NULL, NULL, '2026-02-21 07:40:43'),
(39, 2, 'validation_technique', 'validation_biologiste', 'Valider (biologiste)', 1, NULL, NULL, NULL, '2026-02-21 07:41:10'),
(40, 2, 'validation_biologiste', 'resultat_transmis', 'Transmettre resultats', 1, NULL, NULL, NULL, '2026-02-21 07:41:13'),
(41, 6, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 08:17:55'),
(42, 6, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 08:18:03'),
(43, 6, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 08:18:15'),
(44, 9, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, 'oui', NULL, NULL, '2026-02-21 08:46:47'),
(45, 9, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 08:47:03'),
(46, 9, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 08:47:21'),
(47, 9, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-21 08:47:34'),
(48, 24, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 08:53:11'),
(49, 24, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 08:53:18'),
(50, 24, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 08:53:35'),
(51, 24, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-21 08:53:42'),
(52, 23, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 09:11:06'),
(53, 23, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 09:11:16'),
(54, 23, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 09:11:23'),
(55, 22, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 09:26:20'),
(56, 22, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 09:26:30'),
(57, 22, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 09:26:43'),
(58, 21, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 09:36:25'),
(59, 21, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 09:36:33'),
(60, 21, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 09:36:37'),
(61, 20, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 10:04:55'),
(62, 20, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 10:05:01'),
(63, 20, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 10:05:05'),
(64, 19, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 10:06:44'),
(65, 19, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 10:06:50'),
(66, 19, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 10:06:53'),
(67, 18, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 10:18:09'),
(68, 18, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 10:18:16'),
(69, 18, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 10:18:19'),
(70, 18, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-21 10:26:32'),
(71, 17, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 10:49:07'),
(72, 17, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 10:49:12'),
(73, 17, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 10:49:16'),
(74, 17, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-21 10:49:24'),
(75, 15, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-21 11:13:12'),
(76, 15, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-21 11:13:15'),
(77, 15, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-21 11:13:18'),
(78, 15, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-21 11:13:24'),
(79, 20, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-22 06:26:55'),
(80, 16, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-22 07:23:41'),
(81, 16, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-22 07:23:52'),
(82, 16, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-22 07:24:01'),
(83, 16, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-22 07:24:11'),
(84, 12, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-22 07:38:49'),
(85, 12, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-22 07:38:59'),
(86, 12, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-22 07:39:10'),
(87, 12, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-22 07:39:16'),
(88, 11, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 01:55:54'),
(89, 11, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 01:56:02'),
(90, 11, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 01:56:09'),
(91, 11, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 01:56:19'),
(92, 25, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 01:58:47'),
(93, 25, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 01:58:53'),
(94, 25, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 01:59:00'),
(95, 25, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 01:59:03'),
(96, 26, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 02:21:06'),
(97, 26, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 02:21:14'),
(98, 26, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 02:21:17'),
(99, 26, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 02:21:19'),
(100, 28, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-23 02:35:14'),
(101, 27, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 02:35:24'),
(102, 27, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 02:35:30'),
(103, 27, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 02:35:33'),
(104, 27, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 02:35:36'),
(105, 30, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-23 03:21:17'),
(106, 29, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 03:21:27'),
(107, 29, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 03:21:33'),
(108, 29, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 03:21:37'),
(109, 29, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 03:21:40'),
(110, 32, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-23 04:29:48'),
(111, 31, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 04:29:55'),
(112, 31, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 04:29:57'),
(113, 31, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 04:30:00'),
(114, 31, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 04:30:02'),
(115, 34, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-23 04:40:19'),
(116, 33, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-23 04:40:25'),
(117, 33, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-23 04:40:28'),
(118, 33, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-23 04:40:31'),
(119, 33, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-23 04:40:34'),
(120, 36, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-25 15:50:32'),
(121, 36, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-25 15:50:42'),
(122, 36, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-25 15:50:53'),
(123, 36, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-25 15:51:04'),
(124, 38, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-25 15:55:13'),
(125, 38, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-25 15:56:36'),
(126, 38, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-25 15:56:38'),
(127, 38, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-25 15:56:40'),
(128, 38, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-25 15:56:43'),
(129, 40, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 17:58:47'),
(130, 42, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 17:58:47'),
(131, 42, 'attente_prelevement', 'preleve', 'Marquer prélevé', 28, NULL, NULL, NULL, '2026-02-26 17:59:42'),
(132, 42, 'preleve', 'receptionne', 'Réceptionner', 28, NULL, NULL, NULL, '2026-02-26 18:00:19'),
(133, 42, 'receptionne', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-26 18:00:45'),
(134, 42, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 28, NULL, NULL, NULL, '2026-02-26 18:00:57'),
(135, 37, 'attente_prelevement', 'preleve', 'Marquer prélevé', 28, NULL, NULL, NULL, '2026-02-26 18:08:47'),
(136, 44, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:22:05'),
(137, 46, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:22:05'),
(138, 40, 'attente_prelevement', 'preleve', 'Marquer prélevé', 28, NULL, NULL, NULL, '2026-02-26 19:24:21'),
(139, 40, 'preleve', 'receptionne', 'Réceptionner', 28, NULL, NULL, NULL, '2026-02-26 19:25:06'),
(140, 40, 'receptionne', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-26 19:25:28'),
(141, 40, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 28, NULL, NULL, NULL, '2026-02-26 19:25:35'),
(142, 48, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:29:40'),
(143, 50, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:32:25'),
(144, 52, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:32:26'),
(145, 54, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:32:26'),
(146, 56, NULL, 'attente_prelevement', 'Création par prescription (services)', 28, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-26 19:32:26'),
(147, 55, 'attente_prelevement', 'preleve', 'Marquer prélevé', 28, NULL, NULL, NULL, '2026-02-26 19:33:04'),
(148, 55, 'preleve', 'receptionne', 'Réceptionner', 28, NULL, NULL, NULL, '2026-02-26 19:33:13'),
(149, 55, 'receptionne', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-26 19:34:09'),
(150, 55, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 28, NULL, NULL, NULL, '2026-02-26 19:34:18'),
(151, 39, 'attente_prelevement', 'preleve', 'Marquer prélevé', 28, NULL, NULL, NULL, '2026-02-26 19:40:54'),
(152, 39, 'preleve', 'receptionne', 'Réceptionner', 28, NULL, NULL, NULL, '2026-02-26 19:41:06'),
(153, 39, 'receptionne', 'analyse_terminee', 'Terminer analyse', 28, NULL, NULL, NULL, '2026-02-26 19:41:57'),
(154, 39, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 28, NULL, NULL, NULL, '2026-02-26 19:41:59'),
(155, 58, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0001', NULL, NULL, '2026-02-27 04:13:54'),
(156, 60, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0001', NULL, NULL, '2026-02-27 04:13:55'),
(157, 62, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0001', NULL, NULL, '2026-02-27 04:13:55'),
(158, 64, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0001', NULL, NULL, '2026-02-27 04:13:56'),
(159, 66, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0001', NULL, NULL, '2026-02-27 04:13:56'),
(160, 68, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-27 05:38:01'),
(161, 70, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-27 05:38:01'),
(162, 72, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-27 05:38:01'),
(163, 73, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0002', NULL, NULL, '2026-02-27 05:38:01'),
(164, 74, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0002', NULL, NULL, '2026-02-27 05:38:01'),
(165, 75, NULL, 'attente_prelevement', 'Création par prescription (groupe)', 1, 'Échantillon du groupe LAB-20260227-0002', NULL, NULL, '2026-02-27 05:38:01'),
(166, 73, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-02-27 07:31:41'),
(167, 73, 'preleve', 'receptionne', 'Réceptionner', 1, NULL, NULL, NULL, '2026-02-27 07:32:05'),
(168, 73, 'receptionne', 'analyse_terminee', 'Terminer analyse', 1, NULL, NULL, NULL, '2026-02-27 07:32:11'),
(169, 73, 'analyse_terminee', 'validation_technique', 'Valider technique et saisir résultats', 1, NULL, NULL, NULL, '2026-02-27 07:32:15'),
(170, 58, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-02-27 15:28:43'),
(171, 60, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-02-27 15:28:43'),
(172, 62, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-02-27 15:28:43'),
(173, 64, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-02-27 15:28:43'),
(174, 66, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-02-27 15:28:44'),
(175, 82, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-02-27 15:30:28'),
(176, 84, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-03-04 08:56:40'),
(177, 86, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-03-04 08:56:40'),
(178, 88, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Ajout sous-examen #1 au groupe LAB-20260304-0001', NULL, NULL, '2026-03-04 09:13:18'),
(179, 90, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Ajout sous-examen #2 au groupe LAB-20260304-0001', NULL, NULL, '2026-03-04 09:13:18'),
(180, 88, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-03-04 09:14:10'),
(181, 90, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-03-04 09:14:10'),
(182, 92, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Ajout sous-examen #3 au groupe LAB-20260304-0001', NULL, NULL, '2026-03-04 09:26:08'),
(183, 94, NULL, 'attente_prelevement', 'Création par prescription (services)', 1, 'Prescription créée depuis l\'application Services', NULL, NULL, '2026-03-04 10:59:47'),
(184, 74, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-03-04 13:51:42'),
(185, 75, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-03-04 13:52:23'),
(186, 74, 'preleve', 'validation_technique', 'Validé / Résultats saisis', 1, NULL, NULL, NULL, '2026-03-04 13:52:35'),
(187, 90, 'preleve', 'validation_technique', 'Validé / Résultats saisis', 1, NULL, NULL, NULL, '2026-03-04 13:54:30'),
(188, 75, 'preleve', 'validation_technique', 'Validé / Résultats saisis', 1, NULL, NULL, NULL, '2026-03-04 13:55:12'),
(189, 92, 'attente_prelevement', 'preleve', 'Prélevé', 1, NULL, NULL, NULL, '2026-03-04 14:01:56'),
(190, 92, 'preleve', 'validation_technique', 'Validé / Résultats saisis', 1, NULL, NULL, NULL, '2026-03-04 14:46:45'),
(191, 41, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 09:51:56'),
(192, 96, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 10:17:37'),
(193, 100, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 10:34:12'),
(194, 102, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 10:34:12'),
(195, 104, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 10:48:20'),
(196, 106, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 10:48:20'),
(197, 108, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 12:05:17'),
(198, 110, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 13:46:41'),
(199, 112, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 13:46:41'),
(200, 114, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 14:42:01'),
(201, 116, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 14:42:01'),
(202, 117, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 18:45:51'),
(203, 118, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 18:50:28'),
(204, 119, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 18:50:28'),
(205, 122, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 19:36:43'),
(206, 124, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 19:36:43'),
(207, 125, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 19:54:13'),
(208, 129, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 20:36:25'),
(209, 130, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 20:36:25'),
(210, 131, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 20:42:43'),
(211, 132, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 20:53:48'),
(212, 133, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 20:56:54'),
(213, 133, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 20:58:21'),
(214, 122, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 21:00:25'),
(215, 124, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 21:00:28'),
(216, 134, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 22:35:37'),
(217, 135, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 22:36:54'),
(218, 136, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-05 22:38:05'),
(219, 133, 'preleve', 'validation_technique', 'Résultat saisi — validé', 1, NULL, NULL, NULL, '2026-03-05 23:13:21'),
(220, 110, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 23:43:47'),
(221, 112, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 23:43:49'),
(222, 125, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 23:43:52'),
(223, 134, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-05 23:43:54'),
(224, 104, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 00:25:22'),
(225, 110, 'preleve', 'validation_technique', 'Résultat saisi — validé', 1, NULL, NULL, NULL, '2026-03-06 00:28:13'),
(226, 112, 'preleve', 'validation_technique', 'Résultat saisi — validé', 1, NULL, NULL, NULL, '2026-03-06 00:28:14'),
(227, 125, 'preleve', 'validation_technique', 'Résultat saisi — validé', 1, NULL, NULL, NULL, '2026-03-06 00:28:14'),
(228, 134, 'preleve', 'validation_technique', 'Résultat saisi — validé', 1, NULL, NULL, NULL, '2026-03-06 00:28:14'),
(229, 100, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 10:12:39'),
(230, 102, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 10:12:41'),
(231, 108, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 10:12:44'),
(232, 117, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 10:12:47'),
(233, 136, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 10:12:50'),
(234, 135, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 12:14:00'),
(235, 106, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 12:57:12'),
(236, 132, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 12:57:16'),
(237, 129, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 13:09:20'),
(238, 130, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 13:09:22'),
(239, 131, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-06 13:09:25'),
(240, 137, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-08 00:59:06'),
(241, 138, NULL, 'attente_prelevement', 'Création par prescription', 1, 'Prescription créée', NULL, NULL, '2026-03-08 00:59:06'),
(242, 137, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-08 00:59:23'),
(243, 138, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-08 00:59:25'),
(244, 114, 'attente_prelevement', 'preleve', 'Marquer prélevé', 1, NULL, NULL, NULL, '2026-03-08 01:12:41');

-- --------------------------------------------------------

--
-- Table structure for table `pharmacie_preparations`
--

CREATE TABLE `pharmacie_preparations` (
  `idpreparation` int NOT NULL,
  `code_preparation` varchar(50) NOT NULL,
  `idpharma_presc` int NOT NULL,
  `idpatient` int NOT NULL,
  `idsejour` int DEFAULT NULL,
  `idsous_sejour` int DEFAULT NULL,
  `statut` enum('attente','verification_stock','en_preparation','preparation_terminee','controle_qualite','prete','delivree','retournee','annulee') DEFAULT 'attente',
  `idprodpharma` int DEFAULT NULL,
  `dosage_prescrit` varchar(100) DEFAULT NULL,
  `forme_galenique` varchar(50) DEFAULT NULL,
  `voie_administration` varchar(50) DEFAULT NULL,
  `posologie` text,
  `duree_traitement_jours` int DEFAULT NULL,
  `lot_utilise` varchar(50) DEFAULT NULL,
  `peremption_utilisee` date DEFAULT NULL,
  `quantite_preparee` decimal(10,3) DEFAULT NULL,
  `unite_preparation` varchar(20) DEFAULT NULL,
  `conditionnement` varchar(50) DEFAULT NULL,
  `preparateur` int DEFAULT NULL,
  `verificateur` int DEFAULT NULL,
  `pharmacien_validateur` int DEFAULT NULL,
  `delivreur` int DEFAULT NULL,
  `date_verification_stock` datetime DEFAULT NULL,
  `date_debut_preparation` datetime DEFAULT NULL,
  `date_fin_preparation` datetime DEFAULT NULL,
  `date_controle_qualite` datetime DEFAULT NULL,
  `date_disponibilite` datetime DEFAULT NULL,
  `date_delivrance` datetime DEFAULT NULL,
  `conforme` tinyint(1) DEFAULT '1',
  `tests_realises` json DEFAULT NULL,
  `observations_preparation` text,
  `motif_retour` text,
  `urgence` tinyint(1) DEFAULT '0',
  `etiquetage_correct` tinyint(1) DEFAULT '1',
  `double_verification` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pharmacie_preparations`
--

INSERT INTO `pharmacie_preparations` (`idpreparation`, `code_preparation`, `idpharma_presc`, `idpatient`, `idsejour`, `idsous_sejour`, `statut`, `idprodpharma`, `dosage_prescrit`, `forme_galenique`, `voie_administration`, `posologie`, `duree_traitement_jours`, `lot_utilise`, `peremption_utilisee`, `quantite_preparee`, `unite_preparation`, `conditionnement`, `preparateur`, `verificateur`, `pharmacien_validateur`, `delivreur`, `date_verification_stock`, `date_debut_preparation`, `date_fin_preparation`, `date_controle_qualite`, `date_disponibilite`, `date_delivrance`, `conforme`, `tests_realises`, `observations_preparation`, `motif_retour`, `urgence`, `etiquetage_correct`, `double_verification`, `created_at`, `updated_at`) VALUES
(1, 'PHAR-20250211-0001', 10, 4, 1, 37, 'delivree', 1, '500mg', NULL, NULL, NULL, NULL, NULL, NULL, 10.000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2025-12-13 14:40:00', '2026-02-11 09:46:55'),
(2, 'PHAR-20250211-0002', 11, 22, 72, 67, 'delivree', 7, '1 compresse', NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, '2026-02-21 00:57:14', 1, NULL, NULL, NULL, 0, 1, 0, '2026-01-02 06:37:00', '2026-02-21 00:57:14'),
(3, 'PHAR-20260219-0001', 19, 22, 71, 67, 'delivree', 3, '3', NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, '2026-02-21 01:06:20', 1, NULL, NULL, NULL, 0, 1, 0, '2026-02-19 01:25:33', '2026-02-21 01:06:20'),
(4, 'PHAR-20260219-0002', 20, 22, 71, 67, 'delivree', 1, '4', NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, 1, 1, NULL, 1, '2026-02-21 01:35:14', '2026-02-21 01:35:14', NULL, NULL, NULL, '2026-02-21 01:47:26', 1, NULL, NULL, NULL, 0, 1, 0, '2026-02-19 01:25:33', '2026-02-21 01:47:26'),
(5, 'PHAR-20260220-0001', 21, 22, 71, 67, 'delivree', 3, '3x/jr', NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, '2026-02-21 00:39:45', 1, NULL, NULL, NULL, 1, 1, 0, '2026-02-20 16:48:05', '2026-02-21 00:39:45'),
(6, 'PHAR-20260220-0002', 22, 22, 71, 67, 'delivree', 1, '2', NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, '2026-02-21 00:41:07', 1, NULL, NULL, NULL, 1, 1, 0, '2026-02-20 16:48:05', '2026-02-21 00:41:07'),
(7, 'PHAR-20260221-0001', 23, 22, 71, 67, 'delivree', 3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, 1, 1, NULL, 1, '2026-02-21 01:52:58', '2026-02-21 01:52:58', NULL, NULL, NULL, '2026-02-21 01:54:08', 1, NULL, NULL, NULL, 0, 1, 0, '2026-02-21 01:52:37', '2026-02-21 01:54:08'),
(8, 'PHAR-20260221-0002', 24, 22, 71, 67, 'delivree', 6, 'après bain', NULL, NULL, NULL, NULL, NULL, NULL, 5.000, NULL, NULL, 1, 1, NULL, 1, '2026-02-21 02:07:35', '2026-02-21 02:07:35', '2026-02-21 02:36:44', NULL, NULL, '2026-02-21 02:56:19', 1, NULL, NULL, NULL, 0, 1, 1, '2026-02-21 02:07:13', '2026-02-21 02:56:19'),
(9, 'PHAR-20260221-0003', 25, 22, 71, 67, 'delivree', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, 1, 1, NULL, 1, '2026-02-21 02:57:11', '2026-02-21 02:57:11', '2026-02-21 03:17:53', NULL, NULL, '2026-02-21 03:18:40', 1, NULL, NULL, NULL, 0, 1, 1, '2026-02-21 02:56:55', '2026-02-21 03:18:40'),
(10, 'PHAR-20260221-0004', 26, 22, 71, 67, 'attente', 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 19.000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2026-02-21 03:35:29', '2026-02-21 03:35:29'),
(11, 'PHAR-20260224-0001', 27, 4, 44, 44, 'attente', 2, '3', NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2026-02-24 03:48:38', '2026-02-24 03:48:38'),
(12, 'PHAR-20260304-0001', 28, 4, 65, 65, 'attente', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2026-03-04 11:07:57', '2026-03-04 11:07:57'),
(13, 'PHAR-20260304-0002', 29, 4, 65, 65, 'en_preparation', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, 1, 1, NULL, NULL, '2026-03-04 13:04:09', '2026-03-04 13:04:09', NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2026-03-04 11:12:02', '2026-03-04 12:04:09'),
(14, 'PHAR-20260304-0003', 30, 1, 23, 23, 'attente', 5, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2026-03-04 12:04:55', '2026-03-04 12:04:55'),
(15, 'PHAR-20260305-0001', 31, 5, 52, 57, 'en_preparation', 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1.000, NULL, NULL, 1, 1, NULL, NULL, '2026-03-05 20:47:17', '2026-03-05 20:47:17', NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, 0, 1, 0, '2026-03-05 19:39:20', '2026-03-05 19:47:17');

-- --------------------------------------------------------

--
-- Table structure for table `pharmacie_workflow_history`
--

CREATE TABLE `pharmacie_workflow_history` (
  `idhistory` int NOT NULL,
  `idpreparation` int NOT NULL,
  `ancien_statut` varchar(50) DEFAULT NULL,
  `nouveau_statut` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `idutilisateur` int NOT NULL,
  `observation` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pharmacie_workflow_history`
--

INSERT INTO `pharmacie_workflow_history` (`idhistory`, `idpreparation`, `ancien_statut`, `nouveau_statut`, `action`, `idutilisateur`, `observation`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, NULL, 'attente', 'Reception prescription', 1, 'Prescription recue - Amoxicilline 500mg', NULL, NULL, '2026-02-10 08:00:00'),
(2, 1, 'attente', 'verification_stock', 'Verification stock', 1, 'Stock verifie - disponible', NULL, NULL, '2026-02-10 08:15:00'),
(3, 1, 'verification_stock', 'en_preparation', 'Debut preparation', 1, 'Preparation en cours - lot LOT-2026-001', NULL, NULL, '2026-02-10 08:30:00'),
(4, 2, NULL, 'attente', 'Reception prescription', 1, 'Prescription recue - Paracetamol 1g', NULL, NULL, '2026-02-11 10:00:00'),
(5, 1, NULL, 'attente', 'Reception prescription', 1, 'Prescription recue - Amoxicilline 500mg', NULL, NULL, '2026-02-10 08:00:00'),
(6, 1, 'attente', 'verification_stock', 'Verification stock', 1, 'Stock verifie - disponible', NULL, NULL, '2026-02-10 08:15:00'),
(7, 1, 'verification_stock', 'en_preparation', 'Debut preparation', 1, 'Preparation en cours - lot LOT-2026-001', NULL, NULL, '2026-02-10 08:30:00'),
(8, 2, NULL, 'attente', 'Reception prescription', 1, 'Prescription recue - Paracetamol 1g', NULL, NULL, '2026-02-11 10:00:00'),
(9, 5, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 00:39:45'),
(10, 6, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 00:41:07'),
(12, 2, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 00:57:14'),
(13, 3, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 01:06:20'),
(14, 4, 'attente', 'verification_stock', 'Vérification stock auto', 1, NULL, NULL, NULL, '2026-02-21 01:35:14'),
(15, 4, 'verification_stock', 'en_preparation', 'Démarrage préparation', 1, NULL, NULL, NULL, '2026-02-21 01:35:14'),
(16, 4, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 01:47:26'),
(17, 7, 'attente', 'verification_stock', 'Vérification stock automatique', 1, NULL, NULL, NULL, '2026-02-21 01:52:58'),
(18, 7, 'verification_stock', 'en_preparation', 'Démarrage préparation', 1, NULL, NULL, NULL, '2026-02-21 01:52:58'),
(19, 7, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 01:54:08'),
(20, 8, 'attente', 'verification_stock', 'Vérification stock automatique', 1, NULL, NULL, NULL, '2026-02-21 02:07:35'),
(21, 8, 'verification_stock', 'en_preparation', 'Démarrage préparation', 1, NULL, NULL, NULL, '2026-02-21 02:07:35'),
(22, 8, 'en_preparation', 'preparation_terminee', 'Préparation terminée automatiquement (Accès officine)', 1, NULL, NULL, NULL, '2026-02-21 02:36:44'),
(23, 8, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 5 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 02:56:19'),
(24, 9, 'attente', 'verification_stock', 'Vérification stock automatique', 1, NULL, NULL, NULL, '2026-02-21 02:57:11'),
(25, 9, 'verification_stock', 'en_preparation', 'Démarrage préparation', 1, NULL, NULL, NULL, '2026-02-21 02:57:11'),
(26, 9, 'en_preparation', 'preparation_terminee', 'Préparation terminée automatiquement (Accès officine)', 1, NULL, NULL, NULL, '2026-02-21 03:17:53'),
(27, 9, 'attente', 'delivree', 'Délivrance effectuée', 1, 'Délivrance de 1 unités depuis l\'officine #1', NULL, NULL, '2026-02-21 03:18:40'),
(28, 13, 'attente', 'verification_stock', 'Vérification stock automatique', 1, NULL, NULL, NULL, '2026-03-04 12:04:09'),
(29, 13, 'verification_stock', 'en_preparation', 'Démarrage préparation', 1, NULL, NULL, NULL, '2026-03-04 12:04:09'),
(30, 15, 'attente', 'verification_stock', 'Vérification stock automatique', 1, NULL, NULL, NULL, '2026-03-05 19:47:17'),
(31, 15, 'verification_stock', 'en_preparation', 'Démarrage préparation', 1, NULL, NULL, NULL, '2026-03-05 19:47:17');

-- --------------------------------------------------------

--
-- Table structure for table `resultatslabo_lignes`
--

CREATE TABLE `resultatslabo_lignes` (
  `id` int NOT NULL,
  `idresultat` int NOT NULL,
  `libelle_examen` varchar(150) DEFAULT NULL,
  `valeur_resultat` varchar(100) DEFAULT NULL,
  `valeur_normale` varchar(150) DEFAULT NULL,
  `unite` varchar(30) DEFAULT NULL,
  `interpretation` enum('normal','anormal','critique') DEFAULT NULL,
  `ordre` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services_notifications`
--

CREATE TABLE `services_notifications` (
  `idnotification` int NOT NULL,
  `service` enum('labo','imagerie','pharmacie','system') NOT NULL,
  `type_notification` enum('info','alerte','urgence','validation','erreur') NOT NULL,
  `id_reference` int NOT NULL,
  `table_reference` varchar(50) DEFAULT NULL,
  `code_reference` varchar(50) DEFAULT NULL,
  `titre` varchar(200) NOT NULL,
  `message` text,
  `details` json DEFAULT NULL,
  `id_destinateur` int DEFAULT NULL,
  `id_destinataire` int DEFAULT NULL,
  `groupe_destinataire` varchar(50) DEFAULT NULL,
  `lu` tinyint(1) DEFAULT '0',
  `date_lecture` datetime DEFAULT NULL,
  `archive` tinyint(1) DEFAULT '0',
  `renvoyer` tinyint(1) DEFAULT '0',
  `actions_possibles` json DEFAULT NULL,
  `action_effectuee` varchar(50) DEFAULT NULL,
  `date_action` datetime DEFAULT NULL,
  `priorite` enum('basse','normale','haute','critique') DEFAULT 'normale',
  `metadata` json DEFAULT NULL,
  `expiration` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `services_notifications`
--

INSERT INTO `services_notifications` (`idnotification`, `service`, `type_notification`, `id_reference`, `table_reference`, `code_reference`, `titre`, `message`, `details`, `id_destinateur`, `id_destinataire`, `groupe_destinataire`, `lu`, `date_lecture`, `archive`, `renvoyer`, `actions_possibles`, `action_effectuee`, `date_action`, `priorite`, `metadata`, `expiration`, `created_at`, `updated_at`) VALUES
(1, 'labo', 'alerte', 1, 'labo_echantillons', 'LAB-20250211-0003', 'Échantillon rejeté', 'Échantillon hémolysé, besoin de nouveau prélèvement', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-11 09:46:55', '2026-02-23 08:05:33'),
(2, 'imagerie', 'info', 52, 'imagerie_examens', 'IMG-20250211-0001', 'Examen programmé', 'Radiographie abdomen programmée pour demain 14h', NULL, NULL, NULL, 'manipulateurs_imagerie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-11 09:46:55', '2026-02-23 08:05:33'),
(3, 'pharmacie', 'urgence', 10, 'pharmacie_preparations', 'PHAR-20250211-0001', 'Médicament délivré', 'Amoxicilline 500mg délivrée au patient', NULL, NULL, NULL, 'pharmaciens', 1, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2025-12-13 15:00:00', '2026-02-11 09:46:55'),
(4, 'labo', 'info', 53, 'labo_echantillons', 'LAB-20260211-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260211-0001', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-11 09:50:33', '2026-02-23 08:05:33'),
(5, 'labo', 'info', 54, 'labo_echantillons', 'LAB-20260211-0002', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260211-0002', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-11 17:45:35', '2026-02-23 08:05:33'),
(6, 'labo', 'info', 55, 'labo_echantillons', 'LAB-20260217-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260217-0001', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-17 00:33:42', '2026-02-23 08:05:33'),
(7, 'labo', 'info', 56, 'labo_echantillons', 'LAB-20260217-0002', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260217-0002', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-17 01:32:39', '2026-02-23 08:05:33'),
(8, 'labo', 'info', 57, 'labo_echantillons', 'LAB-20260217-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260217-0003', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-17 02:07:05', '2026-02-23 08:05:33'),
(9, 'labo', 'info', 58, 'labo_echantillons', 'LAB-20260217-0005', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260217-0005', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-17 02:32:34', '2026-02-23 08:05:33'),
(10, 'labo', 'info', 59, 'labo_echantillons', 'LAB-20260217-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260217-0007', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-17 02:55:36', '2026-02-23 08:05:33'),
(11, 'labo', 'info', 60, 'labo_echantillons', 'LAB-20260217-0009', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260217-0009', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-17 03:03:29', '2026-02-23 08:05:33'),
(12, 'imagerie', 'info', 61, 'imagerie_examens', 'IMG-20260218-0001', 'Nouvel examen d\'imagerie', 'Échographie Pelvienne - Code: IMG-20260218-0001', NULL, NULL, NULL, 'secretaires_imagerie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-18 11:50:47', '2026-02-23 08:05:33'),
(13, 'labo', 'info', 62, 'labo_echantillons', 'LAB-20260218-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260218-0001', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-18 12:19:30', '2026-02-23 08:05:33'),
(14, 'labo', 'info', 63, 'labo_echantillons', 'LAB-20260218-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260218-0003', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-18 12:19:30', '2026-02-23 08:05:33'),
(15, 'pharmacie', 'info', 19, 'pharmacie_preparations', 'PHAR-20260219-0001', 'Nouvelle prescription pharmacie', 'ASPIRINE 100mg - 3 - Code: PHAR-20260219-0001', NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-19 01:25:33', '2026-02-23 08:05:33'),
(16, 'pharmacie', 'info', 20, 'pharmacie_preparations', 'PHAR-20260219-0002', 'Nouvelle prescription pharmacie', 'PARACETAMOL 500mg - 4 - Code: PHAR-20260219-0002', NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-19 01:25:33', '2026-02-23 08:05:33'),
(17, 'labo', 'info', 64, 'labo_echantillons', 'LAB-20260219-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260219-0001', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-19 18:30:50', '2026-02-23 08:05:33'),
(18, 'labo', 'info', 65, 'labo_echantillons', 'LAB-20260219-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260219-0003', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-19 18:30:50', '2026-02-23 08:05:33'),
(19, 'pharmacie', 'info', 21, 'pharmacie_preparations', 'PHAR-20260220-0001', 'Nouvelle prescription pharmacie', 'ASPIRINE 100mg - 3x/jr - Code: PHAR-20260220-0001', NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-20 16:48:05', '2026-02-23 08:05:33'),
(20, 'pharmacie', 'info', 22, 'pharmacie_preparations', 'PHAR-20260220-0002', 'Nouvelle prescription pharmacie', 'PARACETAMOL 500mg - 2 - Code: PHAR-20260220-0002', NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-20 16:48:05', '2026-02-23 08:05:33'),
(21, 'pharmacie', 'info', 19, 'pharma_presc', 'PHAR-20260219-0001', '💊 Prescription délivrée — ASPIRINE 100mg', 'Patient : Jean Ikula | Produit : ASPIRINE 100mg (ASP100) | Quantité : 1 | Délivré par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 01:06:20', '2026-02-23 08:05:33'),
(22, 'pharmacie', 'info', 20, 'pharma_presc', 'PHAR-20260219-0002', '💊 Prescription délivrée — PARACETAMOL 500mg', 'Patient : Jean Ikula | Produit : PARACETAMOL 500mg (PARA500) | Quantité : 1 | Délivré par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 01:47:26', '2026-02-23 08:05:33'),
(23, 'pharmacie', 'info', 23, 'pharmacie_preparations', 'PHAR-20260221-0001', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 01:52:37', '2026-02-23 08:05:33'),
(24, 'pharmacie', 'info', 23, 'pharma_presc', 'PHAR-20260221-0001', '💊 Prescription délivrée — ASPIRINE 100mg', 'Patient : Jean Ikula | Produit : ASPIRINE 100mg (ASP100) | Quantité : 1 | Délivré par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 01:54:08', '2026-02-23 08:05:33'),
(25, 'pharmacie', 'info', 24, 'pharmacie_preparations', 'PHAR-20260221-0002', 'Nouvelle prescription pharmacie', 'COMPRESSE STERILE - après bain - Code: PHAR-20260221-0002', NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 02:07:13', '2026-02-23 08:05:33'),
(26, 'pharmacie', 'info', 24, 'pharma_presc', 'PHAR-20260221-0002', '💊 Prescription délivrée — COMPRESSE STERILE', 'Patient : Jean Ikula | Produit : COMPRESSE STERILE (COMP-ST) | Quantité : 5 | Délivré par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 02:56:19', '2026-02-23 08:05:33'),
(27, 'pharmacie', 'info', 25, 'pharmacie_preparations', 'PHAR-20260221-0003', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 02:56:55', '2026-02-23 08:05:33'),
(28, 'pharmacie', 'info', 25, 'pharma_presc', 'PHAR-20260221-0003', '💊 Prescription délivrée — PARACETAMOL 500mg', 'Patient : Jean Ikula | Produit : PARACETAMOL 500mg (PARA500) | Quantité : 1 | Délivré par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 03:18:40', '2026-02-23 08:05:33'),
(29, 'pharmacie', 'info', 26, 'pharmacie_preparations', 'PHAR-20260221-0004', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-21 03:35:29', '2026-02-23 08:05:33'),
(30, 'labo', 'info', 47, 'labo_echantillons', 'LAB-20250211-0001', 'Résultats disponibles', 'Les résultats pour l\'échantillon LAB-20250211-0001 sont disponibles', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-21 07:40:43', '2026-02-23 08:05:33'),
(31, 'labo', 'info', 48, 'labo_echantillons', 'LAB-20250211-0002', 'Résultats disponibles', 'Les résultats pour l\'échantillon LAB-20250211-0002 sont disponibles', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-21 07:41:13', '2026-02-23 08:05:33'),
(32, 'labo', 'info', 17, 'resultatslabo', 'LAB-20260218-0001', '🔬 Résultat labo disponible — VS (Vitesse de Sédimentation)', '⚠️ Anormal | Patient : Jean Ikula | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-21 10:49:43', '2026-02-23 08:05:33'),
(33, 'labo', 'info', 19, 'resultatslabo', 'LAB-20260218-0004', '🔬 Résultat labo disponible — Coproculture', '✅ Normal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-22 06:27:54', '2026-02-23 08:05:33'),
(34, 'labo', 'info', 22, 'resultatslabo', 'LAB-20260217-0005', '🔬 Résultat labo disponible — Frottis Vaginal', '✅ Normal | Patient : Jean Ikula | Acte : Frottis Vaginal (FROT-VAG) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 01:57:07', '2026-02-23 08:05:33'),
(35, 'labo', 'info', 66, 'labo_echantillons', 'LAB-20260223-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260223-0001', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-23 01:58:20', '2026-02-23 08:05:33'),
(36, 'labo', 'info', 23, 'resultatslabo', 'LAB-20260223-0001', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 01:59:54', '2026-02-23 08:05:33'),
(37, 'labo', 'info', 67, 'labo_echantillons', 'LAB-20260223-0002', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260223-0002', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-23 02:20:48', '2026-02-23 08:05:33'),
(38, 'labo', 'info', 68, 'labo_echantillons', 'LAB-20260223-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260223-0003', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:29', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-23 02:35:12', '2026-02-23 08:05:29'),
(39, 'labo', 'info', 25, 'resultatslabo', 'LAB-20260223-0003', '🔬 Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Jean Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 02:35:56', '2026-02-23 08:05:28'),
(40, 'labo', 'info', 69, 'labo_echantillons', 'LAB-20260223-0005', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260223-0005', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:33', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-23 03:21:10', '2026-02-23 08:05:33'),
(41, 'labo', 'info', 26, 'resultatslabo', 'LAB-20260223-0005', '🔬 Résultat labo disponible — Ionogramme Sanguin', '✅ Normal | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 1, '2026-02-23 09:05:26', 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 03:22:15', '2026-02-23 08:05:26'),
(42, 'labo', 'info', 70, 'labo_echantillons', 'LAB-20260223-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260223-0007', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-23 04:29:48', '2026-02-23 08:05:23'),
(43, 'labo', 'info', 27, 'resultatslabo', 'LAB-20260223-0007', '🔬 Résultat labo disponible — Urée et Créatinine', 'Disponible | Patient : Jean Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 04:30:04', '2026-02-23 08:05:16'),
(44, 'labo', 'info', 71, 'labo_echantillons', 'LAB-20260223-0009', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260223-0009', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-02-23 09:05:07', 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-23 04:40:19', '2026-02-23 08:05:07'),
(45, 'labo', 'info', 28, 'resultatslabo', 'LAB-20260223-0009', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 04:40:50', '2026-02-23 05:29:08'),
(46, 'labo', 'info', 16, 'resultatslabo', 'LAB-20260217-3415', '🔬 Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', 'Disponible | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 05:05:06', '2026-02-23 05:44:52'),
(47, 'labo', 'info', 28, 'resultatslabo', 'LAB-20260223-0009', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 05:08:42', '2026-02-23 05:28:53'),
(48, 'labo', 'info', 28, 'resultatslabo', 'LAB-20260223-0009', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 05:10:17', '2026-02-23 05:28:52'),
(49, 'labo', 'info', 28, 'resultatslabo', 'LAB-20260223-0009', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 05:15:08', '2026-02-23 05:28:47'),
(50, 'labo', 'info', 28, 'resultatslabo', 'LAB-20260223-0009', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-23 05:22:52', '2026-02-23 05:28:49'),
(51, 'imagerie', 'info', 72, 'imagerie_examens', 'IMG-20260224-0001', 'Nouvel examen d\'imagerie', 'Échographie Abdominale - Code: IMG-20260224-0001', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-24 03:12:10', '2026-02-24 03:12:10'),
(52, 'labo', 'info', 77, 'labo_echantillons', 'LAB-20260224-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260224-0001', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-24 03:48:24', '2026-02-24 03:48:24'),
(53, 'pharmacie', 'info', 27, 'pharmacie_preparations', 'PHAR-20260224-0001', 'Nouvelle prescription pharmacie', 'AMOXICILLINE 500mg - 3 - Code: PHAR-20260224-0001', NULL, NULL, NULL, 'preparateurs_pharmacie', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-24 03:48:38', '2026-02-24 03:48:38'),
(54, 'imagerie', 'info', 82, 'imagerie_examens', 'IMG-20260224-0002', 'Nouvel examen d\'imagerie', 'Échographie Mammaire - Code: IMG-20260224-0002', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-24 07:36:19', '2026-02-24 07:36:19'),
(55, 'imagerie', 'info', 83, 'imagerie_examens', 'IMG-20260224-0003', 'Nouvel examen d\'imagerie', 'Échographie Mammaire - Code: IMG-20260224-0003', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-24 09:15:42', '2026-02-24 09:15:42'),
(57, 'imagerie', 'info', 85, 'imagerie_examens', 'IMG-20260224-0005', 'Nouvel examen d\'imagerie', 'Échographie Abdominale - Code: IMG-20260224-0005', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-24 10:23:47', '2026-02-24 10:23:47'),
(59, 'labo', 'info', 8, 'resultatslabo', 'LAB-20260217-0003', '🔬 Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 03:35:41', '2026-02-25 03:35:41'),
(62, 'labo', 'info', 16, 'resultatslabo', 'LAB-20260217-3415', '🔬 Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', 'Disponible | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 03:57:46', '2026-02-25 03:57:46'),
(63, 'imagerie', 'info', 7, 'imagerie_examens', 'IMG-20260224-0004', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0004', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 04:09:00', '2026-02-25 04:09:00'),
(64, 'imagerie', 'info', 7, 'imagerie_examens', 'IMG-20260224-0004', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0004', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 04:09:00', '2026-02-25 04:09:00'),
(65, 'imagerie', 'info', 10, 'imagerie_examens', 'IMG-20260225-0001', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 05:03:39', '2026-02-25 05:18:45'),
(66, 'imagerie', 'info', 10, 'imagerie_examens', 'IMG-20260225-0001', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 05:03:39', '2026-02-25 05:18:45'),
(67, 'imagerie', 'info', 10, 'imagerie_examens', 'IMG-20260225-0001', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 05:18:55', '2026-02-25 05:18:55'),
(68, 'imagerie', 'info', 10, 'imagerie_examens', 'IMG-20260225-0001', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 05:18:55', '2026-02-25 05:18:55'),
(69, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 12:02:09', '2026-02-25 12:04:33'),
(70, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 12:02:10', '2026-02-25 12:04:33'),
(71, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 1, '2026-02-25 16:43:07', 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 12:38:00', '2026-02-25 15:43:12'),
(72, 'labo', 'info', 87, 'labo_echantillons', 'LAB-20260225-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260225-0001', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-25 15:49:59', '2026-02-25 15:49:59'),
(73, 'labo', 'info', 29, 'resultatslabo', 'LAB-20260225-0001', '🔬 Résultat labo disponible — Frottis Vaginal', '⚠️ Anormal | Patient : Boris Ikula | Acte : Frottis Vaginal (FROT-VAG) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 15:51:44', '2026-02-25 15:51:44'),
(74, 'labo', 'info', 88, 'labo_echantillons', 'LAB-20260225-0002', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260225-0002', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 15:55:13', '2026-02-25 15:55:13'),
(75, 'labo', 'info', 30, 'resultatslabo', 'LAB-20260225-0003', '🔬 Résultat labo disponible — Sérologie VIH', 'Disponible | Patient : Jean Ikula | Acte : Sérologie VIH (SERO-VIH) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 15:56:46', '2026-02-25 15:56:46'),
(76, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-25 16:41:46', '2026-02-26 02:13:12'),
(77, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:13:18', '2026-02-26 02:16:27'),
(78, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:16:31', '2026-02-26 02:18:59'),
(79, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:18:01', '2026-02-26 02:18:59'),
(80, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:19:03', '2026-02-26 02:21:19'),
(81, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:21:24', '2026-02-26 02:30:41'),
(82, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:28:15', '2026-02-26 02:30:41'),
(83, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:30:53', '2026-02-26 02:33:39'),
(84, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:33:54', '2026-02-26 02:34:16'),
(85, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:34:20', '2026-02-26 02:35:48'),
(86, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:35:53', '2026-02-26 02:37:09'),
(87, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:37:13', '2026-02-26 02:38:17'),
(88, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:38:27', '2026-02-26 02:40:00'),
(89, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:40:05', '2026-02-26 02:40:42'),
(90, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:40:49', '2026-02-26 02:43:16'),
(91, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:43:19', '2026-02-26 02:44:17'),
(92, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 1, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 02:50:41', '2026-02-26 14:50:21'),
(93, 'imagerie', 'info', 9, 'imagerie_examens', 'IMG-20260224-0006', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 14:50:35', '2026-02-26 14:50:35'),
(94, 'labo', 'info', 89, 'labo_echantillons', 'LAB-20260226-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0001', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 17:58:47', '2026-02-26 17:58:47'),
(95, 'labo', 'info', 90, 'labo_echantillons', 'LAB-20260226-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0003', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 17:58:47', '2026-02-26 17:58:47'),
(96, 'labo', 'info', 31, 'resultatslabo', 'LAB-20260226-0004', '🔬 Résultat labo disponible — Hémogramme Complet (NFS)', '✅ Normal | Patient : Jean Ikula | Acte : Hémogramme Complet (NFS) (NFS) | Saisi par : Papy KIBETE', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 18:05:56', '2026-02-26 18:05:56'),
(97, 'imagerie', 'info', 91, 'imagerie_examens', 'IMG-20260226-0001', 'Nouvel examen d\'imagerie', 'Échographie Thyroïdienne - Code: IMG-20260226-0001', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 0, 1, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 18:22:01', '2026-02-26 19:48:19'),
(98, 'imagerie', 'info', 12, 'imagerie_examens', 'IMG-20260226-0002', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour MBALA Jean - Échographie Thyroïdienne | Code: IMG-20260226-0002', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 18:26:27', '2026-02-26 18:26:27'),
(99, 'labo', 'info', 92, 'labo_echantillons', 'LAB-20260226-0005', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0005', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:22:05', '2026-02-26 19:22:05'),
(100, 'labo', 'info', 93, 'labo_echantillons', 'LAB-20260226-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0007', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:22:05', '2026-02-26 19:22:05'),
(101, 'labo', 'info', 32, 'resultatslabo', 'LAB-20260226-0002', '🔬 Résultat labo disponible — Coproculture', '⚠️ Anormal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Papy KIBETE', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 19:26:54', '2026-02-26 19:26:54'),
(102, 'labo', 'info', 94, 'labo_echantillons', 'LAB-20260226-0009', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0009', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:29:40', '2026-02-26 19:29:40'),
(103, 'labo', 'info', 95, 'labo_echantillons', 'LAB-20260226-0011', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0011', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:32:25', '2026-02-26 19:32:25'),
(104, 'labo', 'info', 96, 'labo_echantillons', 'LAB-20260226-0013', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0013', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:32:25', '2026-02-26 19:32:25'),
(105, 'labo', 'info', 97, 'labo_echantillons', 'LAB-20260226-0015', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0015', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:32:26', '2026-02-26 19:32:26'),
(106, 'labo', 'info', 98, 'labo_echantillons', 'LAB-20260226-0017', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260226-0017', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-26 19:32:26', '2026-02-26 19:32:26'),
(107, 'labo', 'info', 33, 'resultatslabo', 'LAB-20260226-0017', '🔬 Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Marie KASONGO | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Papy KIBETE', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 19:35:12', '2026-02-26 19:35:12'),
(108, 'labo', 'info', 34, 'resultatslabo', 'LAB-20260226-0001', '🔬 Résultat labo disponible — Coproculture', 'Disponible | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Papy KIBETE', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 19:42:07', '2026-02-26 19:42:07'),
(109, 'imagerie', 'info', 11, 'imagerie_examens', 'IMG-20260226-0001', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour MBALA Jean - Échographie Thyroïdienne | Code: IMG-20260226-0001', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-26 19:48:24', '2026-02-26 19:48:24'),
(110, 'labo', 'info', 99, 'labo_echantillons', 'LAB-20260227-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0001', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 04:13:53', '2026-02-27 04:13:53'),
(111, 'labo', 'info', 100, 'labo_echantillons', 'LAB-20260227-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0003', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 04:13:54', '2026-02-27 04:13:54'),
(112, 'labo', 'info', 101, 'labo_echantillons', 'LAB-20260227-0005', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0005', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 04:13:55', '2026-02-27 04:13:55'),
(113, 'labo', 'info', 102, 'labo_echantillons', 'LAB-20260227-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0007', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 04:13:56', '2026-02-27 04:13:56'),
(114, 'labo', 'info', 103, 'labo_echantillons', 'LAB-20260227-0009', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0009', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 04:13:56', '2026-02-27 04:13:56'),
(115, 'labo', 'info', 23, 'resultatslabo', 'LAB-20260223-0001', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 04:34:43', '2026-02-27 04:34:43'),
(116, 'labo', 'info', 23, 'resultatslabo', 'LAB-20260223-0001', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 04:34:46', '2026-02-27 04:34:46'),
(117, 'labo', 'info', 23, 'resultatslabo', 'LAB-20260223-0001', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 04:39:25', '2026-02-27 04:39:25'),
(118, 'labo', 'info', 34, 'resultatslabo', 'LAB-20260226-0001', '🔬 Résultat labo disponible — Coproculture', 'Disponible | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 04:53:33', '2026-02-27 04:53:33'),
(119, 'labo', 'info', 23, 'resultatslabo', 'LAB-20260223-0001', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '✅ Normal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 04:55:15', '2026-02-27 04:55:15'),
(120, 'labo', 'info', 104, 'labo_echantillons', 'LAB-20260227-0011', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0011', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 05:38:01', '2026-02-27 05:38:01'),
(121, 'labo', 'info', 105, 'labo_echantillons', 'LAB-20260227-0013', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0013', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 05:38:01', '2026-02-27 05:38:01'),
(122, 'labo', 'info', 106, 'labo_echantillons', 'LAB-20260227-0015', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0015', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 05:38:01', '2026-02-27 05:38:01'),
(123, 'labo', 'info', 107, 'labo_echantillons', 'LAB-20260227-0020', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0020', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 10:24:12', '2026-02-27 10:24:12'),
(124, 'labo', 'info', 108, 'labo_echantillons', 'LAB-20260227-0021', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0021', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 10:24:12', '2026-02-27 10:24:12'),
(125, 'labo', 'info', 109, 'labo_echantillons', 'LAB-20260227-0022', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0022', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 10:24:12', '2026-02-27 10:24:12'),
(126, 'labo', 'info', 110, 'labo_echantillons', 'LAB-20260227-0023', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0023', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 13:33:14', '2026-02-27 13:33:14'),
(127, 'labo', 'info', 111, 'labo_echantillons', 'LAB-20260227-0024', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0024', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 13:33:14', '2026-02-27 13:33:14'),
(128, 'labo', 'info', 32, 'resultatslabo', 'LAB-20260226-0002', '🔬 Résultat labo disponible — Coproculture', '⚠️ Anormal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 13:38:13', '2026-02-27 13:38:13'),
(129, 'labo', 'info', 112, 'labo_echantillons', 'LAB-20260227-0025', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260227-0025', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-02-27 15:30:26', '2026-02-27 15:30:26'),
(130, 'labo', 'info', 32, 'resultatslabo', 'LAB-20260226-0002', '🔬 Résultat labo disponible — Coproculture', '⚠️ Anormal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-02-27 17:53:02', '2026-02-27 17:53:02'),
(131, 'labo', 'info', 113, 'labo_echantillons', 'LAB-20260304-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0001', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 08:56:40', '2026-03-04 08:56:40'),
(132, 'labo', 'info', 114, 'labo_echantillons', 'LAB-20260304-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0003', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 08:56:40', '2026-03-04 08:56:40'),
(133, 'labo', 'info', 115, 'labo_echantillons', 'LAB-20260304-0005', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0005', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 09:13:18', '2026-03-04 09:13:18'),
(134, 'labo', 'info', 116, 'labo_echantillons', 'LAB-20260304-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0007', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 09:13:18', '2026-03-04 09:13:18'),
(135, 'labo', 'info', 117, 'labo_echantillons', 'LAB-20260304-0009', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0009', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 09:26:08', '2026-03-04 15:46:14'),
(136, 'labo', 'info', 118, 'labo_echantillons', 'LAB-20260304-0011', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0011', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 10:59:47', '2026-03-04 15:46:14'),
(137, 'pharmacie', 'info', 28, 'pharmacie_preparations', 'PHAR-20260304-0001', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 11:07:57', '2026-03-04 15:46:11'),
(138, 'pharmacie', 'info', 29, 'pharmacie_preparations', 'PHAR-20260304-0002', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 11:12:02', '2026-03-04 15:46:10'),
(139, 'pharmacie', 'info', 30, 'pharmacie_preparations', 'PHAR-20260304-0003', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 12:04:55', '2026-03-04 15:46:09'),
(140, 'imagerie', 'info', 119, 'imagerie_examens', 'IMG-20260304-0001', 'Nouvel examen d\'imagerie', 'Échographie Testiculaire - Code: IMG-20260304-0001', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 12:06:28', '2026-03-04 15:46:09'),
(141, 'imagerie', 'info', 120, 'imagerie_examens', 'IMG-20260304-0003', 'Nouvel examen d\'imagerie', 'Radiographie Abdomen sans Préparation - Code: IMG-20260304-0003', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 12:08:20', '2026-03-04 15:46:08'),
(142, 'labo', 'info', 121, 'labo_echantillons', 'LAB-20260304-0013', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0013', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 13:29:23', '2026-03-04 15:46:07'),
(143, 'imagerie', 'info', 122, 'imagerie_examens', 'IMG-20260304-0005', 'Nouvel examen d\'imagerie', 'Radiographie Thorax Face/Profil - Code: IMG-20260304-0005', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 13:31:27', '2026-03-04 15:46:04'),
(144, 'labo', 'info', 38, 'resultatslabo', 'LAB-20260304-0001-02', '🔬 Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '✅ Normal | Patient : Jean MBALA | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 1, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-04 14:16:39', '2026-03-04 15:46:01'),
(145, 'labo', 'info', 123, 'labo_echantillons', 'LAB-20260304-0014', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260304-0014', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-04 14:59:01', '2026-03-04 15:45:59'),
(146, 'labo', 'info', 40, 'resultatslabo', 'LAB-20260226-0003', '🔬 Résultat labo — Hémogramme Complet (NFS)', 'Disponible | Patient : Jean Ikula | Saisi par : Système Admin', NULL, NULL, 28, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-05 09:53:03', '2026-03-05 09:53:03'),
(147, 'labo', 'info', 41, 'resultatslabo', 'LAB-20260225-0002', '🔬 Résultat labo disponible — Sérologie VIH', '✅ Normal | Patient : Jean Ikula | Acte : Sérologie VIH (SERO-VIH) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-05 10:10:57', '2026-03-05 10:10:57'),
(148, 'labo', 'info', 42, 'resultatslabo', 'LAB-20260304-0014', '🔬 Résultat labo disponible — Groupage Sanguin ABO-Rhésus', '✅ Normal | Patient : Jean MBALA | Acte : Groupage Sanguin ABO-Rhésus (GROUP-ABO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-05 10:18:17', '2026-03-05 10:18:17'),
(149, 'labo', 'info', 124, 'labo_echantillons', 'LAB-20260305-0001', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0001', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 10:22:07', '2026-03-05 10:22:07'),
(150, 'labo', 'info', 125, 'labo_echantillons', 'LAB-20260305-0002', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0002', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 10:22:07', '2026-03-05 10:22:07'),
(151, 'labo', 'info', 126, 'labo_echantillons', 'LAB-20260305-0003', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0003', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 10:34:12', '2026-03-05 10:34:12'),
(152, 'labo', 'info', 127, 'labo_echantillons', 'LAB-20260305-0005', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0005', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 10:34:12', '2026-03-05 10:34:12'),
(153, 'labo', 'info', 128, 'labo_echantillons', 'LAB-20260305-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0007', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 10:48:20', '2026-03-05 10:48:20'),
(154, 'labo', 'info', 129, 'labo_echantillons', 'LAB-20260305-0009', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0009', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 10:48:20', '2026-03-05 10:48:20'),
(155, 'labo', 'info', 130, 'labo_echantillons', 'LAB-20260305-0011', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0011', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 12:05:17', '2026-03-05 12:05:17'),
(156, 'labo', 'info', 131, 'labo_echantillons', 'LAB-20260305-0013', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0013', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 13:46:41', '2026-03-05 13:46:41'),
(157, 'labo', 'info', 132, 'labo_echantillons', 'LAB-20260305-0015', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0015', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 13:46:41', '2026-03-05 13:46:41'),
(158, 'labo', 'info', 133, 'labo_echantillons', 'LAB-20260305-0017', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0017', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 14:42:01', '2026-03-05 14:42:01'),
(159, 'labo', 'info', 134, 'labo_echantillons', 'LAB-20260305-0019', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0019', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 14:42:01', '2026-03-05 14:42:01'),
(160, 'labo', 'info', 135, 'actes_presc', 'AP-135', 'Nouvelle prescription laboratoire', 'Prescription labo #135', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 18:45:51', '2026-03-05 18:45:51');
INSERT INTO `services_notifications` (`idnotification`, `service`, `type_notification`, `id_reference`, `table_reference`, `code_reference`, `titre`, `message`, `details`, `id_destinateur`, `id_destinataire`, `groupe_destinataire`, `lu`, `date_lecture`, `archive`, `renvoyer`, `actions_possibles`, `action_effectuee`, `date_action`, `priorite`, `metadata`, `expiration`, `created_at`, `updated_at`) VALUES
(161, 'labo', 'info', 138, 'labo_echantillons', 'LAB-20260305-0024', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0024', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 19:36:43', '2026-03-05 19:36:43'),
(162, 'labo', 'info', 139, 'labo_echantillons', 'LAB-20260305-0026', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0026', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 19:36:43', '2026-03-05 19:36:43'),
(163, 'imagerie', 'info', 140, 'imagerie_examens', 'IMG-20260305-0001', 'Nouvel examen d\'imagerie', 'Échographie Pelvienne - Code:IMG-20260305-0001', NULL, NULL, NULL, 'secretaires_imagerie', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 19:38:13', '2026-03-05 19:38:13'),
(164, 'pharmacie', 'info', 31, 'pharmacie_preparations', 'PHAR-20260305-0001', 'Nouvelle prescription pharmacie', NULL, NULL, NULL, NULL, 'preparateurs_pharmacie', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 19:39:20', '2026-03-05 22:35:11'),
(165, 'labo', 'info', 141, 'labo_echantillons', 'LAB-20260305-0028', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0028', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 19:54:13', '2026-03-05 22:35:12'),
(166, 'labo', 'info', 149, 'actes_presc', 'AP-149', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260305-0006 (examen #1)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 20:36:25', '2026-03-05 22:35:09'),
(167, 'labo', 'info', 150, 'actes_presc', 'AP-150', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260305-0006 (examen #2)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 20:36:25', '2026-03-05 22:35:09'),
(168, 'labo', 'info', 151, 'actes_presc', 'LAB-20260305-0006', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260305-0006 (examen #3)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 20:42:43', '2026-03-05 22:35:07'),
(169, 'labo', 'info', 152, 'actes_presc', 'LAB-20260305-0003', 'Nouvelle prescription laboratoire', 'Prescription ajoutée au groupe LAB-20260305-0003 (examen #3)', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-03-05 23:35:04', 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 20:53:48', '2026-03-05 22:35:05'),
(170, 'labo', 'info', 153, 'labo_echantillons', 'LAB-20260305-0007', 'Nouvelle prescription laboratoire', 'Prescription créée pour l\'échantillon LAB-20260305-0007', NULL, NULL, NULL, 'techniciens_labo', 1, '2026-03-05 23:35:02', 1, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 20:56:54', '2026-03-05 22:35:06'),
(171, 'labo', 'info', 154, 'actes_presc', 'LAB-20260305-0001', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260305-0001 (examen #4)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 22:35:37', '2026-03-05 22:35:37'),
(172, 'labo', 'info', 155, 'actes_presc', 'LAB-20260305-0005', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260305-0005 (examen #3)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-05 22:36:54', '2026-03-05 22:36:54'),
(173, 'labo', 'info', 43, 'resultatslabo', 'LAB-20260305-0007-01', '🔬 Résultat labo — Ionogramme Sanguin', '✅ Normal | Patient : Test1 Bloc | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-05 23:13:21', '2026-03-05 23:13:21'),
(174, 'labo', 'info', 44, 'resultatslabo', 'LAB-20260305-0001-01', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '✅ Normal | Patient : Sacha kasaka | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 00:28:13', '2026-03-06 00:28:13'),
(175, 'labo', 'info', 45, 'resultatslabo', 'LAB-20260305-0001-02', '🔬 Résultat labo disponible — VS (Vitesse de Sédimentation)', '✅ Normal | Patient : Sacha kasaka | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 00:28:14', '2026-03-06 00:28:14'),
(176, 'labo', 'info', 46, 'resultatslabo', 'LAB-20260305-0001-03', '🔬 Résultat labo disponible — Coproculture', '✅ Normal | Patient : Sacha kasaka | Acte : Coproculture (COPRO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 00:28:14', '2026-03-06 00:28:14'),
(177, 'labo', 'info', 47, 'resultatslabo', 'LAB-20260305-0001-04', '🔬 Résultat labo disponible — CRP (Protéine C Réactive)', '✅ Normal | Patient : Sacha kasaka | Acte : CRP (Protéine C Réactive) (CRP) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 00:28:14', '2026-03-06 00:28:14'),
(178, 'labo', 'info', 48, 'resultatslabo', 'LAB-20260305-0002-01', '🔬 Résultat labo disponible — Glycémie à Jeun', 'Disponible | Patient : Marie KASONGO | Acte : Glycémie à Jeun (GLYC-JEUN) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 10:13:16', '2026-03-06 10:13:16'),
(179, 'labo', 'info', 49, 'resultatslabo', 'LAB-20260305-0002-02', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Marie KASONGO | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 10:13:17', '2026-03-06 10:13:17'),
(180, 'labo', 'info', 50, 'resultatslabo', 'LAB-20260305-0002-03', '🔬 Résultat labo disponible — Groupage Sanguin ABO-Rhésus', 'Disponible | Patient : Marie KASONGO | Acte : Groupage Sanguin ABO-Rhésus (GROUP-ABO) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 10:13:17', '2026-03-06 10:13:17'),
(181, 'labo', 'info', 51, 'resultatslabo', 'LAB-20260305-0002-04', '🔬 Résultat labo disponible — ECBU (Examen Cyto-Bactériologique Urines)', 'Disponible | Patient : Marie KASONGO | Acte : ECBU (Examen Cyto-Bactériologique Urines) (ECBU) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 10:13:17', '2026-03-06 10:13:17'),
(182, 'labo', 'info', 52, 'resultatslabo', 'LAB-20260305-0002-05', '🔬 Résultat labo disponible — VS (Vitesse de Sédimentation)', 'Disponible | Patient : Marie KASONGO | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 10:13:17', '2026-03-06 10:13:17'),
(183, 'labo', 'info', 0, 'labo_groupes_echantillons', 'LAB-20260305-0005', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0005', 'Patient : Test1 Patient | Examens : CRP (Protéine C Réactive), Urée et Créatinine, TP, TCA, INR | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 12:16:07', '2026-03-06 12:16:07'),
(184, 'labo', 'info', 0, 'labo_groupes_echantillons', 'LAB-20260305-0003', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0003', 'Patient : Jean Ikula | Examens : Groupage Sanguin ABO-Rhésus, Ionogramme Sanguin, Glycémie à Jeun | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 12:58:24', '2026-03-06 12:58:24'),
(185, 'labo', 'info', 0, 'labo_groupes_echantillons', 'LAB-20260227-0001', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260227-0001', 'Patient : Sacha kasaka | Examens : Ionogramme Sanguin, Sérologie Hépatite B, ECBU (Examen Cyto-Bactériologique Urines), Urée et Créatinine | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-06 13:09:54', '2026-03-06 13:09:54'),
(186, 'labo', 'info', 0, 'labo_groupes_echantillons', 'LAB-20260305-0006', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0006', 'Patient : Boris Ikula | Examens : Bilan Hépatique Complet, VS (Vitesse de Sédimentation), Glycémie à Jeun | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-07 08:36:29', '2026-03-07 08:36:29'),
(187, 'labo', 'info', 157, 'actes_presc', 'LAB-20260308-0001', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260308-0001 (examen #1)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-08 00:59:06', '2026-03-08 00:59:06'),
(188, 'labo', 'info', 158, 'actes_presc', 'LAB-20260308-0001', 'Nouvelle prescription laboratoire', 'Prescription pour le groupe LAB-20260308-0001 (examen #2)', NULL, NULL, NULL, 'techniciens_labo', 0, NULL, 0, 0, NULL, NULL, NULL, 'normale', NULL, NULL, '2026-03-08 00:59:06', '2026-03-08 00:59:06'),
(189, 'labo', 'info', 0, 'labo_groupes_echantillons', 'LAB-20260308-0001', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0001', 'Patient : Jean Ikula | Examens : Bilan Hépatique Complet, Ionogramme Sanguin | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-08 01:00:33', '2026-03-08 01:00:33'),
(190, 'labo', 'info', 0, 'labo_groupes_echantillons', 'LAB-20260305-0004', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0004', 'Patient : Pierre NKULU | Examens : Urée et Créatinine | Saisi par : Système Admin', NULL, NULL, 1, NULL, 0, NULL, 0, 0, NULL, NULL, NULL, 'haute', NULL, NULL, '2026-03-08 01:12:54', '2026-03-08 01:12:54');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_dashboard_services`
-- (See below for the actual view)
--
CREATE TABLE `v_dashboard_services` (
`service` varchar(9)
,`total` bigint
,`en_attente` decimal(23,0)
,`en_cours` decimal(23,0)
,`termines` decimal(23,0)
,`urgents` decimal(23,0)
,`plus_ancien` timestamp
,`plus_recent` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_labo_detail`
-- (See below for the actual view)
--
CREATE TABLE `v_labo_detail` (
`code_echantillon` varchar(50)
,`statut` enum('attente_prelevement','preleve','transit','receptionne','controle_qualite','en_analyse','analyse_terminee','validation_technique','validation_biologiste','resultat_transmis','rejete','perdu','annule')
,`type_prelevement` varchar(50)
,`tube_type` varchar(30)
,`couleur_tube` varchar(20)
,`urgence` bigint
,`priorite` varchar(7)
,`date_prelevement` datetime
,`date_reception` datetime
,`date_debut_analyse` datetime
,`date_fin_analyse` datetime
,`delai_theorique_min` bigint
,`delai_actuel_min` bigint
,`patient_nom` varchar(100)
,`patient_prenom` varchar(100)
,`numero_dossier` varchar(20)
,`date_naissance` date
,`sexe` enum('M','F')
,`age` bigint
,`examen_libelle` varchar(200)
,`examen_code` varchar(20)
,`date_prescription` timestamp
,`prescripteur` int
,`preleveur_nom` varchar(100)
,`preleveur_prenom` varchar(100)
,`technicien_nom` varchar(100)
,`technicien_prenom` varchar(100)
,`machine_nom` varchar(100)
,`machine_modele` varchar(100)
,`delai_prelevement_reception_min` bigint
,`delai_reception_analyse_min` bigint
,`alerte_delai` varchar(6)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_labo_statistiques`
-- (See below for the actual view)
--
CREATE TABLE `v_labo_statistiques` (
`date_jour` date
,`total_echantillons` bigint
,`echantillons_termines` decimal(23,0)
,`urgents` decimal(23,0)
,`delai_moyen_prelevement_reception_min` decimal(23,2)
,`delai_moyen_analyse_min` decimal(23,2)
,`delai_moyen_validation_min` decimal(23,2)
,`echantillons_rejetes` decimal(23,0)
,`taux_rejet_pourcentage` decimal(29,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_notifications_non_lues`
-- (See below for the actual view)
--
CREATE TABLE `v_notifications_non_lues` (
`service` enum('labo','imagerie','pharmacie','system')
,`type_notification` enum('info','alerte','urgence','validation','erreur')
,`nombre` bigint
,`plus_ancienne` timestamp
,`plus_recente` timestamp
,`references_liste` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_tokens_resultats`
-- (See below for the actual view)
--
CREATE TABLE `v_tokens_resultats` (
`id` int
,`token` varchar(64)
,`code_echantillon` varchar(50)
,`idresultat` int
,`email_destinataire` varchar(255)
,`date_creation` datetime
,`date_expiration` datetime
,`actif` tinyint
,`vu_le` datetime
,`nb_consultations` int
,`echantillon_statut` enum('attente_prelevement','preleve','transit','receptionne','controle_qualite','en_analyse','analyse_terminee','validation_technique','validation_biologiste','resultat_transmis','rejete','perdu','annule')
,`patient_nom` varchar(201)
,`examen_libelle` varchar(200)
,`createur_nom` varchar(201)
,`statut_token` varchar(12)
,`jours_avant_expiration` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_workflow_complet`
-- (See below for the actual view)
--
CREATE TABLE `v_workflow_complet` (
`service_type` varchar(11)
,`code_reference` varchar(50)
,`statut_courant` varchar(21)
,`urgence` bigint
,`priorite` varchar(15)
,`patient_nom` varchar(100)
,`patient_prenom` varchar(100)
,`numero_dossier` varchar(20)
,`acte_libelle` varchar(200)
,`date_prelevement` datetime
,`date_terminaison` datetime
,`date_creation` timestamp
,`age_heures` bigint
,`actions_recentes` text
,`derniere_action_date` timestamp
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `imagerie_examens`
--
ALTER TABLE `imagerie_examens`
  ADD PRIMARY KEY (`idexamen`),
  ADD UNIQUE KEY `code_examen` (`code_examen`),
  ADD KEY `idx_code_examen` (`code_examen`),
  ADD KEY `idx_statut` (`statut`),
  ADD KEY `idx_date_rdv` (`date_rdv`),
  ADD KEY `idx_radiologue` (`radiologue`),
  ADD KEY `idactes_presc` (`idactes_presc`),
  ADD KEY `idpatient` (`idpatient`),
  ADD KEY `idx_imagerie_statut_date` (`statut`,`date_rdv`),
  ADD KEY `deleted_by` (`deleted_by`),
  ADD KEY `idx_date_examen` (`date_examen`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `imagerie_fichiers`
--
ALTER TABLE `imagerie_fichiers`
  ADD PRIMARY KEY (`idfichier`),
  ADD KEY `idx_fichier_examen` (`idexamen`),
  ADD KEY `idx_fichier_type` (`type_fichier`);

--
-- Indexes for table `imagerie_planning`
--
ALTER TABLE `imagerie_planning`
  ADD PRIMARY KEY (`idplanning`),
  ADD KEY `idx_idexamen` (`idexamen`),
  ADD KEY `idx_date` (`date_planification`),
  ADD KEY `idx_salle` (`idsalle`),
  ADD KEY `idx_statut` (`statut`);

--
-- Indexes for table `imagerie_salles`
--
ALTER TABLE `imagerie_salles`
  ADD PRIMARY KEY (`idsalle`);

--
-- Indexes for table `imagerie_salle_equipements`
--
ALTER TABLE `imagerie_salle_equipements`
  ADD PRIMARY KEY (`idsalle`,`idequipement`),
  ADD KEY `idequipement` (`idequipement`);

--
-- Indexes for table `imagerie_tokens`
--
ALTER TABLE `imagerie_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expiration` (`date_expiration`);

--
-- Indexes for table `imagerie_workflow_history`
--
ALTER TABLE `imagerie_workflow_history`
  ADD PRIMARY KEY (`idhistory`),
  ADD KEY `idx_idexamen` (`idexamen`),
  ADD KEY `idx_nouveau_statut` (`nouveau_statut`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `labo_echantillons`
--
ALTER TABLE `labo_echantillons`
  ADD PRIMARY KEY (`idechantillon`),
  ADD UNIQUE KEY `code_echantillon` (`code_echantillon`),
  ADD KEY `idx_code_echantillon` (`code_echantillon`),
  ADD KEY `idx_statut` (`statut`),
  ADD KEY `idx_idactes_presc` (`idactes_presc`),
  ADD KEY `idx_idpatient` (`idpatient`),
  ADD KEY `idx_date_prelevement` (`date_prelevement`),
  ADD KEY `idx_urgence` (`urgence`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_type_prelevement` (`type_prelevement`),
  ADD KEY `idx_labo_date_statut` (`created_at`,`statut`),
  ADD KEY `idx_labo_patient_date` (`idpatient`,`created_at` DESC),
  ADD KEY `idx_groupe` (`idgroupe`);

--
-- Indexes for table `labo_echantillon_resultats`
--
ALTER TABLE `labo_echantillon_resultats`
  ADD PRIMARY KEY (`idechantillon`,`idresultat`),
  ADD KEY `idresultat` (`idresultat`);

--
-- Indexes for table `labo_groupes_echantillons`
--
ALTER TABLE `labo_groupes_echantillons`
  ADD PRIMARY KEY (`idgroupe`),
  ADD UNIQUE KEY `code_groupe` (`code_groupe`),
  ADD KEY `idx_patient` (`idpatient`),
  ADD KEY `idx_code` (`code_groupe`);

--
-- Indexes for table `labo_groupes_tokens`
--
ALTER TABLE `labo_groupes_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `code_groupe` (`code_groupe`),
  ADD KEY `date_expiration` (`date_expiration`);

--
-- Indexes for table `labo_resultats_tokens`
--
ALTER TABLE `labo_resultats_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expiration` (`date_expiration`),
  ADD KEY `code_echantillon` (`code_echantillon`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_actif_expiration` (`actif`,`date_expiration`);

--
-- Indexes for table `labo_workflow_history`
--
ALTER TABLE `labo_workflow_history`
  ADD PRIMARY KEY (`idhistory`),
  ADD KEY `idx_idechantillon` (`idechantillon`),
  ADD KEY `idx_date` (`created_at`),
  ADD KEY `idx_utilisateur` (`idutilisateur`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `pharmacie_preparations`
--
ALTER TABLE `pharmacie_preparations`
  ADD PRIMARY KEY (`idpreparation`),
  ADD UNIQUE KEY `code_preparation` (`code_preparation`),
  ADD KEY `idx_code_preparation` (`code_preparation`),
  ADD KEY `idx_statut` (`statut`),
  ADD KEY `idx_idpharma_presc` (`idpharma_presc`),
  ADD KEY `idx_date_delivrance` (`date_delivrance`),
  ADD KEY `idpatient` (`idpatient`),
  ADD KEY `idx_pharma_statut_urgence` (`statut`,`urgence`),
  ADD KEY `idx_pharma_presc` (`idpharma_presc`);

--
-- Indexes for table `pharmacie_workflow_history`
--
ALTER TABLE `pharmacie_workflow_history`
  ADD PRIMARY KEY (`idhistory`),
  ADD KEY `idx_idpreparation` (`idpreparation`),
  ADD KEY `idx_nouveau_statut` (`nouveau_statut`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `resultatslabo_lignes`
--
ALTER TABLE `resultatslabo_lignes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `services_notifications`
--
ALTER TABLE `services_notifications`
  ADD PRIMARY KEY (`idnotification`),
  ADD KEY `idx_service` (`service`),
  ADD KEY `idx_type` (`type_notification`),
  ADD KEY `idx_destinataire` (`id_destinataire`),
  ADD KEY `idx_lu` (`lu`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_priorite` (`priorite`),
  ADD KEY `idx_code_reference` (`code_reference`),
  ADD KEY `idx_notif_service_lu` (`service`,`lu`,`created_at` DESC);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `imagerie_examens`
--
ALTER TABLE `imagerie_examens`
  MODIFY `idexamen` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `imagerie_fichiers`
--
ALTER TABLE `imagerie_fichiers`
  MODIFY `idfichier` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `imagerie_planning`
--
ALTER TABLE `imagerie_planning`
  MODIFY `idplanning` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `imagerie_salles`
--
ALTER TABLE `imagerie_salles`
  MODIFY `idsalle` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `imagerie_tokens`
--
ALTER TABLE `imagerie_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `imagerie_workflow_history`
--
ALTER TABLE `imagerie_workflow_history`
  MODIFY `idhistory` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `labo_echantillons`
--
ALTER TABLE `labo_echantillons`
  MODIFY `idechantillon` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=139;

--
-- AUTO_INCREMENT for table `labo_groupes_echantillons`
--
ALTER TABLE `labo_groupes_echantillons`
  MODIFY `idgroupe` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `labo_groupes_tokens`
--
ALTER TABLE `labo_groupes_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `labo_resultats_tokens`
--
ALTER TABLE `labo_resultats_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `labo_workflow_history`
--
ALTER TABLE `labo_workflow_history`
  MODIFY `idhistory` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=245;

--
-- AUTO_INCREMENT for table `pharmacie_preparations`
--
ALTER TABLE `pharmacie_preparations`
  MODIFY `idpreparation` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `pharmacie_workflow_history`
--
ALTER TABLE `pharmacie_workflow_history`
  MODIFY `idhistory` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `resultatslabo_lignes`
--
ALTER TABLE `resultatslabo_lignes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services_notifications`
--
ALTER TABLE `services_notifications`
  MODIFY `idnotification` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=191;

-- --------------------------------------------------------

--
-- Structure for view `v_dashboard_services`
--
DROP TABLE IF EXISTS `v_dashboard_services`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_dashboard_services`  AS SELECT 'labo' AS `service`, count(0) AS `total`, sum((case when (`labo_echantillons`.`statut` = 'attente_prelevement') then 1 else 0 end)) AS `en_attente`, sum((case when (`labo_echantillons`.`statut` in ('preleve','transit','receptionne','controle_qualite','en_analyse')) then 1 else 0 end)) AS `en_cours`, sum((case when (`labo_echantillons`.`statut` in ('resultat_transmis','validation_biologiste')) then 1 else 0 end)) AS `termines`, sum((case when (`labo_echantillons`.`urgence` = 1) then 1 else 0 end)) AS `urgents`, min(`labo_echantillons`.`created_at`) AS `plus_ancien`, max(`labo_echantillons`.`created_at`) AS `plus_recent` FROM `labo_echantillons` WHERE ((`labo_echantillons`.`deleted_at` is null) OR (`labo_echantillons`.`deleted_at` is not null))union all select 'imagerie' AS `service`,count(0) AS `total`,sum((case when (`imagerie_examens`.`statut` = 'programme') then 1 else 0 end)) AS `en_attente`,sum((case when (`imagerie_examens`.`statut` in ('accueil','en_preparation','en_acquisition','en_reconstruction','en_interpretation')) then 1 else 0 end)) AS `en_cours`,sum((case when (`imagerie_examens`.`statut` in ('transmis','validation_radiologue')) then 1 else 0 end)) AS `termines`,sum((case when (`imagerie_examens`.`urgence` = 1) then 1 else 0 end)) AS `urgents`,min(`imagerie_examens`.`created_at`) AS `plus_ancien`,max(`imagerie_examens`.`created_at`) AS `plus_recent` from `imagerie_examens` union all select 'pharmacie' AS `service`,count(0) AS `total`,sum((case when (`pharmacie_preparations`.`statut` = 'attente') then 1 else 0 end)) AS `en_attente`,sum((case when (`pharmacie_preparations`.`statut` in ('verification_stock','en_preparation','controle_qualite')) then 1 else 0 end)) AS `en_cours`,sum((case when (`pharmacie_preparations`.`statut` in ('delivree','prete')) then 1 else 0 end)) AS `termines`,sum((case when (`pharmacie_preparations`.`urgence` = 1) then 1 else 0 end)) AS `urgents`,min(`pharmacie_preparations`.`created_at`) AS `plus_ancien`,max(`pharmacie_preparations`.`created_at`) AS `plus_recent` from `pharmacie_preparations`  ;

-- --------------------------------------------------------

--
-- Structure for view `v_labo_detail`
--
DROP TABLE IF EXISTS `v_labo_detail`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_labo_detail`  AS SELECT `le`.`code_echantillon` AS `code_echantillon`, `le`.`statut` AS `statut`, `le`.`type_prelevement` AS `type_prelevement`, `le`.`tube_type` AS `tube_type`, `le`.`couleur_tube` AS `couleur_tube`, ifnull(`le`.`urgence`,0) AS `urgence`, ifnull(`le`.`priorite`,'normale') AS `priorite`, `le`.`date_prelevement` AS `date_prelevement`, `le`.`date_reception` AS `date_reception`, `le`.`date_debut_analyse` AS `date_debut_analyse`, `le`.`date_fin_analyse` AS `date_fin_analyse`, ifnull(`le`.`delai_theorique_min`,120) AS `delai_theorique_min`, timestampdiff(MINUTE,`le`.`created_at`,now()) AS `delai_actuel_min`, `p`.`nom` AS `patient_nom`, `p`.`prenom` AS `patient_prenom`, `p`.`numero_dossier` AS `numero_dossier`, `p`.`date_naissance` AS `date_naissance`, `p`.`sexe` AS `sexe`, timestampdiff(YEAR,`p`.`date_naissance`,curdate()) AS `age`, `a`.`libelle` AS `examen_libelle`, `a`.`code` AS `examen_code`, `ap`.`date_prescription` AS `date_prescription`, `ap`.`prescripteur` AS `prescripteur`, `u_preleveur`.`nom` AS `preleveur_nom`, `u_preleveur`.`prenom` AS `preleveur_prenom`, `u_technicien`.`nom` AS `technicien_nom`, `u_technicien`.`prenom` AS `technicien_prenom`, `ml`.`nom` AS `machine_nom`, `ml`.`modele` AS `machine_modele`, (case when ((`le`.`date_prelevement` is not null) and (`le`.`date_reception` is not null)) then timestampdiff(MINUTE,`le`.`date_prelevement`,`le`.`date_reception`) else NULL end) AS `delai_prelevement_reception_min`, (case when ((`le`.`date_reception` is not null) and (`le`.`date_fin_analyse` is not null)) then timestampdiff(MINUTE,`le`.`date_reception`,`le`.`date_fin_analyse`) else NULL end) AS `delai_reception_analyse_min`, (case when ((`le`.`statut` not in ('resultat_transmis','annule','rejete','perdu')) and (timestampdiff(MINUTE,`le`.`created_at`,now()) > (ifnull(`le`.`delai_theorique_min`,120) * 1.5))) then 'RETARD' when ((`le`.`statut` not in ('resultat_transmis','annule','rejete','perdu')) and (timestampdiff(MINUTE,`le`.`created_at`,now()) > ifnull(`le`.`delai_theorique_min`,120))) then 'ALERTE' else 'OK' end) AS `alerte_delai` FROM ((((((`labo_echantillons` `le` left join `csk_base`.`patient` `p` on((`le`.`idpatient` = `p`.`idpatient`))) left join `csk_base`.`actes_presc` `ap` on((`le`.`idactes_presc` = `ap`.`idactes_presc`))) left join `csk_base`.`acte` `a` on((`ap`.`idacte` = `a`.`idacte`))) left join `csk_base`.`utilisateur` `u_preleveur` on((`le`.`preleveur` = `u_preleveur`.`idutilisateur`))) left join `csk_base`.`utilisateur` `u_technicien` on((`le`.`technicien_analyse` = `u_technicien`.`idutilisateur`))) left join `csk_base`.`machineslabo` `ml` on((`le`.`idmachinelabo` = `ml`.`idmachinelabo`))) WHERE ((`le`.`deleted_at` is null) OR (`le`.`deleted_at` is null)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_labo_statistiques`
--
DROP TABLE IF EXISTS `v_labo_statistiques`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_labo_statistiques`  AS SELECT cast(`labo_echantillons`.`created_at` as date) AS `date_jour`, count(0) AS `total_echantillons`, sum((case when (`labo_echantillons`.`statut` in ('resultat_transmis','validation_biologiste')) then 1 else 0 end)) AS `echantillons_termines`, sum((case when (ifnull(`labo_echantillons`.`urgence`,0) = 1) then 1 else 0 end)) AS `urgents`, round(avg((case when ((`labo_echantillons`.`date_prelevement` is not null) and (`labo_echantillons`.`date_reception` is not null)) then timestampdiff(MINUTE,`labo_echantillons`.`date_prelevement`,`labo_echantillons`.`date_reception`) else NULL end)),2) AS `delai_moyen_prelevement_reception_min`, round(avg((case when ((`labo_echantillons`.`date_reception` is not null) and (`labo_echantillons`.`date_fin_analyse` is not null)) then timestampdiff(MINUTE,`labo_echantillons`.`date_reception`,`labo_echantillons`.`date_fin_analyse`) else NULL end)),2) AS `delai_moyen_analyse_min`, round(avg((case when ((`labo_echantillons`.`date_fin_analyse` is not null) and (`labo_echantillons`.`date_validation` is not null)) then timestampdiff(MINUTE,`labo_echantillons`.`date_fin_analyse`,`labo_echantillons`.`date_validation`) else NULL end)),2) AS `delai_moyen_validation_min`, sum((case when (`labo_echantillons`.`statut` in ('rejete','perdu')) then 1 else 0 end)) AS `echantillons_rejetes`, round(((sum((case when (`labo_echantillons`.`statut` in ('rejete','perdu')) then 1 else 0 end)) * 100.0) / nullif(count(0),0)),2) AS `taux_rejet_pourcentage` FROM `labo_echantillons` WHERE (`labo_echantillons`.`created_at` >= (now() - interval 30 day)) GROUP BY cast(`labo_echantillons`.`created_at` as date) ORDER BY `date_jour` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_notifications_non_lues`
--
DROP TABLE IF EXISTS `v_notifications_non_lues`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_notifications_non_lues`  AS SELECT `services_notifications`.`service` AS `service`, `services_notifications`.`type_notification` AS `type_notification`, count(0) AS `nombre`, min(`services_notifications`.`created_at`) AS `plus_ancienne`, max(`services_notifications`.`created_at`) AS `plus_recente`, group_concat(distinct `services_notifications`.`code_reference` order by `services_notifications`.`code_reference` ASC separator ', ') AS `references_liste` FROM `services_notifications` WHERE ((ifnull(`services_notifications`.`lu`,0) = 0) AND (ifnull(`services_notifications`.`archive`,0) = 0)) GROUP BY `services_notifications`.`service`, `services_notifications`.`type_notification` ORDER BY `services_notifications`.`service` ASC, `nombre` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_tokens_resultats`
--
DROP TABLE IF EXISTS `v_tokens_resultats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_tokens_resultats`  AS SELECT `t`.`id` AS `id`, `t`.`token` AS `token`, `t`.`code_echantillon` AS `code_echantillon`, `t`.`idresultat` AS `idresultat`, `t`.`email_destinataire` AS `email_destinataire`, `t`.`date_creation` AS `date_creation`, `t`.`date_expiration` AS `date_expiration`, `t`.`actif` AS `actif`, `t`.`vu_le` AS `vu_le`, `t`.`nb_consultations` AS `nb_consultations`, `le`.`statut` AS `echantillon_statut`, concat(`p`.`prenom`,' ',`p`.`nom`) AS `patient_nom`, `a`.`libelle` AS `examen_libelle`, concat(`u`.`prenom`,' ',`u`.`nom`) AS `createur_nom`, (case when (`t`.`actif` = 0) then 'Désactivé' when (`t`.`date_expiration` < now()) then 'Expiré' when (`t`.`vu_le` is null) then 'Non consulté' else 'Actif' end) AS `statut_token`, (to_days(`t`.`date_expiration`) - to_days(now())) AS `jours_avant_expiration` FROM (((((`labo_resultats_tokens` `t` left join `labo_echantillons` `le` on((`t`.`code_echantillon` = `le`.`code_echantillon`))) left join `csk_base`.`patient` `p` on((`le`.`idpatient` = `p`.`idpatient`))) left join `csk_base`.`actes_presc` `ap` on((`le`.`idactes_presc` = `ap`.`idactes_presc`))) left join `csk_base`.`acte` `a` on((`ap`.`idacte` = `a`.`idacte`))) left join `csk_base`.`utilisateur` `u` on((`t`.`created_by` = `u`.`idutilisateur`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_workflow_complet`
--
DROP TABLE IF EXISTS `v_workflow_complet`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_workflow_complet`  AS SELECT 'LABORATOIRE' AS `service_type`, `le`.`code_echantillon` AS `code_reference`, `le`.`statut` AS `statut_courant`, ifnull(`le`.`urgence`,0) AS `urgence`, ifnull(`le`.`priorite`,'normale') AS `priorite`, `p`.`nom` AS `patient_nom`, `p`.`prenom` AS `patient_prenom`, `p`.`numero_dossier` AS `numero_dossier`, `a`.`libelle` AS `acte_libelle`, `le`.`date_prelevement` AS `date_prelevement`, `le`.`date_fin_analyse` AS `date_terminaison`, `le`.`created_at` AS `date_creation`, timestampdiff(HOUR,`le`.`created_at`,now()) AS `age_heures`, group_concat(distinct `lwh`.`action` order by `lwh`.`created_at` DESC separator ' → ') AS `actions_recentes`, max(`lwh`.`created_at`) AS `derniere_action_date` FROM ((((`labo_echantillons` `le` left join `csk_base`.`patient` `p` on((`le`.`idpatient` = `p`.`idpatient`))) left join `csk_base`.`actes_presc` `ap` on((`le`.`idactes_presc` = `ap`.`idactes_presc`))) left join `csk_base`.`acte` `a` on((`ap`.`idacte` = `a`.`idacte`))) left join `labo_workflow_history` `lwh` on((`le`.`idechantillon` = `lwh`.`idechantillon`))) WHERE (`le`.`deleted_at` is null) GROUP BY `le`.`idechantillon`union all select 'IMAGERIE' AS `service_type`,`ie`.`code_examen` AS `code_reference`,`ie`.`statut` AS `statut_courant`,ifnull(`ie`.`urgence`,0) AS `urgence`,ifnull(`ie`.`priorite`,'programme') AS `priorite`,`p`.`nom` AS `patient_nom`,`p`.`prenom` AS `patient_prenom`,`p`.`numero_dossier` AS `numero_dossier`,`a`.`libelle` AS `acte_libelle`,NULL AS `date_prelevement`,`ie`.`updated_at` AS `date_terminaison`,`ie`.`created_at` AS `date_creation`,timestampdiff(HOUR,`ie`.`created_at`,now()) AS `age_heures`,'' AS `actions_recentes`,`ie`.`updated_at` AS `derniere_action_date` from (((`imagerie_examens` `ie` left join `csk_base`.`patient` `p` on((`ie`.`idpatient` = `p`.`idpatient`))) left join `csk_base`.`actes_presc` `ap` on((`ie`.`idactes_presc` = `ap`.`idactes_presc`))) left join `csk_base`.`acte` `a` on((`ap`.`idacte` = `a`.`idacte`))) union all select 'PHARMACIE' AS `service_type`,`pp`.`code_preparation` AS `code_reference`,`pp`.`statut` AS `statut_courant`,ifnull(`pp`.`urgence`,0) AS `urgence`,'normale' AS `priorite`,`p`.`nom` AS `patient_nom`,`p`.`prenom` AS `patient_prenom`,`p`.`numero_dossier` AS `numero_dossier`,`pr`.`libelle` AS `acte_libelle`,NULL AS `date_prelevement`,`pp`.`date_delivrance` AS `date_terminaison`,`pp`.`created_at` AS `date_creation`,timestampdiff(HOUR,`pp`.`created_at`,now()) AS `age_heures`,'' AS `actions_recentes`,`pp`.`updated_at` AS `derniere_action_date` from (((`pharmacie_preparations` `pp` left join `csk_base`.`patient` `p` on((`pp`.`idpatient` = `p`.`idpatient`))) left join `csk_base`.`pharma_presc` `php` on((`pp`.`idpharma_presc` = `php`.`idpharma_presc`))) left join `csk_base`.`prodpharma` `pr` on((`php`.`idprodpharma` = `pr`.`idprodpharma`)))  ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `imagerie_examens`
--
ALTER TABLE `imagerie_examens`
  ADD CONSTRAINT `imagerie_examens_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `csk_base`.`actes_presc` (`idactes_presc`),
  ADD CONSTRAINT `imagerie_examens_ibfk_2` FOREIGN KEY (`idpatient`) REFERENCES `csk_base`.`patient` (`idpatient`),
  ADD CONSTRAINT `imagerie_examens_ibfk_3` FOREIGN KEY (`deleted_by`) REFERENCES `csk_base`.`utilisateur` (`idutilisateur`);

--
-- Constraints for table `imagerie_fichiers`
--
ALTER TABLE `imagerie_fichiers`
  ADD CONSTRAINT `fk_fichier_examen` FOREIGN KEY (`idexamen`) REFERENCES `imagerie_examens` (`idexamen`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `imagerie_planning`
--
ALTER TABLE `imagerie_planning`
  ADD CONSTRAINT `fk_planning_examen` FOREIGN KEY (`idexamen`) REFERENCES `imagerie_examens` (`idexamen`) ON DELETE CASCADE;

--
-- Constraints for table `imagerie_salle_equipements`
--
ALTER TABLE `imagerie_salle_equipements`
  ADD CONSTRAINT `fk_salle_eq_equip` FOREIGN KEY (`idequipement`) REFERENCES `csk_base`.`equipements_imagerie` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_salle_eq_salle` FOREIGN KEY (`idsalle`) REFERENCES `imagerie_salles` (`idsalle`) ON DELETE CASCADE;

--
-- Constraints for table `imagerie_workflow_history`
--
ALTER TABLE `imagerie_workflow_history`
  ADD CONSTRAINT `fk_imghist_examen` FOREIGN KEY (`idexamen`) REFERENCES `imagerie_examens` (`idexamen`) ON DELETE CASCADE;

--
-- Constraints for table `labo_echantillons`
--
ALTER TABLE `labo_echantillons`
  ADD CONSTRAINT `labo_echantillons_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `csk_base`.`actes_presc` (`idactes_presc`),
  ADD CONSTRAINT `labo_echantillons_ibfk_2` FOREIGN KEY (`idpatient`) REFERENCES `csk_base`.`patient` (`idpatient`);

--
-- Constraints for table `labo_echantillon_resultats`
--
ALTER TABLE `labo_echantillon_resultats`
  ADD CONSTRAINT `labo_echantillon_resultats_ibfk_1` FOREIGN KEY (`idechantillon`) REFERENCES `labo_echantillons` (`idechantillon`) ON DELETE CASCADE,
  ADD CONSTRAINT `labo_echantillon_resultats_ibfk_2` FOREIGN KEY (`idresultat`) REFERENCES `csk_base`.`resultatslabo` (`idresultat`) ON DELETE CASCADE;

--
-- Constraints for table `labo_resultats_tokens`
--
ALTER TABLE `labo_resultats_tokens`
  ADD CONSTRAINT `labo_resultats_tokens_ibfk_1` FOREIGN KEY (`code_echantillon`) REFERENCES `labo_echantillons` (`code_echantillon`),
  ADD CONSTRAINT `labo_resultats_tokens_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `csk_base`.`utilisateur` (`idutilisateur`);

--
-- Constraints for table `labo_workflow_history`
--
ALTER TABLE `labo_workflow_history`
  ADD CONSTRAINT `labo_workflow_history_ibfk_1` FOREIGN KEY (`idechantillon`) REFERENCES `labo_echantillons` (`idechantillon`);

--
-- Constraints for table `pharmacie_preparations`
--
ALTER TABLE `pharmacie_preparations`
  ADD CONSTRAINT `pharmacie_preparations_ibfk_1` FOREIGN KEY (`idpharma_presc`) REFERENCES `csk_base`.`pharma_presc` (`idpharma_presc`),
  ADD CONSTRAINT `pharmacie_preparations_ibfk_2` FOREIGN KEY (`idpatient`) REFERENCES `csk_base`.`patient` (`idpatient`);

--
-- Constraints for table `pharmacie_workflow_history`
--
ALTER TABLE `pharmacie_workflow_history`
  ADD CONSTRAINT `fk_pharmhist_preparation` FOREIGN KEY (`idpreparation`) REFERENCES `pharmacie_preparations` (`idpreparation`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
