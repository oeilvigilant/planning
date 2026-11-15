<?php
/**
 * Schéma de l'outil Pointage (tables préfixées pt_).
 * Versionné via le paramètre `pointage_schema_version` : chaque étape n'est
 * exécutée qu'une fois, on ne relit pas INFORMATION_SCHEMA à chaque requête.
 */

const PT_SCHEMA_VERSION = 2;

function ensurePointageSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $current = (int)getParam('pointage_schema_version', '0');
    if ($current >= PT_SCHEMA_VERSION) return;

    $db = getDB();

    if ($current < 1) {
        // Coordonnées et contact terrain des missions
        $cols = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'missions'")->fetchAll(PDO::FETCH_COLUMN);
        $add = [
            'pt_adresse'         => "VARCHAR(255) DEFAULT NULL",
            'pt_latitude'        => "DECIMAL(10,7) DEFAULT NULL",
            'pt_longitude'       => "DECIMAL(10,7) DEFAULT NULL",
            'pt_rayon_m'         => "INT NOT NULL DEFAULT 200",
            'pt_responsable_nom' => "VARCHAR(150) DEFAULT NULL",
            'pt_responsable_tel' => "VARCHAR(30) DEFAULT NULL",
            'pt_consignes'       => "TEXT",
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $db->exec("ALTER TABLE missions ADD COLUMN `$col` $def");
            }
        }

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

        // Paramètres par défaut (sans écraser un réglage existant)
        $defaults = [
            'pointage_tolerance_min'  => '5',
            'pointage_rayon_defaut_m' => '200',
            'pointage_rgpd_version'   => '1',
            'pointage_rgpd_texte'     => ptRgpdTexteDefaut(),
        ];
        $ins = $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)");
        foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

        // Droit 'pointage' : managers et planificateurs alignés sur 'planning'
        $db->exec("INSERT INTO role_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
            SELECT rp.role_id, 'pointage', rp.can_view, rp.can_create, rp.can_edit, rp.can_delete, rp.can_export
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            WHERE r.slug IN ('manager','planificateur') AND rp.module = 'planning'
              AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.module = 'pointage')");
    }

    if ($current < 2) {
        // WhatsApp du responsable de site (si différent du numéro d'appel)
        $hasWa = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'missions' AND COLUMN_NAME = 'pt_responsable_whatsapp'")->fetchColumn();
        if (!$hasWa) $db->exec("ALTER TABLE missions ADD COLUMN pt_responsable_whatsapp VARCHAR(30) DEFAULT NULL AFTER pt_responsable_tel");

        // Contact par défaut (permanence), initialisé avec le téléphone de l'entreprise
        $ins = $db->prepare("INSERT IGNORE INTO parametres (cle, valeur) VALUES (?, ?)");
        $ins->execute(['pointage_contact_nom', 'Permanence OEIL VIGILANT']);
        $ins->execute(['pointage_contact_tel', getParam('entreprise_tel', '')]);
        $ins->execute(['pointage_contact_whatsapp', '']);
    }

    setParam('pointage_schema_version', (string)PT_SCHEMA_VERSION);
}

function ptRgpdTexteDefaut(): string {
    return <<<TXT
## Pourquoi cet outil ?
OEIL VIGILANT utilise cet outil pour enregistrer vos prises et fins de service, vous permettre de signaler un événement et de joindre rapidement votre responsable, et justifier les heures réalisées auprès du client.

## Quelles données ?
- Votre identité (nom, prénom, matricule)
- L'heure de vos pointages
- Votre position GPS **uniquement au moment où vous pointez ou signalez un événement**
- Les photos que vous choisissez de prendre

**Aucun suivi de position en continu.** L'application ne vous localise jamais en dehors de ces actions, ni en dehors de vos heures de service.

## Qui y a accès ?
La direction et les responsables d'OEIL VIGILANT. Le client de la mission peut consulter les heures et les événements de **sa** mission uniquement.

## Combien de temps ?
- Horaires de travail : 5 ans (obligations sociales)
- Positions GPS et photos : 1 an, puis suppression

## Vos droits
Vous pouvez demander l'accès, la rectification ou l'effacement de vos données, et vous opposer à leur traitement pour un motif légitime, auprès de la direction d'OEIL VIGILANT. Vous pouvez aussi saisir la CNIL (www.cnil.fr).
TXT;
}
