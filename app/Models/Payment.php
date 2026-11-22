<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'va_number',
        'bank_name',
        'amount',
        'status',
        'expired_at',
        'paid_at',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'paid_at' => 'datetime',
        'amount' => 'decimal:2'
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
