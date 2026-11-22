<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Building extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'address',
        'description',
        'capacity',
        'status',
    ];

    // Auto-generate slug
    protected static function boot()
    {
        parent::boot(); //metode bawaan Laravel
        static::creating(function ($building) {
            if (empty($building->slug)) {
                $building->slug = Str::slug($building->name); //mengubah nama menjadi slug
            }
        });
    }

    public function rates()
    {
        return $this->hasMany(BuildingRate::class);
    }

    public function images()
    {
        return $this->morphMany(ImagePackage::class, 'imageable');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    // Ambil harga berdasarkan kategori (prioritas: spesifik -> 'all')
    // public function getRateForCategory($category) {
    //     return $this->rates()->where('category', $category)->first() 
    //         ?? $this->rates()->where('category', 'all')->first();
    // }
}
