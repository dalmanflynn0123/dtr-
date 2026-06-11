<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendDTREmail(array $params): array {

    $db = getDB();

    $deviceId  = $params['device_id'] ?? '';
    $dateFrom  = $params['date_from'] ?? '';
    $dateTo    = $params['date_to']   ?? '';
    $recipient = $params['recipient'] ?? '';
    $subject   = $params['subject']   ?? '';
    $body      = $params['body']      ?? '';

    if (!$deviceId || !$dateFrom || !$dateTo) {
        return ['success' => false, 'msg' => 'Missing required parameters'];
    }

    // Employee
    $empStmt = $db->prepare("SELECT * FROM employees WHERE device_id = ?");
    $empStmt->execute([$deviceId]);
    $emp = $empStmt->fetch();
    if (!$emp) return ['success' => false, 'msg' => 'Employee not found'];

    $toEmail = $recipient ?: ($emp['email'] ?? '');
    if (!$toEmail) return ['success' => false, 'msg' => 'No email found'];

    // Attendance
    $attStmt = $db->prepare("
        SELECT * FROM attendance
        WHERE device_id = ? AND work_date BETWEEN ? AND ?
        ORDER BY work_date ASC
    ");
    $attStmt->execute([$deviceId, $dateFrom, $dateTo]);
    $records = $attStmt->fetchAll();

    // Company
    $company = null;
    if (!empty($emp['company'])) {
        $coStmt = $db->prepare("SELECT * FROM companies WHERE name = ?");
        $coStmt->execute([$emp['company']]);
        $company = $coStmt->fetch();
    }

    // Settings
    $settings = [];
    foreach ($db->query("SELECT setting_key, setting_value FROM settings") as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }

    $gmailUser = $settings['gmail_user'] ?? '';
    $gmailPass = $settings['gmail_pass'] ?? '';
    if (!$gmailUser || !$gmailPass) return ['success' => false, 'msg' => 'Gmail not configured'];

    // Build HTML and convert to PDF
    $dtrHtml = buildDTRHtml($emp, $records, $company, $settings, $dateFrom, $dateTo);
    $pdfPath = convertToPdf($dtrHtml, $emp['device_id']);

    // Replace placeholders
    $periodStr = formatDatePhp($dateFrom) . ' to ' . formatDatePhp($dateTo);
    $subject   = str_replace(['{{name}}','{{period}}'], [$emp['full_name'], $periodStr], $subject);
    $body      = str_replace(['{{name}}','{{period}}'], [$emp['full_name'], $periodStr], $body);

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $gmailUser;
        $mail->Password   = $gmailPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($gmailUser, $settings['email_sender_name'] ?? 'DTR System');
        $mail->addAddress($toEmail, $emp['full_name']);
        $mail->Subject = $subject;
        $mail->isHTML(false);
        $mail->Body    = $body;

        $safeName = preg_replace('/[^a-z0-9]/i', '_', $emp['full_name']);
        if ($pdfPath && file_exists($pdfPath)) {
            $mail->addAttachment($pdfPath, "DTR_{$safeName}.pdf");
        } else {
            $htmlPath = sys_get_temp_dir() . "/dtr_{$emp['device_id']}.html";
            file_put_contents($htmlPath, $dtrHtml);
            $mail->addAttachment($htmlPath, "DTR_{$safeName}.html");
        }

        $mail->send();
        if ($pdfPath && file_exists($pdfPath)) @unlink($pdfPath);
        return ['success' => true, 'msg' => "Sent to $toEmail"];

    } catch (Exception $e) {
        return ['success' => false, 'msg' => $mail->ErrorInfo];
    }
}

