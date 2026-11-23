<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use App\Models\Reservation;
use App\Models\Building;
use App\Models\Payment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    /**
     * Customer membuat reservasi baru
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'building_id' => 'required|exists:buildings,id',
            'event_name' => 'required|string|max:255',
            'event_date' => 'required|date|after_or_equal:today',
            'category' => 'required|in:commercial,social',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, 'Validasi gagal', $validator->errors(), 422);
        }

        // 1. Cek Double Booking (Apakah ada yang approved di tanggal & jam yang sama?)
        $isConflict = Reservation::approvedOnDate($request->event_date)
            ->where(function ($query) use ($request) {
                $query->whereBetween('start_time', [$request->start_time, $request->end_time])
                      ->orWhereBetween('end_time', [$request->start_time, $request->end_time]);
            })->exists();

        if ($isConflict) {
            return new ApiResponseResources(false, 'Jadwal bentrok! Gedung sudah dibooking pada jam tersebut.', null, 409);
        }

        // 2. Kalkulasi Harga
        $building = Building::find($request->building_id);
        $rate = $building->getRateForCategory($request->category);

        if (!$rate) {
            return new ApiResponseResources(false, 'Tarif untuk kategori ini belum diatur.', null, 404);
        }

        $start = Carbon::parse($request->start_time);
        $end = Carbon::parse($request->end_time);
        $durationHours = $start->diffInHours($end);
        
        $totalPrice = 0;

        if ($rate->duration_type === 'per_day') {
            $totalPrice = $rate->price; // Harga flat per hari
        } else {
            // Logika Hourly Tier
            if ($rate->max_hours && $durationHours > $rate->max_hours) {
                // Jika lebih dari batas jam tier, bisa dilempar ke harga per_day atau harga custom
                // Untuk simplifikasi, kita kalikan sesuai jam (bisa disesuaikan bisnisnya)
                $totalPrice = $rate->price * $durationHours; 
            } else {
                $totalPrice = $rate->price;
            }
        }

        // 3. Simpan Reservasi
        $reservation = Reservation::create([
            'building_id' => $building->id,
            'event_name' => $request->event_name,
            'event_date' => $request->event_date,
            'category' => $request->category,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'total_price' => $totalPrice,
            'status' => 'pending',
        ]);

        return new ApiResponseResources(true, 'Reservasi berhasil diajukan, menunggu verifikasi admin.', $reservation, 201);
    }

    /**
     * Admin menyetujui reservasi & Generate VA
     */
    public function approve($id)
    {
        $reservation = Reservation::find($id);

        if (!$reservation) {
            return new ApiResponseResources(false, 'Reservasi tidak ditemukan', null, 404);
        }

        if ($reservation->status !== 'pending') {
            return new ApiResponseResources(false, 'Hanya reservasi pending yang bisa disetujui', null, 400);
        }

        DB::beginTransaction();
        try {
            // Update status reservasi
            $reservation->update(['status' => 'approved']);

            // Generate Virtual Account secara otomatis
            $payment = Payment::create([
                'reservation_id' => $reservation->id,
                'va_number' => '8808' . Str::random(12), // Dummy VA Number
                'bank_name' => 'Bank BPD Bali',
                'amount' => $reservation->total_price,
                'status' => 'unpaid',
                'expired_at' => Carbon::now()->addDays(2), // Expired 2 hari
            ]);

            DB::commit();

            // Di sini seharusnya ada trigger Email/Notifikasi ke Customer berisi VA Number
            return new ApiResponseResources(true, 'Reservasi disetujui. Virtual Account telah dibuat.', $payment);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Gagal memproses persetujuan: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Customer upload bukti bayar / Admin konfirmasi pembayaran (Simulasi)
     */
    public function confirmPayment($paymentId)
    {
        $payment = Payment::find($paymentId);

        if (!$payment) {
            return new ApiResponseResources(false, 'Data pembayaran tidak ditemukan', null, 404);
        }

        if ($payment->status === 'paid') {
            return new ApiResponseResources(false, 'Pembayaran sudah dikonfirmasi sebelumnya', null, 400);
        }

        $payment->update([
            'status' => 'paid',
            'paid_at' => Carbon::now()
        ]);

        // Opsional: Kirim E-Ticket ke Email Customer di sini

        return new ApiResponseResources(true, 'Pembayaran berhasil dikonfirmasi. Reservasi selesai.', $payment);
    }
}