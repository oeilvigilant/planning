# OV-Gestion — Système de gestion planning agents de sécurité

Application web interne pour **Oeil Vigilant** — gestion des plannings, agents, salaires et exports.

---

## Prérequis

- [WAMP Server](https://www.wampserver.com/) (ou XAMPP) avec PHP 8.0+ et MySQL 5.7+
- [Composer](https://getcomposer.org/) (pour DomPDF — exports PDF natifs)
- Navigateur moderne (Chrome, Firefox, Edge)

---

## Installation

### 1. Copier le dossier

Placer le dossier `ov-gestion` dans le répertoire web de WAMP :

```
C:\wamp64\www\ov-gestion\
```

### 2. Créer la base de données

Ouvrir phpMyAdmin (`http://localhost/phpmyadmin`) et créer la base :

```sql
CREATE DATABASE ov CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Puis importer le fichier `config/database.sql` dans cette base.

**OU** — lancer le script d'installation automatique :

```
http://localhost/ov-gestion/install.php
```

> Supprimer `install.php` après l'installation.

### 3. Vérifier la configuration

Ouvrir `config/config.php` et ajuster si nécessaire :

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ov');
define('DB_USER', 'root');
define('DB_PASS', '');              // mot de passe MySQL si défini
define('APP_URL',  'http://localhost/ov-gestion');
```

### 4. Installer DomPDF (exports PDF natifs)

Dans le dossier `ov-gestion`, ouvrir un terminal et exécuter :

```bash
composer require dompdf/dompdf
```

> Sans DomPDF, les exports PDF fonctionnent en mode impression navigateur (Ctrl+P).

### 5. Permissions dossiers uploads

S'assurer que le dossier `uploads/` est accessible en écriture.
Sur Windows/WAMP, aucune action nécessaire par défaut.

---

## Premier accès

Ouvrir `http://localhost/ov-gestion/` dans le navigateur.

**Identifiants par défaut :**

| Champ | Valeur |
|-------|--------|
| Email | `admin@ov.fr` |
| Mot de passe | `Admin2024!` |

> Changer le mot de passe après la première connexion via **Paramètres → Utilisateurs**.

---

## Structure des modules

| Module | URL | Description |
|--------|-----|-------------|
| Tableau de bord | `/modules/dashboard/` | Stats, alertes CNAPS, agents du jour |
| Agents | `/modules/agents/` | Fiches, documents, cartes, tokens |
| Planning | `/modules/planning/` | Planning mensuel/hebdo, versioning |
| Salaires | `/modules/salaires/` | Calcul et détail des salaires |
| Rapports | `/modules/rapports/` | Exports PDF et Excel |
| Paramètres | `/modules/parametres/` | Taux, fériés, rôles, carte, PDF |

---

## Fonctionnalités principales

### Planning
- Saisie des heures de travail par agent et par jour (heure début → heure fin)
- Gestion automatique du dépassement de minuit
- Calcul automatique des heures par type : normale, nuit, dimanche, férié (normal / dimanche / nuit)
- Seuils "heures de nuit" configurables dans les paramètres
- Versioning : chaque modification peut créer une nouvelle version archivée
- Export PDF (impression) et Excel (CSV)

### Agents
- Fiche complète : identité, contrat, CNAPS, coordonnées, répartition horaire
- Upload de documents (pièce d'identité, carte vitale, attestation domicile, CNAPS, contrat)
- Lien token à usage unique pour que l'agent remplisse lui-même sa fiche
- Alerte automatique si autorisation CNAPS expire dans les 30 jours

### Carte agent
- Format carte de crédit (85 × 54 mm), recto/verso
- Champs affichés configurables dans les paramètres
- Impression directe ou export PDF

### Salaires
- 6 taux horaires configurables : normal, nuit, dimanche, férié normal, férié dimanche, nuit férié
- Calcul automatique à partir du planning
- Export récapitulatif PDF et CSV par mois

---

## Rôles et droits

| Rôle | Droits |
|------|--------|
| **Admin** | Accès complet, configuration, utilisateurs |
| **Manager** | Agents, planning, salaires (lecture) |
| **Agent** | Accès unique via lien token pour remplir sa fiche |

Les droits par module sont configurables dans **Paramètres → Utilisateurs & Rôles**.

---

## Jours fériés France (pré-chargés)

Les jours fériés légaux français sont pré-chargés pour 2025 et 2026.
Ils sont gérables dans **Paramètres → Jours fériés**.

---

## Mise à jour

Pour mettre à jour l'application, remplacer les fichiers PHP.
La base de données n'est pas touchée lors d'une mise à jour des fichiers.

---

## Support

Société **Oeil Vigilant** — usage interne uniquement.
