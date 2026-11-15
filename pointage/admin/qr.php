<?php
/** Planche d'étiquettes QR à imprimer (A4) pour les points de ronde d'un site. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$mid = (int)($_GET['mission'] ?? 0);
$site = ptSite($mid);
if (!$site) { http_response_code(404); exit('Site introuvable.'); }
$points = ptPointsSite($mid);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Étiquettes QR — <?= h($site['nom']) ?></title>
<style>
@page { size: A4; margin: 10mm; }
body { font-family: Arial, sans-serif; margin: 0; color: #111; }
.bar { padding: 12px; background: #0d1520; color: #fff; display: flex; gap: 12px; align-items: center; }
.bar button { padding: 8px 16px; font-weight: bold; background: #c9a84c; border: 0; border-radius: 6px; cursor: pointer; }
.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; padding: 6mm; }
.lab { border: 1.5px dashed #999; border-radius: 4mm; padding: 4mm; text-align: center; page-break-inside: avoid; }
.lab .m { font-size: 9pt; color: #a8883c; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
.lab .n { font-size: 13pt; font-weight: bold; margin: 2mm 0; }
.lab .q { display: flex; justify-content: center; margin: 2mm 0; }
.lab .c { font-family: monospace; font-size: 9pt; letter-spacing: 1px; }
.lab .i { font-size: 7.5pt; color: #555; margin-top: 1mm; }
@media print { .bar { display: none; } }
</style>
</head>
<body>
<div class="bar">
    <button onclick="window.print()">Imprimer</button>
    <span><?= count($points) ?> étiquette(s) — <?= h($site['nom']) ?>. Conseil : papier autocollant ou plastification.</span>
</div>
<div class="grid">
<?php foreach ($points as $pt): ?>
    <div class="lab">
        <div class="m"><?= h(ptMarque()) ?> · Point de ronde</div>
        <div class="n"><?= h($pt['nom']) ?></div>
        <div class="q" data-qr="<?= h(ptUrlPoint($pt['code'])) ?>"></div>
        <div class="c"><?= h(chunk_split($pt['code'], 4, ' ')) ?></div>
        <div class="i"><?= h($site['nom']) ?> — scannez avec l'application de pointage</div>
    </div>
<?php endforeach; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, { text: el.getAttribute('data-qr'), width: 150, height: 150, correctLevel: QRCode.CorrectLevel.M });
});
</script>
</body>
</html>
