<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\PriorityEnum;
use App\Enums\FindingStatusEnum;

class Finding extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'room_id',
        'finding_category_id',
        'reported_by',
        'assigned_to',
        'assigned_to_external',
        'assigned_at',
        'response_time_minutes',
        'deskripsi',
        'prioritas',
        'status',
        'foto_finding',
        'foto_finding_mime',
        'foto_selesai',
        'foto_selesai_mime',
        'foto_ob_1',
        'foto_ob_1_mime',
        'foto_ob_2',
        'foto_ob_2_mime',
        'foto_ob_3',
        'foto_ob_3_mime',
        'foto_ob_4',
        'foto_ob_4_mime',
        'deadline_perbaikan',
        'resolved_at',
    ];

    protected $hidden = [
        'foto_finding',
        'foto_selesai',
        'foto_ob_1',
        'foto_ob_2',
        'foto_ob_3',
        'foto_ob_4',
    ];

    protected $casts = [
        'prioritas' => PriorityEnum::class,
        'status' => FindingStatusEnum::class,
        'deadline_perbaikan' => 'date',
        'assigned_at' => 'datetime',
        'response_time_minutes' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FindingCategory::class, 'finding_category_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
