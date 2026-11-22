<?php

namespace App\Models;

use illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use hasFactory;

    protected $fillable = [
        'building_id',
        'event_date',
        'event_name',
        'category',
        'start_time',
        'end_time',
        'total_price',
        'status',
    ];

    protected $casts = [
        'event_date' => 'date',
        'total_price' => 'decimal:2',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    // Scope untuk cek jadwal yang sudah dibooking
    public function scopeApprovedOnDate($query, $date) {
        return $query->where('event_date', $date)->where('status', 'approved');
    }
}
