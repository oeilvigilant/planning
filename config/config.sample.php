<?php
// ── Copier ce fichier en config.php et remplir les valeurs ──────────────────

define('DB_HOST', 'localhost');
define('DB_NAME', 'VOTRE_BASE');
define('DB_USER', 'VOTRE_USER');
define('DB_PASS', 'VOTRE_MOT_DE_PASSE');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'OV-Gestion');
define('APP_URL', 'https://VOTRE_DOMAINE.fr/ov-gestion');  // Ex: https://oeilvigilant.fr
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_PATH', APP_ROOT . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');

define('SESSION_NAME', 'ov_gestion_session');
define('SESSION_LIFETIME', 3600 * 8);

date_default_timezone_set('Europe/Paris');

define('DOMPDF_AUTOLOAD', APP_ROOT . '/vendor/autoload.php');
