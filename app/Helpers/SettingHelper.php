<?php

namespace App\Helpers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SettingHelper
{
    /**
     * Dapatkan nilai pengaturan berdasarkan key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        try {
            $setting = Cache::remember("system_setting:{$key}", 60, function () use ($key) {
                return SystemSetting::where('key', $key)->first();
            });

            if (!$setting) {
                return $default;
            }

            $val = $setting->value;

            switch ($setting->type) {
                case 'integer':
                case 'int':
                    return (int) $val;
                case 'float':
                case 'double':
                    return (float) $val;
                case 'boolean':
                case 'bool':
                    return filter_var($val, FILTER_VALIDATE_BOOLEAN);
                default:
                    return $val;
            }
        } catch (\Exception $e) {
            return $default;
        }
    }
}
