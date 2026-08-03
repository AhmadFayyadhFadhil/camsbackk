<?php

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;

class QrCodeService
{
    /**
     * Menghasilkan binary PNG QR Code untuk ruangan kelolaan CAMS.
     *
     * @param string $roomId
     * @param string $token
     * @param string $buildingId
     * @return string Raw PNG binary
     */
    public function generate(string $roomId, string $token, string $buildingId): string
    {
        $payload = json_encode([
            'room_id'     => $roomId,
            'token'       => $token,
            'building_id' => $buildingId,
        ]);

        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'scale' => 10,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
