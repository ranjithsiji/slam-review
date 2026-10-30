<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\PdfFactory;

/** The printable A4 result sheet: the top presenters' names (no positions), optionally with their marks out of 100. */
class ResultsPdfService
{
    /** @param list<array<string, mixed>> $top */
    public function sheet(array $slam, array $top, bool $withMarks): string
    {
        $h   = static fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $n   = count($top);
        $rows = '';
        foreach ($top as $t) {
            $rows .= '<tr><td class="name">' . $h($t['presenter_name']) . ($t['designation'] ? '<div class="inst">' . $h($t['designation']) . '</div>' : '') . '</td>'
                . '<td class="topic">' . $h($t['topic']) . '</td>'
                . ($withMarks ? '<td class="mark">' . number_format($t['final_mark'] * 10, 2) . '</td>' : '') . '</tr>';
        }
        $kind  = $slam['type'] === 'final' ? 'Final' : 'Regional slam';
        $date  = Clock::local($slam['opens_at'], 'l, d F Y');
        $html  = '<style>
            body { font-family: dejavusans; color: #111; }
            .band { background: #ff9a00; color: #1b1200; padding: 6mm 8mm; }
            .band h1 { font-size: 22pt; margin: 0; } .band .sub { font-size: 11pt; margin-top: 1mm; }
            h2 { font-size: 17pt; text-align: center; margin: 12mm 0 2mm; } .lead { text-align: center; font-size: 11pt; color: #444; margin-bottom: 8mm; }
            table.r { width: 100%; border-collapse: collapse; } table.r td, table.r th { padding: 5mm 4mm; border-bottom: 0.3mm solid #bbb; vertical-align: top; }
            table.r th { background: #f3f3f3; font-size: 10pt; text-align: left; letter-spacing: 0.5pt; }
            .name { font-size: 15pt; font-weight: bold; width: 42%; } .inst { font-size: 10pt; font-weight: normal; color: #555; margin-top: 1mm; }
            .topic { font-size: 12pt; } .mark { font-size: 14pt; font-weight: bold; text-align: right; width: 20%; }
            .foot { margin-top: 14mm; font-size: 10pt; color: #555; text-align: center; }
            .sign { margin-top: 22mm; width: 100%; } .sign td { width: 33%; text-align: center; font-size: 10pt; padding-top: 2mm; border-top: 0.3mm solid #666; }
        </style>'
            . '<div class="band"><h1>Kerala Science Slam 2026</h1><div class="sub">' . $h($slam['name']) . ' · ' . $h($date) . ' · ' . $h($slam['venue_name']) . ($slam['city'] ? ', ' . $h($slam['city']) : '') . '</div></div>'
            . '<h2>Result: top ' . $n . ' presenters</h2>'
            . '<div class="lead">' . $h($kind) . ' · names in alphabetical order' . ($withMarks ? ', with marks out of 100' : '') . '</div>'
            . '<table class="r"><tr><th>PRESENTER</th><th>TALK</th>' . ($withMarks ? '<th style="text-align:right">MARKS /100</th>' : '') . '</tr>' . $rows . '</table>'
            . '<table class="sign"><tr><td>Coordinator</td><td>Chief judge</td><td>Organiser</td></tr></table>'
            . '<div class="foot">Printed ' . $h(Clock::local(Clock::db(), 'd M Y, H:i')) . ' · Science Slam results</div>';

        $pdf = PdfFactory::make(['margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12]);
        $pdf->SetTitle('Result sheet — ' . $slam['name']);
        $pdf->WriteHTML($html);

        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
