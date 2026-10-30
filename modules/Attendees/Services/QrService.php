<?php

declare(strict_types=1);

namespace Modules\Attendees\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Modules\Core\Config\Slam as SlamConfig;

/**
 * Server-side QR codes. Black on white, error correction M, quiet zone 4
 * modules. Deterministic from the input, so reprints are identical.
 */
class QrService
{
    public function checkinBase(): string
    {
        return rtrim(config(SlamConfig::class)->checkinUrl, '/');
    }

    /** The URL a badge QR encodes: {APP_CHECKIN_URL}/in/{BADGE_ID}. */
    public function badgeUrl(string $badgeId): string
    {
        return $this->checkinBase() . '/in/' . $badgeId;
    }

    public function typeIdUrl(): string
    {
        return $this->checkinBase() . '/checkin';
    }

    public function svg(string $data): string
    {
        $opts = new QROptions([
            'outputType'      => QROutputInterface::MARKUP_SVG,
            'eccLevel'        => EccLevel::M,
            'addQuietzone'    => true,
            'quietzoneSize'   => 4,
            'outputBase64'    => false,
            'svgAddXmlHeader' => false,
            'connectPaths'    => true,
            'drawLightModules' => false,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($opts))->render($data);
    }

    /** PNG bytes; $scale pixels per module (12 ≈ print quality for badges). */
    public function png(string $data, int $scale = 12): string
    {
        $opts = new QROptions([
            'outputType'      => QROutputInterface::GDIMAGE_PNG,
            'eccLevel'        => EccLevel::M,
            'addQuietzone'    => true,
            'quietzoneSize'   => 4,
            'scale'           => $scale,
            'outputBase64'    => false,
            'bgColor'         => [255, 255, 255],
            'drawLightModules' => true,
        ]);

        return (new QRCode($opts))->render($data);
    }

    /** Decode a PNG (used by tests to prove the QR encodes the exact URL). */
    public function decodePng(string $png): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qr');
        file_put_contents($tmp, $png);
        try {
            return (string) (new QRCode())->readFromFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