/* =========================
   PDF CONVERTER
========================= */
function convertToPdf(string $html, string $deviceId): ?string
{
    $tmpHtml = sys_get_temp_dir() . "/dtr_src_{$deviceId}.html";
    $tmpJpg  = sys_get_temp_dir() . "/dtr_{$deviceId}.jpg";
    $tmpPdf  = sys_get_temp_dir() . "/dtr_{$deviceId}.pdf";

    file_put_contents($tmpHtml, $html);

    // Step 1 — Render HTML to JPEG image
    $wkImg = 'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltoimage.exe';
    $cmd   = escapeshellarg($wkImg)
           . ' --quality 95 --width 1240 --format jpg '
           . escapeshellarg($tmpHtml) . ' '
           . escapeshellarg($tmpJpg)
           . ' 2>NUL';
    exec($cmd, $out, $ret);
    @unlink($tmpHtml);

    if ($ret !== 0 || !file_exists($tmpJpg)) {
        // Fallback to normal Dompdf PDF if wkhtmltoimage not installed
        error_log("wkhtmltoimage failed, falling back to Dompdf");
        file_put_contents($tmpHtml, $html);
        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            file_put_contents($tmpPdf, $dompdf->output());
            @unlink($tmpHtml);
            return file_exists($tmpPdf) ? $tmpPdf : null;
        } catch (\Throwable $e) {
            error_log("DOMPDF ERROR: " . $e->getMessage());
            return null;
        }
    }

    // Step 2 — Embed JPEG into PDF using Dompdf
    try {
        $b64     = base64_encode(file_get_contents($tmpJpg));
        $imgHtml = "<!DOCTYPE html><html><head><style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { background:#fff; }
            img { width:100%; display:block; }
        </style></head><body>
            <img src=\"data:image/jpeg;base64,{$b64}\">
        </body></html>";

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf  = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($imgHtml);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($tmpPdf, $dompdf->output());

        @unlink($tmpJpg);
        return file_exists($tmpPdf) ? $tmpPdf : null;

    } catch (\Throwable $e) {
        error_log("PDF from image error: " . $e->getMessage());
        @unlink($tmpJpg);
        return null;
    }
}

