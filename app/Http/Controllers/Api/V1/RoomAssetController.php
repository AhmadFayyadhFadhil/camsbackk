<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomAssetResource;
use App\Models\RoomAsset;
use App\Models\Room;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoomAssetController extends Controller
{
    use ApiResponse;

    /**
     * GET /room-assets (admin, supervisor, all authenticated)
     */
    public function index(Request $request)
    {
        $query = RoomAsset::query()->with('room');

        if ($request->has('room_id') && $request->filled('room_id')) {
            $query->where('room_id', $request->get('room_id'));
        }

        if ($request->has('building_id') && $request->filled('building_id')) {
            $query->whereHas('room', function ($q) use ($request) {
                $q->where('building_id', $request->get('building_id'));
            });
        }

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('nama_aset', 'like', "%{$search}%")
                  ->orWhere('kode_aset', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 20);
        $assets = $query->paginate($perPage);

        return $this->paginated(
            RoomAssetResource::collection($assets),
            'Daftar aset ruangan berhasil diambil.'
        );
    }

    /**
     * POST /room-assets (admin, supervisor)
     * Supports both single asset (with optional foto_aset) and batch multi-asset array
     */
    public function store(Request $request)
    {
        if ($request->has('assets') && is_array($request->input('assets'))) {
            $request->validate([
                'room_id' => ['required', 'uuid', 'exists:rooms,id'],
                'assets' => ['required', 'array', 'min:1'],
                'assets.*.nama_aset' => ['required', 'string', 'max:255'],
                'assets.*.kode_aset' => ['nullable', 'string', 'max:100', 'distinct', Rule::unique('room_assets', 'kode_aset')->whereNull('deleted_at')],
                'assets.*.jumlah' => ['nullable', 'integer', 'min:1'],
                'assets.*.status' => ['nullable', 'string', 'in:active,damaged,repaired'],
            ]);

            $created = DB::transaction(function () use ($request) {
                $results = [];
                $roomId = $request->room_id;
                foreach ($request->assets as $idx => $item) {
                    $kodeAset = isset($item['kode_aset']) && trim((string)$item['kode_aset']) !== '' ? trim((string)$item['kode_aset']) : null;
                    $fotoPath = null;
                    if ($request->hasFile("assets.{$idx}.foto_aset")) {
                        $fotoPath = $request->file("assets.{$idx}.foto_aset")->store('room_assets', 'public');
                    }

                    $asset = RoomAsset::create([
                        'id' => (string) Str::uuid(),
                        'room_id' => $roomId,
                        'nama_aset' => trim($item['nama_aset']),
                        'kode_aset' => $kodeAset,
                        'jumlah' => isset($item['jumlah']) && $item['jumlah'] !== '' ? (int)$item['jumlah'] : 1,
                        'foto_aset' => $fotoPath,
                        'status' => $item['status'] ?? 'active',
                    ]);
                    $asset->load('room');
                    AuditLogService::log('CREATE_ROOM_ASSET', 'room_assets', $asset->id, null, $asset->toArray());
                    $results[] = $asset;
                }
                return $results;
            });

            return $this->success(
                RoomAssetResource::collection(collect($created)),
                count($created) . ' aset ruangan berhasil ditambahkan sekaligus.',
                201
            );
        }

        if ($request->has('kode_aset') && trim((string)$request->input('kode_aset')) === '') {
            $request->merge(['kode_aset' => null]);
        }

        $request->validate([
            'room_id' => ['required', 'uuid', 'exists:rooms,id'],
            'nama_aset' => ['required', 'string', 'max:255'],
            'kode_aset' => ['nullable', 'string', 'max:100', Rule::unique('room_assets', 'kode_aset')->whereNull('deleted_at')],
            'jumlah' => ['nullable', 'integer', 'min:1'],
            'foto_aset' => ['nullable', 'image', 'max:5120'],
            'status' => ['nullable', 'string', 'in:active,damaged,repaired'],
        ]);

        $fotoPath = null;
        if ($request->hasFile('foto_aset')) {
            $fotoPath = $request->file('foto_aset')->store('room_assets', 'public');
        }

        $asset = RoomAsset::create([
            'id' => (string) Str::uuid(),
            'room_id' => $request->room_id,
            'nama_aset' => trim($request->nama_aset),
            'kode_aset' => $request->filled('kode_aset') ? trim($request->kode_aset) : null,
            'jumlah' => $request->filled('jumlah') ? (int)$request->jumlah : 1,
            'foto_aset' => $fotoPath,
            'status' => $request->input('status', 'active'),
        ]);

        $asset->load('room');

        AuditLogService::log('CREATE_ROOM_ASSET', 'room_assets', $asset->id, null, $asset->toArray());

        return $this->success(new RoomAssetResource($asset), 'Aset ruangan berhasil ditambahkan.', 201);
    }

    /**
     * GET /room-assets/{id}
     */
    public function show($id)
    {
        $asset = RoomAsset::with('room')->findOrFail($id);
        return $this->success(new RoomAssetResource($asset), 'Detail aset ruangan berhasil diambil.');
    }

    /**
     * POST/PUT/PATCH /room-assets/{id} (admin, supervisor)
     */
    public function update(Request $request, $id)
    {
        $asset = RoomAsset::findOrFail($id);
        $oldData = $asset->toArray();

        if ($request->has('kode_aset') && trim((string)$request->input('kode_aset')) === '') {
            $request->merge(['kode_aset' => null]);
        }

        $request->validate([
            'room_id' => ['sometimes', 'required', 'uuid', 'exists:rooms,id'],
            'nama_aset' => ['sometimes', 'required', 'string', 'max:255'],
            'kode_aset' => ['nullable', 'string', 'max:100', Rule::unique('room_assets', 'kode_aset')->ignore($id)->whereNull('deleted_at')],
            'jumlah' => ['nullable', 'integer', 'min:1'],
            'foto_aset' => ['nullable', 'image', 'max:5120'],
            'remove_photo' => ['nullable'],
            'status' => ['sometimes', 'required', 'string', 'in:active,damaged,repaired'],
        ]);

        $updateData = [];
        if ($request->has('room_id')) $updateData['room_id'] = $request->room_id;
        if ($request->has('nama_aset')) $updateData['nama_aset'] = trim($request->nama_aset);
        if ($request->exists('kode_aset')) {
            $updateData['kode_aset'] = $request->filled('kode_aset') ? trim($request->kode_aset) : null;
        }
        if ($request->filled('jumlah')) $updateData['jumlah'] = (int)$request->jumlah;
        if ($request->has('status')) $updateData['status'] = $request->status;

        if (filter_var($request->input('remove_photo'), FILTER_VALIDATE_BOOLEAN)) {
            if ($asset->foto_aset && Storage::disk('public')->exists($asset->foto_aset)) {
                Storage::disk('public')->delete($asset->foto_aset);
            }
            $updateData['foto_aset'] = null;
        }

        if ($request->hasFile('foto_aset')) {
            if ($asset->foto_aset && Storage::disk('public')->exists($asset->foto_aset)) {
                Storage::disk('public')->delete($asset->foto_aset);
            }
            $updateData['foto_aset'] = $request->file('foto_aset')->store('room_assets', 'public');
        }

        $asset->update($updateData);
        $asset->load('room');

        AuditLogService::log('UPDATE_ROOM_ASSET', 'room_assets', $asset->id, $oldData, $asset->toArray());

        return $this->success(new RoomAssetResource($asset), 'Aset ruangan berhasil diperbarui.');
    }

    /**
     * GET /room-assets/{id}/foto
     * Stream foto master aset ruangan
     */
    public function streamFoto($id)
    {
        $asset = RoomAsset::findOrFail($id);

        if (!$asset->foto_aset || !Storage::disk('public')->exists($asset->foto_aset)) {
            return response()->json(['message' => 'Foto aset tidak ditemukan.'], 404);
        }

        return response()->file(Storage::disk('public')->path($asset->foto_aset));
    }

    /**
     * DELETE /room-assets/{id} (admin, supervisor)
     */
    public function destroy($id)
    {
        $asset = RoomAsset::findOrFail($id);
        $oldData = $asset->toArray();

        $asset->delete();

        AuditLogService::log('DELETE_ROOM_ASSET', 'room_assets', $asset->id, $oldData, null);

        return $this->success(null, 'Aset ruangan berhasil dihapus.');
    }
}
