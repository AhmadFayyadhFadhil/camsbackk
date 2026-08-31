<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Enum;
use App\Enums\FrequencyEnum;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'uuid', 'exists:rooms,id'],
            'checklist_item_id' => ['required', 'uuid', 'exists:checklist_items,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'frekuensi' => ['required', new Enum(FrequencyEnum::class)],
            'hari_minggu' => ['nullable', 'integer', 'min:0', 'max:6'],
            'tanggal_bulan' => ['nullable', 'integer', 'min:1', 'max:31'],
            'target_jam_mulai' => ['nullable'],
            'target_jam_selesai' => ['nullable'],
            'estimasi_durasi_menit' => ['nullable', 'integer', 'min:1', 'max:480'],
            'urutan' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
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
