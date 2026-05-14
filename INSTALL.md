# Déploiement OV-Gestion — Guide complet

## Vue d'ensemble

```
Local (WAMP) ──push──► GitHub (oeilvigilant/planning) ──Actions──► LWS (prod)
```

Chaque `git push` sur `master` déclenche un déploiement automatique sur LWS.

---

## PHASE 1 — Clé SSH pour le compte GitHub oeilvigilant

### Étape 1.1 — Générer la clé SSH

Dans le terminal :

```bash
ssh-keygen -t ed25519 -C "oeilvigilant@github" -f /c/Users/admin/.ssh/id_ed25519_oeilvigilant -N ""
```

### Étape 1.2 — Afficher la clé publique

```bash
cat /c/Users/admin/.ssh/id_ed25519_oeilvigilant.pub
```

Copiez le résultat (commence par `ssh-ed25519 ...`).

### Étape 1.3 — Ajouter la clé sur GitHub

1. Connectez-vous au compte **oeilvigilant** sur GitHub
2. `Settings` → `SSH and GPG keys` → `New SSH key`
3. Titre : `Dev Windows`
4. Collez la clé → `Add SSH key`

### Étape 1.4 — Tester la connexion

```bash
ssh -T git@github-oeilvigilant
```

✅ Résultat attendu : `Hi oeilvigilant! You've successfully authenticated`

---

## PHASE 2 — Initialiser le repo git local

### Étape 2.1 — Initialiser git dans le projet

```bash
git -C /c/wamp64/www/ov-gestion init
```

### Étape 2.2 — Configurer l'identité pour CE repo uniquement

```bash
git -C /c/wamp64/www/ov-gestion config user.name "Oeil Vigilant"
git -C /c/wamp64/www/ov-gestion config user.email "VOTRE_EMAIL_DU_COMPTE_OEILVIGILANT"
```

> Remplacez VOTRE_EMAIL_DU_COMPTE_OEILVIGILANT par l'email du compte GitHub oeilvigilant.
> Cette config est locale au repo — elle n'affecte pas vos autres projets.

### Étape 2.3 — Lier au repo GitHub

```bash
git -C /c/wamp64/www/ov-gestion remote add origin git@github-oeilvigilant:oeilvigilant/planning.git
```

> Note : `git@github-oeilvigilant` = alias SSH défini dans ~/.ssh/config → utilise la clé oeilvigilant

### Étape 2.4 — Premier commit et push

```bash
git -C /c/wamp64/www/ov-gestion add .
git -C /c/wamp64/www/ov-gestion commit -m "Initial commit — OV-Gestion planning agents sécurité"
git -C /c/wamp64/www/ov-gestion branch -M master
git -C /c/wamp64/www/ov-gestion push -u origin master
```

✅ Le code est maintenant sur https://github.com/oeilvigilant/planning

---

## PHASE 3 — Configurer le déploiement automatique (GitHub Actions → LWS)

### Étape 3.1 — Récupérer vos accès FTP LWS

Dans votre **Espace Client LWS** :
1. Hébergement → votre hébergement → `Accès FTP`
2. Notez : **Serveur FTP**, **Identifiant**, **Mot de passe**, **Chemin** (ex: `/www/`)

### Étape 3.2 — Ajouter les secrets GitHub

Sur https://github.com/oeilvigilant/planning :
1. `Settings` → `Secrets and variables` → `Actions`
2. `New repository secret` — ajoutez ces 4 secrets :

| Secret         | Valeur exemple                  | Description              |
|----------------|---------------------------------|--------------------------|
| `FTP_HOST`     | `ftpXXXXX.lws.fr`              | Serveur FTP LWS          |
| `FTP_USER`     | `oeilvigilant`                  | Identifiant FTP LWS      |
| `FTP_PASS`     | `votre_mot_de_passe`            | Mot de passe FTP LWS     |
| `FTP_PATH`     | `/www/ov-gestion/`              | Dossier sur le serveur   |

> Le fichier `.github/workflows/deploy.yml` est déjà en place — il se déclenche à chaque push sur master.

---

## PHASE 4 — Préparer le serveur LWS (une seule fois)

### Étape 4.1 — Exporter la base de données locale

```bash
"C:\wamp64\bin\mysql\mysql9.1.0\bin\mysqldump.exe" -u root ov > C:\Users\admin\Desktop\ov_export.sql
```

### Étape 4.2 — Importer sur LWS

1. Espace Client LWS → `Bases de données` → créez une base MySQL
2. Notez : **Hôte**, **Nom BDD**, **Utilisateur**, **Mot de passe**
3. Ouvrez **phpMyAdmin LWS** → sélectionnez votre base → `Importer` → `ov_export.sql`

### Étape 4.3 — Créer config.php sur le serveur

Via le **Gestionnaire de fichiers LWS** ou FTP :
1. Naviguez dans `/www/ov-gestion/config/`
2. Copiez `config.sample.php` → renommez en `config.php`
3. Modifiez les valeurs :

```php
define('DB_HOST', 'votre-host-mysql-lws');   // ex: sql123.lws.fr
define('DB_NAME', 'votre_base');
define('DB_USER', 'votre_user');
define('DB_PASS', 'votre_mdp');

define('APP_URL', 'https://votre-domaine.fr/ov-gestion');
```

### Étape 4.4 — Créer les dossiers uploads sur LWS

Via Gestionnaire de fichiers LWS, vérifiez que ces dossiers existent et sont accessibles en écriture :
```
/www/ov-gestion/uploads/photos/
/www/ov-gestion/uploads/documents/
/www/ov-gestion/uploads/logos/
```

---

## PHASE 5 — Vérification finale

### Étape 5.1 — Tester le déploiement automatique

Faites un petit changement local, puis :

```bash
git -C /c/wamp64/www/ov-gestion add .
git -C /c/wamp64/www/ov-gestion commit -m "test: vérification déploiement auto"
git -C /c/wamp64/www/ov-gestion push
```

Allez sur https://github.com/oeilvigilant/planning → onglet `Actions` → vérifiez que le job tourne ✅

### Étape 5.2 — Vérifier l'application en ligne

Ouvrez : `https://votre-domaine.fr/ov-gestion/`

---

## Workflow quotidien (après installation)

```bash
# Depuis n'importe où dans ov-gestion :
git add .
git commit -m "feat: description de votre changement"
git push
# → déploiement automatique en ~30 secondes
```

---

## Résolution de problèmes courants

| Problème | Solution |
|---|---|
| `Permission denied (publickey)` | Vérifiez l'étape 1.4, la clé n'est pas bien ajoutée sur GitHub |
| `remote: Repository not found` | Vérifiez l'alias SSH dans `~/.ssh/config` et l'URL du remote |
| `550 Permission denied` sur FTP | Vérifiez `FTP_PATH` — doit commencer et finir par `/` |
| Page blanche sur LWS | `config.php` manquant ou mauvais credentials DB |
| Images/uploads absents | Créer manuellement les dossiers `uploads/` sur LWS |
