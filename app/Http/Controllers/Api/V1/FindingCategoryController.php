<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FindingCategory;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class FindingCategoryController extends Controller
{
    use ApiResponse;

    /**
     * Tampilkan daftar kategori temuan kerusakan.
     */
    public function index()
    {
        $categories = FindingCategory::orderBy('nama_kategori', 'asc')->get();

        return $this->success($categories, 'Daftar kategori temuan berhasil diambil.');
    }
}
