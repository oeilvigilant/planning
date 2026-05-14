-- =====================================================
-- OV-GESTION — Base de données complète
-- Société : Oeil Vigilant
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- -----------------------------------------------------
-- ROLES
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (`nom`, `slug`, `description`) VALUES
('Administrateur', 'admin', 'Accès complet à toutes les fonctionnalités'),
('Manager', 'manager', 'Gestion agents, planning et affectations'),
('Agent', 'agent', 'Accès unique via token pour remplir sa fiche');

-- -----------------------------------------------------
-- PERMISSIONS PAR ROLE PAR MODULE
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(1) DEFAULT 0,
  `can_create` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_delete` tinyint(1) DEFAULT 0,
  `can_export` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_module` (`role_id`, `module`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `role_permissions` (`role_id`, `module`, `can_view`, `can_create`, `can_edit`, `can_delete`, `can_export`) VALUES
-- Admin : tout
(1, 'dashboard', 1,1,1,1,1),
(1, 'agents', 1,1,1,1,1),
(1, 'planning', 1,1,1,1,1),
(1, 'salaires', 1,1,1,1,1),
(1, 'parametres', 1,1,1,1,1),
(1, 'rapports', 1,1,1,1,1),
(1, 'utilisateurs', 1,1,1,1,1),
-- Manager
(2, 'dashboard', 1,0,0,0,0),
(2, 'agents', 1,1,1,0,1),
(2, 'planning', 1,1,1,0,1),
(2, 'salaires', 1,0,0,0,1),
(2, 'parametres', 0,0,0,0,0),
(2, 'rapports', 1,0,0,0,1),
(2, 'utilisateurs', 0,0,0,0,0);

-- -----------------------------------------------------
-- UTILISATEURS
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `utilisateurs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL DEFAULT 1,
  `actif` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin par défaut : admin@ov.fr / Admin2024!
INSERT INTO `utilisateurs` (`nom`, `prenom`, `email`, `password`, `role_id`) VALUES
('Traore', 'Ibrahim', 'admin@ov.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- -----------------------------------------------------
-- PARAMETRES GENERAUX
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `parametres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cle` varchar(100) NOT NULL,
  `valeur` text,
  `label` varchar(150),
  `groupe` varchar(50),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle` (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `parametres` (`cle`, `valeur`, `label`, `groupe`) VALUES
('entreprise_nom', 'Oeil Vigilant', 'Nom de l\'entreprise', 'entreprise'),
('entreprise_dirigeant', 'TRAORE Ibrahim', 'Nom du dirigeant', 'entreprise'),
('entreprise_adresse', '58 RUE DE MONCEAU', 'Adresse', 'entreprise'),
('entreprise_cp', '75008', 'Code postal', 'entreprise'),
('entreprise_ville', 'PARIS', 'Ville', 'entreprise'),
('entreprise_siret', '92855270200013', 'Numéro de SIRET', 'entreprise'),
('entreprise_tel', '', 'Téléphone', 'entreprise'),
('entreprise_email', '', 'Email', 'entreprise'),
('logo_principal', 'logo.png', 'Logo principal', 'entreprise'),
('nuit_debut', '21:00', 'Début heures de nuit', 'planning'),
('nuit_fin', '06:00', 'Fin heures de nuit', 'planning'),
('smtp_host', '', 'Serveur SMTP', 'email'),
('smtp_port', '587', 'Port SMTP', 'email'),
('smtp_user', '', 'Utilisateur SMTP', 'email'),
('smtp_pass', '', 'Mot de passe SMTP', 'email'),
('smtp_from', 'noreply@oeilvigilant.fr', 'Email expéditeur', 'email'),
('token_expiration_jours', '7', 'Durée validité token agent (jours)', 'securite');

-- -----------------------------------------------------
-- TAUX HORAIRES
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `taux_horaires` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_heure` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `taux` decimal(8,4) NOT NULL DEFAULT 0,
  `ordre` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_heure` (`type_heure`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `taux_horaires` (`type_heure`, `label`, `taux`, `ordre`) VALUES
('normal', 'Heure normale', 12.00, 1),
('nuit', 'Heure de nuit', 14.40, 2),
('dimanche', 'Heure dimanche', 18.00, 3),
('ferie_normal', 'Heure férié (semaine)', 24.00, 4),
('ferie_dimanche', 'Heure férié dimanche', 24.00, 5),
('ferie_nuit', 'Heure nuit férié', 24.00, 6);

-- -----------------------------------------------------
-- JOURS FERIES
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `jours_feries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `nom` varchar(150) NOT NULL,
  `recurrent` tinyint(1) DEFAULT 1,
  `annee` int(4),
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jours fériés France 2025 & 2026
INSERT INTO `jours_feries` (`date`, `nom`, `recurrent`, `annee`) VALUES
('2025-01-01', 'Jour de l\'An', 1, 2025),
('2025-04-21', 'Lundi de Pâques', 0, 2025),
('2025-05-01', 'Fête du Travail', 1, 2025),
('2025-05-08', 'Victoire 1945', 1, 2025),
('2025-05-29', 'Ascension', 0, 2025),
('2025-06-09', 'Lundi de Pentecôte', 0, 2025),
('2025-07-14', 'Fête Nationale', 1, 2025),
('2025-08-15', 'Assomption', 1, 2025),
('2025-11-01', 'Toussaint', 1, 2025),
('2025-11-11', 'Armistice', 1, 2025),
('2025-12-25', 'Noël', 1, 2025),
('2026-01-01', 'Jour de l\'An', 1, 2026),
('2026-04-06', 'Lundi de Pâques', 0, 2026),
('2026-05-01', 'Fête du Travail', 1, 2026),
('2026-05-08', 'Victoire 1945', 1, 2026),
('2026-05-14', 'Ascension', 0, 2026),
('2026-05-25', 'Lundi de Pentecôte', 0, 2026),
('2026-07-14', 'Fête Nationale', 1, 2026),
('2026-08-15', 'Assomption', 1, 2026),
('2026-11-01', 'Toussaint', 1, 2026),
('2026-11-11', 'Armistice', 1, 2026),
('2026-12-25', 'Noël', 1, 2026);

-- -----------------------------------------------------
-- AGENTS
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `agents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  -- Identité
  `matricule` varchar(30) DEFAULT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) NOT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(150) DEFAULT NULL,
  `nationalite` varchar(100) DEFAULT NULL,
  `num_secu` varchar(30) DEFAULT NULL,
  `situation_familiale` enum('Célibataire','Marié(e)','Divorcé(e)','Veuf(ve)','PACS') DEFAULT NULL,
  `nb_enfants` int(11) DEFAULT 0,
  `photo` varchar(255) DEFAULT NULL,
  -- Coordonnées
  `adresse` text DEFAULT NULL,
  `cp` varchar(10) DEFAULT NULL,
  `ville` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  -- Contrat
  `type_contrat` enum('CDI','CDD','CDD Usage','Saisonnier') DEFAULT NULL,
  `poste` varchar(100) DEFAULT NULL,
  `statut` enum('Cadre','Non cadre') DEFAULT NULL,
  `temps_travail_hebdo` varchar(50) DEFAULT NULL,
  `date_debut_contrat` date DEFAULT NULL,
  `date_fin_contrat` date DEFAULT NULL,
  `lieu_travail` varchar(200) DEFAULT NULL,
  `periode_essai` varchar(100) DEFAULT NULL,
  `motif_embauche` enum('Accroissement activité','Remplacement','Autres') DEFAULT NULL,
  `remuneration` decimal(10,2) DEFAULT NULL,
  `type_remuneration` enum('Nette','Brute') DEFAULT NULL,
  -- Autorisation CNAPS
  `num_autorisation_cnaps` varchar(50) DEFAULT NULL,
  `date_autorisation_cnaps` date DEFAULT NULL,
  `date_expiration_cnaps` date DEFAULT NULL,
  -- Répartition horaire (si temps partiel)
  `h_lundi` decimal(4,2) DEFAULT 0,
  `h_mardi` decimal(4,2) DEFAULT 0,
  `h_mercredi` decimal(4,2) DEFAULT 0,
  `h_jeudi` decimal(4,2) DEFAULT 0,
  `h_vendredi` decimal(4,2) DEFAULT 0,
  `h_samedi` decimal(4,2) DEFAULT 0,
  `h_dimanche` decimal(4,2) DEFAULT 0,
  -- Pôle Social
  `dpae` tinyint(1) DEFAULT 0,
  `contrat_realise` tinyint(1) DEFAULT 0,
  `bulletins_depuis` varchar(20) DEFAULT NULL,
  `prelevement_auto` tinyint(1) DEFAULT 0,
  -- Statut
  `actif` tinyint(1) DEFAULT 1,
  `token_acces` varchar(64) DEFAULT NULL,
  `token_used` tinyint(1) DEFAULT 0,
  `token_expires_at` timestamp NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `matricule` (`matricule`),
  UNIQUE KEY `token_acces` (`token_acces`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- DOCUMENTS AGENTS
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `type_document` enum('piece_identite','carte_vitale','attestation_domicile','titre_sejour','attestation_cnaps','contrat','autre') NOT NULL,
  `nom_fichier` varchar(255) NOT NULL,
  `chemin` varchar(255) NOT NULL,
  `taille` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- VERSIONS DE PLANNING
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `planning_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mois` int(2) NOT NULL,
  `annee` int(4) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `note` text DEFAULT NULL,
  `is_current` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- LIGNES DE PLANNING
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `planning_lignes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version_id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `date_travail` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `depasse_minuit` tinyint(1) DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  -- Heures calculées (en minutes)
  `min_normal` int(11) DEFAULT 0,
  `min_nuit` int(11) DEFAULT 0,
  `min_dimanche` int(11) DEFAULT 0,
  `min_ferie_normal` int(11) DEFAULT 0,
  `min_ferie_dimanche` int(11) DEFAULT 0,
  `min_ferie_nuit` int(11) DEFAULT 0,
  `calcul_ok` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`version_id`) REFERENCES `planning_versions`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- CHAMPS CARTE AGENT (paramétrables)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `carte_champs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cle` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `face` enum('recto','verso') NOT NULL DEFAULT 'recto',
  `actif` tinyint(1) DEFAULT 1,
  `ordre` int(11) DEFAULT 0,
  `source` enum('agent','entreprise','custom') DEFAULT 'agent',
  `valeur_custom` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `carte_champs` (`cle`, `label`, `face`, `actif`, `ordre`, `source`) VALUES
-- Recto
('photo', 'Photo de l\'agent', 'recto', 1, 1, 'agent'),
('prenom_nom', 'Prénom & Nom', 'recto', 1, 2, 'agent'),
('matricule', 'Matricule', 'recto', 1, 3, 'agent'),
('poste', 'Poste occupé', 'recto', 1, 4, 'agent'),
('num_autorisation_cnaps', 'N° Autorisation CNAPS', 'recto', 1, 5, 'agent'),
('entreprise_nom', 'Nom entreprise', 'recto', 1, 6, 'entreprise'),
('logo', 'Logo entreprise', 'recto', 1, 7, 'entreprise'),
-- Verso
('entreprise_adresse', 'Adresse entreprise', 'verso', 1, 1, 'entreprise'),
('entreprise_tel', 'Téléphone entreprise', 'verso', 1, 2, 'entreprise'),
('entreprise_siret', 'SIRET', 'verso', 1, 3, 'entreprise'),
('date_debut_contrat', 'Date début contrat', 'verso', 1, 4, 'agent'),
('date_expiration_cnaps', 'Expiration CNAPS', 'verso', 1, 5, 'agent');

-- -----------------------------------------------------
-- CHAMPS PDF COMPTABLE (paramétrables)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_champs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cle` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `actif` tinyint(1) DEFAULT 1,
  `ordre` int(11) DEFAULT 0,
  `source` enum('agent','entreprise') DEFAULT 'agent',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `pdf_champs` (`cle`, `label`, `actif`, `ordre`, `source`) VALUES
('nom', 'Nom', 1, 1, 'agent'),
('prenom', 'Prénom', 1, 2, 'agent'),
('date_naissance', 'Date de naissance', 1, 3, 'agent'),
('lieu_naissance', 'Lieu de naissance', 1, 4, 'agent'),
('nationalite', 'Nationalité', 1, 5, 'agent'),
('num_secu', 'N° Sécurité Sociale', 1, 6, 'agent'),
('adresse', 'Adresse', 1, 7, 'agent'),
('cp', 'Code Postal', 1, 8, 'agent'),
('ville', 'Ville', 1, 9, 'agent'),
('situation_familiale', 'Situation familiale', 1, 10, 'agent'),
('nb_enfants', 'Nombre d\'enfants', 1, 11, 'agent'),
('type_contrat', 'Type de contrat', 1, 12, 'agent'),
('poste', 'Poste occupé', 1, 13, 'agent'),
('statut', 'Statut (Cadre/Non Cadre)', 1, 14, 'agent'),
('date_debut_contrat', 'Date de début de contrat', 1, 15, 'agent'),
('date_fin_contrat', 'Date de fin de contrat', 1, 16, 'agent'),
('lieu_travail', 'Lieu de travail', 1, 17, 'agent'),
('remuneration', 'Rémunération', 1, 18, 'agent'),
('type_remuneration', 'Type (nette/brute)', 1, 19, 'agent'),
('num_autorisation_cnaps', 'N° Autorisation CNAPS', 1, 20, 'agent'),
('date_expiration_cnaps', 'Expiration CNAPS', 1, 21, 'agent'),
('dpae', 'DPAE réalisée', 0, 22, 'agent'),
('contrat_realise', 'Contrat réalisé', 0, 23, 'agent');
