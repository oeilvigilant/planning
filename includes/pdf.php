<?php
// Génère et envoie un PDF via DomPDF (ou fallback print)

function renderPdf(string $html, string $filename, string $orientation = 'portrait'): void {
    if (file_exists(DOMPDF_AUTOLOAD)) {
        require_once DOMPDF_AUTOLOAD;
        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');
        $options->set('chroot', APP_ROOT);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }
    // Fallback navigateur
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    echo '<script>window.onload=()=>{window.print();}</script>';
    exit;
}

function pdfBaseStyle(): string {
    return '
    <style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 9pt; color: #1a2332; margin: 0; padding: 0; }
    .page { padding: 14mm 12mm; }
    .pdf-header { display: flex; justify-content: space-between; align-items: flex-start;
                  border-bottom: 3px solid #c9a84c; padding-bottom: 10px; margin-bottom: 14px; }
    .pdf-header h1 { font-size: 13pt; margin: 0 0 3px; color: #1a2332; }
    .pdf-header p  { font-size: 7.5pt; color: #666; margin: 2px 0; }
    .pdf-title { background: #1a2332; color: white; padding: 7px 12px; text-align: center;
                 font-size: 10pt; font-weight: bold; letter-spacing: 2px; margin-bottom: 14px; border-radius: 3px; }
    .section-title { font-size: 7.5pt; font-weight: bold; color: #c9a84c; text-transform: uppercase;
                     letter-spacing: 1px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; margin: 12px 0 7px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 5px; }
    .field { margin-bottom: 4px; }
    .field-label { font-size: 6.5pt; color: #999; text-transform: uppercase; letter-spacing: 0.3px; }
    .field-value { font-size: 8.5pt; font-weight: 600; color: #1a2332; border-bottom: 1px solid #f0f2f5; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 8pt; }
    th { background: #1a2332; color: white; padding: 5px 4px; text-align: center; font-size: 7.5pt; }
    th:first-child { text-align: left; padding-left: 6px; }
    td { padding: 4px; border-bottom: 1px solid #f0f2f5; text-align: center; }
    td:first-child { text-align: left; padding-left: 6px; }
    tfoot td { background: #f8f9fa; font-weight: 700; border-top: 2px solid #e5e7eb; }
    .pdf-footer { margin-top: 15px; border-top: 1px solid #e5e7eb; padding-top: 6px;
                  font-size: 6.5pt; color: #999; display: flex; justify-content: space-between; }
    .gold { color: #c9a84c; }
    .green { color: #16a34a; font-weight: 700; }
    .badge { background: #f0f2f5; padding: 2px 6px; border-radius: 10px; font-size: 7pt; font-weight: 600; }
    </style>';
}
