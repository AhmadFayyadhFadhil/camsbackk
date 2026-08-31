<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CleaningMaterialResource;
use App\Models\CleaningMaterial;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CleaningMaterialController extends Controller
{
    use ApiResponse;

    /**
     * GET /cleaning-materials (all authenticated)
     */
    public function index(Request $request)
    {
        $query = CleaningMaterial::query();

        if ($request->has('jenis')) {
            $query->where('jenis', $request->get('jenis'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('nama_material', 'like', "%{$search}%")
                  ->orWhere('kode_material', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 50);
        $materials = $query->paginate($perPage);

        return $this->paginated(
            CleaningMaterialResource::collection($materials),
            'Daftar bahan kimia & alat kerja berhasil diambil.'
        );
    }

    /**
     * POST /cleaning-materials (admin, supervisor)
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama_material' => ['required', 'string', 'max:255'],
            'jenis' => ['required', 'string', 'in:chemical,tool'],
            'kode_material' => ['required', 'string', 'max:100', 'unique:cleaning_materials,kode_material'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $material = CleaningMaterial::create([
            'id' => (string) Str::uuid(),
            'nama_material' => $request->nama_material,
            'jenis' => $request->jenis,
            'kode_material' => $request->kode_material,
            'is_active' => $request->has('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : true,
        ]);

        AuditLogService::log('CREATE_CLEANING_MATERIAL', 'cleaning_materials', $material->id, null, $material->toArray());

        return $this->success(new CleaningMaterialResource($material), 'Bahan kimia/alat kerja berhasil ditambahkan.', 201);
    }

    /**
     * GET /cleaning-materials/{id}
     */
    public function show($id)
    {
        $material = CleaningMaterial::findOrFail($id);
        return $this->success(new CleaningMaterialResource($material), 'Detail bahan kimia/alat kerja berhasil diambil.');
    }

    /**
     * PUT/PATCH /cleaning-materials/{id} (admin, supervisor)
     */
    public function update(Request $request, $id)
    {
        $material = CleaningMaterial::findOrFail($id);
        $oldData = $material->toArray();

        $request->validate([
            'nama_material' => ['sometimes', 'required', 'string', 'max:255'],
            'jenis' => ['sometimes', 'required', 'string', 'in:chemical,tool'],
            'kode_material' => ['sometimes', 'required', 'string', 'max:100', 'unique:cleaning_materials,kode_material,' . $id],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updateData = $request->only(['nama_material', 'jenis', 'kode_material']);
        if ($request->has('is_active')) {
            $updateData['is_active'] = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
        }

        $material->update($updateData);

        AuditLogService::log('UPDATE_CLEANING_MATERIAL', 'cleaning_materials', $material->id, $oldData, $material->toArray());

        return $this->success(new CleaningMaterialResource($material), 'Bahan kimia/alat kerja berhasil diperbarui.');
    }

    /**
     * DELETE /cleaning-materials/{id} (admin, supervisor)
     */
    public function destroy($id)
    {
        $material = CleaningMaterial::findOrFail($id);
        $oldData = $material->toArray();

        $material->delete();

        AuditLogService::log('DELETE_CLEANING_MATERIAL', 'cleaning_materials', $material->id, $oldData, null);

        return $this->success(null, 'Bahan kimia/alat kerja berhasil dihapus.');
    }
}
