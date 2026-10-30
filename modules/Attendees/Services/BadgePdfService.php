<?php

declare(strict_types=1);

namespace Modules\Attendees\Services;

use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\PdfFactory;

/**
 * Printable badge sheets and the entrance poster. Output is streamed to the
 * admin, never stored on the server (it contains IDs).
 */
class BadgePdfService
{
    /** Badge presets in millimetres: label => [width, height, columns, rows]. */
    public const PRESETS = [
        'a6'      => ['A6 (4 per A4)', 105, 148, 2, 2],
        'cr80'    => ['ID card 86×54 (10 per A4)', 86, 54, 2, 5],
        'lanyard' => ['Lanyard 90×120 (4 per A4)', 90, 120, 2, 2],
        'small'   => ['Small 70×50 (15 per A4)', 70, 50, 3, 5],
    ];

    /**
     * @param list<array<string, mixed>> $attendees rows with name, badge_id, user_type
     */
    public function badges(array $slam, array $attendees, string $preset = 'a6', bool $cutMarks = true): string
    {
        [, $w, $h, $cols, $rows] = self::PRESETS[$preset] ?? self::PRESETS['a6'];
        $qr     = service('qr');
        $perPage = $cols * $rows;
        $marginX = max(0, (210 - $cols * $w) / 2);
        $marginY = max(0, (297 - $rows * $h) / 2);
        $short   = preg_replace('#^https?://#', '', $qr->checkinBase());
        $qrSize  = min($w, $h) * ($h > $w ? 0.55 : 0.75);
        $big     = $h > 80;

        $css = "body{font-family:dejavusans;} .sheet{border-collapse:collapse;} .b{width:{$w}mm;height:{$h}mm;padding:3mm;vertical-align:top;text-align:center;"
            . ($cutMarks ? 'border:0.2mm dashed #999;' : '') . "} .slam{font-size:" . ($big ? '11pt' : '7pt') . ";font-weight:bold;color:#000;}"
            . " .name{font-size:" . ($big ? '16pt' : '10pt') . ";font-weight:bold;margin-top:2mm;} .type{font-size:" . ($big ? '10pt' : '7pt') . ";letter-spacing:1pt;}"
            . " .id{font-size:" . ($big ? '24pt' : '13pt') . ";font-weight:bold;letter-spacing:2pt;font-family:dejavusansmono;} .url{font-size:" . ($big ? '8pt' : '6pt') . ";}";

        $pdf = PdfFactory::make(['margin_left' => $marginX, 'margin_right' => 0, 'margin_top' => $marginY, 'margin_bottom' => 0]);
        $pdf->SetTitle('Badges — ' . $slam['name']);
        $pdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

        foreach (array_chunk($attendees, $perPage) as $pi => $page) {
            if ($pi > 0) {
                $pdf->AddPage();
            }
            $html = '<table class="sheet">';
            foreach (array_chunk($page, $cols) as $row) {
                $html .= '<tr>';
                foreach ($row as $a) {
                    $svg   = $qr->svg($qr->badgeUrl($a['badge_id']));
                    $html .= '<td class="b"><div class="slam">' . esc($slam['name']) . '</div>'
                        . '<div class="name">' . esc($a['name']) . '</div>'
                        . '<div class="type">' . ($a['user_type'] === 'judge' ? 'JUDGE' : 'USER') . '</div>'
                        . '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="width:' . $qrSize . 'mm;height:' . $qrSize . 'mm" />'
                        . '<div class="id">' . esc($a['badge_id']) . '</div>'
                        . '<div class="url">' . esc($short) . '/checkin</div></td>';
                }
                $html .= str_repeat('<td class="b"></td>', $cols - count($row)) . '</tr>';
            }
            $pdf->WriteHTML($html . '</table>', \Mpdf\HTMLParserMode::HTML_BODY);
        }

        return $pdf->Output('', 'S');
    }

    /** Generic entrance poster: QR to the type-the-ID page, URL in large type. */
    public function entrancePoster(?array $slam, string $format = 'A4', ?string $publicUrl = null): string
    {
        $qr  = service('qr');
        $url = $qr->typeIdUrl();
        $pdf = PdfFactory::make(['format' => $format === 'A3' ? 'A3' : 'A4']);
        $pdf->SetTitle('Check-in poster');
        $size = $format === 'A3' ? 200 : 140;
        $html = '<div style="text-align:center;font-family:dejavusans">'
            . ($slam !== null ? '<div style="font-size:26pt;font-weight:bold">' . esc($slam['name']) . '</div>'
                . '<div style="font-size:14pt">' . esc(Clock::local($slam['opens_at'], 'l, d F Y')) . ' · ' . esc($slam['venue_name']) . '</div>' : '')
            . '<div style="font-size:30pt;font-weight:bold;margin-top:12mm">Check in here</div>'
            . '<div style="font-size:14pt;margin:4mm 0 8mm">Scan with your phone camera, then type the ID printed on your badge</div>'
            . '<img src="data:image/svg+xml;base64,' . base64_encode($qr->svg($url)) . '" style="width:' . $size . 'mm;height:' . $size . 'mm" />'
            . '<div style="font-size:26pt;font-weight:bold;margin-top:8mm">' . esc(preg_replace('#^https?://#', '', $url)) . '</div>'
            . ($publicUrl !== null ? '<div style="font-size:12pt;margin-top:10mm">Schedule: ' . esc(preg_replace('#^https?://#', '', $publicUrl)) . '</div>' : '')
            . '</div>';
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }
}
