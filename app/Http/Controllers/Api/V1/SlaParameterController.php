<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SlaParameterResource;
use App\Models\SlaParameter;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SlaParameterController extends Controller
{
    use ApiResponse;

    /**
     * GET /sla-parameters (all authenticated)
     */
    public function index(Request $request)
    {
        $query = SlaParameter::query();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('nama_parameter', 'like', "%{$search}%")
                  ->orWhere('deskripsi', 'like', "%{$search}%");
        }

        $perPage = $request->get('per_page', 50);
        $parameters = $query->paginate($perPage);

        return $this->paginated(
            SlaParameterResource::collection($parameters),
            'Daftar parameter SLA berhasil diambil.'
        );
    }

    /**
     * POST /sla-parameters (admin, supervisor)
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama_parameter' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'tipe_penilaian' => ['required', 'string', 'in:scale_1_5,yes_no'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $parameter = SlaParameter::create([
            'id' => (string) Str::uuid(),
            'nama_parameter' => $request->nama_parameter,
            'deskripsi' => $request->deskripsi,
            'tipe_penilaian' => $request->tipe_penilaian,
            'is_active' => $request->has('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : true,
        ]);

        AuditLogService::log('CREATE_SLA_PARAMETER', 'sla_parameters', $parameter->id, null, $parameter->toArray());

        return $this->success(new SlaParameterResource($parameter), 'Parameter SLA berhasil dibuat.', 201);
    }

    /**
     * GET /sla-parameters/{id}
     */
    public function show($id)
    {
        $parameter = SlaParameter::findOrFail($id);
        return $this->success(new SlaParameterResource($parameter), 'Detail parameter SLA berhasil diambil.');
    }

    /**
     * PUT/PATCH /sla-parameters/{id} (admin, supervisor)
     */
    public function update(Request $request, $id)
    {
        $parameter = SlaParameter::findOrFail($id);
        $oldData = $parameter->toArray();

        $request->validate([
            'nama_parameter' => ['sometimes', 'required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'tipe_penilaian' => ['sometimes', 'required', 'string', 'in:scale_1_5,yes_no'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updateData = $request->only(['nama_parameter', 'deskripsi', 'tipe_penilaian']);
        if ($request->has('is_active')) {
            $updateData['is_active'] = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
        }

        $parameter->update($updateData);

        AuditLogService::log('UPDATE_SLA_PARAMETER', 'sla_parameters', $parameter->id, $oldData, $parameter->toArray());

        return $this->success(new SlaParameterResource($parameter), 'Parameter SLA berhasil diperbarui.');
    }

    /**
     * DELETE /sla-parameters/{id} (admin, supervisor)
     */
    public function destroy($id)
    {
        $parameter = SlaParameter::findOrFail($id);
        $oldData = $parameter->toArray();

        $parameter->delete();

        AuditLogService::log('DELETE_SLA_PARAMETER', 'sla_parameters', $parameter->id, $oldData, null);

        return $this->success(null, 'Parameter SLA berhasil dihapus.');
    }
}
