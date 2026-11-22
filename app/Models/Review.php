<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'comment',
        'rating',
        'image',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }
}
