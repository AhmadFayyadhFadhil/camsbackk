<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoomArea;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RoomAreaController extends Controller
{
    use ApiResponse;

    /**
     * GET /room-areas
     * Daftar area ruangan (read-only) berurutan sesuai sumber data.
     */
    public function index(Request $request)
    {
        $areas = RoomArea::query()
            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->withCount('rooms')
            ->orderBy('urutan')
            ->orderBy('nama_area')
            ->get()
            ->map(fn (RoomArea $area) => [
                'id' => $area->id,
                'nomor' => $area->nomor,
                'nama_area' => $area->nama_area,
                'name' => $area->nama_area,
                'urutan' => $area->urutan,
                'is_active' => (bool) $area->is_active,
                'rooms_count' => (int) $area->rooms_count,
            ]);

        return $this->success($areas, 'Daftar area ruangan berhasil diambil.');
    }
}
