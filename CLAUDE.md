# OV-Gestion — Contexte Claude Code

## Présentation
Système de gestion du planning et des agents de sécurité pour **Oeil Vigilant**.
Application web PHP interne hébergée sur WAMP, accessible sur `http://localhost/ov-gestion/`.

## Stack technique
- **PHP 8.4** (WAMP) + PDO
- **MySQL 9.1** — base de données : `ov` (root, sans mot de passe)
- **Bootstrap 5.3** (CDN) + Font Awesome 6.5 (CDN)
- **DomPDF** (Composer) — export PDF
- Pas de framework PHP, pas de JS framework

## Structure du projet
```
ov-gestion/
├── CLAUDE.md              ← ce fichier
├── README.md              ← installation
├── index.php              ← page de login
├── logout.php
├── install.php            ← script d'installation BDD (supprimer après usage)
├── config/
│   ├── config.php         ← constantes APP_URL, DB_, SESSION_
│   └── db.php             ← getDB() — singleton PDO
├── includes/
│   ├── auth.php           ← login(), logout(), requireLogin(), canDo()
│   ├── functions.php      ← helpers : getParam(), calculerHeuresParType(), upload...
│   ├── header.php         ← layout HTML + sidebar + topbar
│   └── footer.php         ← fermeture HTML + JS
├── assets/
│   ├── css/theme.css      ← thème complet (CSS variables, sidebar, cartes, tables)
│   ├── js/app.js          ← JS global (confirm, preview photo, format secu)
│   └── img/               ← logo.png, logo-blanc.jpg
├── modules/
│   ├── dashboard/         ← tableau de bord
│   ├── agents/            ← liste, fiche, add, edit, carte, export PDF, token
│   ├── planning/          ← planning mensuel + versions + exports
│   ├── salaires/          ← calcul salaires + détail par agent
│   ├── parametres/        ← taux, fériés, planning, carte, PDF, utilisateurs
│   └── rapports/          ← exports groupés
├── token/
│   └── formulaire.php     ← formulaire public (accès agent via token)
├── uploads/
│   ├── photos/            ← photos agents
│   ├── documents/         ← pièces jointes agents
│   └── logos/             ← logos entreprise
└── libs/
    └── vendor/            ← DomPDF via Composer
```

## Base de données — tables principales
| Table | Description |
|-------|-------------|
| `utilisateurs` | Comptes d'accès (admin, manager) |
| `roles` | Rôles : admin, manager, agent |
| `role_permissions` | Matrice droits par module |
| `agents` | Fiche complète agent (identité, contrat, CNAPS, répartition h.) |
| `agent_documents` | Pièces jointes par agent (PDF/image) |
| `planning_versions` | Versions du planning par mois/année |
| `planning_lignes` | Entrées heure_debut/heure_fin par agent/jour/version |
| `taux_horaires` | 6 taux configurables (normal, nuit, dimanche, etc.) |
| `jours_feries` | Jours fériés France + personnalisés |
| `parametres` | Config globale (clé/valeur) |
| `carte_champs` | Champs actifs sur la carte agent (recto/verso) |
| `pdf_champs` | Champs actifs dans le PDF comptable |

## Logique métier clé

### Calcul des heures (functions.php → calculerHeuresParType)
- Entrée : `date`, `heure_debut`, `heure_fin`
- Si `heure_fin < heure_debut` → dépassement de minuit, découpage automatique sur J et J+1
- Les seuils nuit (ex: 21h–06h) sont configurables dans `parametres`
- Les 6 types : `normal`, `nuit`, `dimanche`, `ferie_normal`, `ferie_dimanche`, `ferie_nuit`
- Les minutes par type sont stockées dans `planning_lignes` (min_normal, min_nuit, etc.)

### Versioning du planning
- Chaque mois a une version active (`is_current=1`)
- "Nouvelle version" archive la courante et crée une copie complète
- On peut restaurer n'importe quelle version archivée

### Authentification
- `requireLogin()` — redirige vers index.php si non connecté
- `requirePerm($module, $action)` — vérifie les droits, affiche 403 sinon
- `canDo($module, $action)` — renvoie bool, pour affichage conditionnel

## Identifiants de développement
- URL : `http://localhost/ov-gestion/`
- Admin : `admin@ov.fr` / `Admin2024!`
- BDD : MySQL root (sans mot de passe), base `ov`

## Conventions de code
- Toutes les sorties HTML passent par `h()` (= htmlspecialchars)
- PDO avec requêtes préparées systématiquement
- `getDB()` retourne un singleton PDO
- `flash('success'|'danger', 'message')` pour les notifications
- Variables CSS dans `theme.css` : `--ov-dark`, `--ov-navy`, `--ov-gold`
- Classes utilitaires CSS : `ov-card`, `ov-table`, `btn-ov-primary`, `btn-sm-icon`
