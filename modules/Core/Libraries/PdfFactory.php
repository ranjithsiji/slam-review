<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Language\LanguageToFont;
use Mpdf\Mpdf;

/**
 * mPDF instances with Malayalam shaping: runs of Malayalam script are detected
 * (autoScriptToLang) and rendered in Manjari (SMC, OFL) with OpenType layout — Noto Sans Malayalam's
 * GPOS tables are not supported by mPDF —
 * Latin text in DejaVu Sans. Used for badges, posters and run-sheets.
 */
final class PdfFactory
{
    /** @param array<string, mixed> $options extra mPDF options (format, margins, ...) */
    public static function make(array $options = []): Mpdf
    {
        $fontDirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];
        $tmp      = WRITEPATH . 'cache/mpdf';
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        return new Mpdf($options + [
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'tempDir'          => $tmp,
            'fontDir'          => [...$fontDirs, ROOTPATH . 'modules/Core/Resources/fonts'],
            'fontdata'         => $fontData + [
                'manjari' => [
                    'R' => 'Manjari-Regular.ttf',
                    'B' => 'Manjari-Bold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font'     => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            'languageToFont'   => new class () extends LanguageToFont {
                public function getLanguageOptions($llcc, $adobeCJK)
                {
                    $tags = explode('-', strtolower((string) $llcc));
                    if (in_array($tags[0], ['ml', 'mal'], true)) {
                        return [false, 'manjari'];
                    }

                    return parent::getLanguageOptions($llcc, $adobeCJK);
                }
            },
        ]);
    }
}
