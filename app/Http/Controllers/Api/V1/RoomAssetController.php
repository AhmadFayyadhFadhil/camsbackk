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
use Illuminate\Support\Str;

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
     * Supports both single asset and batch multi-asset array
     */
    public function store(Request $request)
    {
        if ($request->has('assets') && is_array($request->input('assets'))) {
            $request->validate([
                'room_id' => ['required', 'uuid', 'exists:rooms,id'],
                'assets' => ['required', 'array', 'min:1'],
                'assets.*.nama_aset' => ['required', 'string', 'max:255'],
                'assets.*.kode_aset' => ['required', 'string', 'max:100', 'distinct', 'unique:room_assets,kode_aset'],
                'assets.*.jumlah' => ['nullable', 'integer', 'min:1'],
                'assets.*.status' => ['nullable', 'string', 'in:active,damaged,repaired'],
            ]);

            $created = DB::transaction(function () use ($request) {
                $results = [];
                $roomId = $request->room_id;
                foreach ($request->assets as $item) {
                    $asset = RoomAsset::create([
                        'id' => (string) Str::uuid(),
                        'room_id' => $roomId,
                        'nama_aset' => trim($item['nama_aset']),
                        'kode_aset' => trim($item['kode_aset']),
                        'jumlah' => isset($item['jumlah']) && $item['jumlah'] !== '' ? (int)$item['jumlah'] : 1,
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

        $request->validate([
            'room_id' => ['required', 'uuid', 'exists:rooms,id'],
            'nama_aset' => ['required', 'string', 'max:255'],
            'kode_aset' => ['required', 'string', 'max:100', 'unique:room_assets,kode_aset'],
            'jumlah' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:active,damaged,repaired'],
        ]);

        $asset = RoomAsset::create([
            'id' => (string) Str::uuid(),
            'room_id' => $request->room_id,
            'nama_aset' => trim($request->nama_aset),
            'kode_aset' => trim($request->kode_aset),
            'jumlah' => $request->filled('jumlah') ? (int)$request->jumlah : 1,
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
     * PUT/PATCH /room-assets/{id} (admin, supervisor)
     */
    public function update(Request $request, $id)
    {
        $asset = RoomAsset::findOrFail($id);
        $oldData = $asset->toArray();

        $request->validate([
            'room_id' => ['sometimes', 'required', 'uuid', 'exists:rooms,id'],
            'nama_aset' => ['sometimes', 'required', 'string', 'max:255'],
            'kode_aset' => ['sometimes', 'required', 'string', 'max:100', 'unique:room_assets,kode_aset,' . $id],
            'jumlah' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'string', 'in:active,damaged,repaired'],
        ]);

        $asset->update($request->only(['room_id', 'nama_aset', 'kode_aset', 'jumlah', 'status']));
        $asset->load('room');

        AuditLogService::log('UPDATE_ROOM_ASSET', 'room_assets', $asset->id, $oldData, $asset->toArray());

        return $this->success(new RoomAssetResource($asset), 'Aset ruangan berhasil diperbarui.');
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
