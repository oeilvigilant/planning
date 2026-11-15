<?php
/**
 * Configuration de l'outil OV Pointage.
 *
 * Copiez ce fichier en « config.php » dans le même dossier, puis remplissez
 * les 4 lignes de base de données avec LES MÊMES valeurs que le planning
 * (fichier planning/config/config.php sur le serveur).
 *
 * config.php n'est jamais envoyé par le déploiement automatique :
 * il reste uniquement sur le serveur.
 */

define('DB_HOST', 'localhost');        // ex. : le serveur MySQL indiqué par LWS
define('DB_NAME', 'nom_de_la_base');
define('DB_USER', 'utilisateur_mysql');
define('DB_PASS', 'mot_de_passe_mysql');

// Adresse publique de l'outil
define('POINTAGE_URL', 'https://pointage.oeilvigilant.com');

// Adresse des photos des agents (gérées par le planning)
define('PT_PHOTOS_URL', 'https://oeilvigilant.com/planning/uploads/photos');
