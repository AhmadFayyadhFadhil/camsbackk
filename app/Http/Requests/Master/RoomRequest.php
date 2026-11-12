<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // 1. Map 'name' to 'nama_ruangan' if 'name' is provided and 'nama_ruangan' is not
        if ($this->has('name') && !$this->has('nama_ruangan')) {
            $this->merge(['nama_ruangan' => $this->input('name')]);
        }

        // 2. Map 'code' to 'kode_ruangan' if 'code' is provided and 'kode_ruangan' is not
        if ($this->has('code') && !$this->has('kode_ruangan')) {
            $this->merge(['kode_ruangan' => $this->input('code')]);
        }

        // 3. Map 'floor' to 'lantai' and cast to string (nilai kosong disimpan sebagai NULL, bukan string kosong)
        if ($this->has('floor') && !$this->has('lantai')) {
            $floor = $this->input('floor');
            $this->merge(['lantai' => ($floor === null || $floor === '') ? null : (string) $floor]);
        } elseif ($this->has('lantai')) {
            $lantai = $this->input('lantai');
            $this->merge(['lantai' => ($lantai === null || $lantai === '') ? null : (string) $lantai]);
        }
    }

    public function rules(): array
    {
        $roomParam = $this->route('room') ?? $this->route('id');
        $id = $roomParam instanceof \App\Models\Room ? $roomParam->id : $roomParam;

        return [
            'building_id' => ['required', 'uuid', 'exists:buildings,id'],
            'room_area_id' => ['nullable', 'uuid', 'exists:room_areas,id'],
            'nama_ruangan' => ['required', 'string', 'max:255'],
            'kode_ruangan' => [
                'required',
                'string',
                'max:30',
                Rule::unique('rooms')->where(function ($query) {
                    return $query->where('building_id', $this->building_id);
                })->ignore($id)
            ],
            'lantai' => ['nullable', 'string', 'max:10'],
            'pic_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'checklist_template_id' => ['nullable', 'uuid', 'exists:checklist_templates,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meter' => ['nullable', 'integer', 'min:1', 'max:5000'],
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
