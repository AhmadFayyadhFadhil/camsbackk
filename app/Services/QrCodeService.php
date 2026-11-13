<?php

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Common\EccLevel;

class QrCodeService
{
    /**
     * Menghasilkan binary PNG QR Code untuk ruangan kelolaan CAMS.
     * Menggunakan payload yang ringkas & terstruktur sehingga menghasilkan
     * QR Code dengan kisi/modul besar, tebal, dan sangat cepat dipindai (< 0.2 detik)
     * baik dari stiker fisik, layar HP, maupun kamera webcam laptop.
     *
     * @param string $roomId
     * @param string $token
     * @param string|null $buildingId
     * @param string|null $code
     * @param string|null $name
     * @return string Raw PNG binary
     */
    public function generate(
        string $roomId,
        string $token,
        ?string $buildingId = null,
        ?string $code = null,
        ?string $name = null
    ): string {
        $payloadData = [
            'token'   => $token,
            'room_id' => $roomId,
        ];

        if (!empty($code)) {
            $payloadData['code'] = $code;
        }

        if (!empty($name)) {
            $payloadData['name'] = $name;
        }

        if (!empty($buildingId)) {
            $payloadData['building_id'] = $buildingId;
        }

        $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64'    => false,
            'eccLevel'        => EccLevel::M,
            'scale'           => 10,
            'addQuietzone'    => true,
            'quietzoneSize'   => 2,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
