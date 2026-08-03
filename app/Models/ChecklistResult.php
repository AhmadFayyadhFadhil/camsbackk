<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistResult extends Model
{
    use HasUuids;

    protected $table = 'checklist_results';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'submission_id',
        'checklist_item_id',
        'is_done',
        'catatan',
    ];

    protected $casts = [
        'is_done' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ChecklistSubmission::class, 'submission_id');
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}
