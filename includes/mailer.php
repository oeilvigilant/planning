<?php
if (!defined('APP_ROOT')) exit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

function sendMail(string $to, string $toName, string $subject, string $htmlBody): array {
    require_once APP_ROOT . '/vendor/autoload.php';
    $p    = getAllParams();
    $mail = new PHPMailer(true);
    try {
        if (!empty($p['smtp_host'])) {
            $mail->isSMTP();
            $mail->Host       = $p['smtp_host'];
            $mail->Port       = (int)($p['smtp_port'] ?? 587);
            $mail->SMTPAuth   = !empty($p['smtp_user']);
            $mail->Username   = $p['smtp_user'] ?? '';
            $mail->Password   = $p['smtp_pass'] ?? '';
            $mail->SMTPSecure = (int)$mail->Port === 465
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
        }
        $from = $p['smtp_from'] ?? 'noreply@oeilvigilant.fr';
        $nom  = $p['entreprise_nom'] ?? 'Oeil Vigilant';
        $mail->setFrom($from, $nom);
        $mail->addAddress($to, $toName);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
        $mail->send();
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function buildSignatureEmailHtml(array $a, array $p, string $link, string $expiry): string {
    $nom       = htmlspecialchars(($a['civilite'] ?? 'M./Mme') . ' ' . ($a['prenom'] ?? '') . ' ' . strtoupper($a['nom'] ?? ''));
    $entreprise = htmlspecialchars($p['entreprise_nom'] ?? 'Oeil Vigilant');
    $contact   = htmlspecialchars($p['smtp_from'] ?? $p['entreprise_email'] ?? 'contact@oeilvigilant.fr');
    $adresse   = htmlspecialchars($p['entreprise_adresse'] ?? '') . ', ' . htmlspecialchars($p['entreprise_cp'] ?? '') . ' ' . htmlspecialchars($p['entreprise_ville'] ?? '');
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:24px">
<div style="max-width:580px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.10)">
  <div style="background:#1a2332;padding:24px;text-align:center">
    <div style="color:#c9a84c;font-size:22px;font-weight:bold;letter-spacing:2px">' . $entreprise . '</div>
    <div style="color:#9ca3af;font-size:12px;margin-top:4px">Signature de contrat de travail</div>
  </div>
  <div style="padding:32px">
    <p style="margin:0 0 16px">Bonjour <strong>' . $nom . '</strong>,</p>
    <p style="margin:0 0 16px;color:#374151">La société <strong>' . $entreprise . '</strong> vous invite à signer votre contrat de travail en ligne, de façon sécurisée.</p>
    <p style="margin:0 0 8px;color:#374151">Cliquez sur le bouton ci-dessous pour consulter votre contrat et apposer votre signature :</p>
    <div style="text-align:center;margin:28px 0">
      <a href="' . $link . '" style="background:#c9a84c;color:#1a2332;padding:14px 32px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;display:inline-block">
        ✍&nbsp; Signer mon contrat
      </a>
    </div>
    <div style="background:#fef9e7;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;font-size:13px;color:#92400e;margin-bottom:20px">
      ⏰ Ce lien expire le <strong>' . $expiry . '</strong>. Passé ce délai, contactez votre responsable pour en obtenir un nouveau.
    </div>
    <p style="font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:16px;margin:0">
      ' . $entreprise . ' &mdash; ' . $adresse . '<br>
      Questions : <a href="mailto:' . $contact . '" style="color:#c9a84c">' . $contact . '</a>
    </p>
  </div>
</div>
</body></html>';
}
