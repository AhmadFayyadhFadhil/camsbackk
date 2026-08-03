<?php

namespace App\Services;

use App\Models\Notification;
use App\Enums\NotificationChannelEnum;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Kirim notifikasi sistem (DB, SSE, & Email).
     *
     * @param string $userId ID User penerima
     * @param string $type Jenis/Tipe pemicu notifikasi
     * @param string $title Judul notifikasi
     * @param string $message Detail isi notifikasi
     * @param array $data Data konteks tambahan untuk email/sse
     * @param string $channel Saluran ('in_app', 'email', 'both')
     * @return Notification
     */
    public static function send(string $userId, string $type, string $title, string $message, array $data = [], string $channel = 'both'): Notification
    {
        // 1. Simpan ke database (Notifikasi History)
        $notification = Notification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'is_read' => false,
            'channel' => NotificationChannelEnum::tryFrom($channel) ?? NotificationChannelEnum::BOTH,
        ]);

        // 2. Jika in_app atau both, publish ke Redis channel Pub/Sub
        if ($channel === 'in_app' || $channel === 'both') {
            try {
                Redis::publish("sse:user:{$userId}", json_encode([
                    'id' => $notification->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'created_at' => $notification->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
                ]));
            } catch (\Exception $e) {
                // Log kesalahan Redis tapi tidak membatalkan eksekusi
                logger()->error("SSE Redis Publish Gagal: " . $e->getMessage());
            }
        }

        // 3. Jika email atau both, dispatch Mailable Asinkron
        if ($channel === 'email' || $channel === 'both') {
            try {
                $user = \App\Models\User::find($userId);
                if ($user && $user->email) {
                    MailService::sendNotificationMail($user, $type, $title, $message, $data);
                }
            } catch (\Exception $e) {
                logger()->error("Dispatch Email Antrean Gagal: " . $e->getMessage());
            }
        }

        return $notification;
    }
}
