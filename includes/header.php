<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
ensureInscriptionSchema();
$user = getCurrentUser();
$currentModule = $currentModule ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'OV-Gestion') ?> — OV-Gestion</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= APP_URL ?>/assets/css/theme.css" rel="stylesheet">
<?php if (isset($extraCss)) echo $extraCss; ?>
</head>
<body class="app-body">

<!-- SIDEBAR -->
<nav id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo">
        <div>
            <div class="sidebar-brand-text">OV-GESTION</div>
            <div class="sidebar-brand-sub">OEIL VIGILANT</div>
        </div>
    </div>

    <ul class="nav flex-column px-1 mt-2" style="list-style:none;padding:0">
        <?php if (canDo('dashboard','view')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link <?= $currentModule==='dashboard'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-gauge"></i></span> Tableau de bord
            </a>
        </li>
        <?php endif; ?>

        <?php if (canDo('agents','view')): ?>
        <div class="sidebar-section">Agents</div>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/agents/index.php" class="nav-link <?= $currentModule==='agents'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-user-shield"></i></span> Liste des agents
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/agents/add.php" class="nav-link <?= $currentModule==='agents-add'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-user-plus"></i></span> Ajouter un agent
            </a>
        </li>
        <?php if (canDo('agents','create')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/agents/inscriptions.php" class="nav-link <?= $currentModule==='agents-inscriptions'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-user-clock"></i></span> Inscriptions en attente
                <?php $nbEnAttenteNav = countInscriptionsEnAttente(); ?>
                <?php if ($nbEnAttenteNav > 0): ?><span class="badge bg-danger rounded-pill ms-1"><?= $nbEnAttenteNav ?></span><?php endif; ?>
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (canDo('missions','view')): ?>
        <div class="sidebar-section">Missions</div>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/missions/index.php" class="nav-link <?= (strncmp($currentModule,'missions',8)===0)?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-map-location-dot"></i></span> Missions
            </a>
        </li>
        <?php endif; ?>

        <?php if (canDo('planning','view')): ?>
        <div class="sidebar-section">Planning</div>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/planning/index.php" class="nav-link <?= $currentModule==='planning'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-calendar-days"></i></span> Planning mensuel
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/planning/semaine.php" class="nav-link <?= $currentModule==='planning-semaine'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-calendar-week"></i></span> Vue hebdomadaire
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/planning/versions.php" class="nav-link <?= $currentModule==='planning-versions'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-clock-rotate-left"></i></span> Historique versions
            </a>
        </li>
        <?php endif; ?>

        <?php if (canDo('salaires','view')): ?>
        <div class="sidebar-section">Paie</div>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/salaires/index.php" class="nav-link <?= $currentModule==='salaires'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-euro-sign"></i></span> Calcul salaires
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/rapports/index.php" class="nav-link <?= $currentModule==='rapports'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-file-export"></i></span> Exports & Rapports
            </a>
        </li>
        <?php endif; ?>

        <?php if (canDo('devis','view') || canDo('clients','view')): ?>
        <div class="sidebar-section">Commercial</div>
        <?php if (canDo('clients','view')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/clients/index.php" class="nav-link <?= (strncmp($currentModule,'clients',7)===0)?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-building"></i></span> Clients
            </a>
        </li>
        <?php endif; ?>
        <?php if (canDo('devis','view')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/devis/index.php" class="nav-link <?= (strncmp($currentModule,'devis',5)===0)?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-file-invoice"></i></span> Devis
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (canDo('parametres','view')): ?>
        <div class="sidebar-section">Administration</div>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/parametres/index.php" class="nav-link <?= $currentModule==='parametres'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-sliders"></i></span> Paramètres
            </a>
        </li>
        <?php endif; ?>

        <?php if (canDo('utilisateurs','view')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/parametres/utilisateurs.php" class="nav-link <?= $currentModule==='utilisateurs'?'active':'' ?>">
                <span class="nav-icon"><i class="fa fa-users-gear"></i></span> Utilisateurs & Rôles
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-footer mt-auto">
        <div class="d-flex align-items-center gap-2">
            <div style="width:34px;height:34px;border-radius:50%;background:rgba(201,168,76,0.2);display:flex;align-items:center;justify-content:center;color:var(--ov-gold);font-weight:700;font-size:0.8rem;flex-shrink:0">
                <?= strtoupper(substr($user['prenom'],0,1) . substr($user['nom'],0,1)) ?>
            </div>
            <div style="overflow:hidden">
                <div class="sidebar-user text-truncate"><?= h($user['prenom'] . ' ' . $user['nom']) ?></div>
                <div class="sidebar-role"><?= h($user['role_nom']) ?></div>
            </div>
            <a href="<?= APP_URL ?>/logout.php" class="ms-auto btn-sm-icon view" title="Déconnexion" style="flex-shrink:0">
                <i class="fa fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</nav>

<!-- TOPBAR -->
<header id="topbar">
    <button class="btn-topbar d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <i class="fa fa-bars"></i>
    </button>
    <h1 class="topbar-title"><?= h($pageTitle ?? '') ?></h1>
    <div class="topbar-actions">
        <?php if (isset($topbarActions)) echo $topbarActions; ?>
        <span style="color:#9ca3af;font-size:0.78rem"><?= date('d/m/Y') ?></span>
    </div>
</header>

<!-- FLASH MESSAGES -->
<div class="flash-container" id="flashContainer">
<?php foreach (getFlash() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius:10px">
        <?= $f['message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>
</div>

<!-- MAIN -->
<main id="main-content">
