-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db
-- Generation Time: Mar 09, 2026 at 01:23 AM
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
-- Database: `csk_base`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`%` PROCEDURE `assign_module_permissions` (IN `p_idprofiluser` INT, IN `p_module` VARCHAR(50), IN `p_creer` TINYINT, IN `p_modifier` TINYINT, IN `p_supprimer` TINYINT, IN `p_consulter` TINYINT, IN `p_valider` TINYINT, IN `p_imprimer` TINYINT)   BEGIN
    INSERT INTO fct_profiluser (idprofiluser, idfct, peut_creer, peut_modifier, peut_supprimer, peut_consulter, peut_valider, peut_imprimer)
    SELECT 
        p_idprofiluser,
        idfct,
        p_creer,
        p_modifier,
        p_supprimer,
        p_consulter,
        p_valider,
        p_imprimer
    FROM fct 
    WHERE module = p_module AND statut = 'actif'
    ON DUPLICATE KEY UPDATE
        peut_creer = p_creer,
        peut_modifier = p_modifier,
        peut_supprimer = p_supprimer,
        peut_consulter = p_consulter,
        peut_valider = p_valider,
        peut_imprimer = p_imprimer;
END$$

CREATE DEFINER=`root`@`%` PROCEDURE `marquer_pdf_envoye` (IN `p_idsejour` INT, IN `p_chemin_pdf` VARCHAR(255))   BEGIN
    UPDATE sejour
    SET 
        pdf_resultats_genere = 1,
        date_pdf_resultats = NOW(),
        chemin_pdf_resultats = p_chemin_pdf,
        pdf_envoye_prescripteur = 1,
        date_envoi_pdf = NOW()
    WHERE idsejour = p_idsejour;
    
    -- Notifier le prescripteur principal
    INSERT INTO notifications (
        idutilisateur,
        type,
        titre,
        message,
        lien,
        priorite,
        date_notification
    )
    SELECT
        ap.prescripteur,
        'success',
        CONCAT('PDF résultats disponible - Séjour #', s.numero_sejour),
        CONCAT('Tous les résultats du séjour #', s.numero_sejour, ' pour ', p.prenom, ' ', p.nom, ' sont disponibles en PDF.'),
        CONCAT('../modules/labo/generer_pdf_sejour.php?idsejour=', p_idsejour),
        'haute',
        NOW()
    FROM sejour s
    JOIN patient p ON s.idpatient = p.idpatient
    LEFT JOIN sous_sejour ss ON s.idsejour = ss.idsejour
    LEFT JOIN actes_presc ap ON ss.idsous_sejour = ap.idsous_sejour
    WHERE s.idsejour = p_idsejour
    GROUP BY ap.prescripteur
    LIMIT 1;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`%` FUNCTION `generer_code_prescription` () RETURNS VARCHAR(50) CHARSET utf8mb4 DETERMINISTIC BEGIN
    DECLARE code VARCHAR(50);
    DECLARE compteur INT DEFAULT 1;
    DECLARE existe INT;
    
    -- Format : PRESC-YYYYMMDD-NNNN (aléatoire 4 chiffres)
    REPEAT
        SET code = CONCAT('PRESC-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(FLOOR(RAND() * 9999), 4, '0'));
        SELECT COUNT(*) INTO existe FROM groupe_prescriptions WHERE code_prescription = code;
        SET compteur = compteur + 1;
    UNTIL existe = 0 OR compteur > 100 END REPEAT;
    
    IF existe > 0 THEN
        -- Fallback : utiliser un timestamp si collision après 100 essais
        SET code = CONCAT('PRESC-', UNIX_TIMESTAMP());
    END IF;
    
    RETURN code;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `acte`
--

CREATE TABLE `acte` (
  `idacte` int NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `idcategorie_acte` int DEFAULT NULL,
  `idsous_specialite` int DEFAULT NULL,
  `idspecialite` int DEFAULT NULL,
  `description` text,
  `duree_moyenne` int DEFAULT NULL COMMENT 'Dur?e en minutes',
  `actif` tinyint(1) DEFAULT '1',
  `prix_vente` decimal(10,2) NOT NULL,
  `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `acte`
--

INSERT INTO `acte` (`idacte`, `libelle`, `code`, `idcategorie_acte`, `idsous_specialite`, `idspecialite`, `description`, `duree_moyenne`, `actif`, `prix_vente`, `date_creation`, `date_modification`) VALUES
(1, 'Consultation Médecine Générale', 'CONS-MG', 1, 1, 1, 'Consultation en médecine générale', 30, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(2, 'Consultation Pédiatrie', 'CONS-PED', 1, 2, 2, 'Consultation pédiatrique', 30, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(3, 'Consultation Gynécologie', 'CONS-GYN', 1, 4, 3, 'Consultation gynécologique', 30, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(4, 'Consultation Obstétrique', 'CONS-OBST', 1, 5, 3, 'Consultation obstétricale', 30, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(5, 'Consultation Cardiologie', 'CONS-CARDIO', 1, 33, 5, 'Consultation cardiologique', 30, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(6, 'Consultation Orthopédie', 'CONS-ORTHO', 1, 17, 12, 'Consultation orthopédique', 30, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(7, 'Consultation ORL', 'CONS-ORL', 1, 18, 8, 'Consultation ORL', 30, 1, 7000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(8, 'Consultation Urologie', 'CONS-URO', 1, 19, 11, 'Consultation urologique', 30, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(9, 'Consultation Dermatologie', 'CONS-DERM', 1, 45, 9, 'Consultation dermatologique', 30, 1, 7000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(10, 'Consultation Psychiatrie', 'CONS-PSY', 1, NULL, 14, 'Consultation psychiatrique', 45, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(11, 'Échographie Abdominale Complète', 'ECHO-ABD-C', 2, 10, 15, 'Échographie abdominale complète', 45, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(12, 'Échographie Obstétricale 1er trimestre', 'ECHO-OBST-1', 2, 10, 15, 'Échographie obstétricale 1er trimestre', 30, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(13, 'Électrocardiogramme (ECG)', 'ECG', 2, 11, 5, 'Électrocardiogramme standard', 20, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(14, 'Échographie Cardiaque (Echocardiographie)', 'ECHO-CARD', 2, 11, 5, 'Échographie cardiaque Doppler', 45, 1, 25000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(15, 'Test d\'Effort', 'TEST-EFFORT', 2, 11, 5, 'Test d\'effort cardiaque', 60, 1, 30000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(16, 'Fibroscopie Œsogastroduodénale', 'FOGD', 2, 12, 21, 'Fibroscopie haute', 30, 1, 25000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(17, 'Coloscopie Totale', 'COLOSCOPY', 2, 12, 21, 'Coloscopie complète', 60, 1, 35000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(18, 'Échographie Rénale', 'ECHO-REN', 2, 13, 22, 'Échographie rénale et vésicale', 30, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(19, 'Spirométrie Complète', 'SPIRO', 2, 14, 20, 'Test fonction respiratoire', 30, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(20, 'IRM Cérébrale', 'IRM-CER', 2, 16, 13, 'IRM cérébrale sans injection', 40, 1, 50000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(21, 'Scanner Abdominopelvien', 'SCAN-ABD', 2, 16, 13, 'Scanner abdominal avec injection', 30, 1, 45000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(22, 'Pansement Simple', 'PAN-SIMP', 3, NULL, NULL, 'Pansement non chirurgical', 15, 1, 3000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(23, 'Pansement Complexe', 'PAN-COMP', 3, NULL, NULL, 'Pansement chirurgical ou ulcère', 30, 1, 6000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(24, 'Injection Intramusculaire', 'INJ-IM', 3, NULL, NULL, 'Injection intramusculaire', 10, 1, 2000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(25, 'Injection Intraveineuse', 'INJ-IV', 3, NULL, NULL, 'Injection intraveineuse', 15, 1, 3000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(26, 'Pose de Perfusion', 'PERF-POSE', 3, NULL, NULL, 'Pose de voie veineuse périphérique', 20, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(27, 'Surveillance de Perfusion', 'PERF-SURV', 3, NULL, NULL, 'Surveillance horaire de perfusion', 5, 1, 1000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(28, 'Prélèvement Sanguin', 'PRELEV-SANG', 3, NULL, NULL, 'Prélèvement sanguin veineux', 15, 1, 3000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(29, 'Soins de Sonde Urinaire', 'SOIN-SONDE', 3, NULL, NULL, 'Soins de sonde vésicale', 20, 1, 4000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(30, 'Aspiration Trachéale', 'ASP-TRACH', 3, NULL, NULL, 'Aspiration des voies aériennes', 15, 1, 3500.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(31, 'Mesure de Glycémie Capillaire', 'GLYC-CAP', 3, NULL, NULL, 'Contrôle glycémique au doigt', 5, 1, 1500.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(32, 'Appendicectomie', 'APPEND', 4, 7, 4, 'Ablation de l\'appendice', 90, 1, 250000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(33, 'Cholécystectomie', 'CHOLECYST', 4, 7, 4, 'Ablation de la vésicule biliaire', 120, 1, 300000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(34, 'Herniorraphie Inguinale', 'HERN-ING', 4, 7, 4, 'Réparation de hernie inguinale', 60, 1, 180000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(35, 'Césarienne', 'CESAR', 4, 5, 3, 'Accouchement par césarienne', 90, 1, 280000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(36, 'Hystérectomie Totale', 'HYSTER', 4, 4, 3, 'Ablation de l\'utérus', 150, 1, 350000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(37, 'Prostatectomie', 'PROSTATE', 4, 19, 11, 'Ablation de la prostate', 180, 1, 400000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(38, 'Arthroscopie du Genou', 'ARTHRO-GEN', 4, 17, 12, 'Arthroscopie diagnostique du genou', 60, 1, 220000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(39, 'Laminectomie Lombaire', 'LAMIN-LOMB', 4, 16, 13, 'Décompression rachidienne lombaire', 180, 1, 450000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(40, 'Amputation de Membre Inférieur', 'AMP-MI', 4, 17, 12, 'Amputation transtibiale', 120, 1, 280000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(41, 'Chirurgie de la Cataracte', 'CATARACT', 4, 39, 7, 'Phacoémulsification avec implant', 45, 1, 200000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(42, 'Radiographie Thorax Face/Profil', 'RX-THOR-FP', 5, 22, 15, 'Radiographie thoracique deux incidences', 15, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(43, 'Radiographie Abdomen sans Préparation', 'RX-ASP', 5, 22, 15, 'ASP - Abdomen sans préparation', 15, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(44, 'Radiographie Rachis Lombaire', 'RX-RACH-LOMB', 5, 22, 15, 'Radiographie rachis lombaire face/profil', 20, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(45, 'Échographie Abdominale', 'ECHO-ABD', 5, 10, 15, 'Échographie abdominale standard', 30, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(46, 'Échographie Pelvienne', 'ECHO-PELV', 5, 10, 15, 'Échographie pelvienne', 30, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(47, 'Échographie Obstétricale', 'ECHO-OBST', 5, 10, 15, 'Échographie obstétricale de base', 30, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(48, 'Échographie Mammaire', 'ECHO-MAM', 5, 10, 15, 'Échographie mammaire bilatérale', 30, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(49, 'Échographie Thyroïdienne', 'ECHO-THYR', 5, 10, 15, 'Échographie de la thyroïde', 20, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(50, 'Échographie Testiculaire', 'ECHO-TEST', 5, 10, 15, 'Échographie testiculaire', 20, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(51, 'Mammographie Numérique', 'MAMMO', 5, 22, 15, 'Mammographie bilatérale deux incidences', 30, 1, 20000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(52, 'Hémogramme Complet (NFS)', 'NFS', 6, 23, 16, 'Numération Formule Sanguine complète', NULL, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(53, 'Glycémie à Jeun', 'GLYC-JEUN', 6, 25, 16, 'Glycémie plasmatique à jeun', NULL, 1, 3000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(54, 'Urée et Créatinine', 'UREE-CREAT', 6, 25, 16, 'Fonction rénale - Urée et Créatinine', NULL, 1, 4000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(55, 'Bilan Hépatique Complet', 'BIL-HEP', 6, 25, 16, 'Transaminases, Bilirubine, Phosphatases', NULL, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(56, 'Ionogramme Sanguin', 'IONO', 6, 25, 16, 'Na, K, Cl, Bicarbonate', NULL, 1, 6000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(57, 'CRP (Protéine C Réactive)', 'CRP', 6, 25, 16, 'Marqueur inflammatoire', NULL, 1, 4000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(58, 'VS (Vitesse de Sédimentation)', 'VS', 6, 24, 16, 'Vitesse de sédimentation', NULL, 1, 3000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(59, 'TP, TCA, INR', 'COAG', 6, 24, 16, 'Bilan de coagulation', NULL, 1, 7000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(60, 'Test de Grossesse Sanguin (β-HCG)', 'BHCG', 6, 25, 16, 'Dosage des β-HCG quantitatif', NULL, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(61, 'ECBU (Examen Cyto-Bactériologique Urines)', 'ECBU', 6, 23, 16, 'Examen complet des urines', NULL, 1, 6000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(62, 'Frottis Vaginal', 'FROT-VAG', 6, 23, 16, 'Examen cytologique vaginal', NULL, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(63, 'Coproculture', 'COPRO', 6, 23, 16, 'Examen bactériologique des selles', NULL, 1, 7000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(64, 'Groupage Sanguin ABO-Rhésus', 'GROUP-ABO', 6, 24, 16, 'Détermination groupe sanguin', NULL, 1, 6000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(65, 'Sérologie VIH', 'SERO-VIH', 6, 25, 16, 'Dépistage VIH 1 et 2', NULL, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(66, 'Sérologie Hépatite B', 'SERO-HBV', 6, 25, 16, 'Antigène HBs, anticorps', NULL, 1, 7000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(67, 'Consultation Dentaire', 'CONS-DENT', 8, 36, 6, 'Examen bucco-dentaire complet', 30, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(68, 'Détartrage', 'DETART', 8, 36, 6, 'Nettoyage et détartrage dentaire', 45, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(69, 'Soin Carie Simple', 'CARIE-SIMP', 8, 36, 6, 'Traitement d\'une carie', 30, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(70, 'Extraction Dentaire Simple', 'EXTR-SIMP', 8, 36, 6, 'Extraction non chirurgicale', 20, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(71, 'Extraction Chirurgicale', 'EXTR-CHIR', 8, 36, 6, 'Extraction avec incision', 60, 1, 30000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(72, 'Dévitalisation', 'DEVIT', 8, 36, 6, 'Traitement endodontique', 60, 1, 35000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(73, 'Couronne Métallique', 'COUR-MET', 8, 36, 6, 'Couronne dentaire métallique', 90, 1, 50000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(74, 'Couronne Céramique', 'COUR-CER', 8, 36, 6, 'Couronne céramo-métallique', 120, 1, 80000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(75, 'Prothèse Amovible', 'PROTH-AMOV', 8, 36, 6, 'Dentier partiel ou complet', 60, 1, 60000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(76, 'Radio Panoramique', 'RX-PANO', 8, 36, 6, 'Radiographie panoramique dentaire', 15, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(77, 'Examen Ophtalmologique Complet', 'EXAM-OPHT', 9, 39, 7, 'Acuité visuelle, fond d\'œil, tonométrie', 45, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(78, 'Mesure de la Pression Intraoculaire', 'PIO', 9, 39, 7, 'Tonometrie', 10, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(79, 'Examen du Fond d\'Œil', 'FOND-OEIL', 9, 39, 7, 'Ophtalmoscopie', 20, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(80, 'Réfraction', 'REFRACT', 9, 39, 7, 'Mesure de la correction visuelle', 30, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(81, 'Champ Visuel', 'CHAMP-VIS', 9, 39, 7, 'Périmétrie automatique', 30, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(82, 'Échographie Oculaire', 'ECHO-OEIL', 9, 39, 7, 'Échographie mode B oculaire', 20, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(83, 'Photographie du Fond d\'Œil', 'PHOTO-FOND', 9, 39, 7, 'Rétinographie', 20, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(84, 'Laser Argon', 'LASER-ARG', 9, 39, 7, 'Traitement laser rétinien', 30, 1, 50000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(85, 'Chirurgie Réfractive LASIK', 'LASIK', 9, 39, 7, 'Correction myopie/hypermetropie', 30, 1, 300000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(86, 'Frottis Sanguin', 'FROT-SANG', 24, 23, 16, 'Examen microscopique du sang', NULL, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(87, 'Myélogramme', 'MYELO', 24, 23, 16, 'Examen de la moelle osseuse', NULL, 1, 25000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(88, 'Électrophorèse de l\'Hémoglobine', 'ELECTRO-HB', 24, 23, 16, 'Dépistage hémoglobinopathies', NULL, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(89, 'Dosage de la Ferritine', 'FERRIT', 24, 23, 16, 'Marqueur des réserves en fer', NULL, 1, 7000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(90, 'Dosage de la Vitamine B12', 'VIT-B12', 24, 23, 16, 'Dosage sérique vitamine B12', NULL, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(91, 'Dosage de l\'Acide Folique', 'FOLATE', 24, 23, 16, 'Dosage folates sériques', NULL, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(92, 'Test de Coombs Direct', 'COOMBS-D', 24, 24, 16, 'Recherche d\'anticorps érythrocytaires', NULL, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(93, 'Étude de la Coagulation', 'COAG-ETUDE', 24, 24, 16, 'Panel complet coagulation', NULL, 1, 20000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(94, 'Bilan Lipidique', 'LIPID', 25, 25, 16, 'Cholestérol total, HDL, LDL, Triglycérides', NULL, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(95, 'Bilan Thyroïdien Complet', 'THYRO', 25, 25, 16, 'TSH, T3, T4, anticorps anti-TPO', NULL, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(96, 'Bilan Rénal Complet', 'RENAL', 25, 25, 16, 'Urée, Créatinine, Clairance, Ionogramme', NULL, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(97, 'Bilan Hépatique Élargi', 'HEPAT', 25, 25, 16, 'Transaminases, GGT, Bilirubine, Albumine', NULL, 1, 12000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(98, 'Bilan Phosphocalcique', 'PHOSPHO', 25, 25, 16, 'Calcium, Phosphore, Phosphatase alcaline', NULL, 1, 8000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(99, 'Dosage des Enzymes Cardiaques', 'ENZ-CARD', 25, 25, 16, 'Troponine, CK-MB, Myoglobine', NULL, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(100, 'Dosage des Marqueurs Tumoraux', 'TUMOR', 25, 25, 16, 'PSA, ACE, CA 15-3, CA 19-9', NULL, 1, 20000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(101, 'Dosage des Hormones Stéroïdiennes', 'HORM-STERO', 25, 25, 16, 'Cortisol, Testostérone, Œstradiol', NULL, 1, 18000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(102, 'Hospitalisation Chambre Simple', 'HOSP-SIMP', 21, NULL, NULL, 'Hospitalisation en chambre individuelle', 1440, 1, 30000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(103, 'Hospitalisation Chambre Double', 'HOSP-DOUBLE', 21, NULL, NULL, 'Hospitalisation en chambre à deux lits', 1440, 1, 20000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(104, 'Hospitalisation Chambre VIP', 'HOSP-VIP', 21, NULL, NULL, 'Hospitalisation en suite VIP', 1440, 1, 50000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(105, 'Repas Hospitalier Standard', 'REPAS-STD', 21, NULL, NULL, 'Repas complet diététique', NULL, 1, 5000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(106, 'Repas Spécial (Diabétique, Sans sel)', 'REPAS-SPEC', 21, NULL, NULL, 'Repas adapté régime spécifique', NULL, 1, 6000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(107, 'Accompagnant', 'ACCOMP', 21, NULL, NULL, 'Présence d\'un accompagnant', 1440, 1, 10000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(108, 'Lit d\'Accompagnant', 'LIT-ACCOMP', 21, NULL, NULL, 'Lit supplémentaire pour accompagnant', 1440, 1, 15000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(109, 'Télévision', 'TV', 21, NULL, NULL, 'Location télévision', 1440, 1, 3000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06'),
(110, 'WiFi Hospitalier', 'WIFI', 21, NULL, NULL, 'Accès internet illimité', 1440, 1, 2000.00, '2026-01-06 12:22:06', '2026-01-06 12:22:06');

-- --------------------------------------------------------

--
-- Table structure for table `actes_presc`
--

CREATE TABLE `actes_presc` (
  `idactes_presc` int NOT NULL,
  `id_groupe_prescription` int DEFAULT NULL COMMENT 'FK vers groupe_prescriptions si fait partie d''un groupe',
  `idsous_sejour` int NOT NULL,
  `idacte` int NOT NULL,
  `idsite` int NOT NULL,
  `idsociete` int DEFAULT NULL,
  `idspecialite` int DEFAULT NULL,
  `quantite` int DEFAULT '1',
  `prix_unitaire` decimal(10,2) NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `date_prescription` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `prescripteur` int DEFAULT NULL,
  `statut_validation` enum('rien','valide') DEFAULT 'rien',
  `mode_paiement` enum('rien','cash','cc','acompte','dette','credit_societe','prise_en_charge') DEFAULT 'rien',
  `date_validation` timestamp NULL DEFAULT NULL,
  `valideur` int DEFAULT NULL,
  `statut_execution` enum('en_attente','en_cours','termine') NOT NULL DEFAULT 'en_attente' COMMENT 'Statut d''exécution de la prescription',
  `date_execution` timestamp NULL DEFAULT NULL,
  `executeur` int DEFAULT NULL,
  `urgent` tinyint(1) DEFAULT '0',
  `observation` text,
  `indication` text,
  `type_externe` varchar(50) DEFAULT NULL,
  `centre_externe` varchar(200) DEFAULT NULL,
  `date_retour_externe` datetime DEFAULT NULL,
  `source_prescription` varchar(30) DEFAULT NULL COMMENT 'Source: csk_gps, csk_services, ou NULL pour legacy'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `actes_presc`
--

INSERT INTO `actes_presc` (`idactes_presc`, `id_groupe_prescription`, `idsous_sejour`, `idacte`, `idsite`, `idsociete`, `idspecialite`, `quantite`, `prix_unitaire`, `montant_total`, `date_prescription`, `prescripteur`, `statut_validation`, `mode_paiement`, `date_validation`, `valideur`, `statut_execution`, `date_execution`, `executeur`, `urgent`, `observation`, `indication`, `type_externe`, `centre_externe`, `date_retour_externe`, `source_prescription`) VALUES
(1, NULL, 1, 8, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-07 13:34:13', 2, 'valide', 'credit_societe', NULL, NULL, 'en_attente', NULL, NULL, 1, NULL, 'Suspicion paludisme - fi?vre 39?C', NULL, NULL, NULL, NULL),
(2, NULL, 3, 9, 1, NULL, 1, 1, 3000.00, 3000.00, '2025-12-07 13:35:28', 2, 'valide', 'credit_societe', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'Contr?le diab?te', NULL, NULL, NULL, NULL),
(3, NULL, 2, 5, 1, NULL, 3, 1, 15000.00, 15000.00, '2025-12-07 13:41:17', 2, 'valide', 'credit_societe', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'Douleurs ?pigastriques - recherche pathologie h?patobiliaire', 'interne', NULL, NULL, NULL),
(4, NULL, 3, 7, 1, NULL, 1, 1, 8000.00, 8000.00, '2025-12-07 13:41:17', 2, 'valide', 'credit_societe', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'Suspicion pneumopathie', 'externe', NULL, NULL, NULL),
(5, NULL, 2, 5, 1, NULL, 3, 1, 15000.00, 15000.00, '2025-12-07 13:41:34', 2, 'valide', 'credit_societe', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'Douleurs ?pigastriques - recherche pathologie h?patobiliaire', 'interne', NULL, NULL, NULL),
(6, NULL, 3, 7, 1, NULL, 1, 1, 8000.00, 8000.00, '2025-12-07 13:41:34', 2, 'valide', 'credit_societe', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'Suspicion pneumopathie', 'externe', NULL, NULL, NULL),
(7, NULL, 19, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 22:32:38', 1, 'rien', NULL, '2025-12-08 22:32:38', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(8, NULL, 20, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 22:58:51', 1, 'rien', NULL, '2025-12-08 22:58:51', 1, 'en_attente', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(9, NULL, 23, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 23:26:09', 1, 'valide', 'cash', '2025-12-08 23:27:53', 1, 'en_attente', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(10, NULL, 24, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 23:32:26', 1, 'valide', 'credit_societe', '2025-12-08 23:32:26', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(11, NULL, 25, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 23:43:19', 1, 'valide', 'credit_societe', '2025-12-08 23:43:19', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(12, NULL, 26, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 23:43:38', 1, 'valide', 'credit_societe', '2025-12-08 23:43:38', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(13, NULL, 27, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-08 23:57:56', 1, 'valide', 'credit_societe', '2025-12-08 23:57:56', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(14, NULL, 28, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:08:17', 1, 'valide', 'credit_societe', '2025-12-09 00:08:17', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(15, NULL, 29, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:26:40', 1, 'valide', 'credit_societe', '2025-12-09 00:26:40', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(16, NULL, 30, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:29:40', 1, 'valide', 'credit_societe', '2025-12-09 00:29:40', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(17, NULL, 31, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:32:54', 1, 'valide', 'credit_societe', '2025-12-09 00:32:54', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(18, NULL, 31, 1, 2, NULL, NULL, 1, 5000.00, 5000.00, '2025-12-09 00:33:22', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(19, NULL, 32, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:37:51', 1, 'valide', 'credit_societe', '2025-12-09 00:37:51', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(20, NULL, 33, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:44:24', 1, 'rien', NULL, '2025-12-09 00:44:24', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(21, NULL, 34, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:49:40', 1, 'valide', 'credit_societe', '2025-12-09 00:49:40', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(22, NULL, 35, 1, 2, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:50:22', 1, 'valide', 'credit_societe', '2025-12-09 00:50:22', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(23, NULL, 35, 1, 2, NULL, NULL, 1, 5000.00, 5000.00, '2025-12-09 00:50:34', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(24, NULL, 36, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-09 00:51:59', 1, 'valide', 'credit_societe', '2025-12-09 00:51:59', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(26, NULL, 3, 3, 1, NULL, NULL, 1, 5000.00, 5000.00, '2025-12-10 09:03:37', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', 'interne', NULL, NULL, NULL),
(27, NULL, 37, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-11 06:36:08', 1, 'valide', 'credit_societe', '2025-12-11 06:36:08', 1, 'en_cours', '2025-12-27 09:18:04', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(28, NULL, 40, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-11 08:01:35', 1, 'valide', 'credit_societe', '2025-12-11 08:01:35', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(29, NULL, 41, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-11 08:03:09', 1, 'valide', 'credit_societe', '2025-12-11 08:03:09', 1, 'en_attente', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(30, NULL, 42, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-11 08:41:07', 1, 'valide', 'credit_societe', '2025-12-11 08:41:07', 1, 'en_attente', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(31, NULL, 43, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-11 08:42:48', 1, 'valide', 'credit_societe', '2025-12-11 08:42:48', 1, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL),
(32, NULL, 44, 1, 1, NULL, 1, 1, 5000.00, 5000.00, '2025-12-11 08:58:54', 1, 'valide', 'credit_societe', '2025-12-11 08:58:54', 1, 'en_attente', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL),
(33, NULL, 3, 3, 1, NULL, NULL, 1, 5000.00, 5000.00, '2025-12-13 14:41:18', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', 'interne', NULL, NULL, NULL),
(34, NULL, 41, 81, 1, NULL, NULL, 1, 12000.00, 12000.00, '2026-01-06 13:34:42', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'obs', 'interne', NULL, NULL, NULL),
(35, NULL, 41, 8, 1, NULL, NULL, 1, 8000.00, 8000.00, '2026-01-14 12:26:26', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, ' vjjkblnm,', NULL, NULL, NULL, NULL),
(36, NULL, 41, 8, 1, NULL, NULL, 1, 8000.00, 8000.00, '2026-01-14 12:30:49', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, ' vjjkblnm,', NULL, NULL, NULL, NULL),
(37, NULL, 41, 10, 1, NULL, NULL, 1, 12000.00, 12000.00, '2026-01-14 12:31:15', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'sfjklnj', NULL, NULL, NULL, NULL),
(38, NULL, 41, 17, 1, NULL, NULL, 1, 35000.00, 35000.00, '2026-01-15 11:54:39', 1, 'rien', NULL, NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'exam colo', NULL, NULL, NULL, NULL),
(43, NULL, 41, 8, 1, NULL, 10, 1, 8000.00, 8000.00, '2026-01-17 14:20:37', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', NULL, NULL, NULL, NULL),
(44, NULL, 41, 80, 1, NULL, 7, 1, 10000.00, 10000.00, '2026-01-17 15:00:58', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'ok', NULL, NULL, NULL, NULL),
(45, NULL, 41, 78, 1, 1, 7, 1, 5000.00, 5000.00, '2026-01-17 15:19:13', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', NULL, NULL, NULL, NULL),
(46, NULL, 41, 10, 1, 1, 13, 1, 12000.00, 12000.00, '2026-01-17 15:36:13', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', NULL, NULL, NULL, NULL),
(47, NULL, 41, 66, 1, 1, 10, 1, 7000.00, 7000.00, '2026-01-17 15:49:24', 1, 'rien', 'rien', NULL, NULL, 'en_attente', '2026-02-21 07:40:43', 28, 0, NULL, 'MI', 'interne', NULL, NULL, NULL),
(48, NULL, 41, 50, 1, 1, 10, 1, 10000.00, 10000.00, '2026-01-17 15:49:47', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 07:41:13', 28, 0, NULL, 'MI', 'interne', NULL, NULL, NULL),
(49, NULL, 41, 64, 1, 1, 18, 1, 6000.00, 6000.00, '2026-01-18 07:00:17', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'jk', 'interne', NULL, NULL, NULL),
(50, NULL, 41, 61, 1, 1, 10, 1, 6000.00, 6000.00, '2026-01-20 11:47:30', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', 'interne', NULL, NULL, NULL),
(51, NULL, 41, 50, 1, 1, 18, 1, 10000.00, 10000.00, '2026-01-20 12:05:37', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 1, NULL, '', 'interne', NULL, NULL, NULL),
(52, NULL, 41, 43, 1, 1, 10, 1, 8000.00, 8000.00, '2026-01-20 15:00:53', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', 'interne', NULL, NULL, NULL),
(53, NULL, 41, 59, 1, 1, 8, 1, 7000.00, 7000.00, '2026-02-11 09:50:33', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-17 08:31:04', 1, 0, NULL, 'ORL clinique', 'interne', NULL, NULL, NULL),
(54, NULL, 41, 54, 1, 1, 22, 1, 4000.00, 4000.00, '2026-02-11 17:45:35', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'cvjbkjl', 'interne', NULL, NULL, NULL),
(55, NULL, 67, 60, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-02-17 00:33:42', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, 'voir plus', NULL, NULL, NULL, NULL, 'csk_services'),
(56, NULL, 44, 56, 1, 1, 6, 1, 6000.00, 6000.00, '2026-02-17 01:32:39', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'oui à plus', 'interne', NULL, NULL, NULL),
(57, NULL, 65, 54, 1, 1, 16, 1, 4000.00, 4000.00, '2026-02-17 02:07:05', 28, 'rien', 'rien', NULL, NULL, 'en_cours', '2026-02-17 08:24:42', 1, 1, 'voir', NULL, NULL, NULL, NULL, 'csk_services'),
(58, NULL, 67, 62, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-02-17 02:32:34', 28, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, 'VS', NULL, NULL, NULL, NULL, 'csk_services'),
(59, NULL, 65, 60, 1, 1, 16, 1, 5000.00, 5000.00, '2026-02-17 02:55:36', 28, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 28, 1, 'ok jazz', NULL, NULL, NULL, NULL, 'csk_services'),
(60, NULL, 67, 59, 1, NULL, 16, 1, 7000.00, 7000.00, '2026-02-17 03:03:29', 28, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(61, NULL, 67, 46, 1, NULL, 15, 1, 12000.00, 12000.00, '2026-02-18 11:50:47', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(62, NULL, 67, 58, 1, NULL, 16, 1, 3000.00, 3000.00, '2026-02-18 12:19:30', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(63, NULL, 67, 63, 1, NULL, 16, 1, 7000.00, 7000.00, '2026-02-18 12:19:30', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(64, NULL, 65, 61, 1, 1, 16, 1, 6000.00, 6000.00, '2026-02-19 18:30:50', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(65, NULL, 65, 59, 1, 1, 16, 1, 7000.00, 7000.00, '2026-02-19 18:30:50', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(66, NULL, 44, 55, 1, 1, 13, 1, 8000.00, 8000.00, '2026-02-23 01:58:20', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, '', 'interne', NULL, NULL, NULL),
(67, NULL, 44, 61, 1, 1, 16, 1, 6000.00, 6000.00, '2026-02-23 02:20:48', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, '', 'interne', NULL, NULL, NULL),
(68, NULL, 67, 54, 1, NULL, 16, 1, 4000.00, 4000.00, '2026-02-23 02:35:12', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(69, NULL, 67, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-02-23 03:21:10', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(70, NULL, 67, 54, 1, NULL, 16, 1, 4000.00, 4000.00, '2026-02-23 04:29:48', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(71, NULL, 67, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-02-23 04:40:19', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(72, NULL, 67, 45, 1, NULL, 15, 1, 15000.00, 15000.00, '2026-02-24 03:12:10', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(77, NULL, 44, 63, 1, 1, 10, 1, 7000.00, 7000.00, '2026-02-24 03:48:24', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', 'interne', NULL, NULL, NULL),
(79, NULL, 44, 97, 1, 1, 5, 1, 12000.00, 12000.00, '2026-02-24 03:58:29', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', NULL, NULL, NULL, NULL),
(82, NULL, 42, 48, 1, 1, 22, 1, 12000.00, 12000.00, '2026-02-24 07:36:19', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, '', 'interne', NULL, NULL, NULL),
(83, NULL, 65, 48, 1, 1, 15, 1, 12000.00, 12000.00, '2026-02-24 09:15:42', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(84, NULL, 67, 45, 1, NULL, 15, 1, 15000.00, 15000.00, '2026-02-24 09:25:04', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(85, NULL, 67, 45, 1, NULL, 15, 1, 15000.00, 15000.00, '2026-02-24 10:23:47', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(86, NULL, 44, 47, 1, 1, 10, 1, 10000.00, 10000.00, '2026-02-25 00:59:30', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, 'wwwp', 'interne', NULL, NULL, NULL),
(87, NULL, 44, 62, 1, 1, 1, 1, 5000.00, 5000.00, '2026-02-25 15:49:59', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, '', 'interne', NULL, NULL, NULL),
(88, NULL, 67, 65, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-02-25 15:55:13', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 1, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(89, NULL, 67, 63, 1, NULL, 16, 1, 7000.00, 7000.00, '2026-02-26 17:58:47', 28, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 28, 1, 'xcdfjk', NULL, NULL, NULL, NULL, 'csk_services'),
(90, NULL, 67, 52, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-02-26 17:58:47', 28, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 28, 1, 'xcdfjk', NULL, NULL, NULL, NULL, 'csk_services'),
(91, NULL, 23, 49, 2, NULL, 15, 1, 10000.00, 10000.00, '2026-02-26 18:22:01', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, 'sdfjjlk', NULL, NULL, NULL, NULL, 'csk_services'),
(92, NULL, 67, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-02-26 19:22:05', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, 'xcfjvkbln', NULL, NULL, NULL, NULL, 'csk_services'),
(93, NULL, 67, 52, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-02-26 19:22:05', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, 'xcfjvkbln', NULL, NULL, NULL, NULL, 'csk_services'),
(94, NULL, 23, 52, 2, NULL, 16, 1, 5000.00, 5000.00, '2026-02-26 19:29:40', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(95, NULL, 36, 52, 1, 1, 16, 1, 5000.00, 5000.00, '2026-02-26 19:32:25', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(96, NULL, 36, 56, 1, 1, 16, 1, 6000.00, 6000.00, '2026-02-26 19:32:25', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(97, NULL, 36, 57, 1, 1, 16, 1, 4000.00, 4000.00, '2026-02-26 19:32:26', 28, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(98, NULL, 36, 54, 1, 1, 16, 1, 4000.00, 4000.00, '2026-02-26 19:32:26', 28, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 28, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(99, NULL, 66, 52, 1, 3, 16, 1, 5000.00, 5000.00, '2026-02-27 04:13:53', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(100, NULL, 66, 56, 1, 3, 16, 1, 6000.00, 6000.00, '2026-02-27 04:13:54', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(101, NULL, 66, 66, 1, 3, 16, 1, 7000.00, 7000.00, '2026-02-27 04:13:55', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(102, NULL, 66, 61, 1, 3, 16, 1, 6000.00, 6000.00, '2026-02-27 04:13:56', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(103, NULL, 66, 54, 1, 3, 16, 1, 4000.00, 4000.00, '2026-02-27 04:13:56', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(104, NULL, 66, 52, 1, 3, 16, 1, 5000.00, 5000.00, '2026-02-27 05:38:01', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(105, NULL, 66, 56, 1, 3, 16, 1, 6000.00, 6000.00, '2026-02-27 05:38:01', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(106, NULL, 66, 54, 1, 3, 16, 1, 4000.00, 4000.00, '2026-02-27 05:38:01', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(107, NULL, 66, 54, 1, 3, 16, 1, 4000.00, 4000.00, '2026-02-27 10:24:12', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(108, NULL, 66, 57, 1, 3, 16, 1, 4000.00, 4000.00, '2026-02-27 10:24:12', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(109, NULL, 66, 52, 1, 3, 16, 1, 5000.00, 5000.00, '2026-02-27 10:24:12', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(110, NULL, 23, 59, 2, NULL, 16, 1, 7000.00, 7000.00, '2026-02-27 13:33:14', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(111, NULL, 23, 62, 2, NULL, 16, 1, 5000.00, 5000.00, '2026-02-27 13:33:14', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(112, NULL, 67, 66, 1, NULL, 16, 1, 7000.00, 7000.00, '2026-02-27 15:30:26', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(113, NULL, 23, 58, 2, NULL, 16, 1, 3000.00, 3000.00, '2026-03-04 08:56:40', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(114, NULL, 23, 60, 2, NULL, 16, 1, 5000.00, 5000.00, '2026-03-04 08:56:40', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(115, NULL, 23, 66, 2, NULL, 16, 1, 7000.00, 7000.00, '2026-03-04 09:13:18', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(116, NULL, 23, 60, 2, NULL, 16, 1, 5000.00, 5000.00, '2026-03-04 09:13:18', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(117, NULL, 23, 59, 2, NULL, 16, 1, 7000.00, 7000.00, '2026-03-04 09:26:08', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(118, NULL, 65, 53, 1, 1, 16, 1, 3000.00, 3000.00, '2026-03-04 10:59:47', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(119, NULL, 23, 50, 2, NULL, 15, 1, 10000.00, 10000.00, '2026-03-04 12:06:28', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(120, NULL, 23, 43, 2, NULL, 15, 1, 8000.00, 8000.00, '2026-03-04 12:08:20', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(121, NULL, 23, 55, 2, NULL, 16, 1, 8000.00, 8000.00, '2026-03-04 13:29:23', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(122, NULL, 36, 42, 1, 1, 15, 1, 8000.00, 8000.00, '2026-03-04 13:31:27', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(123, NULL, 23, 64, 2, NULL, 16, 1, 6000.00, 6000.00, '2026-03-04 14:59:01', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(124, NULL, 66, 53, 1, 3, 16, 1, 3000.00, 3000.00, '2026-03-05 10:22:07', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(125, NULL, 66, 56, 1, 3, 16, 1, 6000.00, 6000.00, '2026-03-05 10:22:07', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(126, NULL, 36, 53, 1, 1, 16, 1, 3000.00, 3000.00, '2026-03-05 10:34:12', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(127, NULL, 36, 56, 1, 1, 16, 1, 6000.00, 6000.00, '2026-03-05 10:34:12', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(128, NULL, 67, 64, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-03-05 10:48:20', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(129, NULL, 67, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-03-05 10:48:20', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(130, NULL, 36, 64, 1, 1, 16, 1, 6000.00, 6000.00, '2026-03-05 12:05:17', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(131, NULL, 66, 55, 1, 3, 16, 1, 8000.00, 8000.00, '2026-03-05 13:46:41', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(132, NULL, 66, 58, 1, 3, 16, 1, 3000.00, 3000.00, '2026-03-05 13:46:41', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(133, NULL, 33, 54, 1, NULL, 16, 1, 4000.00, 4000.00, '2026-03-05 14:42:01', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(134, NULL, 33, 53, 1, NULL, 16, 1, 3000.00, 3000.00, '2026-03-05 14:42:01', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(135, NULL, 36, 61, 1, 1, 16, 1, 6000.00, 6000.00, '2026-03-05 18:45:51', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(136, NULL, 33, 62, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-03-05 18:50:28', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(137, NULL, 33, 66, 1, NULL, 16, 1, 7000.00, 7000.00, '2026-03-05 18:50:28', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(138, NULL, 57, 57, 1, NULL, 16, 1, 4000.00, 4000.00, '2026-03-05 19:36:43', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(139, NULL, 57, 54, 1, NULL, 16, 1, 4000.00, 4000.00, '2026-03-05 19:36:43', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(140, NULL, 57, 46, 1, NULL, 15, 1, 12000.00, 12000.00, '2026-03-05 19:38:13', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(141, NULL, 66, 63, 1, 3, 16, 1, 7000.00, 7000.00, '2026-03-05 19:54:13', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(142, NULL, 33, 52, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-03-05 20:09:03', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(143, NULL, 33, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-03-05 20:12:12', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(144, NULL, 33, 60, 1, NULL, 16, 1, 5000.00, 5000.00, '2026-03-05 20:15:59', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(149, NULL, 65, 55, 1, 1, 16, 1, 8000.00, 8000.00, '2026-03-05 20:36:25', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(150, NULL, 65, 58, 1, 1, 16, 1, 3000.00, 3000.00, '2026-03-05 20:36:25', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(151, NULL, 65, 53, 1, 1, 16, 1, 3000.00, 3000.00, '2026-03-05 20:42:43', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(152, NULL, 67, 53, 1, NULL, 16, 1, 3000.00, 3000.00, '2026-03-05 20:53:48', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(153, NULL, 62, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-03-05 20:56:54', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(154, NULL, 66, 57, 1, 3, 16, 1, 4000.00, 4000.00, '2026-03-05 22:35:37', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(155, NULL, 57, 59, 1, NULL, 16, 1, 7000.00, 7000.00, '2026-03-05 22:36:54', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(156, NULL, 36, 58, 1, 1, 16, 1, 3000.00, 3000.00, '2026-03-05 22:38:05', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(157, NULL, 67, 55, 1, NULL, 16, 1, 8000.00, 8000.00, '2026-03-08 00:59:06', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(158, NULL, 67, 56, 1, NULL, 16, 1, 6000.00, 6000.00, '2026-03-08 00:59:06', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(159, NULL, 65, 63, 1, 1, 16, 1, 7000.00, 7000.00, '2026-03-08 01:37:08', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(160, NULL, 65, 62, 1, 1, 16, 1, 5000.00, 5000.00, '2026-03-08 01:37:09', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services'),
(161, NULL, 66, 55, 1, 3, 16, 1, 8000.00, 8000.00, '2026-03-08 02:06:06', 1, 'rien', 'rien', NULL, NULL, 'en_cours', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'csk_services');

--
-- Triggers `actes_presc`
--
DELIMITER $$
CREATE TRIGGER `after_actes_presc_insert_imagerie` AFTER INSERT ON `actes_presc` FOR EACH ROW
BEGIN
    DECLARE v_categorie INT;
    DECLARE v_code_examen VARCHAR(50);
    DECLARE v_patient_id INT;
    DECLARE v_sejour_id INT;
    DECLARE v_compteur_jour INT;
    DECLARE v_libelle_acte VARCHAR(200);
    
    SELECT a.idcategorie_acte, a.libelle, s.idpatient, s.idsejour
    INTO v_categorie, v_libelle_acte, v_patient_id, v_sejour_id
    FROM csk_base.acte a
    JOIN csk_base.sous_sejour ss ON NEW.idsous_sejour = ss.idsous_sejour
    JOIN csk_base.sejour s ON ss.idsejour = s.idsejour
    WHERE a.idacte = NEW.idacte;
    
    IF v_categorie = 5 THEN
        SELECT COUNT(*) + 1 INTO v_compteur_jour
        FROM csk_services.imagerie_examens
        WHERE DATE(created_at) = CURDATE();
        
        SET v_code_examen = CONCAT(
            'IMG-', 
            DATE_FORMAT(NOW(), '%Y%m%d'), 
            '-', 
            LPAD(v_compteur_jour, 4, '0')
        );
        
        INSERT INTO csk_services.imagerie_examens (
            code_examen,
            idactes_presc,
            idpatient,
            idsejour,
            idsous_sejour,
            type_examen,
            statut,
            urgence,
            priorite,
            created_at
        ) VALUES (
            v_code_examen,
            NEW.idactes_presc,
            v_patient_id,
            v_sejour_id,
            NEW.idsous_sejour,
            v_libelle_acte,
            'programme',
            NEW.urgent,
            CASE WHEN NEW.urgent = 1 THEN 'urgence' ELSE 'programme' END,
            NOW()
        );
        
        INSERT INTO csk_services.services_notifications (
            service,
            type_notification,
            id_reference,
            table_reference,
            code_reference,
            titre,
            message,
            groupe_destinataire,
            priorite,
            created_at
        ) VALUES (
            'imagerie',
            'info',
            NEW.idactes_presc,
            'imagerie_examens',
            v_code_examen,
            'Nouvel examen d''imagerie',
            CONCAT(v_libelle_acte, ' - Code:', v_code_examen),
            'secretaires_imagerie',
            'normale',
            NOW()
        );
    END IF;
END$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_actes_presc_insert_labo` AFTER INSERT ON `actes_presc` FOR EACH ROW
BEGIN
    DECLARE v_categorie INT;
    DECLARE v_code_groupe VARCHAR(50);
    DECLARE v_idgroupe INT;
    DECLARE v_sous_numero INT;
    DECLARE v_patient_id INT;
    DECLARE v_sejour_id INT;
    DECLARE v_exists INT;
    
    SELECT a.idcategorie_acte, s.idpatient, s.idsejour
    INTO v_categorie, v_patient_id, v_sejour_id
    FROM csk_base.acte a
    JOIN csk_base.sous_sejour ss ON NEW.idsous_sejour = ss.idsous_sejour
    JOIN csk_base.sejour s ON ss.idsejour = s.idsejour
    WHERE a.idacte = NEW.idacte;
    
    IF v_categorie = 6 THEN
        
        SELECT COUNT(*) INTO v_exists
        FROM csk_services.labo_echantillons
        WHERE idactes_presc = NEW.idactes_presc;
        
        IF v_exists = 0 THEN
            
            SELECT idgroupe, code_groupe INTO v_idgroupe, v_code_groupe
            FROM csk_services.labo_groupes_echantillons
            WHERE idpatient = v_patient_id 
            AND DATE(date_creation) = CURDATE()
            LIMIT 1;
            
            IF v_code_groupe IS NULL THEN
                SELECT CONCAT(
                    'LAB-', 
                    DATE_FORMAT(NOW(), '%Y%m%d'), 
                    '-', 
                    LPAD(COALESCE(MAX(CAST(SUBSTRING(code_groupe, -4) AS UNSIGNED)) + 1, 1), 4, '0')
                ) INTO v_code_groupe
                FROM csk_services.labo_groupes_echantillons
                WHERE code_groupe LIKE CONCAT('LAB-', DATE_FORMAT(NOW(), '%Y%m%d'), '-%');
                
                INSERT INTO csk_services.labo_groupes_echantillons 
                    (code_groupe, idpatient, date_creation)
                VALUES (v_code_groupe, v_patient_id, NOW());
                
                SET v_idgroupe = LAST_INSERT_ID();
                SET v_sous_numero = 1;
            ELSE
                SELECT COALESCE(MAX(sous_numero), 0) + 1 INTO v_sous_numero
                FROM csk_services.labo_echantillons
                WHERE idgroupe = v_idgroupe;
            END IF;
            
            INSERT INTO csk_services.services_notifications (
                service,
                type_notification,
                id_reference,
                table_reference,
                code_reference,
                titre,
                message,
                groupe_destinataire,
                priorite,
                created_at
            ) VALUES (
                'labo',
                'info',
                NEW.idactes_presc,
                'actes_presc',
                v_code_groupe,
                'Nouvelle prescription laboratoire',
                CONCAT('Prescription pour le groupe ', v_code_groupe, ' (examen #', v_sous_numero, ')'),
                'techniciens_labo',
                CASE WHEN NEW.urgent = 1 THEN 'haute' ELSE 'normale' END,
                NOW()
            );
        END IF;
    END IF;
END$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `actes_presc_historique`
--

CREATE TABLE `actes_presc_historique` (
  `idhistorique` int NOT NULL,
  `idactes_presc` int NOT NULL,
  `action` varchar(255) NOT NULL,
  `commentaire` text,
  `date_action` datetime DEFAULT CURRENT_TIMESTAMP,
  `idutilisateur` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allergies_patients`
--

CREATE TABLE `allergies_patients` (
  `idallergie` int NOT NULL,
  `idpatient` int NOT NULL,
  `idutilisateur` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `allergene` varchar(200) NOT NULL,
  `type_reaction` varchar(100) DEFAULT NULL,
  `gravite` enum('l?g?re','mod?r?e','s?v?re') DEFAULT NULL,
  `date_decouverte` date DEFAULT NULL,
  `mesures_eviction` text,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `allergies_patients`
--

INSERT INTO `allergies_patients` (`idallergie`, `idpatient`, `idutilisateur`, `created_at`, `allergene`, `type_reaction`, `gravite`, `date_decouverte`, `mesures_eviction`, `date_creation`) VALUES
(3, 1, NULL, '2025-12-09 10:45:36', 'P?nicilline', 'Urticaire', 's?v?re', NULL, '?viter tous les antibiotiques b?ta-lactamines', '2025-12-09 10:45:36'),
(4, 1, NULL, '2025-12-09 10:45:36', 'Arachides', '?d?me de Quincke', 's?v?re', NULL, '?viction stricte des arachides', '2025-12-09 10:45:36');

-- --------------------------------------------------------

--
-- Table structure for table `antecedents_patients`
--

CREATE TABLE `antecedents_patients` (
  `idantecedent` int NOT NULL,
  `idpatient` int NOT NULL,
  `libelle` varchar(255) NOT NULL,
  `type_antecedent` varchar(50) DEFAULT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `gravite` varchar(20) DEFAULT NULL,
  `commentaire` text,
  `idutilisateur` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_diagnostic` date DEFAULT NULL,
  `traitement` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `antecedents_patients`
--

INSERT INTO `antecedents_patients` (`idantecedent`, `idpatient`, `libelle`, `type_antecedent`, `date_debut`, `date_fin`, `gravite`, `commentaire`, `idutilisateur`, `created_at`, `date_diagnostic`, `traitement`) VALUES
(1, 1, 'Hypertension art?rielle', 'personnel', NULL, NULL, 'moderee', NULL, NULL, '2025-12-09 02:00:18', NULL, NULL),
(2, 1, 'Diab?te type 2', 'personnel', NULL, NULL, 'moderee', NULL, NULL, '2025-12-09 02:00:18', NULL, NULL),
(3, 1, 'Cancer du sein maternel', 'familial', NULL, NULL, 'severe', NULL, NULL, '2025-12-09 02:00:18', NULL, NULL),
(4, 1, 'Appendicectomie en 2018', 'Chirurgical', NULL, NULL, NULL, NULL, NULL, '2025-12-09 10:40:31', '2018-06-15', 'Suivi post-op?ratoire normal'),
(5, 1, 'Allergie ? la p?nicilline', 'Allergique', NULL, NULL, NULL, NULL, NULL, '2025-12-09 10:40:31', '2019-08-22', '?viction');

-- --------------------------------------------------------

--
-- Table structure for table `audit_permissions`
--

CREATE TABLE `audit_permissions` (
  `id` int NOT NULL,
  `idutilisateur` int DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `module` varchar(50) DEFAULT NULL,
  `details` text,
  `date_action` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bloc_intervention`
--

CREATE TABLE `bloc_intervention` (
  `idintervention` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `type_intervention` enum('programmee','urgente','reglee') COLLATE utf8mb4_unicode_ci DEFAULT 'programmee',
  `libelle_intervention` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `idchirurgien` int DEFAULT NULL,
  `idanesthesiste` int DEFAULT NULL,
  `idsalle_bloc` int DEFAULT NULL,
  `date_prevue` date DEFAULT NULL,
  `heure_debut_prevue` time DEFAULT NULL,
  `duree_prevue_minutes` int DEFAULT '60',
  `heure_debut_reelle` time DEFAULT NULL,
  `heure_fin_reelle` time DEFAULT NULL,
  `type_anesthesie` enum('locale','locoregionale','generale','sedation','sans') COLLATE utf8mb4_unicode_ci DEFAULT 'generale',
  `urgence` tinyint(1) DEFAULT '0',
  `position_patient` enum('decubitus dorsal','decubitus lateral','assise','decubitus ventral','gynécologique','autre') COLLATE utf8mb4_unicode_ci DEFAULT 'decubitus dorsal',
  `check_list_preop` text COLLATE utf8mb4_unicode_ci,
  `check_list_postop` text COLLATE utf8mb4_unicode_ci,
  `observations_preop` text COLLATE utf8mb4_unicode_ci,
  `observations_debut` text COLLATE utf8mb4_unicode_ci,
  `observations_postop` text COLLATE utf8mb4_unicode_ci,
  `complications` text COLLATE utf8mb4_unicode_ci,
  `incidents_perop` text COLLATE utf8mb4_unicode_ci,
  `pertes_sanguines` int DEFAULT NULL,
  `transfusion` tinyint(1) DEFAULT '0',
  `volume_transfusion` int DEFAULT NULL,
  `type_suture` enum('resorbable','non resorbable','agrafe','autre') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `drainage` tinyint(1) DEFAULT '0',
  `type_drainage` enum('Redon','lame','Jost','Penrose','autre') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut` enum('programmee','confirmee','en_cours','terminee','annulee','reportee') COLLATE utf8mb4_unicode_ci DEFAULT 'programmee',
  `motif_annulation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_annulation` datetime DEFAULT NULL,
  `idutilisateur_annulation` int DEFAULT NULL,
  `idutilisateur_programmation` int DEFAULT NULL,
  `date_programmation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `caisse_transact`
--

CREATE TABLE `caisse_transact` (
  `idcaisse_transact` int NOT NULL,
  `idpatient` int NOT NULL,
  `idcaisse_typetransact` int NOT NULL,
  `montant_fc` decimal(10,2) DEFAULT '0.00',
  `montant_usd` decimal(10,2) DEFAULT '0.00',
  `devise` enum('FC','USD') NOT NULL,
  `taux_change` decimal(10,4) DEFAULT '1.0000',
  `mode_paiement` enum('cash','cc','mobile','virement') DEFAULT 'cash',
  `reference_paiement` varchar(100) DEFAULT NULL,
  `date_transaction` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `idutilisateur` int NOT NULL,
  `observation` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `caisse_transact`
--

INSERT INTO `caisse_transact` (`idcaisse_transact`, `idpatient`, `idcaisse_typetransact`, `montant_fc`, `montant_usd`, `devise`, `taux_change`, `mode_paiement`, `reference_paiement`, `date_transaction`, `idutilisateur`, `observation`) VALUES
(1, 1, 1, 5000.00, 0.00, 'FC', 1.0000, 'cash', NULL, '2025-12-08 23:27:53', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `caisse_typetransact`
--

CREATE TABLE `caisse_typetransact` (
  `idcaisse_typetransact` int NOT NULL,
  `libelle` varchar(50) NOT NULL,
  `type_mouvement` enum('entree','sortie') NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `caisse_typetransact`
--

INSERT INTO `caisse_typetransact` (`idcaisse_typetransact`, `libelle`, `type_mouvement`, `description`) VALUES
(1, 'Paiement consultation', 'entree', 'Paiement d\'une consultation'),
(2, 'Paiement hospitalisation', 'entree', 'Paiement frais d\'hospitalisation'),
(3, 'Caution', 'entree', 'D?p?t de caution'),
(4, 'Remboursement', 'sortie', 'Remboursement au patient'),
(5, 'Acompte pharmacie', 'entree', 'Acompte sur prescription pharmacie');

-- --------------------------------------------------------

--
-- Table structure for table `categorie`
--

CREATE TABLE `categorie` (
  `idcategorie` int NOT NULL,
  `nom` varchar(50) NOT NULL,
  `description` text,
  `taux_couverture` decimal(5,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `categorie`
--

INSERT INTO `categorie` (`idcategorie`, `nom`, `description`, `taux_couverture`) VALUES
(1, 'Ordinaire', 'Cat?gorie standard', 0.00),
(2, 'Personnel CHME', 'Personnel du Centre Hospitalier', 50.00),
(3, 'Personnel Externe', 'Personnel externe conventionn?', 30.00),
(4, 'Enfant Personnel', 'Enfant du personnel', 50.00),
(5, 'VIP', 'Personnalit? ou cas particulier', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `categorie_acte`
--

CREATE TABLE `categorie_acte` (
  `idcategorie_acte` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `description` text,
  `actif` tinyint DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `categorie_acte`
--

INSERT INTO `categorie_acte` (`idcategorie_acte`, `nom`, `description`, `actif`) VALUES
(1, 'Consultation', 'Consultations médicales', 1),
(2, 'Examens', 'Examens complémentaires', 1),
(3, 'Soins', 'Soins infirmiers', 1),
(4, 'Chirurgie', 'Actes chirurgicaux', 1),
(5, 'Imagerie', 'Examens d\'imagerie', 1),
(6, 'Laboratoire', 'Analyses de laboratoire', 1),
(8, 'Dentisterie', 'Soins dentaires', 1),
(9, 'Ophtalmologie', 'Soins oculaires', 1),
(10, 'Échographie', NULL, 1),
(11, 'Cardiologie', 'Soins cardiologiques', 1),
(12, 'Hépato Gastro Entérologie', 'Soins digestifs', 1),
(13, 'Néphrologie', 'Soins rénaux', 1),
(14, 'Pneumologie', 'Soins respiratoires', 1),
(15, 'Gynécologie et Obstétrique', 'Soins gynécologiques et obstétriques', 1),
(16, 'Neuro Chirurgie', 'Chirurgie neurologique', 1),
(17, 'Orthopédie', 'Soins orthopédiques', 1),
(18, 'ORL', 'Oto-rhino-laryngologie', 1),
(19, 'Urologie', 'Soins urologiques', 1),
(20, 'Chirurgie Générale', 'Chirurgie générale', 1),
(21, 'Hôtellerie', 'Services hospitaliers', 1),
(22, 'Radiologie', 'Examens radiologiques', 1),
(24, 'Hématologie', 'Analyses hématologiques', 1),
(25, 'Biochimie', 'Analyses biochimiques', 1),
(26, 'Rhumatologie', 'Soins rhumatologiques', 1),
(27, 'Nursing', 'Soins de nursing', 1),
(28, 'Actes Médicaux', NULL, 1),
(50, 'Autre', 'Autres catégories', 1);

-- --------------------------------------------------------

--
-- Table structure for table `certificats_medicaux`
--

CREATE TABLE `certificats_medicaux` (
  `idcertificat` int NOT NULL,
  `idconsultation` int DEFAULT NULL,
  `type_certificat` varchar(100) DEFAULT NULL,
  `duree_arret` int DEFAULT NULL,
  `contenu` text,
  `date_emission` date DEFAULT NULL,
  `idmedecin` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chambre`
--

CREATE TABLE `chambre` (
  `idchambre` int NOT NULL,
  `idunitehospi` int NOT NULL,
  `numero` varchar(20) NOT NULL,
  `type_chambre` enum('standard','vip','isole','urgence') DEFAULT 'standard',
  `capacite` int DEFAULT '1',
  `actif` tinyint(1) DEFAULT '1',
  `observation` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `chambre`
--

INSERT INTO `chambre` (`idchambre`, `idunitehospi`, `numero`, `type_chambre`, `capacite`, `actif`, `observation`, `created_at`, `updated_at`) VALUES
(1, 1, 'P101', 'standard', 2, 1, 'Proche du poste infirmier', '2025-12-11 05:40:13', '2025-12-11 05:40:13'),
(2, 2, 'M203', 'isole', 1, 1, 'Chambre isol?e pour infection', '2025-12-11 05:40:13', '2025-12-11 05:40:13'),
(3, 3, 'C115', 'vip', 1, 1, 'Chambre VIP avec salle de bain priv?e', '2025-12-11 05:40:13', '2025-12-11 05:40:13'),
(4, 4, 'U010', 'urgence', 1, 1, 'Salle de stabilisation urgente', '2025-12-11 05:40:13', '2025-12-11 05:40:13'),
(5, 5, 'K304', 'standard', 3, 1, 'Chambre collective en cardiologie', '2025-12-11 05:40:13', '2025-12-11 05:40:13');

-- --------------------------------------------------------

--
-- Table structure for table `commune`
--

CREATE TABLE `commune` (
  `idcommune` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `province` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `commune`
--

INSERT INTO `commune` (`idcommune`, `nom`, `province`) VALUES
(1, 'Bandalungwa', 'Kinshasa'),
(2, 'Barumbu', 'Kinshasa'),
(3, 'Gombe', 'Kinshasa'),
(4, 'Kalamu', 'Kinshasa'),
(5, 'Kasa-Vubu', 'Kinshasa'),
(6, 'Kimbanseke', 'Kinshasa'),
(7, 'Kinshasa', 'Kinshasa'),
(8, 'Kintambo', 'Kinshasa'),
(9, 'Kisenso', 'Kinshasa'),
(10, 'Lemba', 'Kinshasa'),
(11, 'Limete', 'Kinshasa'),
(12, 'Lingwala', 'Kinshasa'),
(13, 'Makala', 'Kinshasa'),
(14, 'Maluku', 'Kinshasa'),
(15, 'Masina', 'Kinshasa'),
(16, 'Matete', 'Kinshasa'),
(17, 'Mont-Ngafula', 'Kinshasa'),
(18, 'Ndjili', 'Kinshasa'),
(19, 'Ngaba', 'Kinshasa'),
(20, 'Ngaliema', 'Kinshasa'),
(21, 'Ngiri-Ngiri', 'Kinshasa'),
(22, 'Nsele', 'Kinshasa'),
(23, 'Selembao', 'Kinshasa'),
(24, 'Bumbu', 'Kinshasa');

-- --------------------------------------------------------

--
-- Table structure for table `compte_rendu_operatoire`
--

CREATE TABLE `compte_rendu_operatoire` (
  `idcompte_rendu` int NOT NULL,
  `idintervention` int NOT NULL,
  `indication_operatoire` text COLLATE utf8mb4_unicode_ci,
  `description_intervention` text COLLATE utf8mb4_unicode_ci,
  `technique_operatoire` text COLLATE utf8mb4_unicode_ci,
  `incidents_perop` text COLLATE utf8mb4_unicode_ci,
  `pertes_sanguines` int DEFAULT NULL,
  `gestes_associes` text COLLATE utf8mb4_unicode_ci,
  `materiel_implante` text COLLATE utf8mb4_unicode_ci,
  `prelevement_anatomopathologie` text COLLATE utf8mb4_unicode_ci,
  `antibioprophylaxie` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prescriptions_postop` text COLLATE utf8mb4_unicode_ci,
  `suite_operatoire` text COLLATE utf8mb4_unicode_ci,
  `destination_postop` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surveillance_particuliere` text COLLATE utf8mb4_unicode_ci,
  `valide` tinyint(1) DEFAULT '0',
  `date_validation` datetime DEFAULT NULL,
  `idutilisateur_validation` int DEFAULT NULL,
  `idutilisateur` int DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `idutilisateur_modif` int DEFAULT NULL,
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consommables_bloc`
--

CREATE TABLE `consommables_bloc` (
  `idconsommable_bloc` int NOT NULL,
  `idintervention` int NOT NULL,
  `idproduit` int DEFAULT NULL,
  `designation` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `quantite` int NOT NULL,
  `unite` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `lot_numero` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `observations` text COLLATE utf8mb4_general_ci,
  `idutilisateur` int NOT NULL,
  `date_enregistrement` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consultation`
--

CREATE TABLE `consultation` (
  `idconsultation` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `date_consultation` datetime DEFAULT CURRENT_TIMESTAMP,
  `motif` text,
  `examen_clinique` text,
  `conclusion` text,
  `iddiagnostic` int DEFAULT NULL,
  `traitement` text,
  `idmedecin` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consultations`
--

CREATE TABLE `consultations` (
  `idconsultation` int NOT NULL,
  `idsous_sejour` int DEFAULT NULL,
  `date_consultation` date DEFAULT NULL,
  `idpatient` int NOT NULL,
  `idutilisateur` int DEFAULT NULL,
  `motif_consultation` text,
  `anamnese` text,
  `examen_clinique` text,
  `hypothese_diagnostique` text,
  `conduite_tenir` text,
  `observation` text,
  `statut` enum('en_attente','en_cours','terminee') DEFAULT 'en_attente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `consultations`
--

INSERT INTO `consultations` (`idconsultation`, `idsous_sejour`, `date_consultation`, `idpatient`, `idutilisateur`, `motif_consultation`, `anamnese`, `examen_clinique`, `hypothese_diagnostique`, `conduite_tenir`, `observation`, `statut`) VALUES
(1, 1, NULL, 1, 2, 'Fi?vre et c?phal?es depuis 3 jours', 'Patient se plaint de fi?vre ? 39?C, c?phal?es intenses, frissons, courbatures. Pas de toux ni de dyspn?e. Pas de diarrh?e. Ant?c?dents: RAS', '?tat g?n?ral: conserv?. T?: 38.8?C, TA: 120/75 mmHg, FC: 88 bpm. Examen cardio-pulmonaire: normal. Abdomen: souple, pas de d?fense.', 'Paludisme simple', '1. NFS + Frottis sanguin + Goutte ?paisse\n2. Traitement antipaludique\n3. Antipyr?tique\n4. R??valuation dans 48h', NULL, 'en_attente'),
(2, 2, NULL, 1, 2, 'Douleurs abdominales', 'Patiente se plaint de douleurs abdominales ?pigastriques, br?lures, naus?es. Apparues il y a 2 semaines.', 'T?: 37.2?C, TA: 115/70 mmHg. Abdomen: douleur ? la palpation ?pigastrique, pas de d?fense.', 'Gastrite probable', '1. ?chographie abdominale\n2. Traitement symptomatique\n3. Conseils hygi?no-di?t?tiques', NULL, 'en_attente'),
(3, 37, '2025-12-27', 4, 1, 'Fièvre', 'Annuelle chaque mois de décembre', 'Sanguin', 'Changement de saison', 'La cure malaria', NULL, 'en_attente'),
(4, 37, '2025-12-27', 4, 1, 'Vertige', 'Trop dansé à Noël', 'Apgar', 'Tournie', 'Calmant et dormir', NULL, 'en_cours'),
(5, 37, '2025-12-27', 4, 1, 'Vertige', 'Trop dansé à Noël', 'Apgar', 'Tournie', 'Calmant et dormir', NULL, 'en_cours'),
(6, 37, '2025-12-27', 4, 1, 'Vertige', 'Trop dansé à Noël', 'Apgar', 'Tournie', 'Calmant et dormir', NULL, 'en_cours'),
(7, 37, '2025-12-27', 4, 1, 'Vertige', 'Trop dansé à Noël', 'Apgar', 'Tournie', 'Calmant et dormir', NULL, 'en_cours'),
(8, 37, '2025-12-27', 4, 1, 'Vertige', 'Trop dansé à Noël', 'Apgar', 'Tournie', 'Calmant et dormir', NULL, 'en_cours'),
(9, 37, '2025-12-27', 4, 1, 'Vertige', 'Trop dansé à Noël', 'Apgar', 'Tournie', 'Calmant et dormir', NULL, 'en_cours'),
(10, 37, '2025-12-27', 4, 1, 'Froid', 'Plongé dans la glace', 'Pigmentation', 'Traumatisme cutané', 'Pas de douche froide', NULL, 'en_cours'),
(11, 37, '2025-12-27', 4, 1, 'Froid', 'Plongé dans la glace', 'Pigmentation', 'Traumatisme cutané', 'Pas de douche froide', NULL, 'en_cours'),
(12, 37, '2025-12-27', 4, 1, 'new', 'new', 'new', 'New', 'new', NULL, 'en_cours'),
(13, 37, '2025-12-27', 4, 1, 'new', 'new', 'new', 'New', 'new', NULL, 'en_cours'),
(14, 67, '2026-01-02', 22, 1, 'Maux de dent', 'Après avoir pris une glace', 'Lavage de la bouche', 'La carie', 'Plus de sucrerie ni nourriture froide', NULL, 'en_cours');

-- --------------------------------------------------------

--
-- Table structure for table `demande_transfert_hospi`
--

CREATE TABLE `demande_transfert_hospi` (
  `iddemande_transfert` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `idunitehospi_destination` int NOT NULL,
  `motif` text,
  `statut` enum('en_attente','approuvee','rejetee','annulee') DEFAULT 'en_attente',
  `priorite` enum('normale','urgente','critique') DEFAULT 'normale',
  `idinfirmiere_demandeur` int DEFAULT NULL,
  `idmedecin_approbateur` int DEFAULT NULL,
  `date_demande` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_approbation` datetime DEFAULT NULL,
  `observation` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `destinationsprod`
--

CREATE TABLE `destinationsprod` (
  `iddestination` int NOT NULL,
  `libelle` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `destinationsprod`
--

INSERT INTO `destinationsprod` (`iddestination`, `libelle`) VALUES
(1, 'Produit périmé'),
(2, 'Correction de stock'),
(3, 'Don'),
(4, 'Transfert autre site'),
(5, 'Retour fournisseur');

-- --------------------------------------------------------

--
-- Table structure for table `diagnostic`
--

CREATE TABLE `diagnostic` (
  `iddiagnostic` int NOT NULL,
  `code_cim` varchar(20) DEFAULT NULL,
  `libelle` varchar(255) NOT NULL,
  `description` text,
  `actif` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `diagnostic`
--

INSERT INTO `diagnostic` (`iddiagnostic`, `code_cim`, `libelle`, `description`, `actif`, `created_at`) VALUES
(1, 'J18.9', 'Pneumopathie aiguë', NULL, 1, '2025-12-19 14:50:06'),
(2, 'I10', 'Hypertension essentielle', NULL, 1, '2025-12-19 14:50:06'),
(3, 'E11.9', 'Diabète de type 2', NULL, 1, '2025-12-19 14:50:06'),
(4, 'I20.9', 'Angine de poitrine', NULL, 1, '2025-12-19 14:50:06'),
(5, 'J45.9', 'Asthme', NULL, 1, '2025-12-19 14:50:06'),
(6, 'K29.7', 'Gastrite', NULL, 1, '2025-12-19 14:50:06'),
(7, 'M54.5', 'Lombalgie', NULL, 1, '2025-12-19 14:50:06'),
(8, 'N39.0', 'Infection urinaire', NULL, 1, '2025-12-19 14:50:06'),
(9, 'LIBRE', 'Diagnostic libre (non codé)', NULL, 1, '2025-12-27 11:10:58');

-- --------------------------------------------------------

--
-- Table structure for table `diagnostic_patient`
--

CREATE TABLE `diagnostic_patient` (
  `iddiagnostic_patient` int NOT NULL,
  `idpatient` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `iddiagnostic` int NOT NULL,
  `libelle_diagnostic` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type_diagnostic` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_diagnostic` datetime NOT NULL,
  `confirmé` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `diagnostic_patient`
--

INSERT INTO `diagnostic_patient` (`iddiagnostic_patient`, `idpatient`, `idsous_sejour`, `iddiagnostic`, `libelle_diagnostic`, `type_diagnostic`, `date_diagnostic`, `confirmé`, `created_at`, `updated_at`) VALUES
(1, 4, 37, 9, 'New', 'présomptif', '2025-12-27 11:12:27', 0, '2025-12-27 11:12:27', '2025-12-27 11:12:27'),
(2, 22, 67, 9, 'La carie', 'présomptif', '2026-01-02 06:31:40', 0, '2026-01-02 06:31:40', '2026-01-02 06:31:40');

-- --------------------------------------------------------

--
-- Table structure for table `entreprod`
--

CREATE TABLE `entreprod` (
  `identreprod` int NOT NULL,
  `idfournissuer` int NOT NULL,
  `idofficine` int NOT NULL,
  `numero_entree` varchar(20) DEFAULT NULL,
  `date_entree` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `observation` text,
  `idutilisateur` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `entreprod`
--

INSERT INTO `entreprod` (`identreprod`, `idfournissuer`, `idofficine`, `numero_entree`, `date_entree`, `observation`, `idutilisateur`) VALUES
(1, 1, 1, 'ENT000001', '2025-12-07 13:52:09', 'Livraison mensuelle PHARMAKINA', 6);

-- --------------------------------------------------------

--
-- Table structure for table `equipements_imagerie`
--

CREATE TABLE `equipements_imagerie` (
  `id` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `type_equipement` varchar(50) NOT NULL,
  `numero_serie` varchar(50) DEFAULT NULL,
  `marque` varchar(50) DEFAULT NULL,
  `modele` varchar(50) DEFAULT NULL,
  `date_installation` date DEFAULT NULL,
  `date_derniere_maintenance` date DEFAULT NULL,
  `prochaine_maintenance` date DEFAULT NULL,
  `localisation` varchar(100) DEFAULT NULL,
  `statut` enum('actif','maintenance','hors_service') DEFAULT 'actif',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipe_chirurgicale`
--

CREATE TABLE `equipe_chirurgicale` (
  `idequipe` int NOT NULL,
  `idintervention` int NOT NULL,
  `idutilisateur` int NOT NULL,
  `role` enum('chirurgien','aide_operatoire','instrumentiste','panseuse','anesthesiste','infirmier_anesthesiste','radiologue','cardiologue','circulant') COLLATE utf8mb4_unicode_ci NOT NULL,
  `heure_entree` time DEFAULT NULL,
  `heure_sortie` time DEFAULT NULL,
  `observations` text COLLATE utf8mb4_unicode_ci,
  `date_ajout` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ethnie`
--

CREATE TABLE `ethnie` (
  `idethnie` int NOT NULL,
  `nom` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `ethnie`
--

INSERT INTO `ethnie` (`idethnie`, `nom`) VALUES
(5, 'Autre'),
(1, 'Kongo'),
(2, 'Luba'),
(3, 'Mongo'),
(4, 'Swahili');

-- --------------------------------------------------------

--
-- Table structure for table `evolution_urgence`
--

CREATE TABLE `evolution_urgence` (
  `idevolution_urgence` int NOT NULL,
  `idurgence` int NOT NULL,
  `date_evolution` datetime NOT NULL,
  `observation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `idutilisateur` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `famiprod`
--

CREATE TABLE `famiprod` (
  `idfamiprod` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `famiprod`
--

INSERT INTO `famiprod` (`idfamiprod`, `nom`, `description`) VALUES
(1, 'Antalgiques', 'Médicaments contre la douleur'),
(2, 'Antibiotiques', 'Médicaments antibactériens'),
(3, 'Antipyrétiques', 'Médicaments contre la fièvre'),
(4, 'Anti-inflammatoires', 'Médicaments anti-inflammatoires'),
(5, 'Antipaludiques', 'Médicaments contre le paludisme'),
(6, 'Perfusions', 'Solutions de perfusion'),
(7, 'Consommables', 'Matériel médical consommable'),
(8, 'Pansements', 'Matériel de pansement'),
(9, 'Antibiotiques', NULL),
(10, 'Analgésiques', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `fct`
--

CREATE TABLE `fct` (
  `idfct` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` text,
  `module` varchar(50) DEFAULT NULL,
  `categorie` varchar(50) DEFAULT NULL,
  `ordre` int DEFAULT '0',
  `icone` varchar(50) DEFAULT NULL,
  `statut` enum('actif','inactif','cach?') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fct`
--

INSERT INTO `fct` (`idfct`, `nom`, `code`, `description`, `module`, `categorie`, `ordre`, `icone`, `statut`) VALUES
(1, 'Réception', 'reception', 'Accès au module réception', 'reception', 'Gestion Patients', 1, 'fa-user-plus', 'actif'),
(2, 'Enregistrer Patient', 'reception_create', 'Créer un nouveau patient', 'reception', 'Gestion Patients', 2, 'fa-user-plus', 'actif'),
(3, 'Modifier Patient', 'reception_update', 'Modifier les informations patient', 'reception', 'Gestion Patients', 3, 'fa-user-edit', 'actif'),
(4, 'Rechercher Patient', 'reception_search', 'Rechercher un patient', 'reception', 'Gestion Patients', 4, 'fa-search', 'actif'),
(5, 'Créer Séjour', 'reception_sejour', 'Créer un séjour pour un patient', 'reception', 'Gestion Patients', 5, 'fa-calendar-plus', 'actif'),
(6, 'Urgences', 'urgence', 'Accès au module urgences', 'urgence', 'Soins Urgents', 10, 'fa-ambulance', 'actif'),
(7, 'Triage Urgence', 'urgence_triage', 'Effectuer le triage des patients', 'urgence', 'Soins Urgents', 11, 'fa-sort', 'actif'),
(8, 'Consultation Urgence', 'urgence_consultation', 'Consulter aux urgences', 'urgence', 'Soins Urgents', 12, 'fa-stethoscope', 'actif'),
(9, 'évolution Urgence', 'urgence_evolution', 'Ajouter des évolutions', 'urgence', 'Soins Urgents', 13, 'fa-notes-medical', 'actif'),
(10, 'Transfert Urgence', 'urgence_transfert', 'Transférer un patient', 'urgence', 'Soins Urgents', 14, 'fa-exchange-alt', 'actif'),
(11, 'Statistiques Urgence', 'urgence_stats', 'Voir statistiques urgences', 'urgence', 'Soins Urgents', 15, 'fa-chart-line', 'actif'),
(12, 'Consultation', 'consultation', 'Accès au module consultation', 'consultation', 'Soins Médicaux', 20, 'fa-stethoscope', 'actif'),
(13, 'Créer Consultation', 'consultation_create', 'Créer une consultation', 'consultation', 'Soins Médicaux', 21, 'fa-plus-circle', 'actif'),
(14, 'Voir Dossier Médical', 'consultation_dossier', 'Consulter le dossier médical', 'consultation', 'Soins Médicaux', 22, 'fa-folder-open', 'actif'),
(15, 'Prescrire', 'consultation_prescription', 'Faire des prescriptions', 'consultation', 'Soins Médicaux', 23, 'fa-prescription', 'actif'),
(16, 'Demander Examens', 'consultation_examens', 'Demander examens complémentaires', 'consultation', 'Soins Médicaux', 24, 'fa-file-medical', 'actif'),
(17, 'Valider Consultation', 'consultation_valider', 'Valider une consultation', 'consultation', 'Soins Médicaux', 25, 'fa-check-circle', 'actif'),
(18, 'Hospitalisation', 'hospitalisation', 'Accès au module hospitalisation', 'hospitalisation', 'Hospitalisation', 30, 'fa-bed', 'actif'),
(19, 'Admission Hospitalisation', 'hospitalisation_admission', 'Admettre un patient', 'hospitalisation', 'Hospitalisation', 31, 'fa-sign-in-alt', 'actif'),
(20, 'Gestion Lits', 'hospitalisation_lits', 'Gérer les lits et chambres', 'hospitalisation', 'Hospitalisation', 32, 'fa-bed', 'actif'),
(21, 'Soins Infirmiers', 'hospitalisation_soins', 'Administrer des soins', 'hospitalisation', 'Hospitalisation', 33, 'fa-syringe', 'actif'),
(22, 'Surveillance Patients', 'hospitalisation_surveillance', 'Surveiller les patients', 'hospitalisation', 'Hospitalisation', 34, 'fa-heartbeat', 'actif'),
(23, 'Sortie Hospitalisation', 'hospitalisation_sortie', 'Gérer les sorties', 'hospitalisation', 'Hospitalisation', 35, 'fa-sign-out-alt', 'actif'),
(24, 'Bloc Opératoire', 'bloc', 'Accès au bloc opératoire', 'bloc', 'Chirurgie', 40, 'fa-procedures', 'actif'),
(25, 'Programmer Intervention', 'bloc_programmer', 'Programmer une intervention', 'bloc', 'Chirurgie', 41, 'fa-calendar-plus', 'actif'),
(26, 'Réaliser Intervention', 'bloc_realiser', 'Réaliser une intervention', 'bloc', 'Chirurgie', 42, 'fa-user-md', 'actif'),
(27, 'Compte-Rendu Opératoire', 'bloc_cr', 'Rédiger CR opératoire', 'bloc', 'Chirurgie', 43, 'fa-file-medical-alt', 'actif'),
(28, 'Feuille Anesthésie', 'bloc_anesthesie', 'Gérer anesthésie', 'bloc', 'Chirurgie', 44, 'fa-notes-medical', 'actif'),
(29, 'Gestion Salles Bloc', 'bloc_salles', 'Gérer les salles', 'bloc', 'Chirurgie', 45, 'fa-door-open', 'actif'),
(30, 'Statistiques Bloc', 'bloc_stats', 'Voir statistiques bloc', 'bloc', 'Chirurgie', 46, 'fa-chart-bar', 'actif'),
(31, 'Laboratoire', 'laboratoire', 'Accès au laboratoire', 'laboratoire', 'Examens', 50, 'fa-flask', 'actif'),
(32, 'Enregistrer Prélévement', 'labo_prelevement', 'Enregistrer un prélévement', 'laboratoire', 'Examens', 51, 'fa-vial', 'actif'),
(33, 'Saisir Résultats', 'labo_resultats', 'Saisir les résultats', 'laboratoire', 'Examens', 52, 'fa-keyboard', 'actif'),
(34, 'Valider Résultats', 'labo_valider', 'Valider les résultats', 'laboratoire', 'Examens', 53, 'fa-check-double', 'actif'),
(35, 'Imprimer Résultats', 'labo_imprimer', 'Imprimer les résultats', 'laboratoire', 'Examens', 54, 'fa-print', 'actif'),
(36, 'Gestion Réactifs', 'labo_reactifs', 'Gérer les réactifs', 'laboratoire', 'Examens', 55, 'fa-box', 'actif'),
(37, 'Imagerie', 'imagerie', 'Accès au module imagerie', 'imagerie', 'Examens', 60, 'fa-x-ray', 'actif'),
(38, 'Enregistrer Examen', 'imagerie_create', 'Créer un examen', 'imagerie', 'Examens', 61, 'fa-plus-circle', 'actif'),
(39, 'Effectuer Examen', 'imagerie_realiser', 'Réaliser l\'examen', 'imagerie', 'Examens', 62, 'fa-camera', 'actif'),
(40, 'Rédiger Compte-Rendu', 'imagerie_cr', 'Rédiger le compte-rendu', 'imagerie', 'Examens', 63, 'fa-file-medical', 'actif'),
(41, 'Valider Compte-Rendu', 'imagerie_valider', 'Valider le compte-rendu', 'imagerie', 'Examens', 64, 'fa-check-circle', 'actif'),
(42, 'Pharmacie', 'pharmacie', 'Accès au module pharmacie', 'pharmacie', 'Pharmacie', 70, 'fa-pills', 'actif'),
(43, 'Dispensation', 'pharmacie_dispenser', 'Dispenser les médicaments', 'pharmacie', 'Pharmacie', 71, 'fa-hand-holding-medical', 'actif'),
(44, 'Gestion Stock', 'pharmacie_stock', 'Gérer le stock', 'pharmacie', 'Pharmacie', 72, 'fa-boxes', 'actif'),
(45, 'Entrée Produits', 'pharmacie_entree', 'Enregistrer les entrées', 'pharmacie', 'Pharmacie', 73, 'fa-sign-in-alt', 'actif'),
(46, 'Sortie Produits', 'pharmacie_sortie', 'Enregistrer les sorties', 'pharmacie', 'Pharmacie', 74, 'fa-sign-out-alt', 'actif'),
(47, 'Inventaire', 'pharmacie_inventaire', 'Faire l\'inventaire', 'pharmacie', 'Pharmacie', 75, 'fa-clipboard-list', 'actif'),
(48, 'Péremption', 'pharmacie_peremption', 'Gérer les péremptions', 'pharmacie', 'Pharmacie', 76, 'fa-calendar-times', 'actif'),
(49, 'Validation Pharmacien', 'pharmacie_validation', 'Valider les prescriptions', 'pharmacie', 'Pharmacie', 77, 'fa-check-double', 'actif'),
(50, 'Kinésithérapie', 'kinesitherapie', 'Accès é la kinésithérapie', 'kinesitherapie', 'Rééducation', 80, 'fa-running', 'actif'),
(51, 'Séance Kiné', 'kine_seance', 'Créer une séance', 'kinesitherapie', 'Rééducation', 81, 'fa-calendar-check', 'actif'),
(52, 'Bilan Kinésithérapique', 'kine_bilan', 'Faire un bilan', 'kinesitherapie', 'Rééducation', 82, 'fa-clipboard', 'actif'),
(53, 'Planning Kiné', 'kine_planning', 'Gérer le planning', 'kinesitherapie', 'Rééducation', 83, 'fa-calendar-alt', 'actif'),
(54, 'Facturation', 'facturation', 'Accès au module facturation', 'facturation', 'Finance', 90, 'fa-file-invoice-dollar', 'actif'),
(55, 'Créer Facture', 'facturation_create', 'Créer une facture', 'facturation', 'Finance', 91, 'fa-plus-circle', 'actif'),
(56, 'Modifier Facture', 'facturation_update', 'Modifier une facture', 'facturation', 'Finance', 92, 'fa-edit', 'actif'),
(57, 'Valider Facture', 'facturation_valider', 'Valider une facture', 'facturation', 'Finance', 93, 'fa-check-circle', 'actif'),
(58, 'Encaisser', 'facturation_encaisser', 'Encaisser un paiement', 'facturation', 'Finance', 94, 'fa-cash-register', 'actif'),
(59, 'Annuler Facture', 'facturation_annuler', 'Annuler une facture', 'facturation', 'Finance', 95, 'fa-times-circle', 'actif'),
(60, 'Remise', 'facturation_remise', 'Appliquer des remises', 'facturation', 'Finance', 96, 'fa-percent', 'actif'),
(61, 'Rapports Financiers', 'facturation_rapports', 'Voir les rapports', 'facturation', 'Finance', 97, 'fa-chart-line', 'actif'),
(62, 'Administration Systéme', 'admin', 'Administration compléte', 'admin', 'Administration', 100, 'fa-cog', 'actif'),
(63, 'Gestion Utilisateurs', 'admin_users', 'Gérer les utilisateurs', 'admin', 'Administration', 101, 'fa-users-cog', 'actif'),
(64, 'Gestion Profils', 'admin_profils', 'Gérer les profils', 'admin', 'Administration', 102, 'fa-user-tag', 'actif'),
(65, 'Gestion Permissions', 'admin_permissions', 'Gérer les permissions', 'admin', 'Administration', 103, 'fa-key', 'actif'),
(66, 'Gestion Sites', 'admin_sites', 'Gérer les sites', 'admin', 'Administration', 104, 'fa-hospital', 'actif'),
(67, 'Gestion Services', 'admin_services', 'Gérer les services', 'admin', 'Administration', 105, 'fa-sitemap', 'actif'),
(68, 'Configuration Tarifs', 'admin_tarifs', 'Configurer les tarifs', 'admin', 'Administration', 106, 'fa-dollar-sign', 'actif'),
(69, 'Logs Systéme', 'admin_logs', 'Consulter les logs', 'admin', 'Administration', 107, 'fa-history', 'actif'),
(70, 'Sauvegarde Base', 'admin_backup', 'Gérer les sauvegardes', 'admin', 'Administration', 108, 'fa-database', 'actif'),
(71, 'Paramétres Systéme', 'admin_params', 'Paramétres généraux', 'admin', 'Administration', 109, 'fa-sliders-h', 'actif');

-- --------------------------------------------------------

--
-- Table structure for table `fct_profiluser`
--

CREATE TABLE `fct_profiluser` (
  `idfct` int NOT NULL,
  `idprofiluser` int NOT NULL,
  `peut_creer` tinyint(1) DEFAULT '0',
  `peut_modifier` tinyint(1) DEFAULT '0',
  `peut_supprimer` tinyint(1) DEFAULT '0',
  `peut_consulter` tinyint(1) DEFAULT '1',
  `peut_imprimer` tinyint(1) DEFAULT '0',
  `date_attribution` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `idutilisateur` int DEFAULT NULL,
  `peut_valider` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fct_profiluser`
--

INSERT INTO `fct_profiluser` (`idfct`, `idprofiluser`, `peut_creer`, `peut_modifier`, `peut_supprimer`, `peut_consulter`, `peut_imprimer`, `date_attribution`, `idutilisateur`, `peut_valider`) VALUES
(1, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(1, 2, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(1, 3, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(1, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(1, 15, 1, 1, 0, 1, 0, '2025-12-15 16:35:30', NULL, 0),
(2, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(2, 2, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(2, 3, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(2, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(3, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(3, 2, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(3, 3, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(3, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(4, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(4, 2, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(4, 3, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(4, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(5, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(5, 2, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(5, 3, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(5, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(6, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(6, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(6, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(7, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(7, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(7, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(8, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(8, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(8, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(9, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(9, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(9, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(10, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(10, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(10, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(11, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(11, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(11, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(12, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(12, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(12, 7, 0, 0, 0, 1, 0, '2025-12-11 14:27:57', NULL, 0),
(13, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(13, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(13, 7, 0, 0, 0, 1, 0, '2025-12-11 14:27:57', NULL, 0),
(14, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(14, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(14, 7, 0, 0, 0, 1, 0, '2025-12-11 14:27:57', NULL, 0),
(15, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(15, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(15, 7, 0, 0, 0, 1, 0, '2025-12-11 14:27:57', NULL, 0),
(16, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(16, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(16, 7, 0, 0, 0, 1, 0, '2025-12-11 14:27:57', NULL, 0),
(17, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(17, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(17, 7, 0, 0, 0, 1, 0, '2025-12-11 14:27:57', NULL, 0),
(18, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(18, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(18, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(19, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(19, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(19, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(20, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(20, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(20, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(21, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(21, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(21, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(22, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(22, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(22, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(23, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(23, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(23, 7, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 0),
(24, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(25, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(26, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(27, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(28, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(29, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(30, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(31, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(31, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(32, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(32, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(33, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(33, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(34, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(34, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(35, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(35, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(36, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(36, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(37, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(38, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(39, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(40, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(41, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(42, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(42, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(42, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(42, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(42, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(43, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(43, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(43, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(43, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(43, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(44, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(44, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(44, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(44, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(44, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(45, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(45, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(45, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(45, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(45, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(46, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(46, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(46, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(46, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(46, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(47, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(47, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(47, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(47, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(47, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(48, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(48, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(48, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(48, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(48, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(49, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(49, 4, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(49, 5, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(49, 6, 1, 1, 0, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(49, 7, 0, 0, 0, 1, 0, '2025-12-15 03:45:31', NULL, 0),
(50, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(51, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(52, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(53, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(54, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(54, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(54, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(54, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(55, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(55, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(55, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(55, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(56, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(56, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(56, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(56, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(57, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(57, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(57, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(57, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(58, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(58, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(58, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(58, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(59, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(59, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(59, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(59, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(60, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(60, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(60, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(60, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(61, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(61, 2, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(61, 3, 1, 1, 1, 1, 1, '2025-12-15 03:45:31', NULL, 1),
(61, 37, 0, 0, 0, 1, 1, '2025-12-15 03:45:31', NULL, 0),
(62, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(63, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(64, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(65, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(66, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(67, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(68, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(69, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(70, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1),
(71, 1, 1, 1, 1, 1, 1, '2025-12-11 14:13:45', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `feuille_anesthesie`
--

CREATE TABLE `feuille_anesthesie` (
  `idfeuille_anesth` int NOT NULL,
  `idintervention` int NOT NULL,
  `classification_asa` enum('ASA1','ASA2','ASA3','ASA4','ASA5','ASA6') COLLATE utf8mb4_unicode_ci DEFAULT 'ASA1',
  `intubation_difficile` tinyint(1) DEFAULT '0',
  `risques_particuliers` text COLLATE utf8mb4_unicode_ci,
  `type_anesthesie` enum('locale','locoregionale','generale','sedation','sans') COLLATE utf8mb4_unicode_ci DEFAULT 'generale',
  `agents_anesthesiques` text COLLATE utf8mb4_unicode_ci,
  `curares_utilises` text COLLATE utf8mb4_unicode_ci,
  `ventilation_mode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parametres_monitorage` text COLLATE utf8mb4_unicode_ci,
  `incidents_anesthesie` text COLLATE utf8mb4_unicode_ci,
  `drogues_administrees` text COLLATE utf8mb4_unicode_ci,
  `volumes_perfuses` text COLLATE utf8mb4_unicode_ci,
  `transfusions` text COLLATE utf8mb4_unicode_ci,
  `heure_fin_anesthesie` time DEFAULT NULL,
  `qualite_reveil` enum('excellent','bon','moyen','mauvais') COLLATE utf8mb4_unicode_ci DEFAULT 'bon',
  `complications_reveil` text COLLATE utf8mb4_unicode_ci,
  `score_aldrete` int DEFAULT NULL,
  `valide` tinyint(1) DEFAULT '0',
  `date_validation` datetime DEFAULT NULL,
  `idanesthesiste` int DEFAULT NULL,
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fournisseur`
--

CREATE TABLE `fournisseur` (
  `idfournisseur` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `personne_contact` varchar(100) DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `fournisseur`
--

INSERT INTO `fournisseur` (`idfournisseur`, `nom`, `adresse`, `telephone`, `email`, `personne_contact`, `actif`) VALUES
(1, 'PHARMAKINA', 'Kinshasa', '+243 XXX XXX XXX', 'contact@pharmakina.cd', NULL, 1),
(2, 'DIPHARMA', 'Kinshasa', '+243 XXX XXX XXX', 'info@dipharma.cd', NULL, 1),
(3, 'PHARMACO', 'Kinshasa', '+243 XXX XXX XXX', 'vente@pharmaco.cd', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `frm_prod`
--

CREATE TABLE `frm_prod` (
  `idfrm_prod` int NOT NULL,
  `nom` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `frm_prod`
--

INSERT INTO `frm_prod` (`idfrm_prod`, `nom`) VALUES
(1, 'Comprim?'),
(2, 'G?lule'),
(3, 'Sirop'),
(4, 'Injectable'),
(5, 'Suppositoire'),
(6, 'Pommade'),
(7, 'Cr?me'),
(8, 'Sachet'),
(9, 'Flacon'),
(10, 'Ampoule'),
(11, 'Comprim?'),
(12, 'Sirop');

-- --------------------------------------------------------

--
-- Table structure for table `groupe_prescriptions`
--

CREATE TABLE `groupe_prescriptions` (
  `id_groupe_prescription` int NOT NULL,
  `code_prescription` varchar(50) NOT NULL COMMENT 'Code unique ex: PRESC-20260218-8472',
  `idsous_sejour` int NOT NULL,
  `prescripteur` int NOT NULL COMMENT 'idutilisateur du médecin',
  `date_prescription` datetime DEFAULT CURRENT_TIMESTAMP,
  `observation_generale` text COMMENT 'Observation globale pour tous les actes',
  `urgence` tinyint(1) DEFAULT '0',
  `statut` enum('en_attente','en_cours','termine','annule') DEFAULT 'en_attente',
  `date_validation` datetime DEFAULT NULL COMMENT 'Date génération PDF final',
  `pdf_genere` tinyint(1) DEFAULT '0',
  `chemin_pdf` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Prescriptions groupées — un code = plusieurs actes labo/imagerie/pharma';

-- --------------------------------------------------------

--
-- Table structure for table `grsanguin`
--

CREATE TABLE `grsanguin` (
  `idgrsanguin` int NOT NULL,
  `nom` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `grsanguin`
--

INSERT INTO `grsanguin` (`idgrsanguin`, `nom`) VALUES
(4, 'A-'),
(3, 'A+'),
(8, 'AB-'),
(7, 'AB+'),
(6, 'B-'),
(5, 'B+'),
(2, 'O-'),
(1, 'O+');

-- --------------------------------------------------------

--
-- Table structure for table `historique_transfert_hospi`
--

CREATE TABLE `historique_transfert_hospi` (
  `idhistorique` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `ancien_idunitehospi` int DEFAULT NULL,
  `nouveau_idunitehospi` int NOT NULL,
  `ancien_idlit` int DEFAULT NULL,
  `nouveau_idlit` int DEFAULT NULL,
  `motif` text,
  `type_transfert` enum('hospitalisation','urgence') DEFAULT 'hospitalisation',
  `idinfirmiere_transfert` int DEFAULT NULL,
  `date_transfert` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `image_i`
--

CREATE TABLE `image_i` (
  `idimage` int NOT NULL,
  `idactes_presc` int NOT NULL,
  `technique_utilisee` text NOT NULL,
  `description_images` text NOT NULL,
  `conclusion` text NOT NULL,
  `recommandations` text,
  `date_examen` datetime DEFAULT CURRENT_TIMESTAMP,
  `radiologue` int NOT NULL,
  `compte_rendu` text,
  `image_path` varchar(500) DEFAULT NULL,
  `fichier_externe` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventaire_ajustements`
--

CREATE TABLE `inventaire_ajustements` (
  `idajustement` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `idofficine` int NOT NULL,
  `quantite_theorique` int NOT NULL,
  `quantite_reelle` int NOT NULL,
  `ecart` int NOT NULL,
  `observation` text,
  `idutilisateur` int NOT NULL,
  `date_ajustement` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `labo_controle_qualite`
--

CREATE TABLE `labo_controle_qualite` (
  `idcontrole_qualite` int NOT NULL,
  `idmachinelabo` int NOT NULL,
  `date_controle` datetime DEFAULT CURRENT_TIMESTAMP,
  `type_controle` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parametre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur_obtenue` decimal(10,2) NOT NULL,
  `valeur_attendue` decimal(10,2) NOT NULL,
  `ecart` decimal(10,2) NOT NULL,
  `conforme` tinyint(1) DEFAULT '1',
  `operateur` int NOT NULL,
  `observations` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `labo_numerotation_bons`
--

CREATE TABLE `labo_numerotation_bons` (
  `id` int NOT NULL,
  `annee` year NOT NULL,
  `dernier_numero` int UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `labo_numerotation_bons`
--

INSERT INTO `labo_numerotation_bons` (`id`, `annee`, `dernier_numero`) VALUES
(1, '2026', 8);

-- --------------------------------------------------------

--
-- Table structure for table `labo_prelevements`
--

CREATE TABLE `labo_prelevements` (
  `idprelevement` int NOT NULL,
  `idactes_presc` int NOT NULL,
  `preleveur` int NOT NULL,
  `date_prelevement` datetime DEFAULT CURRENT_TIMESTAMP,
  `type_prelevement` varchar(50) NOT NULL,
  `tubes_utilises` varchar(50) NOT NULL,
  `observations` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `labo_prelevements`
--

INSERT INTO `labo_prelevements` (`idprelevement`, `idactes_presc`, `preleveur`, `date_prelevement`, `type_prelevement`, `tubes_utilises`, `observations`) VALUES
(1, 47, 1, '2026-01-20 15:09:34', 'sang_capillaire', 'tube_rouge', 'RAS'),
(2, 57, 1, '2026-02-17 08:24:42', 'sang_capillaire', 'tube_vert', '');

-- --------------------------------------------------------

--
-- Table structure for table `labo_valeurs_normales`
--

CREATE TABLE `labo_valeurs_normales` (
  `id` int NOT NULL,
  `idacte` int NOT NULL COMMENT 'FK → acte.idacte',
  `parametre` varchar(150) NOT NULL COMMENT 'Libellé du paramètre, ex : Hémoglobine',
  `unite` varchar(30) DEFAULT NULL COMMENT 'Unité : g/dL, UI/L…',
  `valeur_min_homme` decimal(12,4) DEFAULT NULL,
  `valeur_max_homme` decimal(12,4) DEFAULT NULL,
  `valeur_min_femme` decimal(12,4) DEFAULT NULL,
  `valeur_max_femme` decimal(12,4) DEFAULT NULL,
  `valeur_min_enfant` decimal(12,4) DEFAULT NULL,
  `valeur_max_enfant` decimal(12,4) DEFAULT NULL,
  `valeur_normale_texte` text COMMENT 'Texte affiché dans le champ "valeur normale"',
  `format_resultat` text COMMENT 'Modèle pré-rempli dans la zone résultat',
  `ordre` tinyint DEFAULT '1' COMMENT 'Ordre si plusieurs paramètres pour un même acte',
  `actif` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Valeurs de référence normales par acte labo — pré-remplissage automatique du formulaire résultats';

--
-- Dumping data for table `labo_valeurs_normales`
--

INSERT INTO `labo_valeurs_normales` (`id`, `idacte`, `parametre`, `unite`, `valeur_min_homme`, `valeur_max_homme`, `valeur_min_femme`, `valeur_max_femme`, `valeur_min_enfant`, `valeur_max_enfant`, `valeur_normale_texte`, `format_resultat`, `ordre`, `actif`, `created_at`) VALUES
(1, 52, 'Hémoglobine (Hb)', 'g/dL', 13.0000, 17.0000, 12.0000, 16.0000, 11.0000, 15.0000, 'H : 13–17 g/dL | F : 12–16 g/dL | Enfant : 11–15 g/dL', 'Hémoglobine (Hb) : [valeur] g/dL', 1, 1, '2026-02-17 12:12:17'),
(2, 52, 'Hématocrite (Ht)', '%', 40.0000, 54.0000, 36.0000, 46.0000, 33.0000, 44.0000, 'H : 40–54 % | F : 36–46 % | Enfant : 33–44 %', 'Hématocrite (Ht) : [valeur] %', 2, 1, '2026-02-17 12:12:17'),
(3, 52, 'Globules rouges (GR)', '×10⁶/µL', 4.5000, 5.9000, 4.0000, 5.4000, 3.8000, 5.2000, 'H : 4.5–5.9 | F : 4.0–5.4 | Enfant : 3.8–5.2 ×10⁶/µL', 'Globules rouges (GR) : [valeur] ×10⁶/µL', 3, 1, '2026-02-17 12:12:17'),
(4, 52, 'Globules blancs (GB)', '×10³/µL', 4.0000, 10.0000, 4.0000, 10.0000, 6.0000, 15.0000, '4.0–10.0 ×10³/µL (Enfant : 6–15)', 'Globules blancs (GB) : [valeur] ×10³/µL', 4, 1, '2026-02-17 12:12:17'),
(5, 52, 'Plaquettes', '×10³/µL', 150.0000, 400.0000, 150.0000, 400.0000, 150.0000, 400.0000, '150–400 ×10³/µL', 'Plaquettes : [valeur] ×10³/µL', 5, 1, '2026-02-17 12:12:17'),
(6, 53, 'Glycémie à jeun', 'g/L', 0.7000, 1.1000, 0.7000, 1.1000, 0.6000, 1.0000, 'H/F adulte : 0.70–1.10 g/L | Enfant : 0.60–1.00 g/L', 'Glycémie à jeun : [valeur] g/L ([valeur ×10 = mg/dL])', 1, 1, '2026-02-17 12:12:17'),
(7, 54, 'Urée sanguine', 'g/L', 0.1500, 0.4500, 0.1500, 0.4500, NULL, NULL, '0.15–0.45 g/L', 'Urée : [valeur] g/L', 1, 1, '2026-02-17 12:12:17'),
(8, 54, 'Créatinémie', 'mg/L', 7.0000, 13.0000, 5.0000, 11.0000, NULL, NULL, 'H : 7–13 mg/L | F : 5–11 mg/L', 'Créatinine : [valeur] mg/L', 2, 1, '2026-02-17 12:12:17'),
(9, 55, 'ASAT (TGO)', 'UI/L', 0.0000, 40.0000, 0.0000, 35.0000, NULL, NULL, 'H : <40 UI/L | F : <35 UI/L', 'ASAT (TGO) : [valeur] UI/L', 1, 1, '2026-02-17 12:12:17'),
(10, 55, 'ALAT (TGP)', 'UI/L', 0.0000, 41.0000, 0.0000, 31.0000, NULL, NULL, 'H : <41 UI/L | F : <31 UI/L', 'ALAT (TGP) : [valeur] UI/L', 2, 1, '2026-02-17 12:12:17'),
(11, 55, 'Bilirubine totale', 'mg/L', 0.0000, 10.0000, 0.0000, 10.0000, NULL, NULL, '<10 mg/L (1 mg/dL)', 'Bilirubine totale : [valeur] mg/L', 3, 1, '2026-02-17 12:12:17'),
(12, 55, 'Phosphatases alcalines', 'UI/L', 40.0000, 130.0000, 35.0000, 105.0000, NULL, NULL, 'H : 40–130 UI/L | F : 35–105 UI/L', 'Phosphatases alcalines : [valeur] UI/L', 4, 1, '2026-02-17 12:12:17'),
(13, 56, 'Sodium (Na)', 'mmol/L', 135.0000, 145.0000, 135.0000, 145.0000, NULL, NULL, '135–145 mmol/L', 'Sodium (Na) : [valeur] mmol/L', 1, 1, '2026-02-17 12:12:17'),
(14, 56, 'Potassium (K)', 'mmol/L', 3.5000, 5.0000, 3.5000, 5.0000, NULL, NULL, '3.5–5.0 mmol/L', 'Potassium (K) : [valeur] mmol/L', 2, 1, '2026-02-17 12:12:17'),
(15, 56, 'Chlorures (Cl)', 'mmol/L', 98.0000, 106.0000, 98.0000, 106.0000, NULL, NULL, '98–106 mmol/L', 'Chlorures (Cl) : [valeur] mmol/L', 3, 1, '2026-02-17 12:12:17'),
(16, 56, 'Bicarbonates', 'mmol/L', 22.0000, 29.0000, 22.0000, 29.0000, NULL, NULL, '22–29 mmol/L', 'Bicarbonates (HCO₃) : [valeur] mmol/L', 4, 1, '2026-02-17 12:12:17'),
(17, 57, 'CRP (Protéine C Réactive)', 'mg/L', 0.0000, 6.0000, 0.0000, 6.0000, NULL, NULL, '<6 mg/L (normale) | 6–40 : inflammation modérée | >40 : infection bactérienne', 'CRP : [valeur] mg/L', 1, 1, '2026-02-17 12:12:17'),
(18, 58, 'VS 1ère heure', 'mm/h', 0.0000, 15.0000, 0.0000, 20.0000, NULL, NULL, 'H : <15 mm/h | F : <20 mm/h', 'VS 1ère heure : [valeur] mm/h\r\nVS 2ème heure : [valeur] mm/h', 1, 1, '2026-02-17 12:12:17'),
(19, 59, 'TP (Taux de Prothrombine)', '%', 70.0000, 100.0000, 70.0000, 100.0000, NULL, NULL, '70–100 %', 'TP : [valeur] %', 1, 1, '2026-02-17 12:12:17'),
(20, 59, 'TCA', 's', 25.0000, 35.0000, 25.0000, 35.0000, NULL, NULL, '25–35 secondes', 'TCA : [valeur] s (Témoin : [valeur] s)', 2, 1, '2026-02-17 12:12:17'),
(21, 59, 'INR', NULL, 0.8000, 1.2000, 0.8000, 1.2000, NULL, NULL, '0.8–1.2 (sous anticoagulants : 2–3)', 'INR : [valeur]', 3, 1, '2026-02-17 12:12:17'),
(22, 60, 'β-HCG quantitatif', 'mUI/mL', NULL, NULL, NULL, 5.0000, NULL, NULL, 'Non enceinte : <5 mUI/mL | S4 : 10–750 | S6 : 1 000–10 000 | S8-10 : 25 000–300 000', 'β-HCG : [valeur] mUI/mL\r\nInterprétation : [Positif ≥5 / Négatif <5]', 1, 1, '2026-02-17 12:12:17'),
(23, 61, 'Leucocytes urinaires', '/mm³', 0.0000, 10.0000, 0.0000, 10.0000, NULL, NULL, '<10/mm³', 'Leucocytes : [valeur] /mm³', 1, 1, '2026-02-17 12:12:17'),
(24, 61, 'Hématies urinaires', '/mm³', 0.0000, 5.0000, 0.0000, 5.0000, NULL, NULL, '<5/mm³', 'Hématies : [valeur] /mm³', 2, 1, '2026-02-17 12:12:17'),
(25, 61, 'Germes / Bactériologie', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Pas de germe significatif (<10³ UFC/mL)', 'Bactériologie : [positif/négatif]\r\nGerme : [espèce si positif]\r\nAntibiogramme : [résultats]', 3, 1, '2026-02-17 12:12:17'),
(26, 62, 'Cytologie vaginale', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal : flore lactobacillaire, épithélium normal', 'Flore : [lactobacillaire / dysbasique / mixte]\r\nCellules : [normal / koïlocytes / cellules atypiques]\r\nGermes : [Candida / Trichomonas / autres si présents]\r\nConclusion : [normale / anormale]', 1, 1, '2026-02-17 12:12:17'),
(27, 63, 'Bactériologie des selles', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal : flore commensale, absence de germe pathogène', 'Aspect des selles : [normal / liquide / pâteux / sang]\r\nExamen direct : [parasites / levures / bactéries atypiques]\r\nCulture : [positive / négative]\r\nGerme isolé : [si positif]\r\nAntibiogramme : [résultats si positif]', 1, 1, '2026-02-17 12:12:17'),
(28, 64, 'Groupage ABO-Rhésus', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Groupe ABO : [A / B / AB / O]\r\nRhésus (Rh) : [Positif (+) / Négatif (-)]\r\nRAI (Recherche d\'agglutinines irrégulières) : [positive / négative]', 1, 1, '2026-02-17 12:12:17'),
(29, 65, 'Sérologie VIH 1 & 2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Résultat normal : Négatif', 'Test rapide VIH 1/2 : [Positif / Négatif]\r\nWestern Blot (si positif) : [confirmatoire]\r\nConclusion : [Séronégatif / Séropositif]', 1, 1, '2026-02-17 12:12:17'),
(30, 66, 'Sérologie Hépatite B', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Normal : AgHBs négatif, Ac anti-HBc négatif, Ac anti-HBs >10 UI/L si vacciné', 'AgHBs : [positif / négatif]\r\nAc anti-HBc : [positif / négatif]\r\nAc anti-HBs : [valeur] UI/L\r\nADN VHB (si AgHBs+) : [charge virale]\r\nConclusion : [non infecté / infecté / immunisé]', 1, 1, '2026-02-17 12:12:18'),
(31, 86, 'Frottis sanguin / Goutte épaisse', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Résultat normal : absence de Plasmodium, frottis sans anomalie', 'Goutte épaisse : [positive / négative]\r\nEspèce Plasmodium : [P. falciparum / P. vivax / P. malariae / P. ovale / néant]\r\nDensité parasitaire : [valeur] trophozoïtes/µL\r\nStade : [trophozoïte / schizonte / gamétocyte]\r\nFrottis sanguin (anomalies GR) : [drépanocytes / anisocytose / autres / RAS]', 1, 1, '2026-02-17 12:12:18'),
(32, 94, 'Cholestérol total', 'g/L', 0.0000, 2.0000, 0.0000, 2.0000, NULL, NULL, '<2.0 g/L (<5.2 mmol/L)', 'Cholestérol total : [valeur] g/L', 1, 1, '2026-02-17 12:12:18'),
(33, 94, 'HDL-Cholestérol', 'g/L', 0.4000, NULL, 0.5000, NULL, NULL, NULL, 'H : >0.40 g/L | F : >0.50 g/L', 'HDL-Cholestérol : [valeur] g/L', 2, 1, '2026-02-17 12:12:18'),
(34, 94, 'LDL-Cholestérol', 'g/L', 0.0000, 1.3000, 0.0000, 1.3000, NULL, NULL, '<1.30 g/L (si risque CV élevé : <1.0)', 'LDL-Cholestérol : [valeur] g/L', 3, 1, '2026-02-17 12:12:18'),
(35, 94, 'Triglycérides', 'g/L', 0.0000, 1.5000, 0.0000, 1.5000, NULL, NULL, '<1.50 g/L', 'Triglycérides : [valeur] g/L', 4, 1, '2026-02-17 12:12:18'),
(36, 96, 'Urée', 'g/L', 0.1500, 0.4500, 0.1500, 0.4500, NULL, NULL, '0.15–0.45 g/L', 'Urée : [valeur] g/L', 1, 1, '2026-02-17 12:12:18'),
(37, 96, 'Créatinémie', 'mg/L', 7.0000, 13.0000, 5.0000, 11.0000, NULL, NULL, 'H : 7–13 mg/L | F : 5–11 mg/L', 'Créatinine : [valeur] mg/L', 2, 1, '2026-02-17 12:12:18'),
(38, 96, 'Clairance créatinine', 'mL/min', 60.0000, NULL, 60.0000, NULL, NULL, NULL, '>60 mL/min/1.73m² (DFG normal >90)', 'DFG / Clairance : [valeur] mL/min/1.73m²', 3, 1, '2026-02-17 12:12:18'),
(39, 96, 'Acide urique (Uricémie)', 'mg/L', 25.0000, 70.0000, 20.0000, 60.0000, NULL, NULL, 'H : 25–70 mg/L | F : 20–60 mg/L', 'Uricémie : [valeur] mg/L', 4, 1, '2026-02-17 12:12:18'),
(40, 97, 'ASAT (TGO)', 'UI/L', 0.0000, 40.0000, 0.0000, 35.0000, NULL, NULL, 'H : <40 UI/L | F : <35 UI/L', 'ASAT (TGO) : [valeur] UI/L', 1, 1, '2026-02-17 12:12:18'),
(41, 97, 'ALAT (TGP)', 'UI/L', 0.0000, 41.0000, 0.0000, 31.0000, NULL, NULL, 'H : <41 UI/L | F : <31 UI/L', 'ALAT (TGP) : [valeur] UI/L', 2, 1, '2026-02-17 12:12:18'),
(42, 97, 'GGT', 'UI/L', 0.0000, 55.0000, 0.0000, 35.0000, NULL, NULL, 'H : <55 UI/L | F : <35 UI/L', 'GGT : [valeur] UI/L', 3, 1, '2026-02-17 12:12:18'),
(43, 97, 'Bilirubine totale', 'mg/L', 0.0000, 10.0000, 0.0000, 10.0000, NULL, NULL, '<10 mg/L (totale)', 'Bilirubine totale : [valeur] mg/L — Conjuguée : [valeur] — Libre : [valeur]', 4, 1, '2026-02-17 12:12:18'),
(44, 97, 'Albumine', 'g/L', 35.0000, 50.0000, 35.0000, 50.0000, NULL, NULL, '35–50 g/L', 'Albumine : [valeur] g/L', 5, 1, '2026-02-17 12:12:18'),
(45, 98, 'Calcémie', 'mg/L', 85.0000, 105.0000, 85.0000, 105.0000, NULL, NULL, '85–105 mg/L (2.1–2.6 mmol/L)', 'Calcémie : [valeur] mg/L', 1, 1, '2026-02-17 12:12:18'),
(46, 98, 'Phosphorémie', 'mg/L', 25.0000, 45.0000, 25.0000, 45.0000, NULL, NULL, '25–45 mg/L (0.8–1.45 mmol/L)', 'Phosphorémie : [valeur] mg/L', 2, 1, '2026-02-17 12:12:18'),
(47, 98, 'Phosphatases alc.', 'UI/L', 40.0000, 130.0000, 35.0000, 105.0000, NULL, NULL, 'H : 40–130 UI/L | F : 35–105 UI/L', 'Phosphatases alcalines : [valeur] UI/L', 3, 1, '2026-02-17 12:12:18'),
(48, 99, 'Troponine I', 'ng/L', 0.0000, 34.0000, 0.0000, 16.0000, NULL, NULL, 'H : <34 ng/L | F : <16 ng/L (selon méthode)', 'Troponine I (Tn I) : [valeur] ng/L', 1, 1, '2026-02-17 12:12:18'),
(49, 99, 'CK-MB', 'UI/L', 0.0000, 25.0000, 0.0000, 25.0000, NULL, NULL, '<25 UI/L', 'CK-MB : [valeur] UI/L', 2, 1, '2026-02-17 12:12:18'),
(50, 99, 'Myoglobine', 'µg/L', 0.0000, 92.0000, 0.0000, 76.0000, NULL, NULL, 'H : <92 µg/L | F : <76 µg/L', 'Myoglobine : [valeur] µg/L', 3, 1, '2026-02-17 12:12:18'),
(51, 100, 'PSA total', 'ng/mL', 0.0000, 4.0000, NULL, NULL, NULL, NULL, 'H : <4 ng/mL (H>50 ans : <6.5)', 'PSA total : [valeur] ng/mL', 1, 1, '2026-02-17 12:12:18'),
(52, 100, 'ACE', 'ng/mL', 0.0000, 5.0000, 0.0000, 5.0000, NULL, NULL, '<5 ng/mL (fumeur : <10)', 'ACE : [valeur] ng/mL', 2, 1, '2026-02-17 12:12:18'),
(53, 100, 'CA 15-3', 'UI/mL', NULL, NULL, 0.0000, 38.0000, NULL, NULL, 'F : <38 UI/mL', 'CA 15-3 : [valeur] UI/mL', 3, 1, '2026-02-17 12:12:18'),
(54, 100, 'CA 19-9', 'UI/mL', 0.0000, 37.0000, 0.0000, 37.0000, NULL, NULL, '<37 UI/mL', 'CA 19-9 : [valeur] UI/mL', 4, 1, '2026-02-17 12:12:18'),
(55, 101, 'Cortisol (8h)', 'µg/L', 60.0000, 230.0000, 60.0000, 230.0000, NULL, NULL, '60–230 µg/L (matin)', 'Cortisol (8h) : [valeur] µg/L', 1, 1, '2026-02-17 12:12:18'),
(56, 101, 'Testostérone', 'ng/L', 280.0000, 1100.0000, 15.0000, 70.0000, NULL, NULL, 'H : 280–1100 ng/L | F : 15–70 ng/L', 'Testostérone : [valeur] ng/L', 2, 1, '2026-02-17 12:12:18'),
(57, 101, 'Œstradiol (E2)', 'pg/mL', NULL, NULL, 20.0000, 400.0000, NULL, NULL, 'F phase folliculaire : 20–150 | ovulation : 150–400 | ménopause : <30', 'Œstradiol (E2) : [valeur] pg/mL', 3, 1, '2026-02-17 12:12:18');

-- --------------------------------------------------------

--
-- Table structure for table `ligneentree`
--

CREATE TABLE `ligneentree` (
  `identreprod` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `quantite` int NOT NULL,
  `prix_achat` decimal(10,2) NOT NULL,
  `montant_total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `ligneentree`
--

INSERT INTO `ligneentree` (`identreprod`, `idprodpharma`, `quantite`, `prix_achat`, `montant_total`) VALUES
(1, 1, 1000, 50.00, 50000.00),
(1, 2, 500, 200.00, 100000.00);

-- --------------------------------------------------------

--
-- Table structure for table `lignesortieprod`
--

CREATE TABLE `lignesortieprod` (
  `idsortieprod` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `quantite` int NOT NULL,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `observation` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lignesrecquisition`
--

CREATE TABLE `lignesrecquisition` (
  `idrequisition` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `quantite_demandee` int NOT NULL,
  `quantite_servie` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `lignesrecquisition`
--

INSERT INTO `lignesrecquisition` (`idrequisition`, `idprodpharma`, `quantite_demandee`, `quantite_servie`) VALUES
(1, 1, 100, 100),
(1, 2, 50, 50),
(3, 2, 3, 0),
(3, 3, 5, 0),
(4, 2, 40, 0);

-- --------------------------------------------------------

--
-- Table structure for table `lit`
--

CREATE TABLE `lit` (
  `idlit` int NOT NULL,
  `idchambre` int NOT NULL,
  `numero` varchar(20) NOT NULL,
  `statut` enum('libre','occupe','reserve','hors_service') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'libre',
  `idsous_sejour` int DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `observation` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `lit`
--

INSERT INTO `lit` (`idlit`, `idchambre`, `numero`, `statut`, `idsous_sejour`, `actif`, `observation`, `created_at`, `updated_at`) VALUES
(1, 1, 'L1', 'libre', NULL, 1, 'Lit 1 de la chambre P101', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(2, 1, 'L2', 'libre', NULL, 1, 'Lit 2 de la chambre P101', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(3, 2, 'L1', 'libre', NULL, 1, 'Lit unique de la chambre isol?e M203', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(4, 3, 'L1', 'libre', NULL, 1, 'Lit VIP avec confort premium', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(5, 4, 'L1', 'libre', NULL, 1, 'Lit unique salle de stabilisation', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(6, 5, 'L1', 'libre', NULL, 1, 'Lit 1 chambre collective K304', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(7, 5, 'L2', 'libre', NULL, 1, 'Lit 2 chambre collective K304', '2025-12-12 14:24:05', '2025-12-12 14:24:05'),
(8, 5, 'L3', 'libre', NULL, 1, 'Lit 3 chambre collective K304', '2025-12-12 14:24:05', '2025-12-12 14:24:05');

-- --------------------------------------------------------

--
-- Table structure for table `logs_connexion`
--

CREATE TABLE `logs_connexion` (
  `idlog` int NOT NULL,
  `idutilisateur` int NOT NULL,
  `date_connexion` datetime NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('success','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logs_connexion`
--

INSERT INTO `logs_connexion` (`idlog`, `idutilisateur`, `date_connexion`, `ip_address`, `user_agent`, `statut`) VALUES
(1, 1, '2025-12-15 15:37:24', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(2, 1, '2025-12-15 16:38:37', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(3, 1, '2025-12-15 17:04:10', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(4, 1, '2025-12-15 17:05:59', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(5, 1, '2025-12-15 17:36:46', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(6, 1, '2025-12-15 19:34:24', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(7, 1, '2025-12-15 19:42:03', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(8, 1, '2025-12-15 21:58:25', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(9, 1, '2025-12-16 07:52:47', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(10, 1, '2025-12-17 14:45:20', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(11, 1, '2025-12-17 20:29:09', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(12, 1, '2025-12-18 09:20:07', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(13, 1, '2025-12-19 10:12:21', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(14, 1, '2025-12-27 08:14:19', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(15, 1, '2026-01-02 06:51:05', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(16, 1, '2026-01-14 11:33:02', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', 'success'),
(17, 1, '2026-01-18 07:00:34', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(18, 1, '2026-01-21 08:09:46', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(19, 1, '2026-01-21 15:18:38', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(20, 1, '2026-01-23 11:49:32', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(21, 1, '2026-01-23 14:29:40', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(22, 1, '2026-02-10 23:05:07', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(23, 1, '2026-02-11 09:49:54', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(24, 1, '2026-02-11 13:23:39', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(25, 1, '2026-02-11 13:54:26', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(26, 1, '2026-02-11 13:55:24', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(27, 1, '2026-02-11 13:59:18', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(28, 28, '2026-02-11 14:01:14', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(29, 1, '2026-02-11 14:06:48', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(30, 28, '2026-02-11 14:07:06', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(31, 28, '2026-02-11 17:30:25', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(32, 1, '2026-02-11 17:42:58', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(33, 1, '2026-02-11 17:44:14', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(34, 28, '2026-02-11 21:12:13', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(35, 28, '2026-02-11 23:22:30', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(36, 28, '2026-02-12 07:22:44', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(37, 1, '2026-02-12 07:23:03', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(38, 28, '2026-02-12 07:44:30', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(39, 1, '2026-02-12 07:44:59', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(40, 1, '2026-02-13 07:33:06', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(41, 1, '2026-02-13 10:52:25', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(42, 1, '2026-02-13 11:45:20', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(43, 28, '2026-02-13 12:15:25', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(44, 1, '2026-02-13 12:30:33', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(45, 1, '2026-02-13 14:55:02', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36', 'success'),
(46, 1, '2026-02-16 05:16:46', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(47, 1, '2026-02-16 09:00:04', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(48, 1, '2026-02-17 01:31:47', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(49, 1, '2026-02-17 08:21:41', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(50, 28, '2026-02-17 08:28:05', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(51, 1, '2026-02-17 08:30:05', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(52, 28, '2026-02-17 12:15:31', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(53, 1, '2026-02-17 12:15:39', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(54, 1, '2026-02-19 03:02:39', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(55, 1, '2026-02-19 04:03:21', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(56, 1, '2026-02-19 09:37:33', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(57, 1, '2026-02-19 10:42:27', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(58, 1, '2026-02-19 15:12:20', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(59, 1, '2026-02-19 18:36:14', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(60, 1, '2026-02-20 11:39:53', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(61, 1, '2026-02-20 23:48:42', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(62, 1, '2026-02-21 10:50:10', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(63, 1, '2026-02-22 07:25:19', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(64, 1, '2026-02-23 01:57:29', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(65, 1, '2026-02-23 03:22:51', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(66, 1, '2026-02-23 04:30:20', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(67, 1, '2026-02-24 03:46:51', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(68, 1, '2026-02-25 00:58:54', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(69, 1, '2026-02-25 03:49:12', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(70, 1, '2026-02-25 05:04:07', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(71, 1, '2026-02-25 12:38:46', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(72, 1, '2026-02-25 15:49:34', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(73, 28, '2026-02-26 18:07:13', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(74, 1, '2026-02-26 18:37:01', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(75, 1, '2026-02-26 19:27:27', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(76, 28, '2026-02-26 19:42:58', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(77, 1, '2026-02-26 19:48:40', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(78, 1, '2026-02-27 04:35:07', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(79, 1, '2026-03-04 15:44:57', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(80, 1, '2026-03-05 09:53:56', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(81, 1, '2026-03-05 18:23:53', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(82, 1, '2026-03-05 23:35:28', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(83, 1, '2026-03-06 10:13:58', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(84, 1, '2026-03-06 12:16:28', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(85, 1, '2026-03-07 08:37:31', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success'),
(86, 1, '2026-03-08 00:57:03', '172.20.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', 'success');

-- --------------------------------------------------------

--
-- Table structure for table `machineslabo`
--

CREATE TABLE `machineslabo` (
  `idmachinelabo` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `marque` varchar(100) DEFAULT NULL,
  `modele` varchar(100) DEFAULT NULL,
  `description` text,
  `actif` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` varchar(20) DEFAULT 'operationnelle',
  `fabricant` varchar(100) DEFAULT NULL,
  `numero_serie` varchar(100) DEFAULT NULL,
  `date_acquisition` date DEFAULT NULL,
  `date_derniere_maintenance` date DEFAULT NULL,
  `observations` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `machineslabo`
--

INSERT INTO `machineslabo` (`idmachinelabo`, `nom`, `marque`, `modele`, `description`, `actif`, `created_at`, `statut`, `fabricant`, `numero_serie`, `date_acquisition`, `date_derniere_maintenance`, `observations`) VALUES
(1, 'Sysmex XN-1000', 'Sysmex', 'XN-1000', NULL, 1, '2025-12-16 01:18:49', 'operationnelle', 'Sysmex', NULL, NULL, NULL, NULL),
(2, 'Cobas Integra 400', 'Roche', 'Integra 400', NULL, 1, '2025-12-16 01:18:49', 'operationnelle', 'Roche', NULL, NULL, NULL, NULL),
(3, 'URIT 8020', 'URIT', '8020', NULL, 1, '2025-12-16 01:18:49', 'operationnelle', 'URIT', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `machineslabo_maintenance`
--

CREATE TABLE `machineslabo_maintenance` (
  `idmaintenance` int NOT NULL,
  `idmachinelabo` int NOT NULL,
  `date_maintenance` date NOT NULL,
  `type_maintenance` enum('preventive','corrective','calibration','reparation') NOT NULL,
  `technicien` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `cout` decimal(15,2) DEFAULT NULL,
  `prochain_entretien` date DEFAULT NULL,
  `date_enregistrement` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `motif`
--

CREATE TABLE `motif` (
  `idmotif` int NOT NULL,
  `libelle` varchar(255) DEFAULT NULL,
  `type_sejour` enum('ambulatoire','urgence','hospitalisation') NOT NULL,
  `motif_autre` text,
  `actif` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `motif`
--

INSERT INTO `motif` (`idmotif`, `libelle`, `type_sejour`, `motif_autre`, `actif`) VALUES
(1, 'Fi?vre', 'ambulatoire', NULL, 1),
(2, 'Toux', 'ambulatoire', NULL, 1),
(3, 'Douleurs abdominales', 'ambulatoire', NULL, 1),
(4, 'Contr?le m?dical', 'ambulatoire', NULL, 1),
(5, 'Vaccination', 'ambulatoire', NULL, 1),
(6, 'Suivi de grossesse', 'ambulatoire', NULL, 1),
(7, 'Accident', 'urgence', NULL, 1),
(8, 'Malaise', 'urgence', NULL, 1),
(9, 'Accouchement', 'hospitalisation', NULL, 1),
(10, 'Chirurgie programm?e', 'hospitalisation', NULL, 1),
(11, 'Sortie de la salle d\'op', 'hospitalisation', 'Douleur atroce', 1),
(12, 'Transfert externe', 'hospitalisation', 'intervention rapide', 1),
(13, 'Angoisse', 'ambulatoire', NULL, 1),
(14, NULL, 'urgence', 'urgence urgente', 1),
(15, NULL, 'urgence', 'Nouveau cas d\'urgence', 1),
(16, NULL, 'urgence', 'Ballonnement', 1),
(17, NULL, 'ambulatoire', 'Maux de dent', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notes_evolution`
--

CREATE TABLE `notes_evolution` (
  `idnote` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `date_note` datetime DEFAULT NULL,
  `type_note` enum('medicale','infirmiere','kine') DEFAULT NULL,
  `contenu` text,
  `idutilisateur` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `idnotification` int NOT NULL,
  `idutilisateur` int DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `titre` varchar(100) DEFAULT NULL,
  `message` text,
  `lu` tinyint(1) NOT NULL DEFAULT '0',
  `date_lecture` datetime DEFAULT NULL,
  `lien` varchar(255) DEFAULT NULL,
  `priorite` varchar(20) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `date_notification` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`idnotification`, `idutilisateur`, `type`, `titre`, `message`, `lu`, `date_lecture`, `lien`, `priorite`, `metadata`, `date_notification`) VALUES
(1, 3, 'info', 'Nouvelle prescription laboratoire', 'Analyse prescrite : H?mogramme Complet (NFS) pour MBALA Jean - URGENT', 0, NULL, '../laboratoire/index.php', 'haute', NULL, '2025-12-07 13:41:34'),
(2, 3, 'info', 'Nouvelle prescription laboratoire', 'Analyse prescrite : H?mogramme Complet (NFS) pour MBALA Jean - URGENT', 0, NULL, '../laboratoire/index.php', 'haute', NULL, '2025-12-07 13:43:29'),
(3, 3, 'info', 'Nouvelle prescription laboratoire', 'Analyse prescrite : H?mogramme Complet (NFS) pour MBALA Jean - URGENT', 0, NULL, '../laboratoire/index.php', 'haute', NULL, '2025-12-07 13:46:28'),
(4, 2, 'success', 'R?sultat disponible', 'R?sultat disponible pour NKULU Pierre : Glyc?mie', 0, NULL, NULL, 'haute', NULL, '2025-12-07 13:46:28'),
(5, 4, 'info', 'Nouvelle prescription imagerie', 'Examen prescrit : ?chographie Abdominale pour KASONGO Marie', 0, NULL, '../imagerie/index.php', 'normale', NULL, '2025-12-07 13:46:28'),
(6, 5, 'warning', 'Nouvelle prescription m?dicamenteuse URGENTE', 'M?dicament prescrit : PARACETAMOL 500mg x20 pour MBALA Jean', 0, NULL, '../pharmacie/officine.php', 'haute', NULL, '2025-12-07 13:46:28'),
(7, 1, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=8', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:15:20'),
(8, 2, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=8', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:15:20'),
(9, 4, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=8', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:15:20'),
(10, 5, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=8', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:15:20'),
(11, 6, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=8', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:15:20'),
(12, 1, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=9', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:16:56'),
(13, 2, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=9', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:16:56'),
(14, 4, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=9', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:16:56'),
(15, 5, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=9', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:16:56'),
(16, 6, 'info', 'Nouvelle prescription m?dicamenteuse', 'M?dicament prescrit : ASPIRINE 100mg x1', 0, NULL, '../pharmacie/delivrer.php?id=9', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-10 10:16:56'),
(17, 1, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 1, '2025-12-18 04:11:22', '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(18, 2, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(19, 5, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(20, 6, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(21, 7, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(22, 8, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(23, 9, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(24, 10, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=10', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": 1}', '2025-12-13 14:40:37'),
(25, 1, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(26, 2, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(27, 5, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(28, 6, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(29, 7, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(30, 8, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(31, 9, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(32, 10, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : COMPRESSE STERILE x1', 0, NULL, '../pharmacie/delivrer.php?id=11', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-02 06:37:16'),
(33, 1, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(34, 2, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(35, 5, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(36, 6, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(37, 7, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(38, 8, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(39, 9, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(40, 10, 'info', 'Nouvelle prescription médicamenteuse', 'Médicament prescrit : AMOXICILLINE 500mg x1', 0, NULL, '../pharmacie/delivrer.php?id=12', 'normale', '{\"service\": \"pharmacie\", \"prescripteur_id\": \"1\"}', '2026-01-06 13:35:11'),
(41, 2, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Radiographie Abdomen sans Préparation - 8 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=41', 'normale', NULL, '2026-01-20 15:00:53'),
(42, 1, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Radiographie Abdomen sans Préparation - 8 000,00 FC', 1, '2026-01-20 15:01:18', '../facturation/validation.php?sous_sejour_id=41', 'normale', NULL, '2026-01-20 15:00:53'),
(43, 4, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Radiographie Abdomen sans Préparation - 8 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=41', 'normale', NULL, '2026-01-20 15:00:53'),
(44, 3, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Radiographie Abdomen sans Préparation - 8 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=41', 'normale', NULL, '2026-01-20 15:00:53'),
(45, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : TP, TCA, INR - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=53&type=laboratoire', 'haute', NULL, '2026-02-11 09:50:33'),
(46, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : TP, TCA, INR - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=53&type=laboratoire', 'haute', NULL, '2026-02-11 09:50:33'),
(47, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : TP, TCA, INR - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=53&type=laboratoire', 'haute', NULL, '2026-02-11 09:50:33'),
(48, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : TP, TCA, INR - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=53&type=laboratoire', 'haute', NULL, '2026-02-11 09:50:33'),
(49, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Urée et Créatinine - 4 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=54&type=laboratoire', 'haute', NULL, '2026-02-11 17:45:35'),
(50, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Urée et Créatinine - 4 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=54&type=laboratoire', 'haute', NULL, '2026-02-11 17:45:35'),
(51, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Urée et Créatinine - 4 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=54&type=laboratoire', 'haute', NULL, '2026-02-11 17:45:35'),
(52, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Urée et Créatinine - 4 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=54&type=laboratoire', 'haute', NULL, '2026-02-11 17:45:35'),
(53, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Ionogramme Sanguin - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=56&type=laboratoire', 'haute', NULL, '2026-02-17 01:32:39'),
(54, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Ionogramme Sanguin - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=56&type=laboratoire', 'haute', NULL, '2026-02-17 01:32:39'),
(55, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Ionogramme Sanguin - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=56&type=laboratoire', 'haute', NULL, '2026-02-17 01:32:39'),
(56, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Ionogramme Sanguin - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=56&type=laboratoire', 'haute', NULL, '2026-02-17 01:32:39'),
(57, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=53', 'haute', NULL, '2026-02-17 08:05:26'),
(58, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=53', 'haute', NULL, '2026-02-17 08:31:04'),
(59, 1, 'success', 'Résultat d\'analyse disponible', 'Résultat disponible pour Boris Ikula - TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=53', 'haute', NULL, '2026-02-17 08:31:04'),
(60, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Échographie Testiculaire', 0, NULL, '../laboratoire/voir-resultat.php?id=48', 'haute', NULL, '2026-02-17 12:15:02'),
(61, 1, 'success', 'Résultat labo disponible — Échographie Testiculaire', '✅ Normal | Patient : Boris Ikula | Acte : Échographie Testiculaire (ECHO-TEST) | Saisi par : Papy KIBETE', 1, '2026-02-21 00:59:03', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20250211-0002', 'haute', NULL, '2026-02-17 12:15:02'),
(62, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Test de Grossesse Sanguin (β-HCG)', 0, NULL, '../laboratoire/voir-resultat.php?id=59', 'haute', NULL, '2026-02-19 18:35:54'),
(63, 28, 'warning', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '⚠️ Anormal | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Papy KIBETE', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-0007', 'haute', NULL, '2026-02-19 18:35:54'),
(64, 1, 'pharmacie', 'Prescription délivrée - Patient: Jean Ikula', 'Le médicament COMPRESSE STERILE (1 Unité) a été délivré au patient.', 1, '2026-02-21 00:59:19', 'index.php?page=prescriptions&id=11', 'normale', NULL, '2026-02-21 00:57:14'),
(65, 1, 'success', '💊 Prescription délivrée — ASPIRINE 100mg', 'Patient : Jean Ikula | Produit : ASPIRINE 100mg (ASP100) | Quantité : 1 | Délivré par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=pharmacie&action=preparations&code=PHAR-20260219-0001', 'normale', NULL, '2026-02-21 01:06:20'),
(66, 1, 'success', '💊 Prescription délivrée — PARACETAMOL 500mg', 'Patient : Jean Ikula | Produit : PARACETAMOL 500mg (PARA500) | Quantité : 1 | Délivré par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=pharmacie&action=preparations&code=PHAR-20260219-0002', 'normale', NULL, '2026-02-21 01:47:26'),
(67, 1, 'success', '💊 Prescription délivrée — ASPIRINE 100mg', 'Patient : Jean Ikula | Produit : ASPIRINE 100mg (ASP100) | Quantité : 1 | Délivré par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=pharmacie&action=preparations&code=PHAR-20260221-0001', 'normale', NULL, '2026-02-21 01:54:08'),
(68, 1, 'success', '💊 Prescription délivrée — COMPRESSE STERILE', 'Patient : Jean Ikula | Produit : COMPRESSE STERILE (COMP-ST) | Quantité : 5 | Délivré par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=pharmacie&action=preparations&code=PHAR-20260221-0002', 'normale', NULL, '2026-02-21 02:56:19'),
(69, 1, 'success', '💊 Prescription délivrée — PARACETAMOL 500mg', 'Patient : Jean Ikula | Produit : PARACETAMOL 500mg (PARA500) | Quantité : 1 | Délivré par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=pharmacie&action=preparations&code=PHAR-20260221-0003', 'normale', NULL, '2026-02-21 03:18:40'),
(70, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Sérologie Hépatite B', 0, NULL, '../laboratoire/voir-resultat.php?id=47', 'haute', NULL, '2026-02-21 08:40:16'),
(71, 1, 'success', 'Résultat labo disponible — Sérologie Hépatite B', 'Disponible | Patient : Boris Ikula | Acte : Sérologie Hépatite B (SERO-HBV) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20250211-0001', 'haute', NULL, '2026-02-21 08:40:16'),
(72, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Urée et Créatinine', 0, NULL, '../laboratoire/voir-resultat.php?id=54', 'haute', NULL, '2026-02-21 09:18:32'),
(73, 1, 'success', 'Résultat labo disponible — Urée et Créatinine', 'Disponible | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260211-0002', 'haute', NULL, '2026-02-21 09:18:32'),
(74, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Urée et Créatinine', 0, NULL, '../laboratoire/voir-resultat.php?id=57', 'haute', NULL, '2026-02-21 09:48:47'),
(75, 28, 'success', 'Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-0003', 'haute', NULL, '2026-02-21 09:48:47'),
(76, 28, 'success', 'Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-0003', 'haute', NULL, '2026-02-21 09:48:54'),
(77, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=65', 'haute', NULL, '2026-02-21 09:54:49'),
(78, 28, 'success', 'Résultat labo disponible — TP, TCA, INR', 'Disponible | Patient : Boris Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260219-0004', 'haute', NULL, '2026-02-21 09:54:54'),
(79, 28, 'success', 'Résultat labo disponible — TP, TCA, INR', 'Disponible | Patient : Boris Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260219-0004', 'haute', NULL, '2026-02-21 09:55:00'),
(80, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=65', 'haute', NULL, '2026-02-21 10:11:59'),
(81, 28, 'success', 'Résultat labo disponible — TP, TCA, INR', '✅ Normal | Patient : Boris Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260219-0003', 'haute', NULL, '2026-02-21 10:11:59'),
(82, 28, 'success', 'Résultat labo disponible — TP, TCA, INR', '✅ Normal | Patient : Boris Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260219-0003', 'haute', NULL, '2026-02-21 10:15:15'),
(83, 28, 'success', 'Résultat labo disponible — TP, TCA, INR', '✅ Normal | Patient : Boris Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260219-0003', 'haute', NULL, '2026-02-21 10:15:39'),
(88, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : VS (Vitesse de Sédimentation)', 0, NULL, '../laboratoire/voir-resultat.php?id=62', 'haute', NULL, '2026-02-21 11:39:48'),
(89, 1, 'success', 'Résultat labo disponible — VS (Vitesse de Sédimentation)', 'Disponible | Patient : Jean Ikula | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260218-0002', 'haute', NULL, '2026-02-21 11:39:48'),
(90, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Test de Grossesse Sanguin (β-HCG)', 0, NULL, '../laboratoire/voir-resultat.php?id=59', 'haute', NULL, '2026-02-21 11:41:55'),
(91, 28, 'success', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', 'Disponible | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-3415', 'haute', NULL, '2026-02-21 11:41:55'),
(92, 28, 'warning', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '⚠️ Anormal | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-0007', 'haute', NULL, '2026-02-21 11:42:24'),
(93, 28, 'warning', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '⚠️ Anormal | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-0007', 'haute', NULL, '2026-02-21 11:42:32'),
(94, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : VS (Vitesse de Sédimentation)', 0, NULL, '../laboratoire/voir-resultat.php?id=62', 'haute', NULL, '2026-02-21 11:49:43'),
(95, 1, 'warning', '🔬 Résultat labo disponible — VS (Vitesse de Sédimentation)', '⚠️ Anormal | Patient : Jean Ikula | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', 1, '2026-02-21 10:53:55', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260218-0001', 'haute', NULL, '2026-02-21 11:49:43'),
(96, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=60', 'haute', NULL, '2026-02-21 12:13:59'),
(97, 28, 'success', '🔬 Résultat labo disponible — TP, TCA, INR', '✅ Normal | Patient : Jean Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin | Lien public : http://localhost:8002/public/resultats.php?token=0985bc913ba3a554c3f7d70d97e5048e4744d7e6a77f0b0cb168af3acbc1f13b', 0, NULL, 'http://localhost:8002/public/resultats.php?token=0985bc913ba3a554c3f7d70d97e5048e4744d7e6a77f0b0cb168af3acbc1f13b', 'haute', NULL, '2026-02-21 12:13:59'),
(98, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Coproculture', 0, NULL, '../laboratoire/voir-resultat.php?id=63', 'haute', NULL, '2026-02-22 07:27:54'),
(99, 1, 'success', '🔬 Résultat labo disponible — Coproculture', '✅ Normal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', 1, '2026-02-22 07:40:35', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260218-0004', 'haute', NULL, '2026-02-22 07:27:54'),
(100, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : TP, TCA, INR', 0, NULL, '../laboratoire/voir-resultat.php?id=60', 'haute', NULL, '2026-02-22 08:24:45'),
(101, 28, 'success', '🔬 Résultat labo disponible — TP, TCA, INR', '✅ Normal | Patient : Jean Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-3416', 'haute', NULL, '2026-02-22 08:24:45'),
(102, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Frottis Vaginal', 0, NULL, '../laboratoire/voir-resultat.php?id=58', 'haute', NULL, '2026-02-22 08:39:29'),
(103, 28, 'warning', '🔬 Résultat labo disponible — Frottis Vaginal', '⚠️ Anormal | Patient : Jean Ikula | Acte : Frottis Vaginal (FROT-VAG) | Saisi par : Système Admin | 🔗 http://localhost:8002/public/resultats.php?token=ec52b85537fb237808788b5b301c5e3cef98ad71746145458448745a3517c97f', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-3414', 'haute', NULL, '2026-02-22 08:39:30'),
(104, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Frottis Vaginal | 🔗 http://localhost:8002/public/resultats.php?token=7b524944e9e0124f09dbbf780010fbfd4be6a833ba55cc6ef10b451c20451935', 0, NULL, '../laboratoire/voir-resultat.php?id=58', 'haute', NULL, '2026-02-23 02:57:07'),
(105, 28, 'success', '🔬 Résultat labo disponible — Frottis Vaginal', '✅ Normal | Patient : Jean Ikula | Acte : Frottis Vaginal (FROT-VAG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260217-0005', 'haute', NULL, '2026-02-23 02:57:07'),
(106, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Bilan Hépatique Complet - 8 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=66&type=laboratoire', 'haute', NULL, '2026-02-23 01:58:20'),
(107, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Bilan Hépatique Complet - 8 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=66&type=laboratoire', 'haute', NULL, '2026-02-23 01:58:20'),
(108, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Bilan Hépatique Complet - 8 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=66&type=laboratoire', 'haute', NULL, '2026-02-23 01:58:20'),
(109, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Bilan Hépatique Complet - 8 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=66&type=laboratoire', 'haute', NULL, '2026-02-23 01:58:20'),
(110, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Bilan Hépatique Complet | 🔗 http://localhost:8002/public/resultats.php?token=c24f18b1526c1de30d2436f3bfdd9239d084462588e3e41e6724134ea687078c', 0, NULL, '../laboratoire/voir-resultat.php?id=66', 'haute', NULL, '2026-02-23 02:59:53'),
(111, 1, 'warning', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 1, '2026-02-23 02:00:32', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260223-0001', 'haute', NULL, '2026-02-23 02:59:53'),
(112, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : ECBU (Examen Cyto-Bactériologique Urines) - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=67&type=laboratoire', 'haute', NULL, '2026-02-23 02:20:49'),
(113, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : ECBU (Examen Cyto-Bactériologique Urines) - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=67&type=laboratoire', 'haute', NULL, '2026-02-23 02:20:49'),
(114, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : ECBU (Examen Cyto-Bactériologique Urines) - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=67&type=laboratoire', 'haute', NULL, '2026-02-23 02:20:49'),
(115, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : ECBU (Examen Cyto-Bactériologique Urines) - 6 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=67&type=laboratoire', 'haute', NULL, '2026-02-23 02:20:49'),
(116, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : ECBU (Examen Cyto-Bactériologique Urines)', 0, NULL, '../laboratoire/voir-resultat.php?id=67', 'haute', NULL, '2026-02-23 03:21:30'),
(117, 1, 'danger', '🔬 Résultat labo disponible — ECBU (Examen Cyto-Bactériologique Urines)', '🔴 CRITIQUE | Patient : Boris Ikula | Acte : ECBU (Examen Cyto-Bactériologique Urines) (ECBU) | Saisi par : Système Admin | 📄 PDF: http://localhost:8002/public/generer_pdf_resultat_unique.php?token=a48903643b79abef8786d1a2537577bba6898db2de12b508481517278de5a945 | 🔗 http://localhost:8002/public/resultats.php?token=a48903643b79abef8786d1a2537577bba6898db2de12b508481517278de5a945', 1, '2026-02-23 02:21:57', 'http://localhost:8002/public/resultats.php?token=a48903643b79abef8786d1a2537577bba6898db2de12b508481517278de5a945', 'urgente', NULL, '2026-02-23 03:21:31'),
(118, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Urée et Créatinine | 🔗 http://localhost:8002/public/resultats.php?token=0334eea7d7d06cd37fb2e92c05df81b69c8e25132db47735b3e46447721dbd65', 0, NULL, '../laboratoire/voir-resultat.php?id=68', 'haute', NULL, '2026-02-23 03:35:56'),
(119, 1, 'success', '🔬 Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Jean Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin | 📄 PDF: http://localhost:8002/public/generer_pdf_resultat_unique.php?token=0334eea7d7d06cd37fb2e92c05df81b69c8e25132db47735b3e46447721dbd65', 1, '2026-02-23 02:54:02', 'http://localhost:8002/public/resultats.php?token=0334eea7d7d06cd37fb2e92c05df81b69c8e25132db47735b3e46447721dbd65', 'haute', NULL, '2026-02-23 03:35:56'),
(120, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Ionogramme Sanguin | 🔗 http://localhost:8002/public/resultats.php?token=9daa54a415f8ac904c636aaaf960c4ca729416b6c2b4e5c88b6cb220704a44e5', 0, NULL, '../laboratoire/voir-resultat.php?id=69', 'haute', NULL, '2026-02-23 04:22:15'),
(121, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', '✅ Normal | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin | 📄 PDF: http://localhost:8002/public/generer_pdf_resultat_unique.php?token=9daa54a415f8ac904c636aaaf960c4ca729416b6c2b4e5c88b6cb220704a44e5', 1, '2026-02-23 04:19:49', 'http://localhost:8002/public/resultats.php?token=9daa54a415f8ac904c636aaaf960c4ca729416b6c2b4e5c88b6cb220704a44e5', 'haute', NULL, '2026-02-23 04:22:15'),
(122, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Urée et Créatinine | 🔗 http://localhost:8002/public/resultats.php?token=cd6aa1e5b595d5bfcb5c1d5edcb7898de07e831bc32b2b383411856da8cd4445', 0, NULL, '../laboratoire/voir-resultat.php?id=70', 'haute', NULL, '2026-02-23 05:30:04'),
(123, 1, 'success', '🔬 Résultat labo disponible — Urée et Créatinine', 'Disponible | Patient : Jean Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin | 📄 PDF: http://localhost:8002/public/generer_pdf_resultat_unique.php?token=cd6aa1e5b595d5bfcb5c1d5edcb7898de07e831bc32b2b383411856da8cd4445', 1, '2026-02-23 04:30:22', 'http://localhost:8002/public/resultats.php?token=cd6aa1e5b595d5bfcb5c1d5edcb7898de07e831bc32b2b383411856da8cd4445', 'haute', NULL, '2026-02-23 05:30:04'),
(124, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Ionogramme Sanguin', 0, NULL, '../laboratoire/voir-resultat.php?id=71', 'haute', NULL, '2026-02-23 05:40:50'),
(125, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', 1, '2026-02-23 05:03:44', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=abe3941f2f0683f2a61cb956ff9f0a092c7f2835b415480ed090b68eb25f524b', 'haute', NULL, '2026-02-23 05:40:50'),
(126, 28, 'success', '🔬 Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', 'Disponible | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=c3763a78f7024d49e645f440653b0baa516e446bec130b15d32c357700005e2f', 'haute', NULL, '2026-02-23 06:05:06'),
(127, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', 1, '2026-02-23 05:11:02', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=4fff5a9d3e917509150be1225a8c882247427816c0cb34f7539e5869ec798622', 'haute', NULL, '2026-02-23 06:08:42'),
(128, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', 1, '2026-02-23 05:11:22', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=0289752ea9e22e8a3a540feddb426652c74047800212034312f542e28846c1a4', 'haute', NULL, '2026-02-23 06:10:17'),
(129, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', 1, '2026-02-23 05:15:17', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=3ecebae04a2401091a81cb735fe2d0104d636607605b8c79cded3f894f6d4436', 'haute', NULL, '2026-02-23 06:15:08'),
(130, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Jean Ikula | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', 1, '2026-02-25 05:23:32', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=3ee89a48b0154de43d6d87f4960ae0f5f606ef63ac0c3c66fe35eac1a61b467b', 'haute', NULL, '2026-02-23 06:22:52'),
(131, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Coproculture - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=77&type=laboratoire', 'haute', NULL, '2026-02-24 03:48:24'),
(132, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Coproculture - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=77&type=laboratoire', 'haute', NULL, '2026-02-24 03:48:24'),
(133, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Coproculture - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=77&type=laboratoire', 'haute', NULL, '2026-02-24 03:48:24'),
(134, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Coproculture - 7 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=77&type=laboratoire', 'haute', NULL, '2026-02-24 03:48:24'),
(135, 2, 'warning', 'Nouvelle prescription à valider (Pharmacie)', 'Médicament à valider : AMOXICILLINE 500mg x1 - 400,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:48:38'),
(136, 1, 'warning', 'Nouvelle prescription à valider (Pharmacie)', 'Médicament à valider : AMOXICILLINE 500mg x1 - 400,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:48:38'),
(137, 4, 'warning', 'Nouvelle prescription à valider (Pharmacie)', 'Médicament à valider : AMOXICILLINE 500mg x1 - 400,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:48:38'),
(138, 3, 'warning', 'Nouvelle prescription à valider (Pharmacie)', 'Médicament à valider : AMOXICILLINE 500mg x1 - 400,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:48:38'),
(139, 2, 'warning', 'Nouvel acte médical à valider', 'Acte à valider : Bilan Hépatique Élargi x1 - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:58:29'),
(140, 1, 'warning', 'Nouvel acte médical à valider', 'Acte à valider : Bilan Hépatique Élargi x1 - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:58:29'),
(141, 4, 'warning', 'Nouvel acte médical à valider', 'Acte à valider : Bilan Hépatique Élargi x1 - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:58:29'),
(142, 3, 'warning', 'Nouvel acte médical à valider', 'Acte à valider : Bilan Hépatique Élargi x1 - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-24 03:58:29'),
(143, 2, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Mammaire - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=42', 'normale', NULL, '2026-02-24 07:36:20'),
(144, 1, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Mammaire - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=42', 'normale', NULL, '2026-02-24 07:36:20'),
(145, 4, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Mammaire - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=42', 'normale', NULL, '2026-02-24 07:36:20'),
(146, 3, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Mammaire - 12 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=42', 'normale', NULL, '2026-02-24 07:36:20'),
(147, 2, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Obstétricale - 10 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-25 00:59:30'),
(148, 1, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Obstétricale - 10 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-25 00:59:30'),
(149, 4, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Obstétricale - 10 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-25 00:59:30'),
(150, 3, 'warning', 'Nouvelle prescription à valider (Imagerie)', 'Examen à valider : Échographie Obstétricale - 10 000,00 FC', 0, NULL, '../facturation/validation.php?sous_sejour_id=44', 'normale', NULL, '2026-02-25 00:59:30'),
(151, 28, 'success', '🔬 Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=7776101356c0a34c39cabb261334862cc04ce651e81bf6ec5c551a42097af9af', 'haute', NULL, '2026-02-25 04:35:41'),
(152, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', 1, '2026-02-25 03:49:18', 'http://localhost:8002/public/resultat_imagerie.php?token=d098d90f4d6c49c31410023b61387ebe4f7a0a655016d245feeed67769175b52', 'haute', NULL, '2026-02-25 04:47:18'),
(153, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', 1, '2026-02-25 04:13:28', 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=3d565c696a5fbf5bd076347b856de3c08ed768a9c11d9f5f5b21410ede4fab17', 'haute', NULL, '2026-02-25 04:53:46'),
(154, 28, 'success', '🔬 Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', 'Disponible | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=d7b0818c01b32dd561ac1aa1ee36a75b141f5e9a88c9f2d0e37c74b4c75ad27d', 'haute', NULL, '2026-02-25 04:57:46'),
(155, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0004', 1, '2026-02-25 04:13:49', 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=b21e790dad20dcb3e12355cc73517192bd62f275747bae5d92411ae189823a66', 'haute', NULL, '2026-02-25 05:09:00'),
(156, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0004', 1, '2026-02-25 04:12:47', 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=5df98da777d5a9bb448d8db5e5a4a3854f32d84a4a7e7f27dd5f07d3f5d5b5da', 'haute', NULL, '2026-02-25 05:09:00'),
(157, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=c3eca7675de710589c20030328b139fc14946250c1fe4d9a2b74a01e67fb4690', 'haute', NULL, '2026-02-25 06:03:39'),
(158, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', 1, '2026-02-25 05:04:21', 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=b9c73a51b2fab9288d7c9cc4b6bcb38efa85fc3b5ac28984edb26e807a4904b2', 'haute', NULL, '2026-02-25 06:03:39'),
(159, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=6df86b1c064cf72c3e77ee75376d2d2a33d60f0a035f63967b2474d5eabad9f4', 'haute', NULL, '2026-02-25 06:18:55'),
(160, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Boris - Échographie Obstétricale | Code: IMG-20260225-0001', 1, '2026-02-25 05:23:07', 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=41012c0f2fa092b92ce1ee0bf78645b7836fa9878ed6f1e60bd4cc13296c9b9d', 'haute', NULL, '2026-02-25 06:18:55'),
(161, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=00805465541b5ac656c4cb0d6021b3c71e86f54eb5297e7fd637489552f3b556', 'haute', NULL, '2026-02-25 13:02:09'),
(162, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=2f2581fa52216e681697e80872da82c507d976dc1fc325155abe7b56a960b753', 'haute', NULL, '2026-02-25 13:02:10'),
(163, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 1, '2026-02-25 12:38:51', 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=26830d27e1128f2f0a8d1b9d92c5808f5e328eba8681ccc03fb7c7aaf1760e66', 'haute', NULL, '2026-02-25 13:38:00'),
(164, 2, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Frottis Vaginal - 5 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=87&type=laboratoire', 'haute', NULL, '2026-02-25 15:49:59'),
(165, 1, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Frottis Vaginal - 5 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=87&type=laboratoire', 'haute', NULL, '2026-02-25 15:49:59'),
(166, 4, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Frottis Vaginal - 5 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=87&type=laboratoire', 'haute', NULL, '2026-02-25 15:49:59'),
(167, 3, 'warning', 'Nouvelle prescription à valider (Laboratoire)', 'Analyse à valider : Frottis Vaginal - 5 000,00 FC', 0, NULL, '../facturation/validation.php?id_prescription=87&type=laboratoire', 'haute', NULL, '2026-02-25 15:49:59'),
(168, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Frottis Vaginal', 0, NULL, '../laboratoire/voir-resultat.php?id=87', 'haute', NULL, '2026-02-25 16:51:44'),
(169, 1, 'warning', '🔬 Résultat labo disponible — Frottis Vaginal', '⚠️ Anormal | Patient : Boris Ikula | Acte : Frottis Vaginal (FROT-VAG) | Saisi par : Système Admin', 1, '2026-02-25 15:54:00', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=dc4a43b382f86b7e445da8f74b1223b05db31418e6afedad3fc924bfe5e19e0a', 'haute', NULL, '2026-02-25 16:51:44'),
(170, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Sérologie VIH', 0, NULL, '../laboratoire/voir-resultat.php?id=88', 'haute', NULL, '2026-02-25 16:56:46'),
(171, 1, 'success', '🔬 Résultat labo disponible — Sérologie VIH', 'Disponible | Patient : Jean Ikula | Acte : Sérologie VIH (SERO-VIH) | Saisi par : Système Admin', 1, '2026-02-26 18:37:18', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=0492043952c83520c9aa5d0617bfe5e0891a3e8864ca8c2ea52e327465cc8aa3', 'haute', NULL, '2026-02-25 16:56:46'),
(172, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=081ff5e6f9f62abda93e317e66f5e0d5dfa7f444f074716bac2fe9436e946336', 'haute', NULL, '2026-02-25 17:41:46'),
(173, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:13:18'),
(174, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:16:31'),
(175, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=e9f72e02679259453158c3752e5231d79c26393dc142ef58a340c2c09f68ca89', 'haute', NULL, '2026-02-26 03:18:01'),
(176, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=3320d58517b2008001a51913e8cd0e18fbf03d2fc7556a001de3164a2e0500c7', 'haute', NULL, '2026-02-26 03:19:03'),
(177, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:21:24'),
(178, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:28:15'),
(179, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:30:53'),
(180, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:33:54'),
(181, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:34:20'),
(182, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:35:53'),
(183, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=d7c2be00149535bb5eb7dc4a44dd1a54f5274d35b5a7a186fd4a8d27b10accf6', 'haute', NULL, '2026-02-26 03:37:13'),
(184, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=af147ae46f6fc878293e72dbb1eabf399123902bf2d14bef27633b90ef1434dc', 'haute', NULL, '2026-02-26 03:38:26'),
(185, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:40:05'),
(186, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:40:49'),
(187, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, NULL, 'haute', NULL, '2026-02-26 03:43:19'),
(188, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/generer_pdf_imagerie_unique.php?token=b84244845f43d9e1d9ef6964b861686d3fb4bed006e5a328c47e28a745f79d6b', 'haute', NULL, '2026-02-26 03:50:41'),
(189, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour Ikula Jean - Échographie Abdominale | Code: IMG-20260224-0006', 0, NULL, 'http://localhost:8002/public/consultation_imagerie.php?token=7ffdefb24af35c445267b320c7f805d8', 'haute', NULL, '2026-02-26 15:50:35'),
(190, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Hémogramme Complet (NFS)', 0, NULL, '../laboratoire/voir-resultat.php?id=90', 'haute', NULL, '2026-02-26 19:05:56'),
(191, 28, 'success', '🔬 Résultat labo disponible — Hémogramme Complet (NFS)', '✅ Normal | Patient : Jean Ikula | Acte : Hémogramme Complet (NFS) (NFS) | Saisi par : Papy KIBETE', 1, '2026-02-26 18:07:36', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=5e7dec715d9679460d8d4e72e9454a13ba66c76df5d0cac96e808c86fb8add09', 'haute', NULL, '2026-02-26 19:05:56');
INSERT INTO `notifications` (`idnotification`, `idutilisateur`, `type`, `titre`, `message`, `lu`, `date_lecture`, `lien`, `priorite`, `metadata`, `date_notification`) VALUES
(192, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour MBALA Jean - Échographie Thyroïdienne | Code: IMG-20260226-0002', 1, '2026-02-26 19:42:43', 'http://localhost:8002/public/consultation_imagerie.php?token=3f9b4a5034345d57d9e318eaa4f5976d', 'haute', NULL, '2026-02-26 19:26:27'),
(193, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Coproculture', 0, NULL, '../laboratoire/voir-resultat.php?id=89', 'haute', NULL, '2026-02-26 20:26:53'),
(194, 28, 'warning', '🔬 Résultat labo disponible — Coproculture', '⚠️ Anormal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Papy KIBETE', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=f746968bed27a192fccd24521aad85f4ab80a47be75ccce5c7b281f0b358f2df', 'haute', NULL, '2026-02-26 20:26:54'),
(195, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour KASONGO : Urée et Créatinine', 0, NULL, '../laboratoire/voir-resultat.php?id=98', 'haute', NULL, '2026-02-26 20:35:12'),
(196, 28, 'success', '🔬 Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Marie KASONGO | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Papy KIBETE', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=8d87c2dd7494b22146d258c633c38b2429742016d3c8617396d3bbcd3cec6e7c', 'haute', NULL, '2026-02-26 20:35:12'),
(197, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Coproculture', 0, NULL, '../laboratoire/voir-resultat.php?id=89', 'haute', NULL, '2026-02-26 20:42:07'),
(198, 28, 'success', '🔬 Résultat labo disponible — Coproculture', 'Disponible | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Papy KIBETE', 1, '2026-02-26 19:43:05', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=b5ec1e5073078802677f8769ef42a64639775a00b8d3b0f54da1ce7531db9985', 'haute', NULL, '2026-02-26 20:42:07'),
(199, 1, 'info', '🔬 Résultat d\'imagerie disponible', 'Résultat disponible pour MBALA Jean - Échographie Thyroïdienne | Code: IMG-20260226-0001', 1, '2026-02-26 19:48:44', 'http://localhost:8002/public/consultation_imagerie.php?token=9b51998774de61ef5effa6154db45de2', 'haute', NULL, '2026-02-26 20:48:24'),
(200, 1, 'warning', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 1, '2026-02-27 04:35:13', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=9f953f06d56bca3bd107ab7a1b767224059e6c2cc12b818634f1295f53c1328d', 'haute', NULL, '2026-02-27 05:34:43'),
(201, 1, 'warning', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=59230a1714819722fbec749efb73a6316a4ae620b80ea610d15664265810dd17', 'haute', NULL, '2026-02-27 05:34:46'),
(202, 1, 'warning', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '⚠️ Anormal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 1, '2026-02-27 04:39:37', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=ecb6fa1575ab9b4e6012aab46b5b4e61e5ddf0a47c4aafc59e3a66fe8135e2c7', 'haute', NULL, '2026-02-27 05:39:25'),
(203, 1, 'danger', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '🔴 CRITIQUE | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=99b021ba3838a2621f4060959598a594cc850c533b75492c1c86dc60db6e442b', 'urgente', NULL, '2026-02-27 05:53:12'),
(204, 1, 'danger', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '🔴 CRITIQUE | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 1, '2026-02-27 04:54:22', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=ddee6610b5c1451fdfc7f9f97c427bef977fffd86ba6c7f3ae617b8a8b84ab62', 'urgente', NULL, '2026-02-27 05:53:16'),
(205, 28, 'success', '🔬 Résultat labo disponible — Coproculture', 'Disponible | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=23526f7f5e8aa6283312b16c3cd702a221bfae4821368f67e3ea1d657811b1b6', 'haute', NULL, '2026-02-27 05:53:33'),
(206, 1, 'success', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '✅ Normal | Patient : Boris Ikula | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=08700f0a2a2153bd0725dc81a5fb72eec28869c4fd41238876dd05a0c86a78c3', 'haute', NULL, '2026-02-27 05:55:15'),
(207, 28, 'warning', '🔬 Résultat labo disponible — Coproculture', '⚠️ Anormal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=d09defaeac089492c8bb69e512754ae273cdc3b9a924d094a105d8074f00c9ba', 'haute', NULL, '2026-02-27 14:38:13'),
(208, 28, 'warning', '🔬 Résultat labo disponible — Coproculture', '⚠️ Anormal | Patient : Jean Ikula | Acte : Coproculture (COPRO) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=502edfa9e142a5ee49cf0deda778d056623ebe4614ffdf672f890bcd5dce57e3', 'haute', NULL, '2026-02-27 18:53:02'),
(209, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour kasaka : Hémogramme Complet (NFS)', 0, NULL, '../laboratoire/voir-resultat.php?id=104', 'haute', NULL, '2026-03-04 14:57:18'),
(210, 1, 'success', '🔬 Résultat labo — Hémogramme Complet (NFS)', 'Patient: Sacha kasaka | Saisi par: Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260227-0002', 'haute', NULL, '2026-03-04 14:57:18'),
(211, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour kasaka : Ionogramme Sanguin', 0, NULL, '../laboratoire/voir-resultat.php?id=105', 'haute', NULL, '2026-03-04 14:57:18'),
(212, 1, 'success', '🔬 Résultat labo — Ionogramme Sanguin', 'Patient: Sacha kasaka | Saisi par: Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260227-0002', 'haute', NULL, '2026-03-04 14:57:18'),
(213, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour kasaka : Urée et Créatinine', 0, NULL, '../laboratoire/voir-resultat.php?id=106', 'haute', NULL, '2026-03-04 14:57:18'),
(214, 1, 'success', '🔬 Résultat labo — Urée et Créatinine', 'Patient: Sacha kasaka | Saisi par: Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260227-0002', 'haute', NULL, '2026-03-04 14:57:18'),
(215, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour MBALA : Test de Grossesse Sanguin (β-HCG)', 0, NULL, '../laboratoire/voir-resultat.php?id=116', 'haute', NULL, '2026-03-04 15:16:39'),
(216, 1, 'success', '🔬 Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '✅ Normal | Patient : Jean MBALA | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 1, '2026-03-05 10:08:57', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=6fdca4c37f47483b0c5643b7af7cfae068f395ae53beae5903b6be4bb1340846', 'haute', NULL, '2026-03-04 15:16:39'),
(217, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour MBALA : TP, TCA, INR', 1, '2026-03-05 10:08:42', '../laboratoire/voir-resultat.php?id=117', 'haute', NULL, '2026-03-04 16:37:43'),
(218, 1, 'warning', '🔬 Résultat labo — TP, TCA, INR', 'Patient: Jean MBALA | Saisi par: Système Admin', 1, '2026-03-05 09:58:32', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260304-0001', 'haute', NULL, '2026-03-04 16:37:43'),
(219, 28, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Hémogramme Complet (NFS)', 0, NULL, '../laboratoire/voir-resultat.php?id=90', 'haute', NULL, '2026-03-05 10:53:01'),
(220, 28, 'success', '🔬 Résultat labo — Hémogramme Complet (NFS)', 'Disponible | Patient : Jean Ikula | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260226-0003', 'haute', NULL, '2026-03-05 10:53:02'),
(221, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour Ikula : Sérologie VIH', 1, '2026-03-05 10:13:27', '../laboratoire/voir-resultat.php?id=88', 'haute', NULL, '2026-03-05 11:10:56'),
(222, 1, 'success', '🔬 Résultat labo disponible — Sérologie VIH', '✅ Normal | Patient : Jean Ikula | Acte : Sérologie VIH (SERO-VIH) | Saisi par : Système Admin', 1, '2026-03-05 10:13:04', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=0f096e4ad21e36315cff06b7ca5cbce473ba45688dfdaf2ba88d66c67fcb74fb', 'haute', NULL, '2026-03-05 11:10:57'),
(223, 1, 'success', 'R?sultat d\'analyse disponible', 'R?sultat disponible pour MBALA : Groupage Sanguin ABO-Rhésus', 1, '2026-03-05 18:24:38', '../laboratoire/voir-resultat.php?id=123', 'haute', NULL, '2026-03-05 11:18:17'),
(224, 1, 'success', '🔬 Résultat labo disponible — Groupage Sanguin ABO-Rhésus', '✅ Normal | Patient : Jean MBALA | Acte : Groupage Sanguin ABO-Rhésus (GROUP-ABO) | Saisi par : Système Admin', 1, '2026-03-05 10:18:40', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=020edb7291124392e31f38232b67670ffd25cbcbc021f245c7864efe268a42e9', 'haute', NULL, '2026-03-05 11:18:17'),
(225, 1, 'success', '🔬 Résultat labo — Ionogramme Sanguin', '✅ Normal | Patient : Test1 Bloc | Saisi par : Système Admin', 1, '2026-03-05 23:35:33', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260305-0007', 'haute', NULL, '2026-03-06 00:13:21'),
(226, 1, 'success', '🔬 Résultat labo disponible — Bilan Hépatique Complet', '✅ Normal | Patient : Sacha kasaka | Acte : Bilan Hépatique Complet (BIL-HEP) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=698cce8a8c357aefaac36d8f42171b502ab6fbdaa12ae60fba9c85b05cd1d7f8', 'haute', NULL, '2026-03-06 01:28:13'),
(227, 1, 'success', '🔬 Résultat labo disponible — VS (Vitesse de Sédimentation)', '✅ Normal | Patient : Sacha kasaka | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=b767c65af761edf396f6ff60a71aad7879af3e938c27c9a37669b3afd3f5c172', 'haute', NULL, '2026-03-06 01:28:14'),
(228, 1, 'success', '🔬 Résultat labo disponible — Coproculture', '✅ Normal | Patient : Sacha kasaka | Acte : Coproculture (COPRO) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=f5a8a0822289fe1d099a384b64010a5ce2ba19a78a1a50e22a73b44848cabfbe', 'haute', NULL, '2026-03-06 01:28:14'),
(229, 1, 'success', '🔬 Résultat labo disponible — CRP (Protéine C Réactive)', '✅ Normal | Patient : Sacha kasaka | Acte : CRP (Protéine C Réactive) (CRP) | Saisi par : Système Admin', 1, '2026-03-06 00:29:17', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=8b20a743cca19e876e3b816c502d06f10dbbbbebe38c7cbd797803451013c835', 'haute', NULL, '2026-03-06 01:28:14'),
(230, 1, 'success', '🔬 Résultat labo disponible — Glycémie à Jeun', 'Disponible | Patient : Marie KASONGO | Acte : Glycémie à Jeun (GLYC-JEUN) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=eddaf0a980270d03c6c50fad00a497f5320450edc1985dc6288665bb627a7d50', 'haute', NULL, '2026-03-06 11:13:16'),
(231, 1, 'success', '🔬 Résultat labo disponible — Ionogramme Sanguin', 'Disponible | Patient : Marie KASONGO | Acte : Ionogramme Sanguin (IONO) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=c7464d547913dfe5ce541611f11aeeab0f4c94c7d20c7c61a4c84014759e61a7', 'haute', NULL, '2026-03-06 11:13:17'),
(232, 1, 'success', '🔬 Résultat labo disponible — Groupage Sanguin ABO-Rhésus', 'Disponible | Patient : Marie KASONGO | Acte : Groupage Sanguin ABO-Rhésus (GROUP-ABO) | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=c797d7b08f5e451ef9d7e80a46723fe93613439d16bcf3fdb6d93efa4f489cb3', 'haute', NULL, '2026-03-06 11:13:17'),
(233, 1, 'success', '🔬 Résultat labo disponible — ECBU (Examen Cyto-Bactériologique Urines)', 'Disponible | Patient : Marie KASONGO | Acte : ECBU (Examen Cyto-Bactériologique Urines) (ECBU) | Saisi par : Système Admin', 1, '2026-03-06 10:35:18', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=75803e121ee79c918f8295bf8764326125f36c732b639910c565f619816194c4', 'haute', NULL, '2026-03-06 11:13:17'),
(234, 1, 'success', '🔬 Résultat labo disponible — VS (Vitesse de Sédimentation)', 'Disponible | Patient : Marie KASONGO | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', 1, '2026-03-06 10:32:45', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=b774335944d4e39826b46ad7c5f2ffd2549889b80233a5a5839558711cefdb7b', 'haute', NULL, '2026-03-06 11:13:17'),
(235, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0005', 'Patient : Test1 Patient | Examens : CRP (Protéine C Réactive), Urée et Créatinine, TP, TCA, INR | Saisi par : Système Admin', 1, '2026-03-08 00:57:18', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260305-0005', 'haute', NULL, '2026-03-06 13:16:07'),
(236, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0003', 'Patient : Jean Ikula | Examens : Groupage Sanguin ABO-Rhésus, Ionogramme Sanguin, Glycémie à Jeun | Saisi par : Système Admin', 1, '2026-03-06 12:59:06', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260305-0003', 'haute', NULL, '2026-03-06 13:58:24'),
(237, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260227-0001', 'Patient : Sacha kasaka | Examens : Ionogramme Sanguin, Sérologie Hépatite B, ECBU (Examen Cyto-Bactériologique Urines), Urée et Créatinine | Saisi par : Système Admin', 1, '2026-03-06 13:10:12', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260227-0001', 'haute', NULL, '2026-03-06 14:09:54'),
(238, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0006', 'Patient : Boris Ikula | Examens : Bilan Hépatique Complet, VS (Vitesse de Sédimentation), Glycémie à Jeun | Saisi par : Système Admin', 1, '2026-03-07 08:37:35', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260305-0006', 'haute', NULL, '2026-03-07 09:36:29'),
(239, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0001', 'Patient : Jean Ikula | Examens : Bilan Hépatique Complet, Ionogramme Sanguin | Saisi par : Système Admin', 1, '2026-03-08 01:03:00', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260308-0001', 'haute', NULL, '2026-03-08 02:00:33'),
(240, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260305-0004', 'Patient : Pierre NKULU | Examens : Urée et Créatinine | Saisi par : Système Admin', 1, '2026-03-08 01:13:20', 'http://localhost:8002/index.php?page=labo&action=resultats&code=LAB-20260305-0004', 'haute', NULL, '2026-03-08 02:12:54'),
(241, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0002', 'Patient : Boris Ikula | Examens : Coproculture, Frottis Vaginal | Saisi par : Système Admin', 1, '2026-03-08 01:38:04', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=57fbf7fd7b46f00155eafe6e4fb58254643d5f7e171dea05f1d7333deeec5f58', 'haute', NULL, '2026-03-08 02:37:41'),
(242, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0002', 'Patient : Boris Ikula | Examens : Coproculture, Frottis Vaginal | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=f60af9505ab59de145dad51a9501dd62e67cefb9644a0c57f2eba43a4c741dcb', 'haute', NULL, '2026-03-08 02:40:37'),
(243, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0002', 'Patient : Boris Ikula | Examens : Coproculture, Frottis Vaginal | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=96bf042b5a10674f9fdfda2a08f500a254ff8e12f8f6c3102173785e55e08d91', 'haute', NULL, '2026-03-08 02:42:53'),
(244, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0002', 'Patient : Boris Ikula | Examens : Coproculture, Frottis Vaginal | Saisi par : Système Admin', 0, NULL, 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=dc4974f3155b3ee7ce55c71959964b4a71b812bb1a7c635e35db96bd2743be9b', 'haute', NULL, '2026-03-08 03:01:22'),
(245, 1, 'success', '🔬 Résultats laboratoire disponibles — Groupe LAB-20260308-0003', 'Patient : Sacha kasaka | Examens : Bilan Hépatique Complet | Saisi par : Système Admin', 1, '2026-03-08 02:07:10', 'http://localhost:8002/public/generer_pdf_resultat_unique.php?token=d119467333ce8bbf47b3a274d67107dbbaa82a168ca3c30e44d65f89826e5767', 'haute', NULL, '2026-03-08 03:06:59');

-- --------------------------------------------------------

--
-- Table structure for table `officine`
--

CREATE TABLE `officine` (
  `idofficine` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `type_officine` enum('ambulatoire','hospitalisation','urgence','covid') NOT NULL,
  `idsite` int NOT NULL,
  `actif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `officine`
--

INSERT INTO `officine` (`idofficine`, `nom`, `type_officine`, `idsite`, `actif`) VALUES
(1, 'Officine Ambulatoire CHME', 'ambulatoire', 1, 1),
(2, 'Officine Hospitalisation CHME', 'hospitalisation', 1, 1),
(3, 'Officine Ambulatoire CMMG', 'ambulatoire', 2, 1);

-- --------------------------------------------------------

--
-- Table structure for table `origine`
--

CREATE TABLE `origine` (
  `idorigine` int NOT NULL,
  `libelle` varchar(100) NOT NULL,
  `actif` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `origine`
--

INSERT INTO `origine` (`idorigine`, `libelle`, `actif`) VALUES
(1, 'Domicile', 1),
(2, 'R?f?r? par un autre centre', 1),
(3, 'Transfert interne', 1),
(4, 'Urgence', 1);

-- --------------------------------------------------------

--
-- Table structure for table `parametresvitaux`
--

CREATE TABLE `parametresvitaux` (
  `idparametre` int NOT NULL,
  `idpatient` int DEFAULT NULL,
  `idsous_sejour` int DEFAULT NULL,
  `idtypeparamvitaux` int DEFAULT NULL,
  `valeur` varchar(20) DEFAULT NULL,
  `date_mesure` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `idutilisateur` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `parametresvitaux`
--

INSERT INTO `parametresvitaux` (`idparametre`, `idpatient`, `idsous_sejour`, `idtypeparamvitaux`, `valeur`, `date_mesure`, `idutilisateur`) VALUES
(1, 1, 1, 1, '38.8', '2025-12-09 01:56:58', 2),
(2, 1, 1, 2, '120', '2025-12-09 01:56:58', 2),
(3, 1, 1, 3, '75', '2025-12-09 01:56:58', 2),
(4, 1, 1, 4, '88', '2025-12-09 01:56:58', 2),
(5, 1, 1, 6, '72', '2025-12-09 01:56:58', 2),
(6, 2, 2, 1, '37.2', '2025-12-09 01:56:58', 2),
(7, 2, 2, 2, '115', '2025-12-09 01:56:58', 2),
(8, 2, 2, 3, '70', '2025-12-09 01:56:58', 2),
(9, 2, 2, 4, '72', '2025-12-09 01:56:58', 2),
(10, 2, 2, 6, '65', '2025-12-09 01:56:58', 2),
(11, 4, 32, 9, '95', '2025-12-09 10:31:19', 1),
(12, 4, 32, 9, '95', '2025-12-09 10:32:58', 1),
(13, 4, 32, 1, '36', '2025-12-09 10:33:28', 1),
(14, 4, 32, 1, '36', '2025-12-09 10:34:08', 1),
(15, 4, 32, 1, '36', '2025-12-09 10:35:59', 1),
(16, 4, 32, 1, '36', '2025-12-09 10:36:10', 1),
(17, 4, 32, 1, '36', '2025-12-09 10:36:32', 1),
(18, 4, 32, 1, '36', '2025-12-09 11:46:20', 1),
(19, 4, 37, 9, '95', '2025-12-27 08:15:15', 1),
(20, 4, 37, 6, '98', '2025-12-27 09:29:45', 1),
(21, 4, 37, 7, '75', '2025-12-27 10:32:37', 1),
(22, 22, 67, 4, '95', '2026-01-02 06:32:05', 1);

-- --------------------------------------------------------

--
-- Table structure for table `patient`
--

CREATE TABLE `patient` (
  `idpatient` int NOT NULL,
  `numero_dossier` varchar(20) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `postnom` varchar(100) DEFAULT NULL,
  `date_naissance` date NOT NULL,
  `lieu_naissance` varchar(100) DEFAULT NULL,
  `sexe` enum('M','F') NOT NULL,
  `etat_civil` enum('celibataire','marie','divorce','veuf') DEFAULT 'celibataire',
  `profession` varchar(100) DEFAULT NULL,
  `nationalite` varchar(50) DEFAULT 'Congolaise',
  `idquartier` int DEFAULT NULL,
  `avenue` varchar(100) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `telephone1` varchar(20) DEFAULT NULL,
  `telephone2` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `idgrsanguin` int DEFAULT NULL,
  `idethnie` int DEFAULT NULL,
  `idreligion` int DEFAULT NULL,
  `type_patient` enum('prive','conventionne') DEFAULT 'prive',
  `idsociete` int DEFAULT NULL,
  `idcategorie` int DEFAULT NULL,
  `numero_carte_assurance` varchar(50) DEFAULT NULL,
  `nom_contact` varchar(100) DEFAULT NULL,
  `telephone_contact` varchar(20) DEFAULT NULL,
  `lien_parente` varchar(50) DEFAULT NULL,
  `idutilisateur` int DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `date_enregistrement` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `idsite` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `patient`
--

INSERT INTO `patient` (`idpatient`, `numero_dossier`, `nom`, `prenom`, `postnom`, `date_naissance`, `lieu_naissance`, `sexe`, `etat_civil`, `profession`, `nationalite`, `idquartier`, `avenue`, `numero`, `telephone1`, `telephone2`, `email`, `idgrsanguin`, `idethnie`, `idreligion`, `type_patient`, `idsociete`, `idcategorie`, `numero_carte_assurance`, `nom_contact`, `telephone_contact`, `lien_parente`, `idutilisateur`, `photo`, `actif`, `date_enregistrement`, `date_modification`, `idsite`) VALUES
(1, 'PAT000100', 'MBALA', 'Jean', NULL, '1985-03-15', NULL, 'M', 'celibataire', NULL, 'Congolaise', NULL, NULL, NULL, '+243 812 345 678', NULL, NULL, NULL, NULL, NULL, 'prive', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-07 13:19:21', '2025-12-07 13:19:21', 1),
(2, 'PAT000101', 'KASONGO', 'Marie', NULL, '1990-07-22', NULL, 'F', 'celibataire', NULL, 'Congolaise', NULL, NULL, NULL, '+243 823 456 789', NULL, NULL, NULL, NULL, NULL, 'conventionne', 1, 1, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-07 13:19:21', '2025-12-07 13:19:21', 1),
(3, 'PAT000102', 'NKULU', 'Pierre', NULL, '1978-11-10', NULL, 'M', 'celibataire', NULL, 'Congolaise', NULL, NULL, NULL, '+243 834 567 890', NULL, NULL, NULL, NULL, NULL, 'prive', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-07 13:19:21', '2025-12-07 13:19:21', 1),
(4, 'PAT000103', 'Ikula', 'Boris', 'Newis', '1997-01-31', 'Kinshasa', 'M', 'celibataire', 'Informaticien', 'Congolaise', 3, 'Luila II', '42', '+243811452125', '', 'boris.ikula@monkole.cd', NULL, NULL, NULL, 'conventionne', 1, 5, '31011997', '', '', '', 1, NULL, 1, '2025-12-08 00:15:30', '2025-12-08 00:15:30', 1),
(5, 'TEST001', 'Patient', 'Test1', NULL, '1980-01-01', NULL, 'M', 'celibataire', NULL, 'Congolaise', NULL, 'Adresse test', NULL, '0123456789', NULL, NULL, NULL, NULL, NULL, 'prive', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-13 10:00:14', '2025-12-13 10:00:14', 1),
(6, 'TEST002', 'Patient', 'Test2', NULL, '1975-05-15', NULL, 'F', 'celibataire', NULL, 'Congolaise', NULL, 'Adresse test', NULL, '0987654321', NULL, NULL, NULL, NULL, NULL, 'conventionne', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-13 10:00:14', '2025-12-13 10:00:14', 1),
(7, 'TEST003', 'Patient', 'Test3', NULL, '1990-11-30', NULL, 'M', 'celibataire', NULL, 'Congolaise', NULL, 'Adresse test', NULL, '0555666777', NULL, NULL, NULL, NULL, NULL, 'prive', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-13 10:00:14', '2025-12-13 10:00:14', 1),
(8, 'TEST004', 'Patient', 'Test4', NULL, '1985-03-22', NULL, 'F', 'celibataire', NULL, 'Congolaise', NULL, 'Adresse test', NULL, '0444555666', NULL, NULL, NULL, NULL, NULL, 'conventionne', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-13 10:00:14', '2025-12-13 10:00:14', 1),
(9, 'TEST005', 'Patient', 'Test5', NULL, '1995-07-18', NULL, 'M', 'celibataire', NULL, 'Congolaise', NULL, 'Adresse test', NULL, '0333222111', NULL, NULL, NULL, NULL, NULL, 'prive', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-13 10:00:14', '2025-12-13 10:00:14', 1),
(20, 'BLOC001', 'Bloc', 'Test1', NULL, '1990-01-01', NULL, 'M', 'celibataire', NULL, 'Congolaise', NULL, 'Test', NULL, '0102030405', NULL, NULL, NULL, NULL, NULL, 'prive', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2025-12-13 10:12:22', '2025-12-13 10:12:22', 1),
(21, 'PAT000104', 'kasaka', 'Sacha', 'Jila', '1998-05-20', 'Kinshasa', 'M', 'celibataire', 'Informaticien', 'Congolaise', 3, '', '', '+243823852069', '', '', NULL, NULL, NULL, 'conventionne', 3, 1, '', '', '', '', 1, NULL, 1, '2025-12-19 10:15:22', '2025-12-19 10:15:22', 1),
(22, 'PAT000105', 'Ikula', 'Jean', 'Elock', '1961-01-01', 'Luem', 'M', 'marie', 'Nurse', 'Congolaise', 1, '', '', '+243816372517', '', '', 1, 1, 1, 'prive', NULL, 1, '', 'Mimbu Patience', '243818502113', '', 1, NULL, 1, '2026-01-02 06:19:27', '2026-01-02 06:19:27', 1);

-- --------------------------------------------------------

--
-- Table structure for table `pharma_entrees`
--

CREATE TABLE `pharma_entrees` (
  `identree` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `idfournisseur` int DEFAULT NULL,
  `quantite` int NOT NULL,
  `prix_achat` decimal(10,2) NOT NULL,
  `date_entree` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_expiration` datetime DEFAULT NULL,
  `idutilisateur` int DEFAULT NULL,
  `observation` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pharma_entrees`
--

INSERT INTO `pharma_entrees` (`identree`, `idprodpharma`, `idfournisseur`, `quantite`, `prix_achat`, `date_entree`, `date_expiration`, `idutilisateur`, `observation`) VALUES
(1, 2, 3, 1, 300.00, '2025-12-12 15:11:16', NULL, 1, NULL),
(3, 6, 3, 300, 500.00, '2026-02-20 11:08:41', '2027-02-20 00:00:00', 1, NULL),
(4, 6, 3, 300, 500.00, '2026-02-20 11:09:45', '2027-02-20 00:00:00', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pharma_presc`
--

CREATE TABLE `pharma_presc` (
  `idpharma_presc` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `idsociete` int DEFAULT NULL,
  `quantite` int NOT NULL,
  `posologie` text,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `montant_total` decimal(10,2) NOT NULL,
  `date_prescription` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `prescripteur` int DEFAULT NULL,
  `statut_validation` enum('rien','valide') DEFAULT 'rien',
  `mode_paiement` enum('rien','cash','cc','acompte','dette') DEFAULT 'rien',
  `date_validation` timestamp NULL DEFAULT NULL,
  `valideur` int DEFAULT NULL,
  `statut_execution` enum('en_attente','en_cours','termine') NOT NULL DEFAULT 'en_attente' COMMENT 'Statut d''exécution de la prescription',
  `date_execution` timestamp NULL DEFAULT NULL,
  `executeur` int DEFAULT NULL,
  `observation` text,
  `urgent` tinyint DEFAULT '0',
  `source_prescription` varchar(30) DEFAULT NULL COMMENT 'Source: csk_gps, csk_services, ou NULL pour legacy'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pharma_presc`
--

INSERT INTO `pharma_presc` (`idpharma_presc`, `idsous_sejour`, `idprodpharma`, `idsociete`, `quantite`, `posologie`, `prix_unitaire`, `montant_total`, `date_prescription`, `prescripteur`, `statut_validation`, `mode_paiement`, `date_validation`, `valideur`, `statut_execution`, `date_execution`, `executeur`, `observation`, `urgent`, `source_prescription`) VALUES
(1, 1, 1, NULL, 20, '1 comprim? 3 fois par jour pendant 5 jours', 100.00, 2000.00, '2025-12-07 13:41:17', 2, 'valide', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 1, NULL),
(2, 1, 2, NULL, 15, '1 g?lule 2 fois par jour pendant 7 jours', 400.00, 6000.00, '2025-12-07 13:41:17', 2, 'valide', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(3, 2, 1, NULL, 10, '1 comprim? si douleur (max 3/jour)', 100.00, 1000.00, '2025-12-07 13:41:17', 2, 'valide', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(4, 1, 1, NULL, 20, '1 comprim? 3 fois par jour pendant 5 jours', 100.00, 2000.00, '2025-12-07 13:41:34', 2, 'valide', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 1, NULL),
(5, 1, 2, NULL, 15, '1 g?lule 2 fois par jour pendant 7 jours', 400.00, 6000.00, '2025-12-07 13:41:34', 2, 'valide', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(6, 2, 1, NULL, 10, '1 comprim? si douleur (max 3/jour)', 100.00, 1000.00, '2025-12-07 13:41:34', 2, 'valide', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(8, 3, 3, NULL, 1, '3/jr', 60.00, 60.00, '2025-12-10 09:15:20', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(9, 3, 3, NULL, 1, '3/jr', 60.00, 60.00, '2025-12-10 09:16:56', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(10, 3, 2, NULL, 1, '2/jr', 400.00, 400.00, '2025-12-13 14:40:37', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(11, 67, 6, NULL, 1, 'Après changement de bandage', 100.00, 100.00, '2026-01-02 06:37:16', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 00:57:14', 1, NULL, 0, NULL),
(12, 41, 2, 1, 1, '2/jr', 400.00, 400.00, '2026-01-06 13:35:11', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(15, 41, 1, 1, 1, '6', 100.00, 100.00, '2026-01-17 14:20:09', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(16, 41, 4, 1, 1, 'ok', 800.00, 800.00, '2026-01-17 15:00:34', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(17, 41, 2, 1, 1, 'oui', 400.00, 400.00, '2026-01-17 15:19:00', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(18, 41, 7, 1, 10, ':', 100.00, 1000.00, '2026-01-17 15:35:51', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(19, 67, 3, NULL, 1, '3', 60.00, 60.00, '2026-02-19 01:25:33', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 01:06:20', 1, NULL, 0, 'csk_services'),
(20, 67, 1, NULL, 1, '4', 100.00, 100.00, '2026-02-19 01:25:33', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 01:47:26', 1, NULL, 0, 'csk_services'),
(21, 67, 3, NULL, 1, '3x/jr', 60.00, 60.00, '2026-02-20 16:48:05', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 00:39:45', 1, NULL, 1, 'csk_services'),
(22, 67, 1, NULL, 1, '2', 100.00, 100.00, '2026-02-20 16:48:05', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 00:41:07', 1, 'Si besoin', 1, 'csk_services'),
(23, 67, 3, NULL, 1, NULL, 60.00, 60.00, '2026-02-21 01:52:37', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 01:54:08', 1, NULL, 0, 'csk_services'),
(24, 67, 6, NULL, 5, 'après bain', 100.00, 500.00, '2026-02-21 02:07:13', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 02:56:19', 1, NULL, 0, 'csk_services'),
(25, 67, 1, NULL, 1, NULL, 100.00, 100.00, '2026-02-21 02:56:55', 1, 'rien', 'rien', NULL, NULL, 'termine', '2026-02-21 03:18:40', 1, NULL, 0, 'csk_services'),
(26, 67, 5, NULL, 19, NULL, 200.00, 3800.00, '2026-02-21 03:35:29', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, 'csk_services'),
(27, 44, 2, 1, 1, '3', 400.00, 400.00, '2026-02-24 03:48:38', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, NULL),
(28, 65, 1, 1, 1, NULL, 100.00, 100.00, '2026-03-04 11:07:57', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, 'csk_services'),
(29, 65, 1, 1, 1, NULL, 100.00, 100.00, '2026-03-04 11:12:02', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, 'csk_services'),
(30, 23, 5, NULL, 1, NULL, 200.00, 200.00, '2026-03-04 12:04:55', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, 'csk_services'),
(31, 57, 1, NULL, 1, NULL, 100.00, 100.00, '2026-03-05 19:39:20', 1, 'rien', 'rien', NULL, NULL, 'en_attente', NULL, NULL, NULL, 0, 'csk_services');

--
-- Triggers `pharma_presc`
--
DELIMITER $$
CREATE TRIGGER `after_pharma_presc_insert` AFTER INSERT ON `pharma_presc` FOR EACH ROW BEGIN
    DECLARE v_code_preparation VARCHAR(50);
    DECLARE v_patient_id INT;
    DECLARE v_sejour_id INT;
    DECLARE v_compteur_jour INT;
    DECLARE v_libelle_produit VARCHAR(200);
    
    -- Récupérer info patient et produit
    SELECT s.idpatient, s.idsejour, p.libelle
    INTO v_patient_id, v_sejour_id, v_libelle_produit
    FROM csk_base.sous_sejour ss
    JOIN csk_base.sejour s ON ss.idsejour = s.idsejour
    JOIN csk_base.prodpharma p ON NEW.idprodpharma = p.idprodpharma
    WHERE ss.idsous_sejour = NEW.idsous_sejour;
    
    -- Compter les préparations du jour
    SELECT COUNT(*) + 1 INTO v_compteur_jour
    FROM csk_services.pharmacie_preparations
    WHERE DATE(created_at) = CURDATE();
    
    -- Générer code préparation
    SET v_code_preparation = CONCAT(
        'PHAR-', 
        DATE_FORMAT(NOW(), '%Y%m%d'), 
        '-', 
        LPAD(v_compteur_jour, 4, '0')
    );
    
    -- Créer la préparation dans csk_services
    INSERT INTO csk_services.pharmacie_preparations (
        code_preparation,
        idpharma_presc,
        idpatient,
        idsejour,
        idsous_sejour,
        idprodpharma,
        dosage_prescrit,
        quantite_preparee,
        statut,
        urgence,
        created_at
    ) VALUES (
        v_code_preparation,
        NEW.idpharma_presc,
        v_patient_id,
        v_sejour_id,
        NEW.idsous_sejour,
        NEW.idprodpharma,
        NEW.posologie,
        NEW.quantite,
        'attente',
        NEW.urgent,
        NOW()
    );
    
    -- Notification
    INSERT INTO csk_services.services_notifications (
        service,
        type_notification,
        id_reference,
        table_reference,
        code_reference,
        titre,
        message,
        groupe_destinataire,
        priorite,
        created_at
    ) VALUES (
        'pharmacie',
        'info',
        NEW.idpharma_presc,
        'pharmacie_preparations',
        v_code_preparation,
        'Nouvelle prescription pharmacie',
        CONCAT(v_libelle_produit, ' - ', NEW.posologie, ' - Code: ', v_code_preparation),
        'preparateurs_pharmacie',
        CASE WHEN NEW.urgent = 1 THEN 'haute' ELSE 'normale' END,
        NOW()
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `planning_soins`
--

CREATE TABLE `planning_soins` (
  `idplanning` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `type_soin` varchar(100) NOT NULL,
  `description` text,
  `date_prevue` date NOT NULL,
  `heure_prevue` time NOT NULL,
  `duree_estimee` int DEFAULT NULL COMMENT 'En minutes',
  `statut` enum('planifie','en_cours','termine','annule') DEFAULT 'planifie',
  `idinfirmiere` int DEFAULT NULL,
  `observation` text,
  `date_realisation` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prelevements_anapath`
--

CREATE TABLE `prelevements_anapath` (
  `idprelevement` int NOT NULL,
  `idintervention` int NOT NULL,
  `type_prelevement` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `localisation` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `nombre_fragments` int DEFAULT '1',
  `fixateur` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `examen_extemporane` tinyint(1) DEFAULT '0',
  `resultat_extemporane` text COLLATE utf8mb4_general_ci,
  `numero_anapath` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `labo_destination` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_envoi` date DEFAULT NULL,
  `resultat_final` text COLLATE utf8mb4_general_ci,
  `date_resultat` date DEFAULT NULL,
  `idutilisateur` int NOT NULL,
  `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prodpharma`
--

CREATE TABLE `prodpharma` (
  `idprodpharma` int NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `forme` enum('comprimé','comprimé effervescent','comprimé pelliculé','comprimé à croquer','comprimé orodispersible','gélule','capsule molle','capsule dure','sirop','solution buvable','suspension buvable','gouttes buvables','ampoule buvable','injectable IV','injectable IM','injectable SC','perfusion','crème','pommade','gel','lotion','solution cutanée','suppositoire','ovule','crème vaginale','collyre','pommade ophtalmique','solution nasale','spray nasal','aérosol','inhaleur','poudre inhalée','patch transdermique') DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `type_produit` enum('medicament','consommable') NOT NULL,
  `idfamiprod` int DEFAULT NULL,
  `idsous_specialite` int DEFAULT NULL,
  `idfrm_prod` int DEFAULT NULL,
  `idvoie_prod` int DEFAULT NULL,
  `idunite` int DEFAULT NULL,
  `prix_achat` decimal(10,2) DEFAULT '0.00',
  `prix_vente_externe` decimal(10,2) DEFAULT '0.00',
  `prix_vente_urgence` decimal(10,2) DEFAULT '0.00',
  `taux_marge` decimal(5,2) DEFAULT '0.00',
  `seuil_alerte` int DEFAULT '10',
  `seuil_reappro` int DEFAULT '20',
  `fonction_tarifaire` varchar(50) DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `disponibilite` enum('disponible','temp_indisponible','def_indisponible') DEFAULT 'disponible',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `prodpharma`
--

INSERT INTO `prodpharma` (`idprodpharma`, `libelle`, `forme`, `code`, `type_produit`, `idfamiprod`, `idsous_specialite`, `idfrm_prod`, `idvoie_prod`, `idunite`, `prix_achat`, `prix_vente_externe`, `prix_vente_urgence`, `taux_marge`, `seuil_alerte`, `seuil_reappro`, `fonction_tarifaire`, `actif`, `disponibilite`, `created_at`, `updated_at`) VALUES
(1, 'PARACETAMOL 500mg', NULL, 'PARA500', 'medicament', 1, NULL, 1, 1, 1, 50.00, 100.00, 120.00, 100.00, 50, 100, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2025-12-07 12:35:59'),
(2, 'AMOXICILLINE 500mg', NULL, 'AMOX500', 'medicament', 2, NULL, 1, 1, 1, 200.00, 400.00, 450.00, 100.00, 30, 60, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2025-12-07 12:35:59'),
(3, 'ASPIRINE 100mg', NULL, 'ASP100', 'medicament', 3, NULL, 1, 1, 1, 30.00, 60.00, 70.00, 100.00, 50, 100, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2025-12-07 12:35:59'),
(4, 'SERUM PHYSIOLOGIQUE 500ml', NULL, 'SERUM500', 'medicament', 6, NULL, 9, 2, 4, 500.00, 800.00, 900.00, 60.00, 20, 50, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2025-12-07 12:35:59'),
(5, 'GANTS STERILES (paire)', NULL, 'GANT-ST', 'consommable', 7, NULL, NULL, NULL, 8, 100.00, 200.00, 250.00, 100.00, 100, 200, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2025-12-07 12:35:59'),
(6, 'COMPRESSE STERILE', NULL, 'COMP-ST', 'consommable', 8, NULL, NULL, NULL, 8, 500.00, 100.00, 120.00, 100.00, 200, 400, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2026-02-20 11:08:41'),
(7, 'SERINGUE 5ml', NULL, 'SER5ML', 'consommable', 7, NULL, NULL, NULL, 8, 50.00, 100.00, 120.00, 100.00, 100, 200, NULL, 1, 'disponible', '2025-12-07 12:35:59', '2025-12-07 12:35:59');

-- --------------------------------------------------------

--
-- Table structure for table `profiluser`
--

CREATE TABLE `profiluser` (
  `idprofiluser` int NOT NULL,
  `nom` varchar(50) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` text,
  `categorie` varchar(50) DEFAULT NULL,
  `niveau` int DEFAULT '1',
  `couleur` varchar(20) DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `statut` enum('actif','inactif') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `profiluser`
--

INSERT INTO `profiluser` (`idprofiluser`, `nom`, `code`, `description`, `categorie`, `niveau`, `couleur`, `actif`, `statut`) VALUES
(1, 'Administrateur', 'admin', 'Profil administrateur système avec tous les droits', 'Administration', 10, '#dc3545', 1, 'actif'),
(2, 'Réceptionniste', 'receptionniste', 'Enregistrement des patients et création des séjours', 'Accueil', 2, '#17a2b8', 1, 'actif'),
(3, 'Facturier', 'facturier', 'Validation et encaissement des factures', 'Finance', 3, '#ffc107', 1, 'actif'),
(4, 'Pharmacien', 'pharmacien', 'Gestion de l\'officine et des prescriptions', 'Pharmacie', 5, '#28a745', 1, 'actif'),
(5, 'Pharmacien Chef', 'pharmacien_chef', 'Gestion complète de la pharmacie et du dépôt', 'Pharmacie', 6, '#198754', 1, 'actif'),
(6, 'Médecin', 'medecin', 'Consultation et prescriptions médicales', 'Médical', 7, '#0d6efd', 1, 'actif'),
(7, 'Infirmier', 'infirmier', 'Soins infirmiers et suivi des patients', 'Soins', 4, '#6610f2', 1, 'actif'),
(8, 'Médecin Généraliste', NULL, 'Médecin de médecine générale', NULL, 1, NULL, 1, 'actif'),
(9, 'Technicien Laboratoire', NULL, 'Technicien de laboratoire médical', NULL, 1, NULL, 1, 'actif'),
(10, 'Radiologue_X', NULL, 'Médecin radiologue', NULL, 1, NULL, 1, 'actif'),
(11, 'Pharmacien Officine', NULL, 'Pharmacien d\'officine', NULL, 1, NULL, 1, 'actif'),
(12, 'Gestionnaire Dépôt', NULL, 'Gestionnaire du dépôt central', NULL, 1, NULL, 1, 'actif'),
(13, 'Chirurgien', NULL, 'Médecin chirurgien', NULL, 1, NULL, 1, 'actif'),
(15, 'Technicien Labo', 'technicien_labo', 'Infirmier spécialisé en urgences', 'Technique', 4, '#fd7e14', 1, 'actif'),
(16, 'Radiologue', 'radiologue', 'Infirmier de bloc opératoire', 'Médical', 8, '#20c997', 1, 'actif'),
(32, 'Sage-Femme', 'sage_femme', 'Sage-femme', 'Soins', 6, '#e685b5', 1, 'actif'),
(33, 'Kinésithérapeute', 'kinesitherapeute', 'Kinésithérapeute', 'R??ducation', 5, '#20c997', 1, 'actif'),
(34, 'Biologiste', 'biologiste', 'Médecin biologiste', 'M?dical', 8, '#fd7e14', 1, 'actif'),
(35, 'Technicien Imagerie', 'technicien_imagerie', 'Technicien en imagerie', 'Technique', 4, '#20c997', 1, 'actif'),
(36, 'Aide-Soignant', 'aide_soignant', 'Aide-soignant', 'Soins', 3, '#adb5bd', 1, 'actif'),
(37, 'Caissier', 'caissier', 'Caissier', 'Finance', 2, '#ffc107', 1, 'actif'),
(38, 'Responsable Service', 'responsable_service', 'Chef de service', 'Administration', 8, '#198754', 1, 'actif'),
(39, 'Directeur Médical', 'directeur_medical', 'Directeur des affaires médicales', 'Administration', 9, '#0d6efd', 1, 'actif'),
(40, 'Directeur Général', 'directeur_general', 'Directeur général', 'Administration', 10, '#dc3545', 1, 'actif'),
(53, 'Secrétaire Imagerie', 'secretaire_imagerie', NULL, 'Imagerie', 3, '#17a2b8', 1, 'actif'),
(54, 'Radiologue Chef', 'radiologue_chef', NULL, 'Imagerie', 8, '#6610f2', 1, 'actif');

-- --------------------------------------------------------

--
-- Table structure for table `quartier`
--

CREATE TABLE `quartier` (
  `idquartier` int NOT NULL,
  `idcommune` int NOT NULL,
  `nom` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `quartier`
--

INSERT INTO `quartier` (`idquartier`, `idcommune`, `nom`) VALUES
(1, 17, 'Kimbondo'),
(2, 17, 'Matadi-Mayo'),
(3, 17, 'Ngafani'),
(4, 17, 'Binza Ozone'),
(5, 17, 'Kindele'),
(6, 17, 'Matadi-Kibala');

-- --------------------------------------------------------

--
-- Table structure for table `religion`
--

CREATE TABLE `religion` (
  `idreligion` int NOT NULL,
  `nom` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `religion`
--

INSERT INTO `religion` (`idreligion`, `nom`) VALUES
(5, 'Autre'),
(1, 'Catholique'),
(3, 'Kimbanguiste'),
(4, 'Musulmane'),
(6, 'Non sp?cifi?e'),
(2, 'Protestante');

-- --------------------------------------------------------

--
-- Table structure for table `requisition`
--

CREATE TABLE `requisition` (
  `idrequisition` int NOT NULL,
  `idofficine` int NOT NULL,
  `numero_requisition` varchar(20) DEFAULT NULL,
  `date_requisition` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('en_attente','servi','refuse') DEFAULT 'en_attente',
  `date_traitement` timestamp NULL DEFAULT NULL,
  `idutilisateur` int DEFAULT NULL,
  `traiteur` int DEFAULT NULL,
  `observation` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `requisition`
--

INSERT INTO `requisition` (`idrequisition`, `idofficine`, `numero_requisition`, `date_requisition`, `statut`, `date_traitement`, `idutilisateur`, `traiteur`, `observation`) VALUES
(1, 2, 'REQ000001', '2025-12-07 13:43:15', 'servi', '2026-02-20 23:28:58', 5, 1, NULL),
(3, 1, 'REQ000002', '2025-12-13 09:17:01', 'en_attente', NULL, 1, NULL, NULL),
(4, 2, 'REQ000003', '2026-02-20 23:41:17', 'en_attente', NULL, 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `resultatslabo`
--

CREATE TABLE `resultatslabo` (
  `idresultat` int NOT NULL,
  `idechantillon` int DEFAULT NULL,
  `idactes_presc` int DEFAULT NULL,
  `resultat` text,
  `valeur_normale` text,
  `interpretation` varchar(50) DEFAULT NULL,
  `analyse_par` int DEFAULT NULL,
  `observations` varchar(255) DEFAULT NULL,
  `fichier_externe` varchar(255) DEFAULT NULL,
  `date_analyse` datetime DEFAULT CURRENT_TIMESTAMP,
  `idmachinelabo` int DEFAULT NULL,
  `numero_bon` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `resultatslabo`
--

INSERT INTO `resultatslabo` (`idresultat`, `idechantillon`, `idactes_presc`, `resultat`, `valeur_normale`, `interpretation`, `analyse_par`, `observations`, `fichier_externe`, `date_analyse`, `idmachinelabo`, `numero_bon`) VALUES
(1, NULL, NULL, 'Glycémie à jeun: 1.82 g/L (182 mg/dL)', 'Valeur normale: 0.70 - 1.10 g/L', 'anormal', 3, NULL, NULL, '2025-12-16 01:49:22', NULL, NULL),
(2, NULL, 53, '5', '1', 'anormal', 28, NULL, NULL, '2026-02-17 08:05:30', 2, NULL),
(3, NULL, 53, '60', '', 'critique', 1, '', NULL, '2026-02-17 08:31:04', 1, NULL),
(4, NULL, 48, '5', NULL, 'normal', 28, NULL, NULL, '2026-02-17 12:15:02', 1, NULL),
(5, NULL, 59, 'β-HCG : 8 mUI/mL\r\nInterprétation : [Positif ≥5 / Négatif <5]', 'Non enceinte : <5 mUI/mL | S4 : 10–750 | S6 : 1 000–10 000 | S8-10 : 25 000–300 000', 'anormal', 1, NULL, NULL, '2026-02-21 11:42:32', 2, NULL),
(6, NULL, 47, 'AgHBs : [positif / négatif]\r\nAc anti-HBc : [positif / négatif]\r\nAc anti-HBs : [valeur] UI/L\r\nADN VHB (si AgHBs+) : [charge virale]\r\nConclusion : [non infecté / infecté / immunisé]', 'Normal : AgHBs négatif, Ac anti-HBc négatif, Ac anti-HBs >10 UI/L si vacciné', NULL, 1, NULL, NULL, '2026-02-21 08:40:16', NULL, NULL),
(7, NULL, 54, 'Urée : [valeur] g/L\r\nCréatinine : [valeur] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', NULL, 1, NULL, NULL, '2026-02-21 09:18:32', NULL, NULL),
(8, NULL, 57, 'Urée : 0.23 g/L\r\nCréatinine : 7 mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', 'normal', 1, 'yufdyufduy', NULL, '2026-03-05 11:08:13', 2, NULL),
(9, NULL, 65, 'TP : [valeur] %\r\nTCA : [valeur] s (Témoin : [valeur] s)\r\nINR : [valeur]', '70–100 %\r\n25–35 secondes\r\n0.8–1.2 (sous anticoagulants : 2–3)', NULL, 1, NULL, NULL, '2026-02-21 09:54:57', NULL, NULL),
(10, NULL, 65, 'TP : 6%\r\nTCA : [9] s (Témoin : [valeur] s)\r\nINR : [10]', '70–100 %\r\n25–35 secondes\r\n0.8–1.2 (sous anticoagulants : 2–3)', 'normal', 1, NULL, NULL, '2026-02-21 10:15:38', 2, NULL),
(15, NULL, 62, 'VS 1ère heure : [valeur] mm/h\r\nVS 2ème heure : [valeur] mm/h', 'H : <15 mm/h | F : <20 mm/h', NULL, 1, NULL, NULL, '2026-02-21 11:39:48', NULL, NULL),
(16, NULL, 59, 'β-HCG : [8] mUI/mL\r\nInterprétation : [Positif ≥5 / Négatif <5]', 'Non enceinte : <5 mUI/mL | S4 : 10–750 | S6 : 1 000–10 000 | S8-10 : 25 000–300 000', NULL, 1, NULL, NULL, '2026-02-25 04:57:46', NULL, NULL),
(17, NULL, 62, 'VS 1ère heure : [12] mm/h\r\nVS 2ème heure : [24] mm/h', 'H : <15 mm/h | F : <20 mm/h', 'anormal', 1, NULL, NULL, '2026-02-21 11:49:43', 2, NULL),
(18, NULL, 60, 'TP : [65] %\r\nTCA : [26] s (Témoin : [valeur] s)\r\nINR : [2]', '70–100 %\r\n25–35 secondes\r\n0.8–1.2 (sous anticoagulants : 2–3)', 'normal', 1, NULL, NULL, '2026-02-21 12:13:59', 3, NULL),
(19, NULL, 63, 'Aspect des selles : [normal]\r\nExamen direct : [parasites]\r\nCulture : [positive]\r\nGerme isolé : [si positif]\r\nAntibiogramme : [résultats si positif]', 'Normal : flore commensale, absence de germe pathogène', 'normal', 1, NULL, NULL, '2026-02-22 07:27:54', 1, NULL),
(20, NULL, 60, 'TP : [85] %\r\nTCA : [30] s (Témoin : [valeur] s)\r\nINR : [1]', '70–100 %\r\n25–35 secondes\r\n0.8–1.2 (sous anticoagulants : 2–3)', 'normal', 1, NULL, NULL, '2026-02-22 08:24:45', 3, NULL),
(21, NULL, 58, 'Flore : [lactobacillaire / dysbasique / mixte]\r\nCellules : [normal / koïlocytes / cellules atypiques]\r\nGermes : [Candida / Trichomonas / autres si présents]\r\nConclusion : [normale / anormale]', 'Normal : flore lactobacillaire, épithélium normal', 'anormal', 1, NULL, NULL, '2026-02-22 08:39:29', 2, NULL),
(22, NULL, 58, 'Flore : [lactobacillaire]\r\nCellules : [normal]\r\nGermes : [Candida]\r\nConclusion : [normale]', 'Normal : flore lactobacillaire, épithélium normal', 'normal', 1, 'RAS', NULL, '2026-02-23 02:57:07', 1, NULL),
(23, NULL, 66, 'ASAT (TGO) : [22] UI/L\r\nALAT (TGP) : [43] UI/L\r\nBilirubine totale : [10] mg/L\r\nPhosphatases alcalines : [50] UI/L', 'H : <40 UI/L | F : <35 UI/L\r\nH : <41 UI/L | F : <31 UI/L\r\n<10 mg/L (1 mg/dL)\r\nH : 40–130 UI/L | F : 35–105 UI/L', 'normal', 1, 'fdfjk', NULL, '2026-02-27 05:55:15', 2, 'LAB-2026-00001'),
(24, NULL, 67, 'Leucocytes : [valeur] /mm³\r\nHématies : [valeur] /mm³\r\nBactériologie : [positif/négatif]\r\nGerme : [espèce si positif]\r\nAntibiogramme : [résultats]', '<10/mm³\r\n<5/mm³\r\nPas de germe significatif (<10³ UFC/mL)', 'critique', 1, NULL, NULL, '2026-02-23 03:21:30', 3, NULL),
(25, NULL, 68, 'Urée : [0.3] g/L\r\nCréatinine : [12] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', 'normal', 1, NULL, NULL, '2026-02-23 03:35:56', 1, NULL),
(26, NULL, 69, 'Sodium (Na) : [140] mmol/L\r\nPotassium (K) : [4] mmol/L\r\nChlorures (Cl) : [99] mmol/L\r\nBicarbonates (HCO₃) : [25] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', 'normal', 1, NULL, NULL, '2026-02-23 04:22:15', 2, NULL),
(27, NULL, 70, 'Urée : [valeur] g/L\r\nCréatinine : [valeur] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', NULL, 1, NULL, NULL, '2026-02-23 05:30:04', NULL, NULL),
(28, NULL, 71, 'Sodium (Na) : [valeur] mmol/L\r\nPotassium (K) : [valeur] mmol/L\r\nChlorures (Cl) : [valeur] mmol/L\r\nBicarbonates (HCO₃) : [valeur] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', NULL, 1, NULL, NULL, '2026-02-23 06:22:52', NULL, NULL),
(29, NULL, 87, 'Flore : [lactobacillaire / dysbasique / mixte]\r\nCellules : [normal / koïlocytes / cellules atypiques]\r\nGermes : [Candida / Trichomonas / autres si présents]\r\nConclusion : [normale / anormale]', 'Normal : flore lactobacillaire, épithélium normal', 'anormal', 1, NULL, NULL, '2026-02-25 16:51:44', 2, NULL),
(30, NULL, 88, 'Test rapide VIH 1/2 : [Positif / Négatif]\r\nWestern Blot (si positif) : [confirmatoire]\r\nConclusion : [Séronégatif / Séropositif]', 'Résultat normal : Négatif', NULL, 1, NULL, NULL, '2026-02-25 16:56:46', NULL, NULL),
(31, NULL, 90, 'Hémoglobine (Hb) : [7] g/dL\r\nHématocrite (Ht) : [12] %\r\nGlobules rouges (GR) : [16] ×10⁶/µL\r\nGlobules blancs (GB) : [valeur] ×10³/µL\r\nPlaquettes : [valeur] ×10³/µL', 'H : 13–17 g/dL | F : 12–16 g/dL | Enfant : 11–15 g/dL\r\nH : 40–54 % | F : 36–46 % | Enfant : 33–44 %\r\nH : 4.5–5.9 | F : 4.0–5.4 | Enfant : 3.8–5.2 ×10⁶/µL\r\n4.0–10.0 ×10³/µL (Enfant : 6–15)\r\n150–400 ×10³/µL', 'normal', 28, NULL, NULL, '2026-02-26 19:05:56', 2, NULL),
(32, NULL, 89, 'Aspect des selles : [sang]\r\nExamen direct : [parasites]\r\nCulture : [positive / négative]\r\nGerme isolé : [si positif]\r\nAntibiogramme : [résultats si positif]', 'Normal : flore commensale, absence de germe pathogène', 'anormal', 1, NULL, NULL, '2026-02-27 18:53:02', 2, NULL),
(33, NULL, 98, 'Urée : [valeur] g/L\r\nCréatinine : [valeur] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', 'normal', 28, NULL, NULL, '2026-02-26 20:35:12', 2, NULL),
(34, NULL, 89, 'Aspect des selles : [normal / liquide / pâteux / sang]\r\nExamen direct : [parasites / levures / bactéries atypiques]\r\nCulture : [positive / négative]\r\nGerme isolé : [si positif]\r\nAntibiogramme : [résultats si positif]', 'Normal : flore commensale, absence de germe pathogène', NULL, 1, NULL, NULL, '2026-02-27 05:53:33', NULL, NULL),
(35, 73, 104, 'Hémoglobine (Hb) : [14] g/dL\r\nHématocrite (Ht) : [45] %\r\nGlobules rouges (GR) : [5] ×10⁶/µL\r\nGlobules blancs (GB) : [7] ×10³/µL\r\nPlaquettes : [211] ×10³/µL', 'H : 13–17 g/dL | F : 12–16 g/dL | Enfant : 11–15 g/dL\r\nH : 40–54 % | F : 36–46 % | Enfant : 33–44 %\r\nH : 4.5–5.9 | F : 4.0–5.4 | Enfant : 3.8–5.2 ×10⁶/µL\r\n4.0–10.0 ×10³/µL (Enfant : 6–15)\r\n150–400 ×10³/µL', 'normal', 1, 'fjkk', NULL, '2026-03-04 14:57:18', 2, NULL),
(36, 74, 105, 'Sodium (Na) : [valeur] mmol/L\r\nPotassium (K) : [valeur] mmol/L\r\nChlorures (Cl) : [valeur] mmol/L\r\nBicarbonates (HCO₃) : [valeur] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', 'normal', 1, NULL, NULL, '2026-03-04 14:57:18', 2, NULL),
(37, 75, 106, 'Urée : [0.3] g/L\r\nCréatinine : [9] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', 'normal', 1, NULL, NULL, '2026-03-04 14:57:18', 2, NULL),
(38, NULL, 116, 'β-HCG : [3] mUI/mL\r\nInterprétation : [Négatif <5]', 'Non enceinte : <5 mUI/mL | S4 : 10–750 | S6 : 1 000–10 000 | S8-10 : 25 000–300 000', 'normal', 1, NULL, NULL, '2026-03-04 16:37:43', 1, 'LAB-2026-00002'),
(39, 92, 117, 'TP : [60] %\r\nTCA : [30] s (Témoin : [valeur] s)\r\nINR : [0.5]', '70–100 %\r\n25–35 secondes\r\n0.8–1.2 (sous anticoagulants : 2–3)', 'anormal', 1, NULL, NULL, '2026-03-04 16:37:43', 1, NULL),
(40, NULL, 90, 'Hémoglobine (Hb) : [valeur] g/dL\r\nHématocrite (Ht) : [valeur] %\r\nGlobules rouges (GR) : [valeur] ×10⁶/µL\r\nGlobules blancs (GB) : [valeur] ×10³/µL\r\nPlaquettes : [valeur] ×10³/µL', 'H : 13–17 g/dL | F : 12–16 g/dL | Enfant : 11–15 g/dL\r\nH : 40–54 % | F : 36–46 % | Enfant : 33–44 %\r\nH : 4.5–5.9 | F : 4.0–5.4 | Enfant : 3.8–5.2 ×10⁶/µL\r\n4.0–10.0 ×10³/µL (Enfant : 6–15)\r\n150–400 ×10³/µL', NULL, 1, NULL, NULL, '2026-03-05 10:53:01', NULL, NULL),
(41, NULL, 88, 'Test rapide VIH 1/2 : [Négatif]\r\nWestern Blot (si positif) : []\r\nConclusion : [Séronégatif]', 'Résultat normal : Négatif', 'normal', 1, 'ras', NULL, '2026-03-05 11:12:23', 2, 'LAB-2026-00003'),
(42, NULL, 123, 'Groupe ABO : [O]\r\nRhésus (Rh) : [Positif (+)]\r\nRAI (Recherche d\'agglutinines irrégulières) : [positive]', 'Groupage ABO-Rhésus :  –', 'normal', 1, NULL, NULL, '2026-03-05 11:18:17', 2, 'LAB-2026-00004'),
(43, NULL, 153, 'Sodium (Na) : [140] mmol/L\r\nPotassium (K) : [4] mmol/L\r\nChlorures (Cl) : [99] mmol/L\r\nBicarbonates (HCO₃) : [25] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', 'normal', 1, NULL, NULL, '2026-03-06 00:13:21', 2, NULL),
(44, NULL, 131, 'ASAT (TGO) : [32] UI/L\r\nALAT (TGP) : [36] UI/L\r\nBilirubine totale : [0.5] mg/L\r\nPhosphatases alcalines : [100] UI/L', 'H : <40 UI/L | F : <35 UI/L\r\nH : <41 UI/L | F : <31 UI/L\r\n<10 mg/L (1 mg/dL)\r\nH : 40–130 UI/L | F : 35–105 UI/L', 'normal', 1, NULL, NULL, '2026-03-06 01:28:13', 2, NULL),
(45, NULL, 132, 'VS 1ère heure : [13] mm/h\r\nVS 2ème heure : [11] mm/h', 'H : <15 mm/h | F : <20 mm/h', 'normal', 1, NULL, NULL, '2026-03-06 01:28:14', 2, NULL),
(46, NULL, 141, 'Aspect des selles : [normal]\r\nExamen direct : [parasites]\r\nCulture : [négative]\r\nGerme isolé : [si positif]\r\nAntibiogramme : [résultats si positif]', 'Normal : flore commensale, absence de germe pathogène', 'normal', 1, NULL, NULL, '2026-03-06 01:28:14', 2, NULL),
(47, NULL, 154, 'CRP : [26] mg/L', '<6 mg/L (normale) | 6–40 : inflammation modérée | >40 : infection bactérienne', 'normal', 1, NULL, NULL, '2026-03-06 01:28:14', 2, 'LAB-2026-00005'),
(48, NULL, 126, 'Glycémie à jeun : [valeur] g/L ([valeur ×10 = mg/dL])', 'H/F adulte : 0.70–1.10 g/L | Enfant : 0.60–1.00 g/L', NULL, 1, NULL, NULL, '2026-03-06 11:13:16', 2, NULL),
(49, NULL, 127, 'Sodium (Na) : [valeur] mmol/L\r\nPotassium (K) : [valeur] mmol/L\r\nChlorures (Cl) : [valeur] mmol/L\r\nBicarbonates (HCO₃) : [valeur] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', NULL, 1, NULL, NULL, '2026-03-06 11:13:17', 2, NULL),
(50, NULL, 130, 'Groupe ABO : [A / B / AB / O]\r\nRhésus (Rh) : [Positif (+) / Négatif (-)]\r\nRAI (Recherche d\'agglutinines irrégulières) : [positive / négative]', NULL, NULL, 1, NULL, NULL, '2026-03-06 11:13:17', 2, NULL),
(51, NULL, 135, 'Leucocytes : [valeur] /mm³\r\nHématies : [valeur] /mm³\r\nBactériologie : [positif/négatif]\r\nGerme : [espèce si positif]\r\nAntibiogramme : [résultats]', '<10/mm³\r\n<5/mm³\r\nPas de germe significatif (<10³ UFC/mL)', NULL, 1, NULL, NULL, '2026-03-06 11:13:17', 2, 'LAB-2026-00007'),
(52, NULL, 156, 'VS 1ère heure : [valeur] mm/h\r\nVS 2ème heure : [valeur] mm/h', 'H : <15 mm/h | F : <20 mm/h', NULL, 1, NULL, NULL, '2026-03-06 11:13:17', 2, 'LAB-2026-00006'),
(53, NULL, 138, 'CRP : [19] mg/L', '<6 mg/L (normale) | 6–40 : inflammation modérée | >40 : infection bactérienne', 'normal', 1, NULL, NULL, '2026-03-06 13:16:07', 3, NULL),
(54, NULL, 139, 'Urée : [0.32] g/L\r\nCréatinine : [11] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', 'normal', 1, NULL, NULL, '2026-03-06 13:16:07', 3, NULL),
(55, NULL, 155, 'TP : [81] %\r\nTCA : [30] s (Témoin : [31] s)\r\nINR : [1.01]', '70–100 %\r\n25–35 secondes\r\n0.8–1.2 (sous anticoagulants : 2–3)', 'normal', 1, NULL, NULL, '2026-03-06 13:16:07', 3, NULL),
(56, NULL, 128, 'Groupe ABO : [A / B / AB / O]\r\nRhésus (Rh) : [Positif (+) / Négatif (-)]\r\nRAI (Recherche d\'agglutinines irrégulières) : [positive / négative]', NULL, NULL, 1, NULL, NULL, '2026-03-06 13:58:23', 1, NULL),
(57, NULL, 129, 'Sodium (Na) : [valeur] mmol/L\r\nPotassium (K) : [valeur] mmol/L\r\nChlorures (Cl) : [valeur] mmol/L\r\nBicarbonates (HCO₃) : [valeur] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', NULL, 1, NULL, NULL, '2026-03-06 13:58:23', 1, NULL),
(58, NULL, 152, 'Glycémie à jeun : [valeur] g/L ([valeur ×10 = mg/dL])', 'H/F adulte : 0.70–1.10 g/L | Enfant : 0.60–1.00 g/L', NULL, 1, NULL, NULL, '2026-03-06 13:58:24', 1, NULL),
(59, NULL, 100, 'Sodium (Na) : [valeur] mmol/L\r\nPotassium (K) : [valeur] mmol/L\r\nChlorures (Cl) : [valeur] mmol/L\r\nBicarbonates (HCO₃) : [valeur] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', NULL, 1, NULL, NULL, '2026-03-06 14:09:54', 2, NULL),
(60, NULL, 101, 'AgHBs : [positif / négatif]\r\nAc anti-HBc : [positif / négatif]\r\nAc anti-HBs : [valeur] UI/L\r\nADN VHB (si AgHBs+) : [charge virale]\r\nConclusion : [non infecté / infecté / immunisé]', 'Normal : AgHBs négatif, Ac anti-HBc négatif, Ac anti-HBs >10 UI/L si vacciné', NULL, 1, NULL, NULL, '2026-03-06 14:09:54', 2, NULL),
(61, NULL, 102, 'Leucocytes : [valeur] /mm³\r\nHématies : [valeur] /mm³\r\nBactériologie : [positif/négatif]\r\nGerme : [espèce si positif]\r\nAntibiogramme : [résultats]', '<10/mm³\r\n<5/mm³\r\nPas de germe significatif (<10³ UFC/mL)', NULL, 1, NULL, NULL, '2026-03-06 14:09:54', 2, NULL),
(62, NULL, 103, 'Urée : [valeur] g/L\r\nCréatinine : [valeur] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', NULL, 1, NULL, NULL, '2026-03-06 14:09:54', 2, NULL),
(63, NULL, 149, 'ASAT (TGO) : [35] UI/L\r\nALAT (TGP) : [33] UI/L\r\nBilirubine totale : [6] mg/L\r\nPhosphatases alcalines : [70] UI/L', 'H : <40 UI/L | F : <35 UI/L\r\nH : <41 UI/L | F : <31 UI/L\r\n<10 mg/L (1 mg/dL)\r\nH : 40–130 UI/L | F : 35–105 UI/L', 'normal', 1, 'ok', NULL, '2026-03-07 09:36:28', 2, NULL),
(64, NULL, 150, 'VS 1ère heure : [11] mm/h\r\nVS 2ème heure : [10.5] mm/h', 'H : <15 mm/h | F : <20 mm/h', 'normal', 1, 'bon', NULL, '2026-03-07 09:36:29', 2, NULL),
(65, NULL, 151, 'Glycémie à jeun : [0.81] g/L ([0.81 ×10 = mg/dL])', 'H/F adulte : 0.70–1.10 g/L | Enfant : 0.60–1.00 g/L', 'normal', 1, 'ok', NULL, '2026-03-07 09:36:29', 2, NULL),
(66, NULL, 157, 'ASAT (TGO) : [30] UI/L\r\nALAT (TGP) : [35] UI/L\r\nBilirubine totale : [6] mg/L\r\nPhosphatases alcalines : [46] UI/L', 'H : <40 UI/L | F : <35 UI/L\r\nH : <41 UI/L | F : <31 UI/L\r\n<10 mg/L (1 mg/dL)\r\nH : 40–130 UI/L | F : 35–105 UI/L', 'anormal', 1, NULL, NULL, '2026-03-08 02:00:33', 3, NULL),
(67, NULL, 158, 'Sodium (Na) : [140] mmol/L\r\nPotassium (K) : [4] mmol/L\r\nChlorures (Cl) : [104] mmol/L\r\nBicarbonates (HCO₃) : [24] mmol/L', '135–145 mmol/L\r\n3.5–5.0 mmol/L\r\n98–106 mmol/L\r\n22–29 mmol/L', 'normal', 1, NULL, NULL, '2026-03-08 02:00:33', 3, NULL),
(68, NULL, 133, 'Urée : [valeur] g/L\r\nCréatinine : [valeur] mg/L', '0.15–0.45 g/L\r\nH : 7–13 mg/L | F : 5–11 mg/L', 'normal', 1, NULL, NULL, '2026-03-08 02:12:54', 3, NULL),
(69, NULL, 159, 'Aspect des selles : [normal / liquide / pâteux / sang]\r\nExamen direct : [parasites / levures / bactéries atypiques]\r\nCulture : [positive / négative]\r\nGerme isolé : [si positif]\r\nAntibiogramme : [résultats si positif]', 'Normal : flore commensale, absence de germe pathogène', 'normal', 1, NULL, NULL, '2026-03-08 03:01:22', 2, NULL),
(70, NULL, 160, '', NULL, NULL, 1, NULL, NULL, '2026-03-08 03:01:22', NULL, NULL),
(71, NULL, 161, 'ASAT (TGO) : [33] UI/L\r\nALAT (TGP) : [33] UI/L\r\nBilirubine totale : [5] mg/L\r\nPhosphatases alcalines : [67] UI/L', 'H : <40 UI/L | F : <35 UI/L\r\nH : <41 UI/L | F : <31 UI/L\r\n<10 mg/L (1 mg/dL)\r\nH : 40–130 UI/L | F : 35–105 UI/L', 'normal', 1, 'cjvkbj', NULL, '2026-03-08 03:06:59', 2, 'LAB-2026-0008');

--
-- Triggers `resultatslabo`
--
DELIMITER $$
CREATE TRIGGER `after_resultatslabo_insert_update_groupe` AFTER INSERT ON `resultatslabo` FOR EACH ROW BEGIN
    DECLARE v_id_groupe INT;
    DECLARE v_nb_actes INT;
    DECLARE v_nb_resultats INT;
    
    -- Récupérer l'id_groupe_prescription lié à cet acte prescrit
    SELECT ap.id_groupe_prescription INTO v_id_groupe
    FROM actes_presc ap
    WHERE ap.idactes_presc = NEW.idactes_presc
    LIMIT 1;
    
    IF v_id_groupe IS NOT NULL THEN
        -- Compter les actes labo du groupe
        SELECT COUNT(*) INTO v_nb_actes
        FROM actes_presc ap
        JOIN acte a ON ap.idacte = a.idacte
        WHERE ap.id_groupe_prescription = v_id_groupe
          AND a.idcategorie_acte = 6;  -- Catégorie labo
        
        -- Compter les résultats labo déjà saisis
        SELECT COUNT(*) INTO v_nb_resultats
        FROM actes_presc ap
        JOIN resultatslabo r ON ap.idactes_presc = r.idactes_presc
        WHERE ap.id_groupe_prescription = v_id_groupe;
        
        -- Si tous les résultats labo sont là, passer en 'termine' (logique simplifiée)
        IF v_nb_resultats >= v_nb_actes AND v_nb_actes > 0 THEN
            UPDATE groupe_prescriptions
            SET statut = 'termine'
            WHERE id_groupe_prescription = v_id_groupe
              AND statut != 'termine';
        ELSEIF v_nb_resultats > 0 THEN
            UPDATE groupe_prescriptions
            SET statut = 'en_cours'
            WHERE id_groupe_prescription = v_id_groupe
              AND statut = 'en_attente';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `resultatslabo_documents`
--

CREATE TABLE `resultatslabo_documents` (
  `iddocument` int NOT NULL,
  `idresultat` int NOT NULL,
  `nom_fichier` varchar(255) NOT NULL,
  `fichier_original` varchar(255) NOT NULL,
  `chemin_fichier` varchar(500) NOT NULL,
  `taille` int DEFAULT NULL,
  `type_mime` varchar(100) DEFAULT NULL,
  `description` text,
  `upload_par` int DEFAULT NULL,
  `date_upload` datetime NOT NULL,
  `actif` tinyint DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `resultatslabo_documents`
--

INSERT INTO `resultatslabo_documents` (`iddocument`, `idresultat`, `nom_fichier`, `fichier_original`, `chemin_fichier`, `taille`, `type_mime`, `description`, `upload_par`, `date_upload`, `actif`) VALUES
(1, 8, 'doc_8_699bdf92133ad.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_8_699bdf92133ad.pdf', 263920, 'application/pdf', NULL, 1, '2026-02-23 06:03:14', 1),
(2, 28, 'doc_28_699bdff27c23b.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_28_699bdff27c23b.pdf', 263920, 'application/pdf', NULL, 1, '2026-02-23 06:04:50', 1),
(3, 28, 'doc_28_699be11e31697.pdf', 'OKONDA.pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_28_699be11e31697.pdf', 316093, 'application/pdf', NULL, 1, '2026-02-23 06:09:50', 1),
(4, 28, 'doc_28_699be243acee5.pdf', 'rapport_complet_imagerie_20251218_032203.pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_28_699be243acee5.pdf', 9378, 'application/pdf', NULL, 1, '2026-02-23 06:14:43', 1),
(5, 28, 'doc_28_699be426eeae7.pdf', 'levons_les_yeux.pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_28_699be426eeae7.pdf', 80371, 'application/pdf', NULL, 1, '2026-02-23 06:22:47', 1),
(6, 29, 'doc_29_699f1aebcb7a3.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_29_699f1aebcb7a3.pdf', 263920, 'application/pdf', NULL, 1, '2026-02-25 16:53:15', 1),
(7, 41, 'doc_41_69a956fe4bf67.pdf', 'WhatsApp Image 2024-10-17 à 20.59.45_4af266c1 (1).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_41_69a956fe4bf67.pdf', 111518, 'application/pdf', NULL, 1, '2026-03-05 11:12:14', 1),
(8, 52, 'doc_52_69aaad0ea540d.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_52_69aaad0ea540d.pdf', 263920, 'application/pdf', NULL, 1, '2026-03-06 11:31:42', 1),
(9, 51, 'doc_51_69aaadc8c9390.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_51_69aaadc8c9390.pdf', 263920, 'application/pdf', NULL, 1, '2026-03-06 11:34:48', 1),
(10, 55, 'doc_55_69acc8c706a11.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_55_69acc8c706a11.pdf', 263920, 'application/pdf', NULL, 1, '2026-03-08 01:54:31', 1),
(11, 70, 'doc_70_69acd40c9f19c.pdf', 'Monkole flyer (4).pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_70_69acd40c9f19c.pdf', 263920, 'application/pdf', NULL, 1, '2026-03-08 02:42:36', 1),
(12, 70, 'doc_70_69acd8b99c978.pdf', 'Carte d\'électeur Boris_compressed.pdf', '/var/www/html/modules/labo/../../uploads/resultats_documents/doc_70_69acd8b99c978.pdf', 313974, 'application/pdf', NULL, 1, '2026-03-08 03:02:33', 1);

-- --------------------------------------------------------

--
-- Table structure for table `resultats_imagerie`
--

CREATE TABLE `resultats_imagerie` (
  `idresultat_imagerie` int NOT NULL,
  `idactes_presc` int NOT NULL,
  `technique` text,
  `description` text,
  `conclusion` text,
  `recommandations` text,
  `radiologue` int DEFAULT NULL,
  `fichier_externe` varchar(255) DEFAULT NULL,
  `date_examen` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salle_bloc`
--

CREATE TABLE `salle_bloc` (
  `idsalle_bloc` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `description` text,
  `idunite_med` int DEFAULT NULL,
  `equipement` text,
  `type_salle` enum('chirurgie','obstetrique','orthopedie','neurochirurgie','cardiologie','pediatrie','gynecologie','urologie','ophthalmologie','ORL','urgence','stérilisation','reanimation','autre') DEFAULT 'chirurgie',
  `statut` enum('disponible','occupée','nettoyage','maintenance','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'disponible',
  `actif` tinyint(1) DEFAULT '1',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `idsite` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `salle_bloc`
--

INSERT INTO `salle_bloc` (`idsalle_bloc`, `nom`, `code`, `description`, `idunite_med`, `equipement`, `type_salle`, `statut`, `actif`, `date_creation`, `idsite`) VALUES
(1, 'Salle Chirurgie 1', 'SC1', 'Salle de chirurgie g?n?rale ?quip?e', NULL, NULL, 'chirurgie', 'disponible', 1, '2025-12-13 09:47:04', 1),
(2, 'Salle Chirurgie 2', 'SC2', 'Salle de chirurgie orthop?dique', NULL, NULL, 'chirurgie', 'disponible', 1, '2025-12-13 09:47:04', 1),
(3, 'Salle Obst?trique', 'SOBS', 'Salle de c?sarienne et accouchement', NULL, NULL, 'chirurgie', 'disponible', 1, '2025-12-13 09:47:04', 1),
(4, 'Salle Urgences', 'SURG', 'Salle de chirurgie d\'urgence', NULL, NULL, 'chirurgie', 'disponible', 1, '2025-12-13 09:47:04', 1),
(5, 'Salle Polyvalente', 'SPOLY', 'Salle pour interventions diverses', NULL, NULL, 'chirurgie', 'disponible', 1, '2025-12-13 09:47:04', 1),
(11, 'Salle 1 - Chirurgie Générale', 'S1', NULL, NULL, 'Table opératoire électrique, Scialytique LED, Bistouri électrique, Aspirateur chirurgical', 'chirurgie', 'disponible', 1, '2025-12-15 16:19:12', 1),
(12, 'Salle 2 - Chirurgie Septique', 'S2', NULL, NULL, 'Table opératoire hydraulique, Scialytique halogène, Bistouri électrique, Aspirateur', 'chirurgie', 'disponible', 1, '2025-12-15 16:19:12', 1),
(13, 'Salle 3 - Orthopédie', 'S3', NULL, NULL, 'Table orthopédique, Amplificateur de brillance, Matériel d\'ostéosynthèse', 'chirurgie', 'disponible', 1, '2025-12-15 16:19:12', 1),
(14, 'Salle 4 - Gynécologie', 'S4', NULL, NULL, 'Table gynécologique, Colonne cœlioscopie, Hystéroscope', 'chirurgie', 'disponible', 1, '2025-12-15 16:19:12', 1),
(15, 'Salle Urgence', 'SU', NULL, NULL, 'Équipement complet pour interventions d\'urgence', 'chirurgie', 'disponible', 1, '2025-12-15 16:19:12', 1),
(26, 'Salle 1 - Chirurgie Générale', 'S1', NULL, NULL, 'Table opératoire électrique, Scialytique LED, Bistouri électrique, Aspirateur chirurgical', 'chirurgie', 'disponible', 1, '2025-12-15 22:12:26', 1),
(27, 'Salle 2 - Chirurgie Septique', 'S2', NULL, NULL, 'Table opératoire hydraulique, Scialytique halogène, Bistouri électrique, Aspirateur', 'chirurgie', 'disponible', 1, '2025-12-15 22:12:26', 1),
(28, 'Salle 3 - Orthopédie', 'S3', NULL, NULL, 'Table orthopédique, Amplificateur de brillance, Matériel d\'ostéosynthèse', 'chirurgie', 'disponible', 1, '2025-12-15 22:12:26', 1),
(29, 'Salle 4 - Gynécologie', 'S4', NULL, NULL, 'Table gynécologique, Colonne cœlioscopie, Hystéroscope', 'chirurgie', 'disponible', 1, '2025-12-15 22:12:26', 1),
(30, 'Salle Urgence', 'SU', NULL, NULL, 'Équipement complet pour interventions d\'urgence', 'chirurgie', 'disponible', 1, '2025-12-15 22:12:26', 1);

-- --------------------------------------------------------

--
-- Table structure for table `sejour`
--

CREATE TABLE `sejour` (
  `idsejour` int NOT NULL,
  `idpatient` int NOT NULL,
  `numero_sejour` varchar(20) NOT NULL,
  `type_sejour` enum('ambulatoire','urgence','hospitalisation') NOT NULL DEFAULT 'ambulatoire',
  `idsite` int NOT NULL,
  `idmotif` int DEFAULT NULL,
  `idorigine` int DEFAULT NULL,
  `anciennete` enum('nouveau','ancien') DEFAULT 'nouveau',
  `numero_jeton` varchar(20) DEFAULT NULL,
  `date_entree` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_sortie` timestamp NULL DEFAULT NULL,
  `statut` enum('en_cours','termine','annule') DEFAULT 'en_cours',
  `observation` text,
  `idutilisateur` int DEFAULT NULL,
  `date_prevue_sortie` date DEFAULT NULL,
  `pdf_resultats_genere` tinyint(1) DEFAULT '0' COMMENT 'PDF complet généré',
  `date_pdf_resultats` datetime DEFAULT NULL COMMENT 'Date génération PDF',
  `chemin_pdf_resultats` varchar(255) DEFAULT NULL COMMENT 'Chemin fichier PDF',
  `pdf_envoye_prescripteur` tinyint(1) DEFAULT '0' COMMENT 'PDF envoyé au prescripteur',
  `date_envoi_pdf` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sejour`
--

INSERT INTO `sejour` (`idsejour`, `idpatient`, `numero_sejour`, `type_sejour`, `idsite`, `idmotif`, `idorigine`, `anciennete`, `numero_jeton`, `date_entree`, `date_sortie`, `statut`, `observation`, `idutilisateur`, `date_prevue_sortie`, `pdf_resultats_genere`, `date_pdf_resultats`, `chemin_pdf_resultats`, `pdf_envoye_prescripteur`, `date_envoi_pdf`) VALUES
(1, 1, 'AMB000100', 'ambulatoire', 1, 1, 1, 'nouveau', '100', '2025-12-07 13:19:21', NULL, 'en_cours', NULL, 2, NULL, 0, NULL, NULL, 0, NULL),
(2, 2, 'AMB000101', 'ambulatoire', 1, 2, 1, 'ancien', '101', '2025-12-07 13:19:21', NULL, 'en_cours', NULL, 2, NULL, 0, NULL, NULL, 0, NULL),
(3, 3, 'AMB000102', 'ambulatoire', 1, 3, 1, 'nouveau', '102', '2025-12-07 13:19:21', NULL, 'en_cours', NULL, 2, NULL, 0, NULL, NULL, 0, NULL),
(19, 4, 'AMB000103', 'ambulatoire', 2, 2, 2, 'ancien', 'A4', '2025-12-08 22:32:38', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(20, 1, 'AMB000104', 'ambulatoire', 2, 4, 1, 'nouveau', 'A5', '2025-12-08 22:58:51', NULL, 'en_cours', 'un peu faible', 1, NULL, 0, NULL, NULL, 0, NULL),
(23, 1, 'AMB000105', 'ambulatoire', 2, 4, 1, 'nouveau', 'A6', '2025-12-08 23:26:09', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(24, 4, 'AMB000106', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-08 23:32:26', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(25, 4, 'AMB000107', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-08 23:43:19', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(26, 4, 'AMB000108', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-08 23:43:38', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(27, 4, 'AMB000109', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-08 23:57:56', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(28, 4, 'AMB000110', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-09 00:08:16', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(29, 4, 'AMB000111', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-09 00:26:40', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(30, 4, 'AMB000112', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-09 00:29:40', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(31, 4, 'AMB000113', 'ambulatoire', 2, 4, 2, 'ancien', 'A7', '2025-12-09 00:32:54', NULL, 'en_cours', 'douleur dos', 1, NULL, 0, NULL, NULL, 0, NULL),
(32, 4, 'AMB000114', 'ambulatoire', 1, 4, 1, 'nouveau', 'A7', '2025-12-09 00:37:51', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(33, 3, 'AMB000115', 'ambulatoire', 1, 2, 1, 'nouveau', 'A8', '2025-12-09 00:44:24', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(34, 2, 'AMB000116', 'ambulatoire', 2, 6, 2, 'nouveau', 'A9', '2025-12-09 00:49:40', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(35, 2, 'AMB000117', 'ambulatoire', 2, 6, 2, 'nouveau', 'A9', '2025-12-09 00:50:22', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(36, 2, 'AMB000118', 'ambulatoire', 1, 6, 1, 'nouveau', 'A10', '2025-12-09 00:51:59', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(37, 4, 'AMB000119', 'ambulatoire', 1, 4, 1, 'nouveau', NULL, '2025-12-11 06:36:08', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(38, 4, 'HOS000001', 'hospitalisation', 1, 11, 4, 'ancien', NULL, '2025-12-11 06:52:29', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(39, 4, 'HOS000002', 'hospitalisation', 1, 12, 1, 'ancien', NULL, '2025-12-11 07:00:19', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(40, 4, 'AMB000120', 'ambulatoire', 1, NULL, 2, 'ancien', NULL, '2025-12-11 08:01:35', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(41, 4, 'AMB000121', 'ambulatoire', 1, NULL, 3, 'ancien', NULL, '2025-12-11 08:03:09', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(42, 4, 'AMB000122', 'ambulatoire', 1, NULL, 2, 'ancien', NULL, '2025-12-11 08:41:07', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(43, 4, 'AMB000123', 'ambulatoire', 1, 5, 2, 'ancien', NULL, '2025-12-11 08:42:48', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(44, 4, 'AMB000124', 'ambulatoire', 1, 13, 2, 'ancien', NULL, '2025-12-11 08:58:54', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(45, 4, 'HOS000003', 'hospitalisation', 1, 11, 1, 'ancien', NULL, '2025-12-11 09:11:55', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(46, 4, 'URG000001', 'urgence', 1, 8, 3, 'ancien', NULL, '2025-12-11 09:12:51', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(47, 4, 'URG000002', 'urgence', 1, 7, 1, 'ancien', NULL, '2025-12-11 09:21:47', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(48, 4, 'URG000003', 'urgence', 1, 13, 1, 'ancien', NULL, '2025-12-11 09:26:04', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(49, 4, 'URG000004', 'urgence', 1, 14, 4, 'ancien', NULL, '2025-12-11 09:54:01', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(50, 4, 'URG000005', 'urgence', 1, 15, 4, 'ancien', NULL, '2025-12-11 09:54:55', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(51, 4, 'URG000006', 'urgence', 1, 15, 4, 'ancien', NULL, '2025-12-12 04:36:06', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(52, 5, 'URG000007', 'hospitalisation', 1, 9, 1, 'nouveau', NULL, '2025-12-13 10:01:35', NULL, 'en_cours', NULL, 1, NULL, 0, NULL, NULL, 0, NULL),
(62, 20, '', 'hospitalisation', 1, 9, 1, 'nouveau', NULL, '2025-12-13 10:12:22', NULL, 'en_cours', NULL, 1, NULL, 0, NULL, NULL, 0, NULL),
(63, 4, 'HOS000004', 'hospitalisation', 1, 10, 3, 'ancien', NULL, '2025-12-15 19:23:48', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(64, 4, 'HOS000005', 'hospitalisation', 1, 10, 3, 'ancien', NULL, '2025-12-15 19:28:51', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(65, 4, 'HOS000006', 'hospitalisation', 1, 10, 3, 'ancien', NULL, '2025-12-15 19:28:56', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(66, 21, 'URG000008', 'urgence', 1, 16, 1, 'nouveau', NULL, '2025-12-19 10:17:16', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(67, 21, 'AMB000125', 'ambulatoire', 1, 2, 1, 'ancien', NULL, '2025-12-19 11:53:48', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(68, 21, 'AMB000126', 'ambulatoire', 1, 3, 1, 'ancien', NULL, '2025-12-19 11:58:56', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(69, 21, 'AMB000127', 'ambulatoire', 1, 3, 1, 'ancien', NULL, '2025-12-19 12:02:12', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(70, 21, 'AMB000128', 'ambulatoire', 1, 13, 1, 'ancien', NULL, '2025-12-19 12:04:27', NULL, 'en_cours', '', 1, NULL, 0, NULL, NULL, 0, NULL),
(71, 22, 'AMB000129', 'ambulatoire', 1, 17, 1, 'nouveau', NULL, '2026-01-02 06:21:06', NULL, 'en_cours', 'gonflément de la joue gauche', 1, NULL, 0, NULL, NULL, 0, NULL),
(74, 9, 'HOS000007', 'ambulatoire', 3, 8, 3, 'nouveau', '14', '2026-03-05 21:32:54', NULL, 'en_cours', NULL, NULL, NULL, 0, NULL, NULL, 0, NULL);

--
-- Triggers `sejour`
--
DELIMITER $$
CREATE TRIGGER `after_sejour_termine_generer_pdf` AFTER UPDATE ON `sejour` FOR EACH ROW BEGIN
    -- Si le séjour passe à "termine" et qu'aucun PDF n'existe
    IF NEW.statut = 'termine' 
       AND OLD.statut != 'termine'
       AND (NEW.pdf_resultats_genere IS NULL OR NEW.pdf_resultats_genere = 0) THEN
        
        -- Vérifier si le séjour a au moins un résultat
        SET @nb_resultats = (
            SELECT COUNT(*)
            FROM actes_presc ap
            LEFT JOIN resultatslabo r ON ap.idactes_presc = r.idactes_presc
            WHERE ap.idsous_sejour IN (SELECT idsous_sejour FROM sous_sejour WHERE idsejour = NEW.idsejour)
              AND r.idresultat IS NOT NULL
        );
        
        -- Si au moins 1 résultat, notifier qu'un PDF doit être généré
        IF @nb_resultats > 0 THEN
            -- Insérer une notification dans services_notifications
            INSERT INTO services_notifications (
                service,
                type_notification,
                id_reference,
                code_reference,
                titre,
                message,
                groupe_destinataire,
                created_at
            ) VALUES (
                'system',
                'info',
                NEW.idsejour,
                NEW.numero_sejour,
                'Séjour terminé - PDF à générer',
                CONCAT('Le séjour #', NEW.numero_sejour, ' est terminé avec ', @nb_resultats, ' résultat(s). Générer le PDF complet.'),
                'admins',
                NOW()
            );
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `idservices` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `type_service` enum('ambulatoire','urgence','hospitalisation','bloc','labo','imagerie') NOT NULL,
  `idsite` int DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`idservices`, `nom`, `code`, `type_service`, `idsite`, `actif`) VALUES
(1, 'Ambulatoire', 'AMB', 'ambulatoire', 1, 1),
(2, 'Urgences', 'URG', 'urgence', 1, 1),
(3, 'Hospitalisation', 'HOSP', 'hospitalisation', 1, 1),
(4, 'Bloc Opératoire', 'BLOC', 'bloc', 1, 1),
(5, 'Laboratoire', 'LABO', 'labo', 1, 1),
(6, 'Imagerie', 'IMG', 'imagerie', 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `services_notifications`
--

CREATE TABLE `services_notifications` (
  `idnotification` int NOT NULL,
  `service` enum('labo','imagerie','pharmacie') NOT NULL,
  `type_notification` enum('info','alerte','urgence','validation') NOT NULL,
  `id_reference` int NOT NULL,
  `code_reference` varchar(50) DEFAULT NULL,
  `titre` varchar(200) DEFAULT NULL,
  `message` text,
  `destinateur` int DEFAULT NULL,
  `destinataire` int DEFAULT NULL,
  `lu` tinyint(1) DEFAULT '0',
  `date_lecture` datetime DEFAULT NULL,
  `actions_possibles` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `services_notifications`
--

INSERT INTO `services_notifications` (`idnotification`, `service`, `type_notification`, `id_reference`, `code_reference`, `titre`, `message`, `destinateur`, `destinataire`, `lu`, `date_lecture`, `actions_possibles`, `metadata`, `created_at`) VALUES
(1, 'labo', 'info', 4, 'LAB-20250211-0002', 'Résultat labo disponible — Échographie Testiculaire', '✅ Normal | Patient : Boris Ikula | Acte : Échographie Testiculaire (ECHO-TEST) | Saisi par : Papy KIBETE', 28, 1, 0, NULL, NULL, NULL, '2026-02-17 12:15:02'),
(2, 'labo', 'info', 5, 'LAB-20260217-0007', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '⚠️ Anormal | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Papy KIBETE', 28, 28, 0, NULL, NULL, NULL, '2026-02-19 18:35:54'),
(3, 'labo', 'info', 6, 'LAB-20250211-0001', 'Résultat labo disponible — Sérologie Hépatite B', 'Disponible | Patient : Boris Ikula | Acte : Sérologie Hépatite B (SERO-HBV) | Saisi par : Système Admin', 1, 1, 0, NULL, NULL, NULL, '2026-02-21 07:40:16'),
(4, 'labo', 'info', 7, 'LAB-20260211-0002', 'Résultat labo disponible — Urée et Créatinine', 'Disponible | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 1, 1, 0, NULL, NULL, NULL, '2026-02-21 08:18:32'),
(5, 'labo', 'info', 8, 'LAB-20260217-0003', 'Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 1, 28, 0, NULL, NULL, NULL, '2026-02-21 08:48:47'),
(6, 'labo', 'info', 8, 'LAB-20260217-0003', 'Résultat labo disponible — Urée et Créatinine', '✅ Normal | Patient : Boris Ikula | Acte : Urée et Créatinine (UREE-CREAT) | Saisi par : Système Admin', 1, 28, 0, NULL, NULL, NULL, '2026-02-21 08:48:54'),
(7, 'labo', 'info', 9, 'LAB-20260219-0004', 'Résultat labo disponible — TP, TCA, INR', 'Disponible | Patient : Boris Ikula | Acte : TP, TCA, INR (COAG) | Saisi par : Système Admin', 1, 28, 0, NULL, NULL, NULL, '2026-02-21 08:55:02'),
(8, 'labo', 'info', 15, 'LAB-20260218-0002', 'Résultat labo disponible — VS (Vitesse de Sédimentation)', 'Disponible | Patient : Jean Ikula | Acte : VS (Vitesse de Sédimentation) (VS) | Saisi par : Système Admin', 1, 1, 0, NULL, NULL, NULL, '2026-02-21 10:39:48'),
(9, 'labo', 'info', 16, 'LAB-20260217-3415', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', 'Disponible | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 1, 28, 0, NULL, NULL, NULL, '2026-02-21 10:41:55'),
(10, 'labo', 'info', 5, 'LAB-20260217-0007', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '⚠️ Anormal | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 1, 28, 0, NULL, NULL, NULL, '2026-02-21 10:42:24'),
(11, 'labo', 'info', 5, 'LAB-20260217-0007', 'Résultat labo disponible — Test de Grossesse Sanguin (β-HCG)', '⚠️ Anormal | Patient : Boris Ikula | Acte : Test de Grossesse Sanguin (β-HCG) (BHCG) | Saisi par : Système Admin', 1, 28, 0, NULL, NULL, NULL, '2026-02-21 10:42:32');

-- --------------------------------------------------------

--
-- Table structure for table `site`
--

CREATE TABLE `site` (
  `idsite` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `abrege` varchar(10) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `site`
--

INSERT INTO `site` (`idsite`, `nom`, `abrege`, `adresse`, `telephone`, `email`, `actif`, `created_at`, `updated_at`) VALUES
(1, 'Centre Hospitalier Monkole Essenza', 'CHME', 'Avenue Monkole, Kinshasa', '+243 XXX XXX XXX', 'chme@monkole.cd', 1, '2025-12-07 12:35:59', '2025-12-07 12:35:59'),
(2, 'Centre Médico Missionnaire de Gombe', 'CMMG', 'Avenue Gombe, Kinshasa', '+243 XXX XXX XXX', 'cmmg@monkole.cd', 1, '2025-12-07 12:35:59', '2025-12-13 14:36:18'),
(3, 'Hôpital Monkole', NULL, 'Kinshasa, RDC', NULL, NULL, 1, '2025-12-07 13:11:04', '2025-12-13 14:36:25');

-- --------------------------------------------------------

--
-- Table structure for table `societe`
--

CREATE TABLE `societe` (
  `idsociete` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `sigle` varchar(20) DEFAULT NULL,
  `adresse` varchar(255) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `type_tarif` enum('acte','forfait_global','forfait_partiel') DEFAULT 'acte',
  `date_debut_contrat` date DEFAULT NULL,
  `date_fin_contrat` date DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `societe`
--

INSERT INTO `societe` (`idsociete`, `nom`, `sigle`, `adresse`, `telephone`, `email`, `type_tarif`, `date_debut_contrat`, `date_fin_contrat`, `actif`, `created_at`) VALUES
(1, 'BANQUE CENTRALE DU CONGO', 'BCC', 'Boulevard Colonel Tshatshi, Kinshasa', '+243 XXX XXX XXX', 'social@bcc.cd', 'acte', '2025-01-01', '2025-12-31', 1, '2025-12-07 12:36:00'),
(2, 'SOCIETE NATIONALE D\'ELECTRICITE', 'SNEL', 'Kinshasa', '+243 XXX XXX XXX', 'rh@snel.cd', 'forfait_partiel', '2025-01-01', '2025-12-31', 1, '2025-12-07 12:36:00'),
(3, 'REGIDESO', 'REGIDESO', 'Kinshasa', '+243 XXX XXX XXX', 'admin@regideso.cd', 'acte', '2025-01-01', '2025-12-31', 1, '2025-12-07 12:36:00');

-- --------------------------------------------------------

--
-- Table structure for table `societe_tarif`
--

CREATE TABLE `societe_tarif` (
  `idsociete` int NOT NULL,
  `idtarif` int NOT NULL,
  `idcategorie` int NOT NULL,
  `idsite` int DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `taux_couverture` decimal(5,2) DEFAULT '80.00' COMMENT 'Taux de couverture en pourcentage'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `societe_tarif`
--

INSERT INTO `societe_tarif` (`idsociete`, `idtarif`, `idcategorie`, `idsite`, `date_debut`, `date_fin`, `actif`, `taux_couverture`) VALUES
(1, 1, 1, 1, '2025-01-01', NULL, 1, 80.00),
(2, 1, 1, 1, '2025-01-01', NULL, 1, 80.00),
(3, 1, 1, 1, '2025-01-01', NULL, 1, 80.00);

-- --------------------------------------------------------

--
-- Table structure for table `soins_infirmiers`
--

CREATE TABLE `soins_infirmiers` (
  `idsoins` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `type_soins` varchar(100) DEFAULT NULL,
  `description` text,
  `date_soins` datetime DEFAULT NULL,
  `idinfirmier` int DEFAULT NULL,
  `observations` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sortieprod`
--

CREATE TABLE `sortieprod` (
  `idsortieprod` int NOT NULL,
  `idofficine` int NOT NULL,
  `iddestination` int NOT NULL,
  `numero_sortie` varchar(20) DEFAULT NULL,
  `date_sortie` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `observation` text,
  `idutilisateur` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sorties_stock`
--

CREATE TABLE `sorties_stock` (
  `idsortie_stock` int NOT NULL,
  `idprodpharma` int NOT NULL,
  `idofficine` int NOT NULL,
  `quantite` int NOT NULL,
  `type_sortie` enum('prescription','transfert','perte','ajustement') DEFAULT 'prescription',
  `iddestination` int DEFAULT NULL,
  `idpharma_presc` int DEFAULT NULL,
  `idutilisateur` int NOT NULL,
  `date_sortie` datetime DEFAULT CURRENT_TIMESTAMP,
  `observation` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sorties_stock`
--

INSERT INTO `sorties_stock` (`idsortie_stock`, `idprodpharma`, `idofficine`, `quantite`, `type_sortie`, `iddestination`, `idpharma_presc`, `idutilisateur`, `date_sortie`, `observation`) VALUES
(1, 2, 1, 6, 'ajustement', 2, NULL, 1, '2026-02-20 13:08:58', NULL),
(2, 2, 1, 50, 'prescription', 1, NULL, 1, '2026-02-20 23:44:21', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sous_sejour`
--

CREATE TABLE `sous_sejour` (
  `idsous_sejour` int NOT NULL,
  `idsejour` int NOT NULL,
  `idunite_med` int NOT NULL,
  `idunitehospi` int DEFAULT NULL,
  `idmotif` int DEFAULT NULL,
  `numero_sous_sejour` varchar(20) DEFAULT NULL,
  `date_entree` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_sortie` timestamp NULL DEFAULT NULL,
  `statut` enum('en_cours','termine') DEFAULT 'en_cours',
  `observation` text,
  `date_prevue_sortie` date DEFAULT NULL,
  `idlit_actuel` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sous_sejour`
--

INSERT INTO `sous_sejour` (`idsous_sejour`, `idsejour`, `idunite_med`, `idunitehospi`, `idmotif`, `numero_sous_sejour`, `date_entree`, `date_sortie`, `statut`, `observation`, `date_prevue_sortie`, `idlit_actuel`) VALUES
(1, 1, 1, NULL, 1, 'SS000100', '2025-12-07 13:19:21', NULL, 'en_cours', NULL, NULL, NULL),
(2, 2, 1, NULL, 3, 'SS000101', '2025-12-07 13:19:21', NULL, 'en_cours', NULL, NULL, NULL),
(3, 3, 1, NULL, 4, 'SS000102', '2025-12-07 13:19:21', NULL, 'en_cours', NULL, NULL, NULL),
(19, 19, 1, NULL, 2, 'SS000103', '2025-12-08 22:32:38', NULL, 'en_cours', NULL, NULL, NULL),
(20, 20, 1, NULL, 4, 'SS000104', '2025-12-08 22:58:51', NULL, 'en_cours', NULL, NULL, NULL),
(23, 23, 1, NULL, 4, 'SS000105', '2025-12-08 23:26:09', NULL, 'en_cours', NULL, NULL, NULL),
(24, 24, 1, NULL, 4, 'SS000106', '2025-12-08 23:32:26', NULL, 'en_cours', NULL, NULL, NULL),
(25, 25, 1, NULL, 4, 'SS000107', '2025-12-08 23:43:19', NULL, 'en_cours', NULL, NULL, NULL),
(26, 26, 1, NULL, 4, 'SS000108', '2025-12-08 23:43:38', NULL, 'en_cours', NULL, NULL, NULL),
(27, 27, 1, NULL, 4, 'SS000109', '2025-12-08 23:57:56', NULL, 'en_cours', NULL, NULL, NULL),
(28, 28, 1, NULL, 4, 'SS000110', '2025-12-09 00:08:16', NULL, 'en_cours', NULL, NULL, NULL),
(29, 29, 1, NULL, 4, 'SS000111', '2025-12-09 00:26:40', NULL, 'en_cours', NULL, NULL, NULL),
(30, 30, 1, NULL, 4, 'SS000112', '2025-12-09 00:29:40', NULL, 'en_cours', NULL, NULL, NULL),
(31, 31, 1, NULL, 4, 'SS000113', '2025-12-09 00:32:54', NULL, 'en_cours', NULL, NULL, NULL),
(32, 32, 1, NULL, 4, 'SS000114', '2025-12-09 00:37:51', NULL, 'en_cours', NULL, NULL, NULL),
(33, 33, 1, NULL, 2, 'SS000115', '2025-12-09 00:44:24', NULL, 'en_cours', NULL, NULL, NULL),
(34, 34, 1, NULL, 6, 'SS000116', '2025-12-09 00:49:40', NULL, 'en_cours', NULL, NULL, NULL),
(35, 35, 1, NULL, 6, 'SS000117', '2025-12-09 00:50:22', NULL, 'en_cours', NULL, NULL, NULL),
(36, 36, 1, NULL, 6, 'SS000118', '2025-12-09 00:51:59', NULL, 'en_cours', NULL, NULL, NULL),
(37, 37, 1, NULL, 4, 'SS000119', '2025-12-11 06:36:08', NULL, 'en_cours', NULL, NULL, NULL),
(38, 38, 3, 4, 11, 'SS000120', '2025-12-11 06:52:29', NULL, 'en_cours', NULL, NULL, NULL),
(39, 39, 7, 3, 12, 'SS000121', '2025-12-11 07:00:19', NULL, 'en_cours', NULL, NULL, NULL),
(40, 40, 1, NULL, NULL, 'SS000122', '2025-12-11 08:01:35', NULL, 'en_cours', NULL, NULL, NULL),
(41, 41, 1, NULL, NULL, 'SS000123', '2025-12-11 08:03:09', NULL, 'en_cours', NULL, NULL, NULL),
(42, 42, 1, NULL, NULL, 'SS000124', '2025-12-11 08:41:07', NULL, 'en_cours', NULL, NULL, NULL),
(43, 43, 1, NULL, 5, 'SS000125', '2025-12-11 08:42:48', NULL, 'en_cours', NULL, NULL, NULL),
(44, 44, 1, NULL, 13, 'SS000126', '2025-12-11 08:58:54', NULL, 'en_cours', NULL, NULL, NULL),
(45, 45, 7, 3, 11, 'SS000127', '2025-12-11 09:11:55', NULL, 'en_cours', NULL, NULL, NULL),
(46, 46, 5, NULL, 8, 'SS000128', '2025-12-11 09:12:51', NULL, 'en_cours', NULL, NULL, NULL),
(47, 47, 5, NULL, 7, 'SS000129', '2025-12-11 09:21:47', NULL, 'en_cours', NULL, NULL, NULL),
(48, 48, 5, NULL, 13, 'SS000130', '2025-12-11 09:26:04', NULL, 'en_cours', NULL, NULL, NULL),
(49, 49, 5, NULL, 14, 'SS000131', '2025-12-11 09:54:01', NULL, 'en_cours', NULL, NULL, NULL),
(50, 50, 5, NULL, 15, 'SS000132', '2025-12-11 09:54:55', NULL, 'en_cours', NULL, NULL, NULL),
(51, 51, 5, NULL, 15, 'SS000133', '2025-12-12 04:36:06', NULL, 'en_cours', NULL, NULL, NULL),
(57, 52, 1, NULL, 9, 'SS000134', '2025-12-13 10:03:06', NULL, 'en_cours', NULL, NULL, NULL),
(62, 62, 1, NULL, 9, 'SSBLOC001', '2025-12-13 10:12:22', NULL, 'en_cours', NULL, NULL, NULL),
(63, 63, 1, 3, 10, 'SS000135', '2025-12-15 19:23:48', NULL, 'en_cours', NULL, NULL, NULL),
(64, 64, 1, 3, 10, 'SS000136', '2025-12-15 19:28:51', NULL, 'en_cours', NULL, NULL, NULL),
(65, 65, 1, 3, 10, 'SS000137', '2025-12-15 19:28:56', NULL, 'en_cours', NULL, NULL, NULL),
(66, 66, 5, NULL, 16, 'SS000138', '2025-12-19 10:17:16', NULL, 'en_cours', NULL, NULL, NULL),
(67, 71, 4, NULL, NULL, 'SS004248', '2026-01-02 06:25:04', NULL, 'en_cours', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sous_specialite`
--

CREATE TABLE `sous_specialite` (
  `idsous_specialite` int NOT NULL,
  `idspecialite` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `description` text,
  `actif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sous_specialite`
--

INSERT INTO `sous_specialite` (`idsous_specialite`, `idspecialite`, `nom`, `code`, `description`, `actif`) VALUES
(1, 1, 'Consultation Générale', 'CG', NULL, 1),
(2, 2, 'Pédiatrie Générale', 'PG', NULL, 1),
(3, 2, 'Néonatologie', 'NEO', NULL, 1),
(4, 3, 'Gynécologie', 'GYNE', NULL, 1),
(5, 3, 'Obstétrique', 'OBST', NULL, 1),
(6, 3, 'Planning Familial', 'PF', NULL, 1),
(7, 4, 'Chirurgie Générale', 'CG', NULL, 1),
(8, 4, 'Chirurgie Pédiatrique', 'CP', NULL, 1),
(10, 15, 'Échographie Générale', 'ECHO-GEN', 'Échographie médicale générale', 1),
(11, 5, 'Cardiologie Non-invasive', 'CARD-NI', 'Examens cardiaques non invasifs', 1),
(12, 21, 'Gastro-entérologie Fonctionnelle', 'GASTRO-F', 'Examens digestifs', 1),
(13, 22, 'Néphrologie Diagnostic', 'NEPHRO-D', 'Examens rénaux', 1),
(14, 20, 'Explorations Fonctionnelles Respiratoires', 'EFR', 'Tests respiratoires', 1),
(15, 3, 'Obstétrique Diagnostic', 'OBST-D', 'Examens obstétricaux', 1),
(16, 13, 'Neurologie Diagnostic', 'NEURO-D', 'Examens neurologiques', 1),
(17, 12, 'Orthopédie Diagnostic', 'ORTHO-D', 'Examens orthopédiques', 1),
(18, 8, 'ORL Diagnostic', 'ORL-D', 'Examens ORL', 1),
(19, 11, 'Urologie Diagnostic', 'URO-D', 'Examens urologiques', 1),
(20, 4, 'Chirurgie Diagnostic', 'CHIR-D', 'Examens pré-opératoires', 1),
(22, 15, 'Radiologie Conventionnelle', 'RAD-CONV', 'Radiographie standard', 1),
(23, 16, 'Hématologie Cellulaire', 'HEMA-CELL', 'Analyses cellulaires', 1),
(24, 16, 'Coagulation', 'COAG', 'Tests de coagulation', 1),
(25, 16, 'Biochimie', 'BIOCHIM', 'Analyses biochimiques', 1),
(26, 1, 'Médecine du Voyage', 'MV', 'Vaccinations et conseils voyage', 1),
(27, 1, 'Médecine Préventive', 'MP', 'Bilans de santé et prévention', 1),
(28, 2, 'Pédiatrie Sociale', 'PS', 'Aspects sociaux de la pédiatrie', 1),
(29, 3, 'Échographie Gynécologique', 'ECHO-GYN', 'Échographie spécialisée', 1),
(30, 4, 'Chirurgie Digestive', 'CD', 'Chirurgie de l\'appareil digestif', 1),
(31, 4, 'Chirurgie Viscérale', 'CV', 'Chirurgie des organes internes', 1),
(32, 4, 'Chirurgie Oncologique', 'CO', 'Chirurgie des cancers', 1),
(33, 5, 'Cardiologie Interventionnelle', 'CI', 'Cathétérisme cardiaque', 1),
(34, 5, 'Rythmologie', 'RHYTHMO', 'Troubles du rythme cardiaque', 1),
(35, 5, 'Cardiologie Pédiatrique', 'CARD-PED', 'Cardiologie chez l\'enfant', 1),
(36, 6, 'Dentisterie Conservatrice', 'DC', 'Soins dentaires de base', 1),
(37, 6, 'Parodontologie', 'PARO', 'Maladies des gencives', 1),
(38, 6, 'Orthodontie', 'ORTHO-D', 'Correction dentaire', 1),
(39, 7, 'Rétinopathie', 'RETINO', 'Pathologies de la rétine', 1),
(40, 7, 'Glaucome', 'GLAU', 'Pathologies du glaucome', 1),
(41, 7, 'Cornée', 'CORNE', 'Pathologies cornéennes', 1),
(42, 8, 'Audiologie', 'AUDIO', 'Problèmes auditifs', 1),
(43, 8, 'Phoniatrie', 'PHON', 'Troubles de la voix', 1),
(44, 8, 'Otologie', 'OTO', 'Pathologies de l\'oreille', 1),
(45, 9, 'Dermato-cosmétologie', 'DERMA-COS', 'Dermatologie esthétique', 1),
(46, 9, 'Dermatologie Pédiatrique', 'DERMA-PED', 'Dermatologie chez l\'enfant', 1);

-- --------------------------------------------------------

--
-- Table structure for table `specialite`
--

CREATE TABLE `specialite` (
  `idspecialite` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `code` varchar(20) DEFAULT NULL,
  `description` text,
  `actif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `specialite`
--

INSERT INTO `specialite` (`idspecialite`, `nom`, `code`, `description`, `actif`) VALUES
(1, 'Médecine Générale', 'MG', 'Consultations générales', 1),
(2, 'Pédiatrie', 'PED', 'Soins des enfants', 1),
(3, 'Gynécologie-Obstétrique', 'GO', 'Santé de la femme', 1),
(4, 'Chirurgie', 'CHIR', 'Interventions chirurgicales', 1),
(5, 'Cardiologie', 'CARDIO', 'Pathologies cardiovasculaires', 1),
(6, 'Dentisterie', 'DENT', 'Soins dentaires', 1),
(7, 'Ophtalmologie', 'OPHTA', 'Soins des yeux', 1),
(8, 'ORL', 'ORL', 'Oreille, nez, gorge', 1),
(9, 'Dermatologie', 'DERMA', 'Pathologies de la peau', 1),
(10, 'Médecine Interne', 'MI', 'Médecine interne', 1),
(11, 'Urologie', 'URO', 'Pathologies urinaires', 1),
(12, 'Orthopédie', 'ORTHO', 'Pathologies ostéo-articulaires', 1),
(13, 'Neurologie', 'NEURO', 'Pathologies nerveuses', 1),
(14, 'Psychiatrie', 'PSY', 'Pathologies psychiatriques', 1),
(15, 'Radiologie', 'RADIO', 'Imagerie médicale', 1),
(16, 'Laboratoire', 'LAB', 'Analyses biologiques', 1),
(17, 'Anesthésie-Réanimation', 'ANES', 'Anesthésie et soins intensifs', 1),
(18, 'Médecine du Travail', 'MT', 'Médecine du travail', 1),
(19, 'Médecine d\'Urgence', 'URG', 'Médecine d\'urgence', 1),
(20, 'Pneumologie', 'PNEUMO', 'Pathologies respiratoires', 1),
(21, 'Gastro-entérologie', 'GASTRO', 'Pathologies digestives', 1),
(22, 'Néphrologie', 'NEPHRO', 'Pathologies rénales', 1),
(23, 'Hématologie', 'HEMA', 'Pathologies sanguines', 1),
(24, 'Endocrinologie', 'ENDO', 'Pathologies hormonales', 1),
(25, 'Rhumatologie', 'RHUMATO', 'Pathologies articulaires', 1);

-- --------------------------------------------------------

--
-- Table structure for table `stockpharma`
--

CREATE TABLE `stockpharma` (
  `idprodpharma` int NOT NULL,
  `idofficine` int NOT NULL,
  `quantite` int DEFAULT '0',
  `date_derniere_maj` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `stockpharma`
--

INSERT INTO `stockpharma` (`idprodpharma`, `idofficine`, `quantite`, `date_derniere_maj`) VALUES
(1, 1, 5897, '2026-02-21 03:18:40'),
(1, 2, 400, '2026-02-20 23:28:58'),
(2, 1, 3394, '2026-02-20 23:44:21'),
(2, 2, 250, '2026-02-20 23:28:58'),
(3, 1, 997, '2026-02-21 01:54:08'),
(4, 1, 2000, '2025-12-07 13:15:50'),
(4, 2, 150, '2025-12-07 12:35:59'),
(5, 1, 3000, '2025-12-07 13:15:50'),
(5, 2, 200, '2025-12-07 12:35:59'),
(6, 1, 494, '2026-02-21 02:56:19'),
(6, 2, 400, '2025-12-07 12:35:59'),
(7, 1, 400, '2025-12-07 12:35:59'),
(7, 2, 300, '2025-12-07 12:35:59');

-- --------------------------------------------------------

--
-- Table structure for table `tarif`
--

CREATE TABLE `tarif` (
  `idtarif` int NOT NULL,
  `nom` varchar(50) NOT NULL,
  `description` text,
  `actif` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tarif`
--

INSERT INTO `tarif` (`idtarif`, `nom`, `description`, `actif`) VALUES
(1, 'Tarif Standard', 'Tarif normal pour patients privés', 1),
(2, 'Tarif Personnel', 'Tarif réduit pour le personnel', 1);

-- --------------------------------------------------------

--
-- Table structure for table `transfert_hospi`
--

CREATE TABLE `transfert_hospi` (
  `idtransfert` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `ancien_idunitehospi` int DEFAULT NULL,
  `ancien_idchambre` int DEFAULT NULL,
  `ancien_idlit` int DEFAULT NULL,
  `nouveau_idunitehospi` int DEFAULT NULL,
  `nouveau_idchambre` int DEFAULT NULL,
  `nouveau_idlit` int DEFAULT NULL,
  `motif` varchar(255) NOT NULL,
  `observation` text,
  `idutilisateur` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfert_urgence`
--

CREATE TABLE `transfert_urgence` (
  `idtransfert_urgence` int NOT NULL,
  `idurgence` int NOT NULL,
  `destination` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Service de destination',
  `motif_transfert` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `date_transfert` datetime NOT NULL,
  `idutilisateur` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transmissioninfirmière`
--

CREATE TABLE `transmissioninfirmière` (
  `idtransmission` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `date_transmission` datetime DEFAULT CURRENT_TIMESTAMP,
  `transmission` text NOT NULL,
  `user_a` varchar(255) DEFAULT NULL COMMENT 'Nom de la personne à qui transmettre',
  `idutilisateur` int NOT NULL COMMENT 'ID de l''infirmière qui transmet',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `typeparamvitaux`
--

CREATE TABLE `typeparamvitaux` (
  `idtypeparamvitaux` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `unite` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `valeur_min` decimal(8,2) DEFAULT NULL,
  `valeur_max` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `description` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `typeparamvitaux`
--

INSERT INTO `typeparamvitaux` (`idtypeparamvitaux`, `nom`, `unite`, `valeur_min`, `valeur_max`, `created_at`, `description`) VALUES
(1, 'Température', '°C', 36.10, 37.20, '2025-12-09 10:30:47', 'Température corporelle normale'),
(2, 'Tension Artérielle Systolique', 'mmHg', 90.00, 120.00, '2025-12-09 10:30:47', 'Pression artérielle systolique'),
(3, 'Tension Artérielle Diastolique', 'mmHg', 60.00, 80.00, '2025-12-09 10:30:47', 'Pression artérielle diastolique'),
(4, 'Fréquence Cardiaque', 'bpm', 60.00, 100.00, '2025-12-09 10:30:47', 'Battements par minute'),
(5, 'Fréquence Respiratoire', '/min', 12.00, 20.00, '2025-12-09 10:30:47', 'Respirations par minute'),
(6, 'Saturation O2', '%', 95.00, 100.00, '2025-12-09 10:30:47', 'Saturation en oxygène'),
(7, 'Poids', 'kg', 40.00, 150.00, '2025-12-09 10:30:47', 'Poids corporel'),
(8, 'Taille', 'cm', 140.00, 200.00, '2025-12-09 10:30:47', 'Taille en centimètres'),
(9, 'Glycémie', 'mg/dL', 70.00, 140.00, '2025-12-09 10:30:47', 'Taux de glucose sanguin (à jeun)'),
(10, 'Pouls', 'bpm', 60.00, 100.00, '2025-12-09 10:30:47', 'Fréquence du pouls'),
(11, 'Température Axillaire', '°C', 35.50, 37.00, '2025-12-09 10:30:47', 'Température prise sous l\'aisselle');

-- --------------------------------------------------------

--
-- Table structure for table `unite`
--

CREATE TABLE `unite` (
  `idunite` int NOT NULL,
  `nom` varchar(20) NOT NULL,
  `abreviation` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `unite`
--

INSERT INTO `unite` (`idunite`, `nom`, `abreviation`) VALUES
(1, 'Comprimé', 'cp'),
(2, 'Gélule', 'gel'),
(3, 'Ampoule', 'amp'),
(4, 'Flacon', 'flac'),
(5, 'Millilitre', 'ml'),
(6, 'Boîte', 'bte'),
(7, 'Sachet', 'sach'),
(8, 'Unité', 'u'),
(9, 'Boîte', 'BT'),
(10, 'Flacon', 'FL');

-- --------------------------------------------------------

--
-- Table structure for table `unite_hospi`
--

CREATE TABLE `unite_hospi` (
  `idunitehospi` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `type_unite` varchar(50) DEFAULT NULL,
  `description` text,
  `actif` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `unite_hospi`
--

INSERT INTO `unite_hospi` (`idunitehospi`, `nom`, `type_unite`, `description`, `actif`, `created_at`, `updated_at`) VALUES
(1, 'Pédiatrie', 'hospitalisation', 'Service dédié aux enfants', 1, '2025-12-11 05:40:13', '2025-12-13 14:27:54'),
(2, 'Médecine Générale', 'medecine', 'Unité de médecine interne', 1, '2025-12-11 05:40:13', '2025-12-13 14:28:05'),
(3, 'Chirurgie A', 'chirurgie', 'Bloc opératoire section A', 1, '2025-12-11 05:40:13', '2025-12-13 14:28:19'),
(4, 'Urgences Adultes', 'urgence', 'Urgences 24/7', 1, '2025-12-11 05:40:13', '2025-12-11 05:40:13'),
(5, 'Cardiologie', 'specialisee', 'Service spécialisé maladies du coeur', 1, '2025-12-11 05:40:13', '2025-12-13 14:28:55');

-- --------------------------------------------------------

--
-- Table structure for table `unite_med`
--

CREATE TABLE `unite_med` (
  `idunite_med` int NOT NULL,
  `idservices` int NOT NULL,
  `idsite` int DEFAULT NULL,
  `abrege` varchar(10) DEFAULT NULL,
  `nom` varchar(100) NOT NULL,
  `idsous_specialite` int DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `idunitehospi` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `unite_med`
--

INSERT INTO `unite_med` (`idunite_med`, `idservices`, `idsite`, `abrege`, `nom`, `idsous_specialite`, `actif`, `idunitehospi`) VALUES
(1, 1, 1, 'MG', 'Médecine Générale', 1, 1, NULL),
(2, 1, 1, 'PED', 'Pédiatrie', 2, 1, NULL),
(3, 1, 1, 'GO', 'Gynécologie', 4, 1, NULL),
(4, 1, 1, 'DENT', 'Dentisterie', 1, 1, NULL),
(5, 2, 1, 'URG', 'Urgences', NULL, 1, NULL),
(6, 3, 1, 'MED', 'Médecine', NULL, 1, NULL),
(7, 3, 1, 'CHIR', 'Chirurgie', NULL, 1, NULL),
(8, 3, 1, 'MAT', 'Maternité', NULL, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `urgence`
--

CREATE TABLE `urgence` (
  `idurgence` int NOT NULL,
  `idsous_sejour` int NOT NULL,
  `niveau_urgence` enum('critique','grave','modere','mineur') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'modere',
  `code_triage` enum('ROUGE','ORANGE','JAUNE','VERT') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'VERT',
  `mode_arrivee` enum('marche','ambulance','vsl','autre') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'marche',
  `moyen_transport` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `accompagnant` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `heure_arrivee` datetime NOT NULL,
  `heure_prise_charge` datetime DEFAULT NULL,
  `medecin_triage` int DEFAULT NULL,
  `constantes_vitales` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'JSON des constantes ? l''arriv?e',
  `score_glasgow` int DEFAULT NULL,
  `observation_initiale` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `statut` enum('en_attente','en_cours','transfere','termine','sorti') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_attente',
  `date_modification` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `urgence`
--

INSERT INTO `urgence` (`idurgence`, `idsous_sejour`, `niveau_urgence`, `code_triage`, `mode_arrivee`, `moyen_transport`, `accompagnant`, `heure_arrivee`, `heure_prise_charge`, `medecin_triage`, `constantes_vitales`, `score_glasgow`, `observation_initiale`, `statut`, `date_modification`) VALUES
(1, 46, 'grave', 'ORANGE', 'marche', 'prive', '', '2025-12-11 09:12:51', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-11 09:12:51'),
(2, 47, 'critique', 'ROUGE', 'ambulance', 'ambulance', 'Tante', '2025-12-11 09:21:47', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-11 09:21:47'),
(3, 48, 'critique', 'ROUGE', 'ambulance', 'ambulance', 'Tante', '2025-12-11 09:26:04', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-11 09:26:04'),
(4, 49, 'critique', 'ROUGE', 'ambulance', 'ambulance', 'Tante', '2025-12-11 09:54:01', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-11 09:54:01'),
(5, 50, 'mineur', 'VERT', 'ambulance', 'ambulance', '', '2025-12-11 09:54:55', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-11 09:54:55'),
(6, 51, 'mineur', 'VERT', 'ambulance', 'ambulance', '', '2025-12-12 04:36:06', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-12 04:36:06'),
(7, 66, 'critique', 'ROUGE', 'ambulance', 'prive', 'Tante', '2025-12-19 10:17:16', NULL, 1, NULL, NULL, '', 'en_attente', '2025-12-19 10:17:16');

-- --------------------------------------------------------

--
-- Table structure for table `utilisateur`
--

CREATE TABLE `utilisateur` (
  `idutilisateur` int NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `idprofiluser` int DEFAULT NULL,
  `idsite` int DEFAULT NULL,
  `actif` tinyint(1) DEFAULT '1',
  `derniere_connexion` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('actif','inactif') DEFAULT 'actif'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `utilisateur`
--

INSERT INTO `utilisateur` (`idutilisateur`, `nom`, `prenom`, `username`, `password`, `email`, `telephone`, `idprofiluser`, `idsite`, `actif`, `derniere_connexion`, `created_at`, `statut`) VALUES
(1, 'Admin', 'Système', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@monkole.cd', NULL, 1, 1, 1, '2026-03-09 01:08:04', '2025-12-07 12:35:59', 'actif'),
(2, 'MUKADI', 'Pierre', 'dr.pierre', '$2y$10$...', 'dr.pierre@monkole.cd', NULL, 1, 1, 1, NULL, '2025-12-07 13:14:10', 'actif'),
(3, 'KALALA', 'Marie', 'tech.marie', '$2y$10$...', 'tech.marie@monkole.cd', NULL, 2, 1, 1, NULL, '2025-12-07 13:14:10', 'actif'),
(4, 'NDALA', 'Jean', 'radio.jean', '$2y$10$...', 'radio.jean@monkole.cd', NULL, 3, 1, 1, NULL, '2025-12-07 13:14:10', 'actif'),
(5, 'MPIANA', 'Grace', 'pharma.grace', '$2y$10$...', 'pharma.grace@monkole.cd', NULL, 4, 1, 1, NULL, '2025-12-07 13:14:10', 'actif'),
(6, 'KAMBALE', 'Joseph', 'depot.joseph', '$2y$10$...', 'depot.joseph@monkole.cd', NULL, 5, 1, 1, NULL, '2025-12-07 13:14:10', 'actif'),
(7, 'Dupont', 'Jean', 'jdupont', '2d69b2f6ae9311560002eb1d81673b24', 'jdupont@hopital.local', NULL, 6, 1, 1, NULL, '2025-12-13 09:53:26', 'actif'),
(8, 'Martin', 'Sophie', 'smartin', '05f3be14de6deb95fb2207d17e52b0f8', 'smartin@hopital.local', NULL, 6, 1, 1, NULL, '2025-12-13 09:53:26', 'actif'),
(9, 'Bernard', 'Pierre', 'pbernard', '7318a84844084679cc48d16319d08453', 'pbernard@hopital.local', NULL, 6, 1, 1, NULL, '2025-12-13 09:53:26', 'actif'),
(10, 'Dubois', 'Marie', 'mdubois', '7113c7c63a2b2562f5e8de0b9129d59e', 'mdubois@hopital.local', NULL, 6, 1, 1, NULL, '2025-12-13 09:53:26', 'actif'),
(28, 'KIBETE', 'Papy', 'papy', '$2y$10$nPVXLzSYdWh7wqu6457ZcuaTcRK4ev0umNHMy6G/1jnosPcuZ2APy', 'papykibete@csk.com', '', 15, 2, 1, '2026-02-26 19:42:58', '2026-02-11 14:00:55', 'actif');

-- --------------------------------------------------------

--
-- Table structure for table `voie_prod`
--

CREATE TABLE `voie_prod` (
  `idvoie_prod` int NOT NULL,
  `nom` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `voie_prod`
--

INSERT INTO `voie_prod` (`idvoie_prod`, `nom`) VALUES
(1, 'Orale'),
(2, 'Intraveineuse'),
(3, 'Intramusculaire'),
(4, 'Sous-cutanée'),
(5, 'Cutanée'),
(6, 'Rectale'),
(7, 'Oculaire'),
(8, 'Auriculaire'),
(9, 'Orale'),
(10, 'Injectable');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_actes_complets`
-- (See below for the actual view)
--
CREATE TABLE `v_actes_complets` (
`idacte` int
,`code` varchar(20)
,`libelle` varchar(200)
,`description` text
,`prix_vente` decimal(10,2)
,`actif` tinyint(1)
,`categorie_nom` varchar(100)
,`idcategorie_acte` int
,`date_creation` timestamp
,`date_modification` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_permissions_profils`
-- (See below for the actual view)
--
CREATE TABLE `v_permissions_profils` (
`idprofiluser` int
,`profil` varchar(50)
,`profil_code` varchar(50)
,`profil_categorie` varchar(50)
,`profil_niveau` int
,`idfct` int
,`permission` varchar(100)
,`permission_code` varchar(50)
,`module` varchar(50)
,`permission_categorie` varchar(50)
,`peut_creer` tinyint(1)
,`peut_modifier` tinyint(1)
,`peut_supprimer` tinyint(1)
,`peut_consulter` tinyint(1)
,`peut_valider` tinyint(1)
,`peut_imprimer` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_planning_bloc`
-- (See below for the actual view)
--
CREATE TABLE `v_planning_bloc` (
`idintervention` int
,`date_prevue` date
,`heure_debut_prevue` time
,`duree_prevue_minutes` int
,`type_intervention` enum('programmee','urgente','reglee')
,`libelle_intervention` varchar(255)
,`urgence` tinyint(1)
,`statut` enum('programmee','confirmee','en_cours','terminee','annulee','reportee')
,`idpatient` int
,`patient_nom` varchar(100)
,`patient_prenom` varchar(100)
,`numero_dossier` varchar(20)
,`date_naissance` date
,`sexe` enum('M','F')
,`numero_sejour` varchar(20)
,`type_sejour` enum('ambulatoire','urgence','hospitalisation')
,`nom` varchar(100)
,`code` varchar(20)
,`chirurgien_nom` varchar(100)
,`chirurgien_prenom` varchar(100)
,`anesthesiste_nom` varchar(100)
,`anesthesiste_prenom` varchar(100)
,`service_nom` varchar(100)
,`duree_reelle_minutes` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_prescriptions_en_attente`
-- (See below for the actual view)
--
CREATE TABLE `v_prescriptions_en_attente` (
`type` varchar(11)
,`id` int
,`idsous_sejour` int
,`libelle` varchar(200)
,`urgent` tinyint
,`type_externe` varchar(50)
,`centre_externe` varchar(200)
,`statut_execution` varchar(10)
,`date_prescription` timestamp
,`patient_nom` varchar(100)
,`patient_prenom` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_sejours_pdf_a_generer`
-- (See below for the actual view)
--
CREATE TABLE `v_sejours_pdf_a_generer` (
`idsejour` int
,`numero_sejour` varchar(20)
,`date_entree` timestamp
,`date_sortie` timestamp
,`type_sejour` enum('ambulatoire','urgence','hospitalisation')
,`statut` enum('en_cours','termine','annule')
,`patient_nom` varchar(201)
,`numero_dossier` varchar(20)
,`nb_resultats_labo` bigint
,`nb_resultats_imagerie` bigint
,`nb_medicaments` bigint
,`total_items` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_utilisateurs_permissions`
-- (See below for the actual view)
--
CREATE TABLE `v_utilisateurs_permissions` (
`idutilisateur` int
,`nom` varchar(100)
,`prenom` varchar(100)
,`username` varchar(50)
,`profil` varchar(50)
,`profil_code` varchar(50)
,`site` varchar(100)
,`module` varchar(50)
,`permission` varchar(100)
,`peut_creer` tinyint(1)
,`peut_modifier` tinyint(1)
,`peut_supprimer` tinyint(1)
,`peut_consulter` tinyint(1)
,`peut_valider` tinyint(1)
,`peut_imprimer` tinyint(1)
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acte`
--
ALTER TABLE `acte`
  ADD PRIMARY KEY (`idacte`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idcategorie_acte` (`idcategorie_acte`),
  ADD KEY `idsous_specialite` (`idsous_specialite`),
  ADD KEY `fk_acte_specialite` (`idspecialite`),
  ADD KEY `idx_acte_libelle` (`libelle`),
  ADD KEY `idx_acte_prix` (`prix_vente`),
  ADD KEY `idx_acte_categorie` (`idcategorie_acte`);

--
-- Indexes for table `actes_presc`
--
ALTER TABLE `actes_presc`
  ADD PRIMARY KEY (`idactes_presc`),
  ADD KEY `idacte` (`idacte`),
  ADD KEY `idsite` (`idsite`),
  ADD KEY `idsociete` (`idsociete`),
  ADD KEY `idspecialite` (`idspecialite`),
  ADD KEY `valideur` (`valideur`),
  ADD KEY `executeur` (`executeur`),
  ADD KEY `idx_actes_presc_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_actes_presc_date` (`date_prescription`),
  ADD KEY `idx_actes_presc_statut` (`statut_validation`),
  ADD KEY `idx_type_externe` (`type_externe`),
  ADD KEY `idx_statut_execution` (`statut_execution`),
  ADD KEY `idx_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_prescripteur` (`prescripteur`),
  ADD KEY `idx_urgent` (`urgent`),
  ADD KEY `idx_date_prescription` (`date_prescription`),
  ADD KEY `idx_actes_presc_categorie` (`idacte`),
  ADD KEY `idx_actes_presc_urgent` (`urgent`),
  ADD KEY `idx_groupe_prescription` (`id_groupe_prescription`);

--
-- Indexes for table `actes_presc_historique`
--
ALTER TABLE `actes_presc_historique`
  ADD PRIMARY KEY (`idhistorique`),
  ADD KEY `idactes_presc` (`idactes_presc`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `allergies_patients`
--
ALTER TABLE `allergies_patients`
  ADD PRIMARY KEY (`idallergie`),
  ADD KEY `idpatient` (`idpatient`);

--
-- Indexes for table `antecedents_patients`
--
ALTER TABLE `antecedents_patients`
  ADD PRIMARY KEY (`idantecedent`),
  ADD KEY `idpatient` (`idpatient`);

--
-- Indexes for table `audit_permissions`
--
ALTER TABLE `audit_permissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bloc_intervention`
--
ALTER TABLE `bloc_intervention`
  ADD PRIMARY KEY (`idintervention`),
  ADD KEY `idutilisateur_annulation` (`idutilisateur_annulation`),
  ADD KEY `idutilisateur_programmation` (`idutilisateur_programmation`),
  ADD KEY `idx_date_statut` (`date_prevue`,`statut`),
  ADD KEY `idx_salle` (`idsalle_bloc`),
  ADD KEY `idx_sejour` (`idsous_sejour`),
  ADD KEY `idx_statut` (`statut`),
  ADD KEY `idx_urgence` (`urgence`),
  ADD KEY `idx_chirurgien` (`idchirurgien`),
  ADD KEY `idx_anesthesiste` (`idanesthesiste`);

--
-- Indexes for table `caisse_transact`
--
ALTER TABLE `caisse_transact`
  ADD PRIMARY KEY (`idcaisse_transact`),
  ADD KEY `idpatient` (`idpatient`),
  ADD KEY `idcaisse_typetransact` (`idcaisse_typetransact`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `caisse_typetransact`
--
ALTER TABLE `caisse_typetransact`
  ADD PRIMARY KEY (`idcaisse_typetransact`);

--
-- Indexes for table `categorie`
--
ALTER TABLE `categorie`
  ADD PRIMARY KEY (`idcategorie`);

--
-- Indexes for table `categorie_acte`
--
ALTER TABLE `categorie_acte`
  ADD PRIMARY KEY (`idcategorie_acte`),
  ADD KEY `idx_categorie_nom` (`nom`);

--
-- Indexes for table `certificats_medicaux`
--
ALTER TABLE `certificats_medicaux`
  ADD PRIMARY KEY (`idcertificat`),
  ADD KEY `idconsultation` (`idconsultation`),
  ADD KEY `idmedecin` (`idmedecin`);

--
-- Indexes for table `chambre`
--
ALTER TABLE `chambre`
  ADD PRIMARY KEY (`idchambre`),
  ADD KEY `idunitehospi` (`idunitehospi`);

--
-- Indexes for table `commune`
--
ALTER TABLE `commune`
  ADD PRIMARY KEY (`idcommune`);

--
-- Indexes for table `compte_rendu_operatoire`
--
ALTER TABLE `compte_rendu_operatoire`
  ADD PRIMARY KEY (`idcompte_rendu`),
  ADD KEY `idutilisateur_validation` (`idutilisateur_validation`),
  ADD KEY `idutilisateur_modif` (`idutilisateur_modif`),
  ADD KEY `idx_intervention` (`idintervention`),
  ADD KEY `idx_utilisateur` (`idutilisateur`),
  ADD KEY `idx_valide` (`valide`);

--
-- Indexes for table `consommables_bloc`
--
ALTER TABLE `consommables_bloc`
  ADD PRIMARY KEY (`idconsommable_bloc`),
  ADD KEY `idintervention` (`idintervention`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `consultation`
--
ALTER TABLE `consultation`
  ADD PRIMARY KEY (`idconsultation`),
  ADD KEY `idsous_sejour` (`idsous_sejour`),
  ADD KEY `iddiagnostic` (`iddiagnostic`);

--
-- Indexes for table `consultations`
--
ALTER TABLE `consultations`
  ADD PRIMARY KEY (`idconsultation`),
  ADD KEY `fk_consult_patient` (`idpatient`),
  ADD KEY `fk_consult_soussejour` (`idsous_sejour`),
  ADD KEY `fk_consult_user` (`idutilisateur`);

--
-- Indexes for table `demande_transfert_hospi`
--
ALTER TABLE `demande_transfert_hospi`
  ADD PRIMARY KEY (`iddemande_transfert`),
  ADD KEY `idsous_sejour` (`idsous_sejour`),
  ADD KEY `idunitehospi_destination` (`idunitehospi_destination`);

--
-- Indexes for table `destinationsprod`
--
ALTER TABLE `destinationsprod`
  ADD PRIMARY KEY (`iddestination`);

--
-- Indexes for table `diagnostic`
--
ALTER TABLE `diagnostic`
  ADD PRIMARY KEY (`iddiagnostic`);

--
-- Indexes for table `diagnostic_patient`
--
ALTER TABLE `diagnostic_patient`
  ADD PRIMARY KEY (`iddiagnostic_patient`),
  ADD KEY `idx_patient` (`idpatient`),
  ADD KEY `idx_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_diagnostic` (`iddiagnostic`);

--
-- Indexes for table `entreprod`
--
ALTER TABLE `entreprod`
  ADD PRIMARY KEY (`identreprod`),
  ADD UNIQUE KEY `numero_entree` (`numero_entree`),
  ADD KEY `idfournissuer` (`idfournissuer`),
  ADD KEY `idofficine` (`idofficine`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `equipements_imagerie`
--
ALTER TABLE `equipements_imagerie`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_serie` (`numero_serie`);

--
-- Indexes for table `equipe_chirurgicale`
--
ALTER TABLE `equipe_chirurgicale`
  ADD PRIMARY KEY (`idequipe`),
  ADD UNIQUE KEY `unique_membre_intervention` (`idintervention`,`idutilisateur`,`role`),
  ADD KEY `idx_intervention` (`idintervention`),
  ADD KEY `idx_utilisateur` (`idutilisateur`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `ethnie`
--
ALTER TABLE `ethnie`
  ADD PRIMARY KEY (`idethnie`),
  ADD UNIQUE KEY `nom` (`nom`);

--
-- Indexes for table `evolution_urgence`
--
ALTER TABLE `evolution_urgence`
  ADD PRIMARY KEY (`idevolution_urgence`),
  ADD KEY `idx_urgence` (`idurgence`),
  ADD KEY `fk_evolution_urgence_user` (`idutilisateur`);

--
-- Indexes for table `famiprod`
--
ALTER TABLE `famiprod`
  ADD PRIMARY KEY (`idfamiprod`);

--
-- Indexes for table `fct`
--
ALTER TABLE `fct`
  ADD PRIMARY KEY (`idfct`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `fct_profiluser`
--
ALTER TABLE `fct_profiluser`
  ADD PRIMARY KEY (`idfct`,`idprofiluser`),
  ADD KEY `fk_fct_profiluser_profiluser` (`idprofiluser`);

--
-- Indexes for table `feuille_anesthesie`
--
ALTER TABLE `feuille_anesthesie`
  ADD PRIMARY KEY (`idfeuille_anesth`),
  ADD UNIQUE KEY `unique_intervention` (`idintervention`),
  ADD KEY `idx_intervention` (`idintervention`),
  ADD KEY `idx_anesthesiste` (`idanesthesiste`),
  ADD KEY `idx_valide` (`valide`);

--
-- Indexes for table `fournisseur`
--
ALTER TABLE `fournisseur`
  ADD PRIMARY KEY (`idfournisseur`) USING BTREE;

--
-- Indexes for table `frm_prod`
--
ALTER TABLE `frm_prod`
  ADD PRIMARY KEY (`idfrm_prod`);

--
-- Indexes for table `groupe_prescriptions`
--
ALTER TABLE `groupe_prescriptions`
  ADD PRIMARY KEY (`id_groupe_prescription`),
  ADD UNIQUE KEY `uk_code_prescription` (`code_prescription`),
  ADD KEY `idx_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_prescripteur` (`prescripteur`),
  ADD KEY `idx_statut` (`statut`);

--
-- Indexes for table `grsanguin`
--
ALTER TABLE `grsanguin`
  ADD PRIMARY KEY (`idgrsanguin`),
  ADD UNIQUE KEY `nom` (`nom`);

--
-- Indexes for table `historique_transfert_hospi`
--
ALTER TABLE `historique_transfert_hospi`
  ADD PRIMARY KEY (`idhistorique`),
  ADD KEY `idsous_sejour` (`idsous_sejour`);

--
-- Indexes for table `image_i`
--
ALTER TABLE `image_i`
  ADD PRIMARY KEY (`idimage`),
  ADD KEY `idactes_presc` (`idactes_presc`),
  ADD KEY `radiologue` (`radiologue`);

--
-- Indexes for table `inventaire_ajustements`
--
ALTER TABLE `inventaire_ajustements`
  ADD PRIMARY KEY (`idajustement`),
  ADD KEY `idprodpharma` (`idprodpharma`),
  ADD KEY `idofficine` (`idofficine`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `labo_controle_qualite`
--
ALTER TABLE `labo_controle_qualite`
  ADD PRIMARY KEY (`idcontrole_qualite`),
  ADD KEY `operateur` (`operateur`),
  ADD KEY `idx_date_controle` (`date_controle`),
  ADD KEY `idx_machine` (`idmachinelabo`),
  ADD KEY `idx_conformite` (`conforme`);

--
-- Indexes for table `labo_numerotation_bons`
--
ALTER TABLE `labo_numerotation_bons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_annee` (`annee`);

--
-- Indexes for table `labo_prelevements`
--
ALTER TABLE `labo_prelevements`
  ADD PRIMARY KEY (`idprelevement`),
  ADD KEY `idactes_presc` (`idactes_presc`),
  ADD KEY `preleveur` (`preleveur`);

--
-- Indexes for table `labo_valeurs_normales`
--
ALTER TABLE `labo_valeurs_normales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_idacte_ordre` (`idacte`,`ordre`);

--
-- Indexes for table `ligneentree`
--
ALTER TABLE `ligneentree`
  ADD PRIMARY KEY (`identreprod`,`idprodpharma`),
  ADD KEY `idprodpharma` (`idprodpharma`);

--
-- Indexes for table `lignesortieprod`
--
ALTER TABLE `lignesortieprod`
  ADD PRIMARY KEY (`idsortieprod`,`idprodpharma`),
  ADD KEY `idprodpharma` (`idprodpharma`);

--
-- Indexes for table `lignesrecquisition`
--
ALTER TABLE `lignesrecquisition`
  ADD PRIMARY KEY (`idrequisition`,`idprodpharma`),
  ADD KEY `idprodpharma` (`idprodpharma`);

--
-- Indexes for table `lit`
--
ALTER TABLE `lit`
  ADD PRIMARY KEY (`idlit`),
  ADD KEY `idchambre` (`idchambre`),
  ADD KEY `idsous_sejour` (`idsous_sejour`);

--
-- Indexes for table `logs_connexion`
--
ALTER TABLE `logs_connexion`
  ADD PRIMARY KEY (`idlog`),
  ADD KEY `idx_date` (`date_connexion`),
  ADD KEY `idx_user` (`idutilisateur`);

--
-- Indexes for table `machineslabo`
--
ALTER TABLE `machineslabo`
  ADD PRIMARY KEY (`idmachinelabo`);

--
-- Indexes for table `machineslabo_maintenance`
--
ALTER TABLE `machineslabo_maintenance`
  ADD PRIMARY KEY (`idmaintenance`),
  ADD KEY `fk_machine_maintenance` (`idmachinelabo`);

--
-- Indexes for table `motif`
--
ALTER TABLE `motif`
  ADD PRIMARY KEY (`idmotif`);

--
-- Indexes for table `notes_evolution`
--
ALTER TABLE `notes_evolution`
  ADD PRIMARY KEY (`idnote`),
  ADD KEY `idsous_sejour` (`idsous_sejour`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`idnotification`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `officine`
--
ALTER TABLE `officine`
  ADD PRIMARY KEY (`idofficine`),
  ADD KEY `idsite` (`idsite`);

--
-- Indexes for table `origine`
--
ALTER TABLE `origine`
  ADD PRIMARY KEY (`idorigine`);

--
-- Indexes for table `parametresvitaux`
--
ALTER TABLE `parametresvitaux`
  ADD PRIMARY KEY (`idparametre`),
  ADD KEY `idpatient` (`idpatient`),
  ADD KEY `idsous_sejour` (`idsous_sejour`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `patient`
--
ALTER TABLE `patient`
  ADD PRIMARY KEY (`idpatient`),
  ADD UNIQUE KEY `numero_dossier` (`numero_dossier`),
  ADD KEY `idquartier` (`idquartier`),
  ADD KEY `idgrsanguin` (`idgrsanguin`),
  ADD KEY `idethnie` (`idethnie`),
  ADD KEY `idreligion` (`idreligion`),
  ADD KEY `idsociete` (`idsociete`),
  ADD KEY `idcategorie` (`idcategorie`),
  ADD KEY `idutilisateur` (`idutilisateur`),
  ADD KEY `idx_patient_nom` (`nom`,`prenom`),
  ADD KEY `idx_patient_date_naissance` (`date_naissance`),
  ADD KEY `idx_patient_numero_dossier` (`numero_dossier`),
  ADD KEY `idx_patient_type` (`type_patient`);

--
-- Indexes for table `pharma_entrees`
--
ALTER TABLE `pharma_entrees`
  ADD PRIMARY KEY (`identree`),
  ADD KEY `idprodpharma` (`idprodpharma`),
  ADD KEY `idutilisateur` (`idutilisateur`),
  ADD KEY `idfournisseur` (`idfournisseur`) USING BTREE;

--
-- Indexes for table `pharma_presc`
--
ALTER TABLE `pharma_presc`
  ADD PRIMARY KEY (`idpharma_presc`),
  ADD KEY `idprodpharma` (`idprodpharma`),
  ADD KEY `idsociete` (`idsociete`),
  ADD KEY `valideur` (`valideur`),
  ADD KEY `idx_pharma_presc_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_pharma_presc_date` (`date_prescription`),
  ADD KEY `idx_pharma_presc_statut` (`statut_validation`),
  ADD KEY `idx_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_prescripteur` (`prescripteur`),
  ADD KEY `idx_urgent` (`urgent`),
  ADD KEY `idx_date_prescription` (`date_prescription`),
  ADD KEY `executeur` (`executeur`);

--
-- Indexes for table `planning_soins`
--
ALTER TABLE `planning_soins`
  ADD PRIMARY KEY (`idplanning`),
  ADD KEY `idsous_sejour` (`idsous_sejour`);

--
-- Indexes for table `prelevements_anapath`
--
ALTER TABLE `prelevements_anapath`
  ADD PRIMARY KEY (`idprelevement`),
  ADD KEY `idintervention` (`idintervention`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `prodpharma`
--
ALTER TABLE `prodpharma`
  ADD PRIMARY KEY (`idprodpharma`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idfamiprod` (`idfamiprod`),
  ADD KEY `idsous_specialite` (`idsous_specialite`),
  ADD KEY `idfrm_prod` (`idfrm_prod`),
  ADD KEY `idvoie_prod` (`idvoie_prod`),
  ADD KEY `idunite` (`idunite`);

--
-- Indexes for table `profiluser`
--
ALTER TABLE `profiluser`
  ADD PRIMARY KEY (`idprofiluser`),
  ADD UNIQUE KEY `nom` (`nom`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `quartier`
--
ALTER TABLE `quartier`
  ADD PRIMARY KEY (`idquartier`),
  ADD KEY `idcommune` (`idcommune`);

--
-- Indexes for table `religion`
--
ALTER TABLE `religion`
  ADD PRIMARY KEY (`idreligion`),
  ADD UNIQUE KEY `nom` (`nom`);

--
-- Indexes for table `requisition`
--
ALTER TABLE `requisition`
  ADD PRIMARY KEY (`idrequisition`),
  ADD UNIQUE KEY `numero_requisition` (`numero_requisition`),
  ADD KEY `idofficine` (`idofficine`),
  ADD KEY `idutilisateur` (`idutilisateur`),
  ADD KEY `traiteur` (`traiteur`);

--
-- Indexes for table `resultatslabo`
--
ALTER TABLE `resultatslabo`
  ADD PRIMARY KEY (`idresultat`),
  ADD KEY `idactes_presc` (`idactes_presc`),
  ADD KEY `analyse_par` (`analyse_par`),
  ADD KEY `idmachinelabo` (`idmachinelabo`),
  ADD KEY `idx_echantillon` (`idechantillon`);

--
-- Indexes for table `resultatslabo_documents`
--
ALTER TABLE `resultatslabo_documents`
  ADD PRIMARY KEY (`iddocument`),
  ADD KEY `idx_idresultat` (`idresultat`),
  ADD KEY `upload_par` (`upload_par`);

--
-- Indexes for table `resultats_imagerie`
--
ALTER TABLE `resultats_imagerie`
  ADD PRIMARY KEY (`idresultat_imagerie`),
  ADD KEY `idx_actes_presc` (`idactes_presc`),
  ADD KEY `idx_radiologue` (`radiologue`),
  ADD KEY `idx_resultats_imagerie_date` (`date_examen`);

--
-- Indexes for table `salle_bloc`
--
ALTER TABLE `salle_bloc`
  ADD PRIMARY KEY (`idsalle_bloc`),
  ADD KEY `idunite_med` (`idunite_med`),
  ADD KEY `idx_site` (`idsite`),
  ADD KEY `idx_type_salle` (`type_salle`);

--
-- Indexes for table `sejour`
--
ALTER TABLE `sejour`
  ADD PRIMARY KEY (`idsejour`),
  ADD UNIQUE KEY `numero_sejour` (`numero_sejour`),
  ADD KEY `idmotif` (`idmotif`),
  ADD KEY `idorigine` (`idorigine`),
  ADD KEY `idutilisateur` (`idutilisateur`),
  ADD KEY `idx_sejour_patient` (`idpatient`),
  ADD KEY `idx_sejour_type` (`type_sejour`),
  ADD KEY `idx_sejour_date` (`date_entree`),
  ADD KEY `idx_sejour_statut` (`statut`),
  ADD KEY `idx_idsite` (`idsite`),
  ADD KEY `idx_pdf_genere` (`pdf_resultats_genere`,`date_pdf_resultats`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`idservices`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idsite` (`idsite`);

--
-- Indexes for table `services_notifications`
--
ALTER TABLE `services_notifications`
  ADD PRIMARY KEY (`idnotification`),
  ADD KEY `idx_service` (`service`),
  ADD KEY `idx_destinataire` (`destinataire`),
  ADD KEY `idx_lu` (`lu`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `site`
--
ALTER TABLE `site`
  ADD PRIMARY KEY (`idsite`),
  ADD UNIQUE KEY `abrege` (`abrege`);

--
-- Indexes for table `societe`
--
ALTER TABLE `societe`
  ADD PRIMARY KEY (`idsociete`);

--
-- Indexes for table `societe_tarif`
--
ALTER TABLE `societe_tarif`
  ADD PRIMARY KEY (`idsociete`,`idtarif`,`idcategorie`),
  ADD KEY `idtarif` (`idtarif`),
  ADD KEY `idcategorie` (`idcategorie`),
  ADD KEY `idsite` (`idsite`);

--
-- Indexes for table `soins_infirmiers`
--
ALTER TABLE `soins_infirmiers`
  ADD PRIMARY KEY (`idsoins`),
  ADD KEY `idsous_sejour` (`idsous_sejour`),
  ADD KEY `idinfirmier` (`idinfirmier`);

--
-- Indexes for table `sortieprod`
--
ALTER TABLE `sortieprod`
  ADD PRIMARY KEY (`idsortieprod`),
  ADD UNIQUE KEY `numero_sortie` (`numero_sortie`),
  ADD KEY `idofficine` (`idofficine`),
  ADD KEY `iddestination` (`iddestination`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `sorties_stock`
--
ALTER TABLE `sorties_stock`
  ADD PRIMARY KEY (`idsortie_stock`),
  ADD KEY `idx_produit` (`idprodpharma`),
  ADD KEY `idx_officine` (`idofficine`),
  ADD KEY `idx_date` (`date_sortie`),
  ADD KEY `idpharma_presc` (`idpharma_presc`),
  ADD KEY `idutilisateur` (`idutilisateur`),
  ADD KEY `iddestination` (`iddestination`);

--
-- Indexes for table `sous_sejour`
--
ALTER TABLE `sous_sejour`
  ADD PRIMARY KEY (`idsous_sejour`),
  ADD KEY `idsejour` (`idsejour`),
  ADD KEY `idunite_med` (`idunite_med`),
  ADD KEY `idmotif` (`idmotif`),
  ADD KEY `idx_unitehospi` (`idunitehospi`),
  ADD KEY `idlit_actuel` (`idlit_actuel`);

--
-- Indexes for table `sous_specialite`
--
ALTER TABLE `sous_specialite`
  ADD PRIMARY KEY (`idsous_specialite`),
  ADD KEY `idspecialite` (`idspecialite`);

--
-- Indexes for table `specialite`
--
ALTER TABLE `specialite`
  ADD PRIMARY KEY (`idspecialite`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `stockpharma`
--
ALTER TABLE `stockpharma`
  ADD PRIMARY KEY (`idprodpharma`,`idofficine`),
  ADD KEY `idx_stock_officine` (`idofficine`),
  ADD KEY `idx_stock_quantite` (`quantite`);

--
-- Indexes for table `tarif`
--
ALTER TABLE `tarif`
  ADD PRIMARY KEY (`idtarif`);

--
-- Indexes for table `transfert_hospi`
--
ALTER TABLE `transfert_hospi`
  ADD PRIMARY KEY (`idtransfert`),
  ADD KEY `fk_transfert_sous_sejour` (`idsous_sejour`),
  ADD KEY `fk_transfert_old_unite` (`ancien_idunitehospi`),
  ADD KEY `fk_transfert_old_chambre` (`ancien_idchambre`),
  ADD KEY `fk_transfert_old_lit` (`ancien_idlit`),
  ADD KEY `fk_transfert_new_unite` (`nouveau_idunitehospi`),
  ADD KEY `fk_transfert_new_chambre` (`nouveau_idchambre`),
  ADD KEY `fk_transfert_new_lit` (`nouveau_idlit`),
  ADD KEY `fk_transfert_user` (`idutilisateur`);

--
-- Indexes for table `transfert_urgence`
--
ALTER TABLE `transfert_urgence`
  ADD PRIMARY KEY (`idtransfert_urgence`),
  ADD KEY `idx_urgence` (`idurgence`),
  ADD KEY `fk_transfert_urgence_user` (`idutilisateur`);

--
-- Indexes for table `transmissioninfirmière`
--
ALTER TABLE `transmissioninfirmière`
  ADD PRIMARY KEY (`idtransmission`),
  ADD KEY `idsous_sejour` (`idsous_sejour`),
  ADD KEY `idutilisateur` (`idutilisateur`);

--
-- Indexes for table `typeparamvitaux`
--
ALTER TABLE `typeparamvitaux`
  ADD PRIMARY KEY (`idtypeparamvitaux`);

--
-- Indexes for table `unite`
--
ALTER TABLE `unite`
  ADD PRIMARY KEY (`idunite`);

--
-- Indexes for table `unite_hospi`
--
ALTER TABLE `unite_hospi`
  ADD PRIMARY KEY (`idunitehospi`);

--
-- Indexes for table `unite_med`
--
ALTER TABLE `unite_med`
  ADD PRIMARY KEY (`idunite_med`),
  ADD KEY `idservices` (`idservices`),
  ADD KEY `idsite` (`idsite`),
  ADD KEY `fk_unite_sous_specialite` (`idsous_specialite`),
  ADD KEY `idunitehospi` (`idunitehospi`);

--
-- Indexes for table `urgence`
--
ALTER TABLE `urgence`
  ADD PRIMARY KEY (`idurgence`),
  ADD KEY `idx_sous_sejour` (`idsous_sejour`),
  ADD KEY `idx_statut` (`statut`),
  ADD KEY `idx_triage` (`code_triage`),
  ADD KEY `idx_niveau` (`niveau_urgence`),
  ADD KEY `fk_urgence_medecin` (`medecin_triage`);

--
-- Indexes for table `utilisateur`
--
ALTER TABLE `utilisateur`
  ADD PRIMARY KEY (`idutilisateur`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idprofiluser` (`idprofiluser`),
  ADD KEY `idsite` (`idsite`);

--
-- Indexes for table `voie_prod`
--
ALTER TABLE `voie_prod`
  ADD PRIMARY KEY (`idvoie_prod`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acte`
--
ALTER TABLE `acte`
  MODIFY `idacte` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `actes_presc`
--
ALTER TABLE `actes_presc`
  MODIFY `idactes_presc` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=162;

--
-- AUTO_INCREMENT for table `actes_presc_historique`
--
ALTER TABLE `actes_presc_historique`
  MODIFY `idhistorique` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allergies_patients`
--
ALTER TABLE `allergies_patients`
  MODIFY `idallergie` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `antecedents_patients`
--
ALTER TABLE `antecedents_patients`
  MODIFY `idantecedent` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `audit_permissions`
--
ALTER TABLE `audit_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bloc_intervention`
--
ALTER TABLE `bloc_intervention`
  MODIFY `idintervention` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `caisse_transact`
--
ALTER TABLE `caisse_transact`
  MODIFY `idcaisse_transact` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `caisse_typetransact`
--
ALTER TABLE `caisse_typetransact`
  MODIFY `idcaisse_typetransact` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `categorie`
--
ALTER TABLE `categorie`
  MODIFY `idcategorie` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `categorie_acte`
--
ALTER TABLE `categorie_acte`
  MODIFY `idcategorie_acte` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `certificats_medicaux`
--
ALTER TABLE `certificats_medicaux`
  MODIFY `idcertificat` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chambre`
--
ALTER TABLE `chambre`
  MODIFY `idchambre` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `commune`
--
ALTER TABLE `commune`
  MODIFY `idcommune` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `compte_rendu_operatoire`
--
ALTER TABLE `compte_rendu_operatoire`
  MODIFY `idcompte_rendu` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consommables_bloc`
--
ALTER TABLE `consommables_bloc`
  MODIFY `idconsommable_bloc` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consultation`
--
ALTER TABLE `consultation`
  MODIFY `idconsultation` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consultations`
--
ALTER TABLE `consultations`
  MODIFY `idconsultation` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `demande_transfert_hospi`
--
ALTER TABLE `demande_transfert_hospi`
  MODIFY `iddemande_transfert` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `destinationsprod`
--
ALTER TABLE `destinationsprod`
  MODIFY `iddestination` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `diagnostic`
--
ALTER TABLE `diagnostic`
  MODIFY `iddiagnostic` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `diagnostic_patient`
--
ALTER TABLE `diagnostic_patient`
  MODIFY `iddiagnostic_patient` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `entreprod`
--
ALTER TABLE `entreprod`
  MODIFY `identreprod` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `equipements_imagerie`
--
ALTER TABLE `equipements_imagerie`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `equipe_chirurgicale`
--
ALTER TABLE `equipe_chirurgicale`
  MODIFY `idequipe` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ethnie`
--
ALTER TABLE `ethnie`
  MODIFY `idethnie` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `evolution_urgence`
--
ALTER TABLE `evolution_urgence`
  MODIFY `idevolution_urgence` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `famiprod`
--
ALTER TABLE `famiprod`
  MODIFY `idfamiprod` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `fct`
--
ALTER TABLE `fct`
  MODIFY `idfct` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `feuille_anesthesie`
--
ALTER TABLE `feuille_anesthesie`
  MODIFY `idfeuille_anesth` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fournisseur`
--
ALTER TABLE `fournisseur`
  MODIFY `idfournisseur` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `frm_prod`
--
ALTER TABLE `frm_prod`
  MODIFY `idfrm_prod` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `groupe_prescriptions`
--
ALTER TABLE `groupe_prescriptions`
  MODIFY `id_groupe_prescription` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grsanguin`
--
ALTER TABLE `grsanguin`
  MODIFY `idgrsanguin` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `historique_transfert_hospi`
--
ALTER TABLE `historique_transfert_hospi`
  MODIFY `idhistorique` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `image_i`
--
ALTER TABLE `image_i`
  MODIFY `idimage` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventaire_ajustements`
--
ALTER TABLE `inventaire_ajustements`
  MODIFY `idajustement` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `labo_controle_qualite`
--
ALTER TABLE `labo_controle_qualite`
  MODIFY `idcontrole_qualite` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `labo_numerotation_bons`
--
ALTER TABLE `labo_numerotation_bons`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `labo_prelevements`
--
ALTER TABLE `labo_prelevements`
  MODIFY `idprelevement` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `labo_valeurs_normales`
--
ALTER TABLE `labo_valeurs_normales`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `lit`
--
ALTER TABLE `lit`
  MODIFY `idlit` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `logs_connexion`
--
ALTER TABLE `logs_connexion`
  MODIFY `idlog` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `machineslabo`
--
ALTER TABLE `machineslabo`
  MODIFY `idmachinelabo` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `machineslabo_maintenance`
--
ALTER TABLE `machineslabo_maintenance`
  MODIFY `idmaintenance` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `motif`
--
ALTER TABLE `motif`
  MODIFY `idmotif` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `notes_evolution`
--
ALTER TABLE `notes_evolution`
  MODIFY `idnote` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `idnotification` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=246;

--
-- AUTO_INCREMENT for table `officine`
--
ALTER TABLE `officine`
  MODIFY `idofficine` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `origine`
--
ALTER TABLE `origine`
  MODIFY `idorigine` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `parametresvitaux`
--
ALTER TABLE `parametresvitaux`
  MODIFY `idparametre` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `patient`
--
ALTER TABLE `patient`
  MODIFY `idpatient` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `pharma_entrees`
--
ALTER TABLE `pharma_entrees`
  MODIFY `identree` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pharma_presc`
--
ALTER TABLE `pharma_presc`
  MODIFY `idpharma_presc` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `planning_soins`
--
ALTER TABLE `planning_soins`
  MODIFY `idplanning` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prelevements_anapath`
--
ALTER TABLE `prelevements_anapath`
  MODIFY `idprelevement` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prodpharma`
--
ALTER TABLE `prodpharma`
  MODIFY `idprodpharma` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `profiluser`
--
ALTER TABLE `profiluser`
  MODIFY `idprofiluser` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `quartier`
--
ALTER TABLE `quartier`
  MODIFY `idquartier` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `religion`
--
ALTER TABLE `religion`
  MODIFY `idreligion` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `requisition`
--
ALTER TABLE `requisition`
  MODIFY `idrequisition` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `resultatslabo`
--
ALTER TABLE `resultatslabo`
  MODIFY `idresultat` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `resultatslabo_documents`
--
ALTER TABLE `resultatslabo_documents`
  MODIFY `iddocument` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `resultats_imagerie`
--
ALTER TABLE `resultats_imagerie`
  MODIFY `idresultat_imagerie` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salle_bloc`
--
ALTER TABLE `salle_bloc`
  MODIFY `idsalle_bloc` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `sejour`
--
ALTER TABLE `sejour`
  MODIFY `idsejour` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `idservices` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `services_notifications`
--
ALTER TABLE `services_notifications`
  MODIFY `idnotification` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `site`
--
ALTER TABLE `site`
  MODIFY `idsite` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `societe`
--
ALTER TABLE `societe`
  MODIFY `idsociete` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `soins_infirmiers`
--
ALTER TABLE `soins_infirmiers`
  MODIFY `idsoins` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sortieprod`
--
ALTER TABLE `sortieprod`
  MODIFY `idsortieprod` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sorties_stock`
--
ALTER TABLE `sorties_stock`
  MODIFY `idsortie_stock` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sous_sejour`
--
ALTER TABLE `sous_sejour`
  MODIFY `idsous_sejour` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `sous_specialite`
--
ALTER TABLE `sous_specialite`
  MODIFY `idsous_specialite` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `specialite`
--
ALTER TABLE `specialite`
  MODIFY `idspecialite` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `tarif`
--
ALTER TABLE `tarif`
  MODIFY `idtarif` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transfert_hospi`
--
ALTER TABLE `transfert_hospi`
  MODIFY `idtransfert` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfert_urgence`
--
ALTER TABLE `transfert_urgence`
  MODIFY `idtransfert_urgence` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transmissioninfirmière`
--
ALTER TABLE `transmissioninfirmière`
  MODIFY `idtransmission` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `typeparamvitaux`
--
ALTER TABLE `typeparamvitaux`
  MODIFY `idtypeparamvitaux` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `unite`
--
ALTER TABLE `unite`
  MODIFY `idunite` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `unite_hospi`
--
ALTER TABLE `unite_hospi`
  MODIFY `idunitehospi` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `unite_med`
--
ALTER TABLE `unite_med`
  MODIFY `idunite_med` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `urgence`
--
ALTER TABLE `urgence`
  MODIFY `idurgence` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `utilisateur`
--
ALTER TABLE `utilisateur`
  MODIFY `idutilisateur` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `voie_prod`
--
ALTER TABLE `voie_prod`
  MODIFY `idvoie_prod` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

-- --------------------------------------------------------

--
-- Structure for view `v_actes_complets`
--
DROP TABLE IF EXISTS `v_actes_complets`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_actes_complets`  AS SELECT `a`.`idacte` AS `idacte`, `a`.`code` AS `code`, `a`.`libelle` AS `libelle`, `a`.`description` AS `description`, `a`.`prix_vente` AS `prix_vente`, `a`.`actif` AS `actif`, `ca`.`nom` AS `categorie_nom`, `ca`.`idcategorie_acte` AS `idcategorie_acte`, `a`.`date_creation` AS `date_creation`, `a`.`date_modification` AS `date_modification` FROM (`acte` `a` left join `categorie_acte` `ca` on((`a`.`idcategorie_acte` = `ca`.`idcategorie_acte`))) WHERE (`a`.`actif` = 1) ;

-- --------------------------------------------------------

--
-- Structure for view `v_permissions_profils`
--
DROP TABLE IF EXISTS `v_permissions_profils`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_permissions_profils`  AS SELECT `p`.`idprofiluser` AS `idprofiluser`, `p`.`nom` AS `profil`, `p`.`code` AS `profil_code`, `p`.`categorie` AS `profil_categorie`, `p`.`niveau` AS `profil_niveau`, `f`.`idfct` AS `idfct`, `f`.`nom` AS `permission`, `f`.`code` AS `permission_code`, `f`.`module` AS `module`, `f`.`categorie` AS `permission_categorie`, `fp`.`peut_creer` AS `peut_creer`, `fp`.`peut_modifier` AS `peut_modifier`, `fp`.`peut_supprimer` AS `peut_supprimer`, `fp`.`peut_consulter` AS `peut_consulter`, `fp`.`peut_valider` AS `peut_valider`, `fp`.`peut_imprimer` AS `peut_imprimer` FROM ((`profiluser` `p` join `fct_profiluser` `fp` on((`p`.`idprofiluser` = `fp`.`idprofiluser`))) join `fct` `f` on((`fp`.`idfct` = `f`.`idfct`))) WHERE ((`p`.`statut` = 'actif') AND (`f`.`statut` = 'actif')) ORDER BY `p`.`niveau` DESC, `p`.`nom` ASC, `f`.`ordre` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_planning_bloc`
--
DROP TABLE IF EXISTS `v_planning_bloc`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_planning_bloc`  AS SELECT `bi`.`idintervention` AS `idintervention`, `bi`.`date_prevue` AS `date_prevue`, `bi`.`heure_debut_prevue` AS `heure_debut_prevue`, `bi`.`duree_prevue_minutes` AS `duree_prevue_minutes`, `bi`.`type_intervention` AS `type_intervention`, `bi`.`libelle_intervention` AS `libelle_intervention`, `bi`.`urgence` AS `urgence`, `bi`.`statut` AS `statut`, `p`.`idpatient` AS `idpatient`, `p`.`nom` AS `patient_nom`, `p`.`prenom` AS `patient_prenom`, `p`.`numero_dossier` AS `numero_dossier`, `p`.`date_naissance` AS `date_naissance`, `p`.`sexe` AS `sexe`, `s`.`numero_sejour` AS `numero_sejour`, `s`.`type_sejour` AS `type_sejour`, `sb`.`nom` AS `nom`, `sb`.`code` AS `code`, `chir`.`nom` AS `chirurgien_nom`, `chir`.`prenom` AS `chirurgien_prenom`, `anesth`.`nom` AS `anesthesiste_nom`, `anesth`.`prenom` AS `anesthesiste_prenom`, `um`.`nom` AS `service_nom`, timestampdiff(MINUTE,`bi`.`heure_debut_reelle`,`bi`.`heure_fin_reelle`) AS `duree_reelle_minutes` FROM (((((((`bloc_intervention` `bi` join `sous_sejour` `ss` on((`bi`.`idsous_sejour` = `ss`.`idsous_sejour`))) join `sejour` `s` on((`ss`.`idsejour` = `s`.`idsejour`))) join `patient` `p` on((`s`.`idpatient` = `p`.`idpatient`))) join `salle_bloc` `sb` on((`bi`.`idsalle_bloc` = `sb`.`idsalle_bloc`))) join `utilisateur` `chir` on((`bi`.`idchirurgien` = `chir`.`idutilisateur`))) left join `utilisateur` `anesth` on((`bi`.`idanesthesiste` = `anesth`.`idutilisateur`))) left join `unite_med` `um` on((`ss`.`idunite_med` = `um`.`idunite_med`))) ;

-- --------------------------------------------------------

--
-- Structure for view `v_prescriptions_en_attente`
--
DROP TABLE IF EXISTS `v_prescriptions_en_attente`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_prescriptions_en_attente`  AS SELECT 'laboratoire' AS `type`, `ap`.`idactes_presc` AS `id`, `ap`.`idsous_sejour` AS `idsous_sejour`, `a`.`libelle` AS `libelle`, `ap`.`urgent` AS `urgent`, `ap`.`type_externe` AS `type_externe`, `ap`.`centre_externe` AS `centre_externe`, `ap`.`statut_execution` AS `statut_execution`, `ap`.`date_prescription` AS `date_prescription`, `p`.`nom` AS `patient_nom`, `p`.`prenom` AS `patient_prenom` FROM ((((`actes_presc` `ap` join `acte` `a` on((`ap`.`idacte` = `a`.`idacte`))) join `sous_sejour` `ss` on((`ap`.`idsous_sejour` = `ss`.`idsous_sejour`))) join `sejour` `s` on((`ss`.`idsejour` = `s`.`idsejour`))) join `patient` `p` on((`s`.`idpatient` = `p`.`idpatient`))) WHERE ((`a`.`idcategorie_acte` = 6) AND (`ap`.`statut_execution` in ('en_attente','en_cours')))union all select 'imagerie' AS `type`,`ap`.`idactes_presc` AS `id`,`ap`.`idsous_sejour` AS `idsous_sejour`,`a`.`libelle` AS `libelle`,`ap`.`urgent` AS `urgent`,`ap`.`type_externe` AS `type_externe`,`ap`.`centre_externe` AS `centre_externe`,`ap`.`statut_execution` AS `statut_execution`,`ap`.`date_prescription` AS `date_prescription`,`p`.`nom` AS `patient_nom`,`p`.`prenom` AS `patient_prenom` from ((((`actes_presc` `ap` join `acte` `a` on((`ap`.`idacte` = `a`.`idacte`))) join `sous_sejour` `ss` on((`ap`.`idsous_sejour` = `ss`.`idsous_sejour`))) join `sejour` `s` on((`ss`.`idsejour` = `s`.`idsejour`))) join `patient` `p` on((`s`.`idpatient` = `p`.`idpatient`))) where ((`a`.`idcategorie_acte` = 5) and (`ap`.`statut_execution` in ('en_attente','en_cours'))) union all select 'pharmacie' AS `type`,`pp`.`idpharma_presc` AS `id`,`pp`.`idsous_sejour` AS `idsous_sejour`,`pr`.`libelle` AS `libelle`,`pp`.`urgent` AS `urgent`,'interne' AS `type_externe`,NULL AS `centre_externe`,`pp`.`statut_execution` AS `statut_execution`,`pp`.`date_prescription` AS `date_prescription`,`p`.`nom` AS `patient_nom`,`p`.`prenom` AS `patient_prenom` from ((((`pharma_presc` `pp` join `prodpharma` `pr` on((`pp`.`idprodpharma` = `pr`.`idprodpharma`))) join `sous_sejour` `ss` on((`pp`.`idsous_sejour` = `ss`.`idsous_sejour`))) join `sejour` `s` on((`ss`.`idsejour` = `s`.`idsejour`))) join `patient` `p` on((`s`.`idpatient` = `p`.`idpatient`))) where (`pp`.`statut_execution` in ('en_attente','en_cours'))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_sejours_pdf_a_generer`
--
DROP TABLE IF EXISTS `v_sejours_pdf_a_generer`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_sejours_pdf_a_generer`  AS SELECT `s`.`idsejour` AS `idsejour`, `s`.`numero_sejour` AS `numero_sejour`, `s`.`date_entree` AS `date_entree`, `s`.`date_sortie` AS `date_sortie`, `s`.`type_sejour` AS `type_sejour`, `s`.`statut` AS `statut`, concat(`p`.`prenom`,' ',`p`.`nom`) AS `patient_nom`, `p`.`numero_dossier` AS `numero_dossier`, count(distinct `r`.`idresultat`) AS `nb_resultats_labo`, count(distinct `ri`.`idresultat_imagerie`) AS `nb_resultats_imagerie`, count(distinct `pp`.`idpharma_presc`) AS `nb_medicaments`, ((count(distinct `r`.`idresultat`) + count(distinct `ri`.`idresultat_imagerie`)) + count(distinct `pp`.`idpharma_presc`)) AS `total_items` FROM ((((((`sejour` `s` join `patient` `p` on((`s`.`idpatient` = `p`.`idpatient`))) left join `sous_sejour` `ss` on((`s`.`idsejour` = `ss`.`idsejour`))) left join `actes_presc` `ap` on((`ss`.`idsous_sejour` = `ap`.`idsous_sejour`))) left join `resultatslabo` `r` on((`ap`.`idactes_presc` = `r`.`idactes_presc`))) left join `resultats_imagerie` `ri` on((`ap`.`idactes_presc` = `ri`.`idactes_presc`))) left join `pharma_presc` `pp` on((`ss`.`idsous_sejour` = `pp`.`idsous_sejour`))) WHERE ((`s`.`statut` = 'termine') AND ((`s`.`pdf_resultats_genere` is null) OR (`s`.`pdf_resultats_genere` = 0)) AND (`s`.`date_sortie` >= (now() - interval 30 day))) GROUP BY `s`.`idsejour` HAVING (`total_items` > 0) ORDER BY `s`.`date_sortie` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `v_utilisateurs_permissions`
--
DROP TABLE IF EXISTS `v_utilisateurs_permissions`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `v_utilisateurs_permissions`  AS SELECT `u`.`idutilisateur` AS `idutilisateur`, `u`.`nom` AS `nom`, `u`.`prenom` AS `prenom`, `u`.`username` AS `username`, `p`.`nom` AS `profil`, `p`.`code` AS `profil_code`, `s`.`nom` AS `site`, `f`.`module` AS `module`, `f`.`nom` AS `permission`, `fp`.`peut_creer` AS `peut_creer`, `fp`.`peut_modifier` AS `peut_modifier`, `fp`.`peut_supprimer` AS `peut_supprimer`, `fp`.`peut_consulter` AS `peut_consulter`, `fp`.`peut_valider` AS `peut_valider`, `fp`.`peut_imprimer` AS `peut_imprimer` FROM ((((`utilisateur` `u` join `profiluser` `p` on((`u`.`idprofiluser` = `p`.`idprofiluser`))) left join `site` `s` on((`u`.`idsite` = `s`.`idsite`))) join `fct_profiluser` `fp` on((`p`.`idprofiluser` = `fp`.`idprofiluser`))) join `fct` `f` on((`fp`.`idfct` = `f`.`idfct`))) WHERE ((`u`.`statut` = 'actif') AND (`p`.`statut` = 'actif') AND (`f`.`statut` = 'actif')) ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `acte`
--
ALTER TABLE `acte`
  ADD CONSTRAINT `acte_ibfk_1` FOREIGN KEY (`idcategorie_acte`) REFERENCES `categorie_acte` (`idcategorie_acte`),
  ADD CONSTRAINT `acte_ibfk_2` FOREIGN KEY (`idsous_specialite`) REFERENCES `sous_specialite` (`idsous_specialite`),
  ADD CONSTRAINT `fk_acte_categorie` FOREIGN KEY (`idcategorie_acte`) REFERENCES `categorie_acte` (`idcategorie_acte`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_acte_specialite` FOREIGN KEY (`idspecialite`) REFERENCES `specialite` (`idspecialite`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `actes_presc`
--
ALTER TABLE `actes_presc`
  ADD CONSTRAINT `actes_presc_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `actes_presc_ibfk_2` FOREIGN KEY (`idacte`) REFERENCES `acte` (`idacte`),
  ADD CONSTRAINT `actes_presc_ibfk_3` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`),
  ADD CONSTRAINT `actes_presc_ibfk_4` FOREIGN KEY (`idsociete`) REFERENCES `societe` (`idsociete`),
  ADD CONSTRAINT `actes_presc_ibfk_5` FOREIGN KEY (`idspecialite`) REFERENCES `specialite` (`idspecialite`),
  ADD CONSTRAINT `actes_presc_ibfk_6` FOREIGN KEY (`prescripteur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `actes_presc_ibfk_7` FOREIGN KEY (`valideur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `actes_presc_ibfk_8` FOREIGN KEY (`executeur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `fk_actes_presc_groupe` FOREIGN KEY (`id_groupe_prescription`) REFERENCES `groupe_prescriptions` (`id_groupe_prescription`) ON DELETE SET NULL;

--
-- Constraints for table `actes_presc_historique`
--
ALTER TABLE `actes_presc_historique`
  ADD CONSTRAINT `actes_presc_historique_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `actes_presc` (`idactes_presc`),
  ADD CONSTRAINT `actes_presc_historique_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `allergies_patients`
--
ALTER TABLE `allergies_patients`
  ADD CONSTRAINT `allergies_patients_ibfk_1` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`);

--
-- Constraints for table `antecedents_patients`
--
ALTER TABLE `antecedents_patients`
  ADD CONSTRAINT `antecedents_patients_ibfk_1` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`);

--
-- Constraints for table `bloc_intervention`
--
ALTER TABLE `bloc_intervention`
  ADD CONSTRAINT `bloc_intervention_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`) ON DELETE CASCADE,
  ADD CONSTRAINT `bloc_intervention_ibfk_2` FOREIGN KEY (`idchirurgien`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL,
  ADD CONSTRAINT `bloc_intervention_ibfk_3` FOREIGN KEY (`idanesthesiste`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL,
  ADD CONSTRAINT `bloc_intervention_ibfk_4` FOREIGN KEY (`idsalle_bloc`) REFERENCES `salle_bloc` (`idsalle_bloc`) ON DELETE SET NULL,
  ADD CONSTRAINT `bloc_intervention_ibfk_5` FOREIGN KEY (`idutilisateur_annulation`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL,
  ADD CONSTRAINT `bloc_intervention_ibfk_6` FOREIGN KEY (`idutilisateur_programmation`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL;

--
-- Constraints for table `caisse_transact`
--
ALTER TABLE `caisse_transact`
  ADD CONSTRAINT `caisse_transact_ibfk_1` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`),
  ADD CONSTRAINT `caisse_transact_ibfk_2` FOREIGN KEY (`idcaisse_typetransact`) REFERENCES `caisse_typetransact` (`idcaisse_typetransact`),
  ADD CONSTRAINT `caisse_transact_ibfk_3` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `certificats_medicaux`
--
ALTER TABLE `certificats_medicaux`
  ADD CONSTRAINT `certificats_medicaux_ibfk_1` FOREIGN KEY (`idconsultation`) REFERENCES `consultations` (`idconsultation`),
  ADD CONSTRAINT `certificats_medicaux_ibfk_2` FOREIGN KEY (`idmedecin`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `chambre`
--
ALTER TABLE `chambre`
  ADD CONSTRAINT `chambre_ibfk_1` FOREIGN KEY (`idunitehospi`) REFERENCES `unite_hospi` (`idunitehospi`);

--
-- Constraints for table `compte_rendu_operatoire`
--
ALTER TABLE `compte_rendu_operatoire`
  ADD CONSTRAINT `compte_rendu_operatoire_ibfk_1` FOREIGN KEY (`idintervention`) REFERENCES `bloc_intervention` (`idintervention`) ON DELETE CASCADE,
  ADD CONSTRAINT `compte_rendu_operatoire_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL,
  ADD CONSTRAINT `compte_rendu_operatoire_ibfk_3` FOREIGN KEY (`idutilisateur_validation`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL,
  ADD CONSTRAINT `compte_rendu_operatoire_ibfk_4` FOREIGN KEY (`idutilisateur_modif`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL;

--
-- Constraints for table `consommables_bloc`
--
ALTER TABLE `consommables_bloc`
  ADD CONSTRAINT `consommables_bloc_ibfk_1` FOREIGN KEY (`idintervention`) REFERENCES `bloc_intervention` (`idintervention`) ON DELETE CASCADE,
  ADD CONSTRAINT `consommables_bloc_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `consultation`
--
ALTER TABLE `consultation`
  ADD CONSTRAINT `consultation_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `consultation_ibfk_2` FOREIGN KEY (`iddiagnostic`) REFERENCES `diagnostic` (`iddiagnostic`);

--
-- Constraints for table `consultations`
--
ALTER TABLE `consultations`
  ADD CONSTRAINT `consultations_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `consultations_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `fk_consult_patient` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`),
  ADD CONSTRAINT `fk_consult_sous_sejour` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `fk_consult_soussejour` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `fk_consult_user` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `fk_consult_utilisateur` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `demande_transfert_hospi`
--
ALTER TABLE `demande_transfert_hospi`
  ADD CONSTRAINT `demande_transfert_hospi_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `demande_transfert_hospi_ibfk_2` FOREIGN KEY (`idunitehospi_destination`) REFERENCES `unite_hospi` (`idunitehospi`);

--
-- Constraints for table `diagnostic_patient`
--
ALTER TABLE `diagnostic_patient`
  ADD CONSTRAINT `diagnostic_patient_ibfk_1` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`),
  ADD CONSTRAINT `diagnostic_patient_ibfk_2` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `diagnostic_patient_ibfk_3` FOREIGN KEY (`iddiagnostic`) REFERENCES `diagnostic` (`iddiagnostic`);

--
-- Constraints for table `entreprod`
--
ALTER TABLE `entreprod`
  ADD CONSTRAINT `entreprod_ibfk_1` FOREIGN KEY (`idfournissuer`) REFERENCES `fournisseur` (`idfournisseur`),
  ADD CONSTRAINT `entreprod_ibfk_2` FOREIGN KEY (`idofficine`) REFERENCES `officine` (`idofficine`),
  ADD CONSTRAINT `entreprod_ibfk_3` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `equipe_chirurgicale`
--
ALTER TABLE `equipe_chirurgicale`
  ADD CONSTRAINT `equipe_chirurgicale_ibfk_1` FOREIGN KEY (`idintervention`) REFERENCES `bloc_intervention` (`idintervention`) ON DELETE CASCADE,
  ADD CONSTRAINT `equipe_chirurgicale_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE CASCADE;

--
-- Constraints for table `evolution_urgence`
--
ALTER TABLE `evolution_urgence`
  ADD CONSTRAINT `fk_evolution_urgence` FOREIGN KEY (`idurgence`) REFERENCES `urgence` (`idurgence`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_evolution_urgence_user` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `fct_profiluser`
--
ALTER TABLE `fct_profiluser`
  ADD CONSTRAINT `fk_fct_profiluser_fct` FOREIGN KEY (`idfct`) REFERENCES `fct` (`idfct`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fct_profiluser_profiluser` FOREIGN KEY (`idprofiluser`) REFERENCES `profiluser` (`idprofiluser`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `feuille_anesthesie`
--
ALTER TABLE `feuille_anesthesie`
  ADD CONSTRAINT `feuille_anesthesie_ibfk_1` FOREIGN KEY (`idintervention`) REFERENCES `bloc_intervention` (`idintervention`) ON DELETE CASCADE,
  ADD CONSTRAINT `feuille_anesthesie_ibfk_2` FOREIGN KEY (`idanesthesiste`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL;

--
-- Constraints for table `groupe_prescriptions`
--
ALTER TABLE `groupe_prescriptions`
  ADD CONSTRAINT `fk_gp_prescripteur` FOREIGN KEY (`prescripteur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `fk_gp_sous_sejour` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`);

--
-- Constraints for table `historique_transfert_hospi`
--
ALTER TABLE `historique_transfert_hospi`
  ADD CONSTRAINT `historique_transfert_hospi_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`);

--
-- Constraints for table `image_i`
--
ALTER TABLE `image_i`
  ADD CONSTRAINT `image_i_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `actes_presc` (`idactes_presc`),
  ADD CONSTRAINT `image_i_ibfk_2` FOREIGN KEY (`radiologue`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `inventaire_ajustements`
--
ALTER TABLE `inventaire_ajustements`
  ADD CONSTRAINT `inventaire_ajustements_ibfk_1` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`),
  ADD CONSTRAINT `inventaire_ajustements_ibfk_2` FOREIGN KEY (`idofficine`) REFERENCES `officine` (`idofficine`),
  ADD CONSTRAINT `inventaire_ajustements_ibfk_3` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `labo_controle_qualite`
--
ALTER TABLE `labo_controle_qualite`
  ADD CONSTRAINT `labo_controle_qualite_ibfk_1` FOREIGN KEY (`idmachinelabo`) REFERENCES `machineslabo` (`idmachinelabo`),
  ADD CONSTRAINT `labo_controle_qualite_ibfk_2` FOREIGN KEY (`operateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `labo_prelevements`
--
ALTER TABLE `labo_prelevements`
  ADD CONSTRAINT `labo_prelevements_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `actes_presc` (`idactes_presc`),
  ADD CONSTRAINT `labo_prelevements_ibfk_2` FOREIGN KEY (`preleveur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `labo_valeurs_normales`
--
ALTER TABLE `labo_valeurs_normales`
  ADD CONSTRAINT `fk_lvn_acte` FOREIGN KEY (`idacte`) REFERENCES `acte` (`idacte`) ON DELETE CASCADE;

--
-- Constraints for table `ligneentree`
--
ALTER TABLE `ligneentree`
  ADD CONSTRAINT `ligneentree_ibfk_1` FOREIGN KEY (`identreprod`) REFERENCES `entreprod` (`identreprod`),
  ADD CONSTRAINT `ligneentree_ibfk_2` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`);

--
-- Constraints for table `lignesortieprod`
--
ALTER TABLE `lignesortieprod`
  ADD CONSTRAINT `lignesortieprod_ibfk_1` FOREIGN KEY (`idsortieprod`) REFERENCES `sortieprod` (`idsortieprod`),
  ADD CONSTRAINT `lignesortieprod_ibfk_2` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`);

--
-- Constraints for table `lignesrecquisition`
--
ALTER TABLE `lignesrecquisition`
  ADD CONSTRAINT `lignesrecquisition_ibfk_1` FOREIGN KEY (`idrequisition`) REFERENCES `requisition` (`idrequisition`),
  ADD CONSTRAINT `lignesrecquisition_ibfk_2` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`);

--
-- Constraints for table `lit`
--
ALTER TABLE `lit`
  ADD CONSTRAINT `lit_ibfk_1` FOREIGN KEY (`idchambre`) REFERENCES `chambre` (`idchambre`),
  ADD CONSTRAINT `lit_ibfk_2` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`);

--
-- Constraints for table `logs_connexion`
--
ALTER TABLE `logs_connexion`
  ADD CONSTRAINT `logs_connexion_ibfk_1` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `machineslabo_maintenance`
--
ALTER TABLE `machineslabo_maintenance`
  ADD CONSTRAINT `fk_machine_maintenance` FOREIGN KEY (`idmachinelabo`) REFERENCES `machineslabo` (`idmachinelabo`) ON DELETE CASCADE;

--
-- Constraints for table `notes_evolution`
--
ALTER TABLE `notes_evolution`
  ADD CONSTRAINT `notes_evolution_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `notes_evolution_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `officine`
--
ALTER TABLE `officine`
  ADD CONSTRAINT `officine_ibfk_1` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`);

--
-- Constraints for table `parametresvitaux`
--
ALTER TABLE `parametresvitaux`
  ADD CONSTRAINT `parametresvitaux_ibfk_1` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`),
  ADD CONSTRAINT `parametresvitaux_ibfk_2` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `parametresvitaux_ibfk_3` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `patient`
--
ALTER TABLE `patient`
  ADD CONSTRAINT `patient_ibfk_1` FOREIGN KEY (`idquartier`) REFERENCES `quartier` (`idquartier`),
  ADD CONSTRAINT `patient_ibfk_2` FOREIGN KEY (`idgrsanguin`) REFERENCES `grsanguin` (`idgrsanguin`),
  ADD CONSTRAINT `patient_ibfk_3` FOREIGN KEY (`idethnie`) REFERENCES `ethnie` (`idethnie`),
  ADD CONSTRAINT `patient_ibfk_4` FOREIGN KEY (`idreligion`) REFERENCES `religion` (`idreligion`),
  ADD CONSTRAINT `patient_ibfk_5` FOREIGN KEY (`idsociete`) REFERENCES `societe` (`idsociete`),
  ADD CONSTRAINT `patient_ibfk_6` FOREIGN KEY (`idcategorie`) REFERENCES `categorie` (`idcategorie`),
  ADD CONSTRAINT `patient_ibfk_7` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `pharma_entrees`
--
ALTER TABLE `pharma_entrees`
  ADD CONSTRAINT `pharma_entrees_ibfk_1` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`),
  ADD CONSTRAINT `pharma_entrees_ibfk_2` FOREIGN KEY (`idfournisseur`) REFERENCES `fournisseur` (`idfournisseur`),
  ADD CONSTRAINT `pharma_entrees_ibfk_3` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `pharma_presc`
--
ALTER TABLE `pharma_presc`
  ADD CONSTRAINT `pharma_presc_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `pharma_presc_ibfk_2` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`),
  ADD CONSTRAINT `pharma_presc_ibfk_3` FOREIGN KEY (`idsociete`) REFERENCES `societe` (`idsociete`),
  ADD CONSTRAINT `pharma_presc_ibfk_4` FOREIGN KEY (`prescripteur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `pharma_presc_ibfk_5` FOREIGN KEY (`valideur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `pharma_presc_ibfk_6` FOREIGN KEY (`executeur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `pharma_presc_ibfk_7` FOREIGN KEY (`executeur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `planning_soins`
--
ALTER TABLE `planning_soins`
  ADD CONSTRAINT `planning_soins_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`);

--
-- Constraints for table `prelevements_anapath`
--
ALTER TABLE `prelevements_anapath`
  ADD CONSTRAINT `prelevements_anapath_ibfk_1` FOREIGN KEY (`idintervention`) REFERENCES `bloc_intervention` (`idintervention`) ON DELETE CASCADE,
  ADD CONSTRAINT `prelevements_anapath_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `prodpharma`
--
ALTER TABLE `prodpharma`
  ADD CONSTRAINT `prodpharma_ibfk_1` FOREIGN KEY (`idfamiprod`) REFERENCES `famiprod` (`idfamiprod`),
  ADD CONSTRAINT `prodpharma_ibfk_2` FOREIGN KEY (`idsous_specialite`) REFERENCES `sous_specialite` (`idsous_specialite`),
  ADD CONSTRAINT `prodpharma_ibfk_3` FOREIGN KEY (`idfrm_prod`) REFERENCES `frm_prod` (`idfrm_prod`),
  ADD CONSTRAINT `prodpharma_ibfk_4` FOREIGN KEY (`idvoie_prod`) REFERENCES `voie_prod` (`idvoie_prod`),
  ADD CONSTRAINT `prodpharma_ibfk_5` FOREIGN KEY (`idunite`) REFERENCES `unite` (`idunite`);

--
-- Constraints for table `quartier`
--
ALTER TABLE `quartier`
  ADD CONSTRAINT `quartier_ibfk_1` FOREIGN KEY (`idcommune`) REFERENCES `commune` (`idcommune`);

--
-- Constraints for table `requisition`
--
ALTER TABLE `requisition`
  ADD CONSTRAINT `requisition_ibfk_1` FOREIGN KEY (`idofficine`) REFERENCES `officine` (`idofficine`),
  ADD CONSTRAINT `requisition_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `requisition_ibfk_3` FOREIGN KEY (`traiteur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `resultatslabo`
--
ALTER TABLE `resultatslabo`
  ADD CONSTRAINT `resultatslabo_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `actes_presc` (`idactes_presc`),
  ADD CONSTRAINT `resultatslabo_ibfk_2` FOREIGN KEY (`analyse_par`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `resultatslabo_ibfk_3` FOREIGN KEY (`idmachinelabo`) REFERENCES `machineslabo` (`idmachinelabo`);

--
-- Constraints for table `resultatslabo_documents`
--
ALTER TABLE `resultatslabo_documents`
  ADD CONSTRAINT `resultatslabo_documents_ibfk_1` FOREIGN KEY (`idresultat`) REFERENCES `resultatslabo` (`idresultat`) ON DELETE CASCADE,
  ADD CONSTRAINT `resultatslabo_documents_ibfk_2` FOREIGN KEY (`upload_par`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `resultats_imagerie`
--
ALTER TABLE `resultats_imagerie`
  ADD CONSTRAINT `resultats_imagerie_ibfk_1` FOREIGN KEY (`idactes_presc`) REFERENCES `actes_presc` (`idactes_presc`) ON DELETE CASCADE,
  ADD CONSTRAINT `resultats_imagerie_ibfk_2` FOREIGN KEY (`radiologue`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL;

--
-- Constraints for table `salle_bloc`
--
ALTER TABLE `salle_bloc`
  ADD CONSTRAINT `fk_salle_bloc_site` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`) ON DELETE CASCADE,
  ADD CONSTRAINT `salle_bloc_ibfk_1` FOREIGN KEY (`idunite_med`) REFERENCES `unite_med` (`idunite_med`) ON DELETE SET NULL;

--
-- Constraints for table `sejour`
--
ALTER TABLE `sejour`
  ADD CONSTRAINT `sejour_ibfk_1` FOREIGN KEY (`idpatient`) REFERENCES `patient` (`idpatient`),
  ADD CONSTRAINT `sejour_ibfk_2` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`),
  ADD CONSTRAINT `sejour_ibfk_3` FOREIGN KEY (`idmotif`) REFERENCES `motif` (`idmotif`),
  ADD CONSTRAINT `sejour_ibfk_4` FOREIGN KEY (`idorigine`) REFERENCES `origine` (`idorigine`),
  ADD CONSTRAINT `sejour_ibfk_5` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_ibfk_1` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`);

--
-- Constraints for table `societe_tarif`
--
ALTER TABLE `societe_tarif`
  ADD CONSTRAINT `societe_tarif_ibfk_1` FOREIGN KEY (`idsociete`) REFERENCES `societe` (`idsociete`),
  ADD CONSTRAINT `societe_tarif_ibfk_2` FOREIGN KEY (`idtarif`) REFERENCES `tarif` (`idtarif`),
  ADD CONSTRAINT `societe_tarif_ibfk_3` FOREIGN KEY (`idcategorie`) REFERENCES `categorie` (`idcategorie`),
  ADD CONSTRAINT `societe_tarif_ibfk_4` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`);

--
-- Constraints for table `soins_infirmiers`
--
ALTER TABLE `soins_infirmiers`
  ADD CONSTRAINT `soins_infirmiers_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `soins_infirmiers_ibfk_2` FOREIGN KEY (`idinfirmier`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `sortieprod`
--
ALTER TABLE `sortieprod`
  ADD CONSTRAINT `sortieprod_ibfk_1` FOREIGN KEY (`idofficine`) REFERENCES `officine` (`idofficine`),
  ADD CONSTRAINT `sortieprod_ibfk_2` FOREIGN KEY (`iddestination`) REFERENCES `destinationsprod` (`iddestination`),
  ADD CONSTRAINT `sortieprod_ibfk_3` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `sorties_stock`
--
ALTER TABLE `sorties_stock`
  ADD CONSTRAINT `sorties_stock_ibfk_1` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`),
  ADD CONSTRAINT `sorties_stock_ibfk_2` FOREIGN KEY (`idofficine`) REFERENCES `officine` (`idofficine`),
  ADD CONSTRAINT `sorties_stock_ibfk_3` FOREIGN KEY (`idpharma_presc`) REFERENCES `pharma_presc` (`idpharma_presc`),
  ADD CONSTRAINT `sorties_stock_ibfk_4` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `sorties_stock_ibfk_5` FOREIGN KEY (`iddestination`) REFERENCES `destinationsprod` (`iddestination`) ON DELETE SET NULL;

--
-- Constraints for table `sous_sejour`
--
ALTER TABLE `sous_sejour`
  ADD CONSTRAINT `sous_sejour_ibfk_1` FOREIGN KEY (`idsejour`) REFERENCES `sejour` (`idsejour`) ON DELETE CASCADE,
  ADD CONSTRAINT `sous_sejour_ibfk_2` FOREIGN KEY (`idunite_med`) REFERENCES `unite_med` (`idunite_med`),
  ADD CONSTRAINT `sous_sejour_ibfk_3` FOREIGN KEY (`idmotif`) REFERENCES `motif` (`idmotif`),
  ADD CONSTRAINT `sous_sejour_ibfk_4` FOREIGN KEY (`idlit_actuel`) REFERENCES `lit` (`idlit`);

--
-- Constraints for table `sous_specialite`
--
ALTER TABLE `sous_specialite`
  ADD CONSTRAINT `sous_specialite_ibfk_1` FOREIGN KEY (`idspecialite`) REFERENCES `specialite` (`idspecialite`);

--
-- Constraints for table `stockpharma`
--
ALTER TABLE `stockpharma`
  ADD CONSTRAINT `stockpharma_ibfk_1` FOREIGN KEY (`idprodpharma`) REFERENCES `prodpharma` (`idprodpharma`),
  ADD CONSTRAINT `stockpharma_ibfk_2` FOREIGN KEY (`idofficine`) REFERENCES `officine` (`idofficine`);

--
-- Constraints for table `transfert_hospi`
--
ALTER TABLE `transfert_hospi`
  ADD CONSTRAINT `fk_transfert_new_chambre` FOREIGN KEY (`nouveau_idchambre`) REFERENCES `chambre` (`idchambre`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_new_lit` FOREIGN KEY (`nouveau_idlit`) REFERENCES `lit` (`idlit`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_new_unite` FOREIGN KEY (`nouveau_idunitehospi`) REFERENCES `unite_hospi` (`idunitehospi`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_old_chambre` FOREIGN KEY (`ancien_idchambre`) REFERENCES `chambre` (`idchambre`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_old_lit` FOREIGN KEY (`ancien_idlit`) REFERENCES `lit` (`idlit`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_old_unite` FOREIGN KEY (`ancien_idunitehospi`) REFERENCES `unite_hospi` (`idunitehospi`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_sous_sejour` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transfert_user` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `transfert_urgence`
--
ALTER TABLE `transfert_urgence`
  ADD CONSTRAINT `fk_transfert_urgence` FOREIGN KEY (`idurgence`) REFERENCES `urgence` (`idurgence`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_transfert_urgence_user` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `transmissioninfirmière`
--
ALTER TABLE `transmissioninfirmière`
  ADD CONSTRAINT `transmissioninfirmière_ibfk_1` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`),
  ADD CONSTRAINT `transmissioninfirmière_ibfk_2` FOREIGN KEY (`idutilisateur`) REFERENCES `utilisateur` (`idutilisateur`);

--
-- Constraints for table `unite_med`
--
ALTER TABLE `unite_med`
  ADD CONSTRAINT `fk_unite_sous_specialite` FOREIGN KEY (`idsous_specialite`) REFERENCES `sous_specialite` (`idsous_specialite`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `unite_med_ibfk_1` FOREIGN KEY (`idservices`) REFERENCES `services` (`idservices`),
  ADD CONSTRAINT `unite_med_ibfk_2` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`),
  ADD CONSTRAINT `unite_med_ibfk_3` FOREIGN KEY (`idunitehospi`) REFERENCES `unite_hospi` (`idunitehospi`);

--
-- Constraints for table `urgence`
--
ALTER TABLE `urgence`
  ADD CONSTRAINT `fk_urgence_medecin` FOREIGN KEY (`medecin_triage`) REFERENCES `utilisateur` (`idutilisateur`),
  ADD CONSTRAINT `fk_urgence_sous_sejour` FOREIGN KEY (`idsous_sejour`) REFERENCES `sous_sejour` (`idsous_sejour`) ON DELETE CASCADE;

--
-- Constraints for table `utilisateur`
--
ALTER TABLE `utilisateur`
  ADD CONSTRAINT `utilisateur_ibfk_1` FOREIGN KEY (`idprofiluser`) REFERENCES `profiluser` (`idprofiluser`),
  ADD CONSTRAINT `utilisateur_ibfk_2` FOREIGN KEY (`idsite`) REFERENCES `site` (`idsite`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
