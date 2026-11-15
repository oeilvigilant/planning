<?php
/** Téléchargement du rapport client en PDF. */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/pdf.php';
$p = ptPortail();
$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-01');
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : date('Y-m-d');
if ($au < $du) $au = $du;
ptPdfEnvoyer(ptRapportClientPdf($p, $du, $au), 'rapport_' . $du . '_' . $au . '.pdf');
