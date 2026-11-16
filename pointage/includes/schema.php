<?php
/**
 * Schéma de l'outil Pointage.
 *
 * L'outil fonctionne de deux façons, sans réglage :
 *  - à côté d'OV-Gestion : il réutilise ses tables (agents, missions, clients,
 *    utilisateurs, planning) et n'ajoute que des colonnes pt_* et des tables pt_* ;
 *  - seul, sur une base vide (vente à un autre client) : il crée les tables
 *    minimales dont il a besoin. Le planning est alors simplement absent.
 *
 * Versionné via le paramètre `pointage_schema_version` : chaque étape ne
 * s'exécute qu'une fois.
 */

const PT_SCHEMA_VERSION = 6;

function ensurePointageSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $current = (int)getParam('pointage_schema_version', '0');
    } catch (PDOException $e) {
        $current = 0;   // base vide : la table parametres n'existe pas encore
    }
    if ($current >= PT_SCHEMA_VERSION) return;

    $db = getDB();

    if ($current < 3) ptEnsureTablesDeBase($db);

    if ($current < 1) {
        // Coordonnées et contact terrain des missions (sites)
        ptAddColumns($db, 'missions', [
            'pt_adresse'         => "VARCHAR(255) DEFAULT NULL",
            'pt_latitude'        => "DECIMAL(10,7) DEFAULT NULL",
            'pt_longitude'       => "DECIMAL(10,7) DEFAULT NULL",
            'pt_rayon_m'         => "INT NOT NULL DEFAULT 200",
            'pt_responsable_nom' => "VARCHAR(150) DEFAULT NULL",
            'pt_responsable_tel' => "VARCHAR(30) DEFAULT NULL",
            'pt_consignes'       => "TEXT",
        ]);

        // Accès agent à l'outil (lien d'activation + PIN + RGPD)
        $db->exec("CREATE TABLE IF NOT EXISTS pt_acces (
            agent_id INT PRIMARY KEY,
            activation_token VARCHAR(64) DEFAULT NULL,
            activation_expires DATETIME DEFAULT NULL,
            pin_hash VARCHAR(255) DEFAULT NULL,
            pin_essais INT NOT NULL DEFAULT 0,
            bloque_jusqua DATETIME DEFAULT NULL,
            active_le DATETIME DEFAULT NULL,
            rgpd_version INT NOT NULL DEFAULT 0,
            rgpd_accepte_le DATETIME DEFAULT NULL,
            derniere_connexion DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_token (activation_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Appareils mémorisés (connexion persistante, iPhone/Android)
        $db->exec("CREATE TABLE IF NOT EXISTS pt_appareils (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            selector CHAR(24) NOT NULL,
            validator_hash CHAR(64) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_selector (selector),
            INDEX idx_agent (agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Preuve d'information RGPD (une ligne par acceptation)
        $db->exec("CREATE TABLE IF NOT EXISTS pt_rgpd_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            version INT NOT NULL,
            accepte_le DATETIME NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            INDEX idx_agent (agent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ins = $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)");
        foreach ([
            'pointage_tolerance_min'  => '5',
            'pointage_rayon_defaut_m' => '200',
            'pointage_rgpd_version'   => '1',
            'pointage_rgpd_texte'     => ptRgpdTexteDefaut(),
        ] as $k => $v) $ins->execute([$k, $v]);

        // Droit 'pointage' : managers et planificateurs alignés sur 'planning' (si OV-Gestion est présent)
        $db->exec("INSERT INTO role_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
            SELECT rp.role_id, 'pointage', rp.can_view, rp.can_create, rp.can_edit, rp.can_delete, rp.can_export
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            WHERE r.slug IN ('manager','planificateur') AND rp.module = 'planning'
              AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.module = 'pointage')");
    }

    if ($current < 2) {
        ptAddColumns($db, 'missions', ['pt_responsable_whatsapp' => "VARCHAR(30) DEFAULT NULL"]);
        $ins = $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)");
        $ins->execute(['pointage_contact_nom', 'Permanence']);
        $ins->execute(['pointage_contact_tel', getParam('entreprise_tel', '')]);
        $ins->execute(['pointage_contact_whatsapp', '']);
    }

    if ($current < 3) {
        // Un service = une prise + une fin de service. Heures « réelles » (saisies par
        // l'agent) et « retenues » (après tolérance ou correction) séparées.
        $db->exec("CREATE TABLE IF NOT EXISTS pt_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            mission_id INT NOT NULL,
            planning_ligne_id INT DEFAULT NULL,
            prevu_debut DATETIME DEFAULT NULL,
            prevu_fin DATETIME DEFAULT NULL,

            debut_reel DATETIME NOT NULL,
            debut_retenu DATETIME NOT NULL,
            debut_uuid CHAR(36) DEFAULT NULL,
            debut_lat DECIMAL(10,7) DEFAULT NULL,
            debut_lng DECIMAL(10,7) DEFAULT NULL,
            debut_precision_m INT DEFAULT NULL,
            debut_distance_m INT DEFAULT NULL,
            debut_hors_zone TINYINT(1) NOT NULL DEFAULT 0,
            debut_photo VARCHAR(255) DEFAULT NULL,
            debut_hors_ligne TINYINT(1) NOT NULL DEFAULT 0,

            fin_reel DATETIME DEFAULT NULL,
            fin_retenu DATETIME DEFAULT NULL,
            fin_uuid CHAR(36) DEFAULT NULL,
            fin_lat DECIMAL(10,7) DEFAULT NULL,
            fin_lng DECIMAL(10,7) DEFAULT NULL,
            fin_precision_m INT DEFAULT NULL,
            fin_distance_m INT DEFAULT NULL,
            fin_hors_zone TINYINT(1) NOT NULL DEFAULT 0,
            fin_photo VARCHAR(255) DEFAULT NULL,
            fin_hors_ligne TINYINT(1) NOT NULL DEFAULT 0,

            statut ENUM('en_cours','a_verifier','ok','valide','annule') NOT NULL DEFAULT 'en_cours',
            alertes VARCHAR(255) DEFAULT NULL,
            source ENUM('agent','manuel') NOT NULL DEFAULT 'agent',
            note VARCHAR(255) DEFAULT NULL,
            valide_par INT DEFAULT NULL,
            valide_le DATETIME DEFAULT NULL,
            created_by_user INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_debut_uuid (debut_uuid),
            UNIQUE KEY uk_fin_uuid (fin_uuid),
            INDEX idx_agent_debut (agent_id, debut_retenu),
            INDEX idx_mission_debut (mission_id, debut_retenu),
            INDEX idx_statut (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Historique des corrections : la donnée d'origine n'est jamais perdue
        $db->exec("CREATE TABLE IF NOT EXISTS pt_corrections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_id INT NOT NULL,
            action VARCHAR(30) NOT NULL,
            avant TEXT,
            apres TEXT,
            motif VARCHAR(255) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            user_nom VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_service (service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)")->execute(['pointage_selfie', 'optionnel']);
    }

    if ($current < 4) {
        // Événements terrain : incidents, SOS, main courante
        $db->exec("CREATE TABLE IF NOT EXISTS pt_evenements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) DEFAULT NULL,
            agent_id INT NOT NULL,
            mission_id INT DEFAULT NULL,
            service_id INT DEFAULT NULL,
            type ENUM('incident','sos','main_courante') NOT NULL DEFAULT 'incident',
            categorie VARCHAR(50) DEFAULT NULL,
            gravite ENUM('info','faible','moyenne','haute','critique') NOT NULL DEFAULT 'info',
            description TEXT,
            photos TEXT,
            lat DECIMAL(10,7) DEFAULT NULL,
            lng DECIMAL(10,7) DEFAULT NULL,
            precision_m INT DEFAULT NULL,
            survenu_le DATETIME NOT NULL,
            statut ENUM('ouvert','en_cours','clos') NOT NULL DEFAULT 'ouvert',
            visible_client TINYINT(1) NOT NULL DEFAULT 1,
            commentaire TEXT,
            traite_par INT DEFAULT NULL,
            traite_le DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_uuid (uuid),
            INDEX idx_mission (mission_id, survenu_le),
            INDEX idx_statut (statut, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Abonnements aux notifications push (agents et responsables)
        $db->exec("CREATE TABLE IF NOT EXISTS pt_push (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            endpoint VARCHAR(600) NOT NULL,
            p256dh VARCHAR(200) NOT NULL,
            auth VARCHAR(100) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_endpoint (endpoint(255)),
            INDEX idx_agent (agent_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Anti-doublon des rappels / alertes / tâches planifiées
        $db->exec("CREATE TABLE IF NOT EXISTS pt_notifs (
            cle VARCHAR(150) PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Portails clients (lien sécurisé, lecture seule)
        $db->exec("CREATE TABLE IF NOT EXISTS pt_portails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(64) NOT NULL,
            libelle VARCHAR(150) NOT NULL,
            client_id INT DEFAULT NULL,
            missions VARCHAR(500) NOT NULL DEFAULT '',
            email VARCHAR(255) DEFAULT NULL,
            voir_heures TINYINT(1) NOT NULL DEFAULT 1,
            voir_evenements TINYINT(1) NOT NULL DEFAULT 1,
            voir_photos TINYINT(1) NOT NULL DEFAULT 0,
            envoi_hebdo TINYINT(1) NOT NULL DEFAULT 0,
            expire_le DATE DEFAULT NULL,
            revoque TINYINT(1) NOT NULL DEFAULT 0,
            derniere_visite DATETIME DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Signatures des rapports par le client
        $db->exec("CREATE TABLE IF NOT EXISTS pt_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            portail_id INT NOT NULL,
            mission_id INT DEFAULT NULL,
            periode_du DATE NOT NULL,
            periode_au DATE NOT NULL,
            total_min INT NOT NULL DEFAULT 0,
            nom VARCHAR(150) NOT NULL,
            fonction VARCHAR(150) DEFAULT NULL,
            signature MEDIUMTEXT NOT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_portail (portail_id, periode_du)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ins = $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)");
        $ins->execute(['pointage_cron_cle', bin2hex(random_bytes(16))]);
        $ins->execute(['pointage_alerte_emails', getParam('entreprise_email', '')]);
        $ins->execute(['pointage_rappel_avant_min', '15']);
        $ins->execute(['pointage_alerte_non_pris_min', '15']);
    }

    if ($current < 5) {
        // Rondes : points de contrôle (QR code collé sur place) et passages scannés
        ptAddColumns($db, 'missions', ['pt_ronde_freq_min' => "INT NOT NULL DEFAULT 0"]);
        $db->exec("CREATE TABLE IF NOT EXISTS pt_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mission_id INT NOT NULL,
            nom VARCHAR(150) NOT NULL,
            consigne VARCHAR(255) DEFAULT NULL,
            code CHAR(16) NOT NULL,
            ordre INT NOT NULL DEFAULT 0,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code (code),
            INDEX idx_mission (mission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS pt_passages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) DEFAULT NULL,
            point_id INT NOT NULL,
            mission_id INT NOT NULL,
            agent_id INT NOT NULL,
            service_id INT DEFAULT NULL,
            scanne_le DATETIME NOT NULL,
            lat DECIMAL(10,7) DEFAULT NULL,
            lng DECIMAL(10,7) DEFAULT NULL,
            precision_m INT DEFAULT NULL,
            hors_ligne TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_uuid (uuid),
            INDEX idx_mission (mission_id, scanne_le),
            INDEX idx_service (service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Mot de passe oublié (responsables)
        $db->exec("CREATE TABLE IF NOT EXISTS pt_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_token (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)")->execute(['pointage_ronde_marge_min', '15']);
    }

    if ($current < 6) {
        // Mode de ronde par site : aucune / qr / photo / simple ; groupe WhatsApp par site
        ptAddColumns($db, 'missions', [
            'pt_ronde_mode'      => "VARCHAR(10) NOT NULL DEFAULT 'aucune'",
            'pt_whatsapp_groupe' => "VARCHAR(255) DEFAULT NULL",
        ]);
        // Les sites qui avaient déjà des points de ronde restent en mode QR
        $db->exec("UPDATE missions m SET pt_ronde_mode = 'qr' WHERE EXISTS (SELECT 1 FROM pt_points p WHERE p.mission_id = m.id)");
        ptAddColumns($db, 'pt_passages', [
            'methode' => "VARCHAR(10) NOT NULL DEFAULT 'qr'",
            'photo'   => "VARCHAR(255) DEFAULT NULL",
        ]);
        $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)")->execute(['pointage_whatsapp_groupe', '']);
    }

    setParam('pointage_schema_version', (string)PT_SCHEMA_VERSION);
}

/**
 * Tables partagées avec OV-Gestion. Si elles existent déjà (installation OV),
 * rien ne change ; sur une base vide, on crée le strict nécessaire.
 */
function ptEnsureTablesDeBase(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS parametres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cle VARCHAR(100) NOT NULL,
        valeur TEXT,
        label VARCHAR(150) DEFAULT NULL,
        groupe VARCHAR(50) DEFAULT NULL,
        UNIQUE KEY cle (cle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        slug VARCHAR(50) NOT NULL,
        description TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO roles (nom, slug, description) VALUES
        ('Administrateur', 'admin', 'Accès complet'),
        ('Manager', 'manager', 'Gestion des agents et des sites')");

    $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        module VARCHAR(50) NOT NULL,
        can_view TINYINT(1) DEFAULT 0,
        can_create TINYINT(1) DEFAULT 0,
        can_edit TINYINT(1) DEFAULT 0,
        can_delete TINYINT(1) DEFAULT 0,
        can_export TINYINT(1) DEFAULT 0,
        UNIQUE KEY role_module (role_id, module)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO role_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
        SELECT id, 'pointage', 1, 1, 1, 0, 1 FROM roles WHERE slug = 'manager'");

    $db->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role_id INT NOT NULL DEFAULT 1,
        actif TINYINT(1) DEFAULT 1,
        last_login TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(255) NOT NULL,
        adresse TEXT,
        email VARCHAR(255) DEFAULT '',
        telephone VARCHAR(30) DEFAULT '',
        contact VARCHAR(255) DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS missions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(255) NOT NULL,
        client_id INT DEFAULT NULL,
        lieu VARCHAR(255) DEFAULT NULL,
        description TEXT,
        actif TINYINT(1) DEFAULT 1,
        is_default TINYINT(1) DEFAULT 0,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS mission_agents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mission_id INT NOT NULL,
        agent_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY mission_agent (mission_id, agent_id),
        INDEX idx_agent (agent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS agents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        matricule VARCHAR(30) DEFAULT NULL,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        sexe ENUM('M','F') NOT NULL DEFAULT 'M',
        telephone VARCHAR(20) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        actif TINYINT(1) DEFAULT 1,
        statut_inscription VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ptAddColumns(PDO $db, string $table, array $cols): void {
    $st = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    $existing = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col => $def) {
        if (!in_array($col, $existing, true)) $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
}

/** Le planning d'OV-Gestion est-il présent dans cette base ? */
function ptHasPlanning(): bool {
    static $has = null;
    if ($has === null) {
        $n = (int)getDB()->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('planning_lignes','planning_versions')")->fetchColumn();
        $has = $n === 2;
    }
    return $has;
}

function ptRgpdTexteDefaut(): string {
    return <<<TXT
## Pourquoi cet outil ?
Votre employeur utilise cet outil pour enregistrer vos prises et fins de service, vous permettre de signaler un événement et de joindre rapidement votre responsable, et justifier les heures réalisées auprès du client.

## Quelles données ?
- Votre identité (nom, prénom, matricule)
- L'heure de vos pointages
- Votre position GPS **uniquement au moment où vous pointez ou signalez un événement**
- Les photos que vous choisissez de prendre

**Aucun suivi de position en continu.** L'application ne vous localise jamais en dehors de ces actions, ni en dehors de vos heures de service.

## Qui y a accès ?
La direction et les responsables de votre employeur. Le client de la mission peut consulter les heures et les événements de **sa** mission uniquement.

## Combien de temps ?
- Horaires de travail : 5 ans (obligations sociales)
- Positions GPS et photos : 1 an, puis suppression

## Vos droits
Vous pouvez demander l'accès, la rectification ou l'effacement de vos données, et vous opposer à leur traitement pour un motif légitime, auprès de votre employeur. Vous pouvez aussi saisir la CNIL (www.cnil.fr).
TXT;
}
