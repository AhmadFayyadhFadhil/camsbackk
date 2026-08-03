<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemSettingController extends Controller
{
    use ApiResponse;

    /**
     * Tampilkan seluruh pengaturan sistem (Admin/Supervisor).
     */
    public function index()
    {
        $settings = SystemSetting::orderBy('key', 'asc')->get();
        return $this->success($settings, 'Seluruh pengaturan sistem berhasil diambil.');
    }

    /**
     * Ambil pengaturan publik (Nama perusahaan, logo, footer).
     */
    public function publicSettings()
    {
        $keys = ['company_name', 'company_logo', 'app_footer_text', 'company_description'];
        $settings = SystemSetting::whereIn('key', $keys)->get();
        
        $result = [];
        foreach ($keys as $key) {
            $setting = $settings->firstWhere('key', $key);
            $value = $setting ? $setting->value : null;
            
            if ($key === 'company_name' && empty($value)) {
                $value = 'CAMS PANDAAN';
            }
            if ($key === 'company_description' && empty($value)) {
                $value = 'Cleaning Activity Monitor';
            }
            if ($key === 'app_footer_text' && empty($value)) {
                $value = '© 2026 CAMS Pandaan. All rights reserved.';
            }
            if ($key === 'company_logo') {
                if (!empty($value)) {
                    $value = url('api/v1/settings/logo/image');
                } else {
                    $value = null;
                }
            }
            
            $result[$key] = $value;
        }
        
        return $this->success($result, 'Pengaturan publik berhasil diambil.');
    }

    /**
     * Stream file gambar logo perusahaan.
     */
    public function streamLogo()
    {
        $setting = SystemSetting::where('key', 'company_logo')->first();
        $path = $setting ? $setting->value : null;
        
        if ($path && Storage::disk('public')->exists($path)) {
            $file = Storage::disk('public')->get($path);
            $type = Storage::disk('public')->mimeType($path);
            return response($file, 200)->header('Content-Type', $type);
        }
        
        return response()->json(['message' => 'Logo not found'], 404);
    }

    /**
     * Upload/perbarui file gambar logo perusahaan.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|file|max:2048', // Max 2MB
        ]);
        
        $file = $request->file('logo');
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpeg', 'png', 'jpg', 'gif', 'svg', 'ico'];
        
        if (!in_array($extension, $allowedExtensions)) {
            return response()->json([
                'message' => 'The logo field must be a file of type: jpeg, png, jpg, gif, svg, ico.',
                'errors' => [
                    'logo' => ['File logo harus berupa gambar dengan ekstensi: jpeg, png, jpg, gif, svg, ico.']
                ]
            ], 422);
        }
        
        $setting = SystemSetting::where('key', 'company_logo')->first();
        if (!$setting) {
            $setting = SystemSetting::create([
                'id' => (string) Str::uuid(),
                'key' => 'company_logo',
                'value' => '',
                'type' => 'string',
                'description' => 'Path file gambar logo perusahaan',
            ]);
        }
        
        // Hapus logo lama jika ada
        if (!empty($setting->value) && Storage::disk('public')->exists($setting->value)) {
            Storage::disk('public')->delete($setting->value);
        }
        
        // Simpan file baru
        $file = $request->file('logo');
        $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('settings', $filename, 'public');
        
        $setting->update([
            'value' => $path
        ]);
        
        Cache::forget("system_setting:company_logo");
        
        return $this->success([
            'logo_url' => url('api/v1/settings/logo/image')
        ], 'Logo perusahaan berhasil diperbarui.');
    }

    /**
     * Update/simpan pengaturan sistem secara massal (Admin/Supervisor).
     */
    public function update(Request $request)
    {
        $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'exists:system_settings,key'],
            'settings.*.value' => ['required', 'string'],
        ]);

        foreach ($request->settings as $item) {
            $setting = SystemSetting::where('key', $item['key'])->first();
            if ($setting) {
                // Jangan perbarui logo lewat sini (logo diperbarui via endpoint uploadLogo)
                if ($item['key'] === 'company_logo') {
                    continue;
                }
                
                $setting->update([
                    'value' => $item['value']
                ]);
                // Clear cache
                Cache::forget("system_setting:{$item['key']}");
            }
        }

        return $this->success(null, 'Pengaturan sistem berhasil diperbarui.');
    }
}
