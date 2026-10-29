<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Str;

class PerformanceReportBuilder
{
    public static function save(array $data, int $userId): Media
    {
        $storagePrivate = __DIR__.'/../../storage/app/private';
        if (! is_dir($storagePrivate)) {
            $storagePrivate = getcwd().'/storage/app/private';
        }
        $base = $storagePrivate.'/media/'.$userId;
        if (! is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $filename = $data['filename'] ?? 'Laporan-Kinerja-'.date('Y-m').'.pdf';
        if (substr($filename, -4) !== '.pdf') {
            $filename .= '.pdf';
        }
        $path = 'media/'.$userId.'/'.$filename;
        $full = $base.'/'.$filename;

        $html = self::html($data);

        $generated = false;
        if (is_executable('/usr/bin/wkhtmltopdf')) {
            $tmp = $storagePrivate.'/tmp-'.Str::random(8).'.html';
            @file_put_contents($tmp, $html);
            @shell_exec('/usr/bin/wkhtmltopdf --page-size A4 --orientation Landscape --margin-top 8mm --margin-bottom 8mm --margin-left 8mm --margin-right 8mm '.$tmp.' '.$full.' 2>&1');
            @unlink($tmp);
            $generated = is_file($full) && filesize($full) > 1000;
        }
        if (! $generated && is_executable('/usr/bin/weasyprint')) {
            $tmp = $storagePrivate.'/tmp-'.Str::random(8).'.html';
            @file_put_contents($tmp, $html);
            @shell_exec('/usr/bin/weasyprint -f pdf -o '.$full.' '.$tmp.' 2>&1');
            @unlink($tmp);
            $generated = is_file($full) && filesize($full) > 1000;
        }
        if (! $generated && is_executable('/usr/bin/google-chrome') || is_executable('/usr/bin/chromium') || is_executable('/snap/bin/chromium')) {
            $chrome = is_executable('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : (is_executable('/usr/bin/chromium') ? '/usr/bin/chromium' : '/snap/bin/chromium');
            $tmp = $storagePrivate.'/tmp-'.Str::random(8).'.html';
            @file_put_contents($tmp, $html);
            @shell_exec($chrome.' --headless --disable-gpu --no-sandbox --no-pdf-header-footer --print-to-pdf='.$full.' file://'.$tmp.' 2>&1');
            @unlink($tmp);
            $generated = is_file($full) && filesize($full) > 1000;
        }

        if (! $generated) {
            // minimal valid pdf
            @file_put_contents($full, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 842 595]/Contents 4 0 R/Resources<</Font<</F1<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>>>>>endobj\n4 0 obj<</Length 80>>stream\nBT/F1 14 Tf 40 550 Td (LAPORAN KINERJA BULANAN) Tj ET\nBT/F1 10 Tf 40 520 Td (Buka file HTML untuk tampilan lengkap) Tj ET\nendstream endobj\nxref\n0 5\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\n0000000220 00000 n\ntrailer<</Size 5/Root 1 0 R>\nstartxref\n348\n%%EOF");
            $generated = true;
        }

        return Media::create([
            'user_id' => $userId,
            'role' => 'assistant',
            'kind' => 'file',
            'filename' => $filename,
            'mime' => 'application/pdf',
            'size' => filesize($full),
            'path' => $path,
            'preview' => null,
            'model' => null,
        ]);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function html(array $data): string
    {
        $title = self::esc($data['title'] ?? 'LAPORAN KINERJA BULANAN');
        $subtitle = self::esc($data['subtitle'] ?? '');
        $note = nl2br(self::esc($data['note'] ?? ''));
        $signature = $data['signature'] ?? [];
        $signatureName = self::esc((string) ($signature['name'] ?? ''));
        $signatureRole = self::esc((string) ($signature['jabatan'] ?? ''));
        $signatureDate = self::esc((string) ($signature['date'] ?? ''));
        $signatureRoleHtml = $signatureRole === '' ? '' : '<div class="signature-role">'.$signatureRole.'</div>';
        $signatureNip = self::esc((string) ($signature['nip'] ?? ''));
        $signatureNipHtml = $signatureNip === '' ? '' : '<div class="signature-nip">NIP. '.$signatureNip.'</div>';
        $leaderName = self::esc((string) ($signature['pimpinan_nama'] ?? ''));
        $leaderRole = self::esc((string) ($signature['pimpinan_jabatan'] ?? ''));
        $leaderNip = self::esc((string) ($signature['pimpinan_nip'] ?? ''));
        $leaderRoleHtml = $leaderRole === '' ? '' : '<div class="signature-role">'.$leaderRole.'</div>';
        $leaderNipHtml = $leaderNip === '' ? '' : '<div class="signature-nip">NIP. '.$leaderNip.'</div>';

        $summary = '';
        foreach ($data['summary'] ?? [] as $row) {
            $summary .= '<tr><td>'.self::esc((string)$row[0]).'</td><td>'.self::esc((string)$row[1]).'</td></tr>';
        }

        $matrix = '';
        foreach ($data['matrix'] ?? [] as $i => $row) {
            $matrix .= '<tr class="'.($i === 0 ? 'head' : '').'">';
            foreach ($row as $cell) {
                if (is_array($cell)) { $text = $cell['text'] ?? ''; } else { $text = (string)$cell; }
                $matrix .= '<td>'.nl2br(self::esc($text)).'</td>';
            }
            $matrix .= '</tr>';
        }

        $details = '';
        foreach ($data['details'] ?? [] as $i => $row) {
            $details .= '<tr class="'.($i === 0 ? 'head' : '').'">';
            foreach ($row as $cell) {
                if (is_array($cell)) { $text = $cell['text'] ?? ''; } else { $text = (string)$cell; }
                $details .= '<td>'.nl2br(self::esc($text)).'</td>';
            }
            $details .= '</tr>';
        }

        $attachmentGroups = '';
        foreach (array_values($data['attachment_groups'] ?? []) as $i => $group) {
            $files = '';
            foreach ($group['attachments'] ?? [] as $attachment) {
                $name = self::esc((string) ($attachment['filename'] ?? 'Lampiran'));
                $url = self::esc((string) ($attachment['url'] ?? '#'));
                if (! empty($attachment['image'])) {
                    $files .= '<figure class="attachment"><a href="'.$url.'"><img src="'.self::esc((string) $attachment['image']).'" alt="'.$name.'"></a><figcaption>'.$name.'</figcaption></figure>';
                } else {
                    $files .= '<div class="attachment-file"><a href="'.$url.'">'.$name.'</a></div>';
                }
            }
            if ($files === '') {
                continue;
            }
            $attachmentGroups .= '<div class="attachment-group"><div class="attachment-heading">Kegiatan '.($i + 1).' &mdash; '.self::esc((string) ($group['title'] ?? '-')).'</div><div class="attachment-meta">'.self::esc((string) ($group['meta'] ?? '')).'</div><div class="attachment-grid">'.$files.'</div></div>';
        }
        $attachmentSection = $attachmentGroups === '' ? '' : '<div class="attachments"><div class="card"><div class="card-title">E. Lampiran Berkas &amp; Dokumentasi</div><div class="attachment-groups">'.$attachmentGroups.'</div></div></div>';
        $noteSection = $note === '' ? '' : '<div class="note-card card"><div class="card-title">D. Catatan</div><div class="note-body">'.$note.'</div></div>';
        $pjBlock = $signatureName === '' ? '' : '<div class="signature"><div class="signature-date">'.$signatureDate.'</div><div class="signature-title">Penanggung Jawab,</div><div class="signature-space"></div><div class="signature-name">'.$signatureName.'</div>'.$signatureRoleHtml.$signatureNipHtml.'</div>';
        $leaderBlock = $leaderName === '' ? '' : '<div class="signature"><div class="signature-date">&nbsp;</div><div class="signature-title">Mengetahui,</div><div class="signature-space"></div><div class="signature-name">'.$leaderName.'</div>'.$leaderRoleHtml.$leaderNipHtml.'</div>';
        $signatureBlock = '';
        if ($leaderBlock !== '' && $pjBlock !== '') {
            $signatureBlock = '<table class="signatures"><tr><td>'.$leaderBlock.'</td><td>'.$pjBlock.'</td></tr></table>';
        } elseif ($leaderBlock !== '') {
            $signatureBlock = $leaderBlock;
        } else {
            $signatureBlock = $pjBlock;
        }

        return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>'.$title.'</title><style>
@page{size:A4 landscape;margin:10mm}
*{font-family:Helvetica,Arial,sans-serif}
body{margin:0;color:#111}
.wrap{padding:12px}
.header{text-align:center;margin-bottom:12px}
.header h1{margin:0;font-size:15px;text-transform:uppercase}
.header h2{margin:4px 0 0;font-size:11px;font-weight:normal;color:#444}
.card{border:1px solid #d1d5db;border-radius:8px;overflow:hidden;margin-bottom:12px}
.card-title{background:#f8fafc;padding:5px 10px;font-weight:bold;font-size:9px;text-transform:uppercase}
table{width:100%;border-collapse:collapse;font-size:7.5px}
table.summary td{padding:3px 9px;border-bottom:1px solid #f1f5f9}
table.summary tr:last-child td{border-bottom:none}
table.matrix td,table.details td{border:1px solid #d1d5db;padding:3px 5px;vertical-align:top}
table.matrix tr.head td,table.details tr.head td{background:#f8fafc;font-weight:bold;text-align:center}
.note-card{margin-bottom:12px}
.note-body{padding:10px 12px;font-size:9px;line-height:1.55;text-align:justify}
.signature{width:75mm;margin:0 0 10px auto;text-align:center;font-size:8.5px;break-inside:avoid}
table.signatures{width:100%;margin:0 0 10px;border-collapse:collapse;break-inside:avoid}
table.signatures td{width:50%;padding:0;vertical-align:top}
table.signatures .signature{margin:0 auto}
.signature-date{text-align:center;color:#000;margin-bottom:8px}
.signature-space{height:16mm}
.signature-name{font-weight:bold;text-decoration:underline}
.signature-role{color:#444;margin-top:1px}
.signature-nip{color:#444;margin-top:1px}
.attachments{page-break-before:always;break-before:page}
.attachment-groups{padding:4px 10px 10px}
.attachment-group{border-top:1.5px solid #111;margin-top:9px;padding-top:6px;break-inside:avoid}
.attachment-group:first-child{border-top:none;margin-top:0;padding-top:0}
.attachment-heading{font-size:9px;font-weight:bold;break-after:avoid}
.attachment-meta{font-size:7.5px;color:#444;margin:2px 0 6px;break-after:avoid}
.attachment-grid{display:flex;flex-wrap:wrap;gap:8px}
.attachment{width:31.5%;margin:0;border:1px solid #e5e7eb;border-radius:6px;padding:5px;box-sizing:border-box;break-inside:avoid}
.attachment img{display:block;width:100%;max-height:45mm;object-fit:contain}
.attachment figcaption{margin-top:4px;font-size:7.5px;word-break:break-all}
.attachment figcaption,.attachment-file a{color:#111}
.attachment-file{width:100%;padding:3px 2px;font-size:8px}
.attachment-file a{color:#111;text-decoration:underline}
</style></head><body><div class="wrap">
<div class="header"><h1>'.$title.'</h1><h2>'.$subtitle.'</h2></div>
<div class="card"><div class="card-title">A. Ringkasan</div><table class="summary">'.$summary.'</table></div>
<div class="card"><div class="card-title">B. Matriks Kinerja Bulanan</div><table class="matrix">'.$matrix.'</table></div>
<div class="card"><div class="card-title">C. Rincian Kegiatan</div><table class="details">'.$details.'</table></div>
'.$noteSection.'
'.$signatureBlock.'
'.$attachmentSection.'
</div></body></html>';
    }
}
