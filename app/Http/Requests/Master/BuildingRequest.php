<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $buildingParam = $this->route('building') ?? $this->route('id');
        $id = $buildingParam instanceof \App\Models\Building ? $buildingParam->id : $buildingParam;

        return [
            'kode_gedung' => ['required_without:code', 'string', 'max:20', 'unique:buildings,kode_gedung,' . $id],
            'code' => ['required_without:kode_gedung', 'string', 'max:20', 'unique:buildings,kode_gedung,' . $id],
            'nama_gedung' => ['required_without:name', 'string', 'max:255'],
            'name' => ['required_without:nama_gedung', 'string', 'max:255'],
            'alamat' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meter' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors' => $validator->errors()->toArray()
        ], 422));
    }
}
