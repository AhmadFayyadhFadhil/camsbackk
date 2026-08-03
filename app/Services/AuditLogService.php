<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Mencatat aktivitas log audit mutasi data ke database.
     *
     * @param string $action Nama aktivitas (misal: ROOM_CREATED, USER_LOGIN, dll)
     * @param string|null $entityType Nama tabel/model (misal: rooms, users, dll)
     * @param string|null $entityId UUID record
     * @param mixed $oldData Data sebelum mutasi
     * @param mixed $newData Data sesudah mutasi
     * @return void
     */
    public static function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        mixed $oldData = null,
        mixed $newData = null
    ): void {
        AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_data'    => self::cleanBinaryData($oldData),
            'new_data'    => self::cleanBinaryData($newData),
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
        ]);
    }

    /**
     * Bersihkan data dari karakter non-UTF8 / binary agar aman di-serialize ke JSON.
     */
    private static function cleanBinaryData(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::cleanBinaryData($value);
            }
        } elseif (is_string($data)) {
            if (!mb_check_encoding($data, 'UTF-8')) {
                return preg_replace('/[\x7F-\xFF]/', '?', $data);
            }
        }
        return $data;
    }
}
