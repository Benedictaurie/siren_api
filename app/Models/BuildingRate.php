<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BuildingRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'category',
        'price',
        'min_hours',
        'max_hours',
        'duration_type',
        'note',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }
}
