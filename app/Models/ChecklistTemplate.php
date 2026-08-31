<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nama_template',
        'deskripsi',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class, 'checklist_template_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'checklist_template_id');
    }
}
