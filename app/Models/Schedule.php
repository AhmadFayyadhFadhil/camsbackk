<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\FrequencyEnum;

class Schedule extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'room_id',
        'checklist_item_id',
        'shift_id',
        'frekuensi',
        'hari_minggu',
        'tanggal_bulan',
        'target_jam_mulai',
        'target_jam_selesai',
        'estimasi_durasi_menit',
        'urutan',
        'is_active',
    ];

    protected $casts = [
        'frekuensi' => FrequencyEnum::class,
        'hari_minggu' => 'integer',
        'tanggal_bulan' => 'integer',
        'estimasi_durasi_menit' => 'integer',
        'urutan' => 'integer',
        'is_active' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'schedule_id');
    }
}