/* =========================
   HTML BUILDER — matches printout layout
========================= */
function buildDTRHtml($emp, $records, $company, $settings, $df, $dt): string {

    $coName   = $company['name']     ?? ($settings['company_name']    ?? '');
    $coAddr   = $company['address']  ?? ($settings['company_address'] ?? '');
    $coDept   = $settings['department'] ?? '';
    $approver = $settings['approver']   ?? '';
    $logoFile = $company['logo_file']   ?? '';

    // Logo as base64 so Dompdf can embed it
    $logoHtml = '';
    if ($logoFile) {
        $logoPath = __DIR__ . '/' . $logoFile;
        if (file_exists($logoPath)) {
            $b64      = base64_encode(file_get_contents($logoPath));
            $ext      = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime     = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $logoHtml = "<img src=\"data:{$mime};base64,{$b64}\" style=\"height:100px;max-width:240px;object-fit:contain\">";
        }
    }

    
    $watermarkHtml = '';
    if ($logoFile) {
        $logoPath = __DIR__ . '/' . $logoFile;
        if (file_exists($logoPath)) {
            $b64  = base64_encode(file_get_contents($logoPath));
            $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $watermarkHtml = "<div style=\"position:absolute;top:0;left:0;width:100%;height:100%;text-align:center;z-index:0;\">
                <img src=\"data:{$mime};base64,{$b64}\" style=\"width:500px;height:500px;object-fit:contain;opacity:0;margin-top:120px;\">
            </div>";
        }
    }

    
    $recMap = [];
    foreach ($records as $r) $recMap[$r['work_date']] = $r;

    $start       = new DateTime($df);
    $end         = new DateTime($dt);
    $rows        = '';
    $totalMins   = 0;
    $daysPresent = 0;
    $rowIdx      = 0;

    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $dateStr = $d->format('Y-m-d');
        $rec     = $recMap[$dateStr] ?? null;
        $in      = $rec['time_in']  ?? '';
        $out     = $rec['time_out'] ?? '';
        $hrs     = '&mdash;';
        $bg      = $rowIdx % 2 === 0 ? '#ffffff' : '#f0f6ff';

        $inColor  = $in  ? '#15803d' : '#94a3b8';
        $outColor = $out ? '#dc2626' : '#94a3b8';
        $inFw     = $in  ? '600' : '400';
        $outFw    = $out ? '600' : '400';

        if ($in && $out) {
            [$ih,$im] = array_map('intval', explode(':', $in));
            [$oh,$om] = array_map('intval', explode(':', $out));
            $diff = ($oh*60+$om) - ($ih*60+$im);
            if ($diff < 0) $diff += 1440;
            $totalMins += $diff;
            $hrs = round($diff / 60, 1) . ' hrs';
        }
        if ($in) $daysPresent++;

        $inDisp  = $in  ? fmtTimePhp($in)  : '&mdash;';
        $outDisp = $out ? fmtTimePhp($out) : '&mdash;';

        $rows .= "
        <tr style=\"background:{$bg}\">
            <td style=\"text-align:left;padding:4px 7px;border-bottom:1px solid #dbeafe;border-right:1px solid #e0eaff;color:#1e293b\">" . formatDatePhp($dateStr) . "</td>
            <td style=\"text-align:center;padding:4px 7px;border-bottom:1px solid #dbeafe;border-right:1px solid #e0eaff;color:#1e293b\">{$d->format('D')}</td>
            <td style=\"text-align:center;padding:4px 7px;border-bottom:1px solid #dbeafe;border-right:1px solid #e0eaff;color:{$inColor};font-weight:{$inFw}\">{$inDisp}</td>
            <td style=\"text-align:center;padding:4px 7px;border-bottom:1px solid #dbeafe;border-right:1px solid #e0eaff;color:{$outColor};font-weight:{$outFw}\">{$outDisp}</td>
            <td style=\"text-align:center;padding:4px 7px;border-bottom:1px solid #dbeafe;border-right:1px solid #e0eaff;font-weight:600;color:#1e293b\">{$hrs}</td>
            <td style=\"text-align:center;padding:4px 7px;border-bottom:1px solid #dbeafe;color:#1e293b\">&nbsp;</td>
        </tr>";
        $rowIdx++;
    }

    $totalDisp = $totalMins > 0
        ? round($totalMins / 60, 1) . ' hrs'
        : '&mdash;';

    // Employee info rows
    $empId   = htmlspecialchars($emp['employee_id'] ?? '');
    $empName = htmlspecialchars($emp['full_name']   ?? '');
    $empPos  = htmlspecialchars($emp['position']    ?? '');
    $empDept = htmlspecialchars($emp['department']  ?? '');

    $periodStr = formatDatePhp($df) . ' &mdash; ' . formatDatePhp($dt);

    return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family: Arial, sans-serif; font-size:15px; color:#000; background:#fff; }
  .wrap { max-width:900px; margin:0 auto; padding:20px 24px; position:relative; }
  .dtr-header { display:table; width:100%; margin-bottom:0; }
  .dtr-header-logo { display:table-cell; width:130px; vertical-align:middle; }
  .dtr-header-text { display:table-cell; vertical-align:middle; text-align:center; padding:0 10px; padding-right:130px; }
  .co-name { font-size:18px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#0c1428; line-height:1.25; }
  .co-addr { font-size:10px; color:#444; margin-top:4px; }
  .co-dept { font-size:11px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:1px; margin-top:4px; }
  .divider-thick { border:none; border-top:2.5px solid #1e3a8a; margin:8px 0 4px; }
  .divider-thin  { border:none; border-top:1px solid #93c5fd; margin:3px 0 8px; }
  .dtr-label { text-align:center; font-size:11px; font-weight:700; letter-spacing:3px; text-transform:uppercase; color:#1e40af; margin:4px 0 2px; }
  .period { text-align:center; font-size:9.5px; color:#555; margin-bottom:10px; }
  .period strong { color:#1d4ed8; font-weight:700; }
  .emp-bar { border:1px solid #bfdbfe; border-radius:4px; background:linear-gradient(90deg,#eff6ff,#dbeafe); padding:7px 10px; margin-bottom:10px; font-size:12px; }
  .emp-bar table { width:100%; border-collapse:collapse; }
  .emp-bar td { padding:2px 6px; color:#1e293b; }
  .emp-bar .lbl { color:#555; }
  .emp-bar strong { color:#1e3a8a; }
  .tbl-wrap { border:1.5px solid #93c5fd; border-radius:4px; overflow:hidden; margin-bottom:14px; }
  .dtr-table { width:100%; border-collapse:collapse; font-size:12px; }
  .dtr-table thead th { background:#1e40af; color:#fff; padding:6px 8px; font-weight:700; text-transform:uppercase; font-size:8.5px; letter-spacing:0.5px; text-align:center; border-right:1px solid rgba(255,255,255,0.2); }
  .dtr-table thead th:last-child { border-right:none; }
  .dtr-table tfoot td { background:#1e3a8a; color:#fff; font-weight:700; padding:5px 8px; text-align:center; font-size:8.5px; text-transform:uppercase; }
  .sigs { margin-top:24px; }
    .sigs table { width:100%; border-collapse:collapse; }
  .sigs td { text-align:center; padding-top:36px; vertical-align:top; width:50%; padding-left:10px; padding-right:10px; }
  .sig-line { border-top:1.5px solid #1e40af; padding-top:4px; margin-bottom:2px; margin-left:10px; margin-right:10px; }
  .sig-label { font-size:8.5px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:1px; }
  .sig-sub { font-size:8px; color:#888; margin-top:1px; }
  .footnote { text-align:center; font-size:7.5px; color:#888; margin-top:10px; border-top:1px solid #e0eaff; padding-top:5px; font-style:italic; }
</style>
</head>
<body>
<div class='wrap'>

  " . ($watermarkHtml) . "

  <!-- HEADER -->
  <div class='dtr-header'>
    <div class='dtr-header-logo'>{$logoHtml}</div>
    <div class='dtr-header-text'>
      <div class='co-name'>" . htmlspecialchars($coName) . "</div>
      " . ($coAddr ? "<div class='co-addr'>" . htmlspecialchars($coAddr) . "</div>" : '') . "
      " . ($coDept ? "<div class='co-dept'>" . htmlspecialchars($coDept) . "</div>" : '') . "
    </div>
  </div>

  <hr class='divider-thick'>
  <div class='dtr-label'>Daily Time Record</div>
  <hr class='divider-thin'>

  <div class='period'>Attendance Period: <strong>{$periodStr}</strong></div>

  <!-- EMPLOYEE INFO BAR -->
  <div class='emp-bar'>
    <table>
      <tr>
        <td><span class='lbl'>Employee ID: </span><strong>{$empId}</strong></td>
        <td><span class='lbl'>Name: </span><strong>{$empName}</strong></td>
        <td><span class='lbl'>Days Present: </span><strong>{$daysPresent}</strong></td>
      </tr>
      " . ($empPos || $empDept ? "<tr>
        <td>" . ($empPos  ? "<span class='lbl'>Position: </span><strong>{$empPos}</strong>"   : '') . "</td>
        <td>" . ($empDept ? "<span class='lbl'>Department: </span><strong>{$empDept}</strong>" : '') . "</td>
        <td><span class='lbl'>Total Hours: </span><strong>{$totalDisp}</strong></td>
      </tr>" : '') . "
    </table>
  </div>

  <!-- ATTENDANCE TABLE -->
  <div class='tbl-wrap'>
    <table class='dtr-table'>
      <thead>
        <tr>
          <th style='text-align:left'>Date</th>
          <th>Day</th>
          <th>Time In</th>
          <th>Time Out</th>
          <th>Total Hours</th>
          <th style='text-align:left'>Remarks</th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
      <tfoot>
        <tr>
          <td colspan='2'>Total</td>
          <td></td><td></td>
          <td>{$totalDisp}</td>
          <td>" . count($records) . " days w/ punch</td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- SIGNATURES -->
  <div class='sigs'>
    <table>
      <tr>
        <td style='width:50%'>
          <div class='sig-line'></div>
          <div class='sig-label'>Employee Signature</div>
          <div class='sig-sub'>{$empName}</div>
        </td>
        <td style='width:50%'>
          <div class='sig-line'></div>
          <div class='sig-label'>Approved By</div>
          " . ($approver ? "<div class='sig-sub'>" . htmlspecialchars($approver) . "</div>" : '') . "
        </td>
      </tr>
    </table>
  </div>

  <div class='footnote'>The details of this DTR were extracted from the biometric device and converted to this document.</div>

</div>
</body>
</html>";
}


function formatDatePhp(string $d): string {
    try { return (new DateTime($d))->format('M d, Y'); }
    catch(Exception $e) { return $d; }
}

function fmtTimePhp(string $t): string {
    if (!$t) return '&mdash;';
    return substr($t, 0, 5);
}