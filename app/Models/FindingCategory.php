<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FindingCategory extends Model
{
    use HasUuids;

    protected $table = 'finding_categories';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nama_kategori',
        'kode_kategori',
    ];

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'finding_category_id');
    }
}
