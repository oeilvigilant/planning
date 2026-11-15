<?php
/**
 * Noyau autonome de l'outil Pointage : configuration, base de données, utilitaires.
 * L'outil ne dépend d'aucun fichier du planning ; il partage seulement la base MySQL.
 *
 * Configuration, par ordre de priorité :
 *   1. pointage/config.php                 (production : dossier du sous-domaine)
 *   2. ../planning/config/config.php       (repli : dossier voisin du planning)
 *   3. ../config/config.php                (développement local WAMP)
 */
(function () {
    $candidats = [
        __DIR__ . '/../config.php',
        __DIR__ . '/../../planning/config/config.php',
        __DIR__ . '/../../config/config.php',
    ];
    foreach ($candidats as $f) {
        if (is_file($f)) { require_once $f; return; }
    }
    http_response_code(500);
    exit('Configuration manquante : créez config.php à partir de config.sample.php.');
})();

if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
date_default_timezone_set('Europe/Paris');

if (!function_exists('getDB')) {
    function getDB(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            try {
                $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('OV Pointage — connexion BDD impossible : ' . $e->getMessage());
                http_response_code(503);
                exit('Service momentanément indisponible. Réessayez dans quelques instants.');
            }
        }
        return $pdo;
    }
}

if (!function_exists('getParam')) {
    function getParam(string $cle, string $default = ''): string {
        $st = getDB()->prepare("SELECT valeur FROM parametres WHERE cle = ?");
        $st->execute([$cle]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    }
}

if (!function_exists('setParam')) {
    function setParam(string $cle, string $valeur): void {
        getDB()->prepare("INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")
            ->execute([$cle, $valeur]);
    }
}

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('generateToken')) {
    function generateToken(): string {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('formatDate')) {
    function formatDate(?string $date): string {
        return $date ? date('d/m/Y', strtotime($date)) : '—';
    }
}

if (!function_exists('getNomMois')) {
    function getNomMois(int $mois): string {
        $noms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        return $noms[$mois] ?? '';
    }
}

/** URL publique des photos d'agents (stockées par le planning). Chaîne vide si inconnue. */
function ptPhotosUrl(): string {
    if (defined('PT_PHOTOS_URL')) return rtrim(PT_PHOTOS_URL, '/');
    if (defined('UPLOAD_URL')) return rtrim(UPLOAD_URL, '/') . '/photos';
    return '';
}
