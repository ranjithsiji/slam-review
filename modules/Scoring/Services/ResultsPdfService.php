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
        return $slam['type'] === 'final' ? $this->finalSheet($slam, $top, service('results')->ranking((int) $slam['id'])) : $this->regionalSheet($slam, $top, $withMarks);
    }

    private function place(int $rank): string
    {
        return match ($rank) {
            1 => 'WINNER', 2 => 'SECOND PLACE', 3 => 'THIRD PLACE',
            default => $rank . 'TH PLACE',
        };
    }

    /**
     * The final: clear positions. A podium for the top three (ties share a position) and the full ranking with every
     * group's average, all marks out of 100.
     *
     * @param list<array<string, mixed>> $top
     * @param list<array<string, mixed>> $all
     */
    private function finalSheet(array $slam, array $top, array $all): string
    {
        $h    = static fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m    = static fn (?float $v) => $v === null ? '–' : number_format($v * 10, 2);
        $date = Clock::local($slam['opens_at'], 'l, d F Y');
        $podium = '';
        foreach ($top as $t) {
            $podium .= '<table class="pod"><tr><td class="place' . ($t['rank'] === 1 ? ' gold' : '') . '">' . $this->place((int) $t['rank']) . '</td>'
                . '<td><div class="pname">' . $h($t['presenter_name']) . '</div>' . ($t['designation'] ? '<div class="inst">' . $h($t['designation']) . '</div>' : '') . '<div class="ptopic">' . $h($t['topic']) . '</div></td>'
                . '<td class="pmark">' . $m($t['final_mark']) . '<div class="of">out of 100</div></td></tr></table>';
        }
        $rows = '';
        foreach ($all as $r) {
            $rows .= '<tr><td class="c">' . (int) $r['rank'] . '</td><td><strong>' . $h($r['presenter_name']) . '</strong><div class="inst">' . $h($r['topic']) . '</div></td>'
                . '<td class="n">' . $m($r['avg_general']) . '</td><td class="n">' . $m($r['avg_representative']) . '</td><td class="n">' . $m($r['avg_judge']) . '</td><td class="n fin">' . $m($r['final_mark']) . '</td></tr>';
        }
        $html = '<style>
            body { font-family: dejavusans; color: #111; }
            .band { background: #ff9a00; color: #1b1200; padding: 6mm 8mm; } .band h1 { font-size: 22pt; margin: 0; } .band .sub { font-size: 11pt; margin-top: 1mm; }
            h2 { font-size: 18pt; text-align: center; margin: 9mm 0 5mm; } h3 { font-size: 12pt; margin: 9mm 0 2mm; }
            table.pod { width: 100%; border: 0.4mm solid #ff9a00; margin-bottom: 4mm; border-collapse: collapse; } table.pod td { padding: 4mm; vertical-align: middle; }
            .place { width: 26%; font-size: 12pt; font-weight: bold; background: #fff1d6; text-align: center; } .gold { background: #ffd36b; font-size: 14pt; }
            .pname { font-size: 15pt; font-weight: bold; } .ptopic { font-size: 10.5pt; margin-top: 1mm; } .inst { font-size: 9.5pt; color: #555; }
            .pmark { width: 22%; text-align: right; font-size: 18pt; font-weight: bold; } .of { font-size: 8pt; font-weight: normal; color: #666; }
            table.r { width: 100%; border-collapse: collapse; } table.r td, table.r th { padding: 2.6mm 2mm; border-bottom: 0.25mm solid #bbb; font-size: 10pt; vertical-align: top; }
            table.r th { background: #f3f3f3; font-size: 8.5pt; text-align: left; } .c { text-align: center; width: 8%; font-weight: bold; } .n { text-align: right; width: 12%; } .fin { font-weight: bold; }
            .foot { margin-top: 10mm; font-size: 9pt; color: #555; text-align: center; }
            .sign { margin-top: 18mm; width: 100%; } .sign td { width: 33%; text-align: center; font-size: 10pt; padding-top: 2mm; border-top: 0.3mm solid #666; }
        </style>'
            . '<div class="band"><h1>Kerala Science Slam 2026: Final</h1><div class="sub">' . $h($slam['name']) . ' · ' . $h($date) . ' · ' . $h($slam['venue_name']) . ($slam['city'] ? ', ' . $h($slam['city']) : '') . '</div></div>'
            . '<h2>Final result</h2>' . $podium
            . '<h3>Complete ranking (marks out of 100)</h3>'
            . '<table class="r"><tr><th class="c">#</th><th>PRESENTER AND TALK</th><th class="n">JUDGES</th><th class="n">AUD. REP.</th><th class="n">EXPERTS</th><th class="n">FINAL</th></tr>' . $rows . '</table>'
            . '<table class="sign"><tr><td>Coordinator</td><td>Chief of experts</td><td>Organiser</td></tr></table>'
            . '<div class="foot">Printed ' . $h(Clock::local(Clock::db(), 'd M Y, H:i')) . ' · Marks out of 100.</div>';
        $pdf = PdfFactory::make(['margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12]);
        $pdf->SetTitle('Final result — ' . $slam['name']);
        $pdf->WriteHTML($html);

        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** @param list<array<string, mixed>> $top */
    private function regionalSheet(array $slam, array $top, bool $withMarks): string
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
            . '<table class="sign"><tr><td>Coordinator</td><td>Chief of experts</td><td>Organiser</td></tr></table>'
            . '<div class="foot">Printed ' . $h(Clock::local(Clock::db(), 'd M Y, H:i')) . ' · Science Slam results</div>';

        $pdf = PdfFactory::make(['margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12]);
        $pdf->SetTitle('Result sheet — ' . $slam['name']);
        $pdf->WriteHTML($html);

        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
