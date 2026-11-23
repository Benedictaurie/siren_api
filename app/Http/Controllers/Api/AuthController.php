<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\ApiResponseResources;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordChangedMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite; // Wajib di-import untuk Google Login
use Laravel\Socialite\Two\AbstractProvider; // Tambahkan import ini di bagian atas file


class AuthController extends Controller
{
    protected $otpService;
    protected $tokenLifetime = 60;

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    // ==========================================
    // 1. REGISTRASI & VERIFIKASI EMAIL (OTP)
    // ==========================================

    public function register(Request $request)
    {
        $messages = [
            'name.required' => 'Nama wajib diisi.',
            'name.min' => 'Nama minimal harus 3 karakter!',
            'name.max' => 'Nama tidak boleh lebih dari 100 karakter!',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid!',
            'email.unique' => 'Email sudah terdaftar!',
            'password.required' => 'Kata sandi wajib diisi!',
            'password.min' => 'Kata sandi minimal harus 8 karakter!',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'password_confirmation.required' => 'Konfirmasi kata sandi wajib diisi!',
            'password_confirmation.min' => 'Konfirmasi kata sandi minimal harus 8 karakter!',
            'phone_number.string' => 'Nomor telepon harus berupa teks.',
            'phone_number.max' => 'Nomor telepon tidak boleh lebih dari 20 karakter!',
        ];
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|max:100',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|confirmed',   
            'password_confirmation' => 'required|min:8',
            'phone_number' => 'nullable|string|max:20', 
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422); 
        }
        
        try {
            DB::beginTransaction();

            // Membuat user baru
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone_number' => $request->phone_number,
                'role' => 'user', 
            ]);

            // Generate OTP
            $this->otpService->generate($request->email);

            // BUAT LIMITED TOKEN (hanya untuk verify email)
            $user->tokens()->delete();

            $verificationToken = $user->createToken(
                'verification_token',
                ['verify-email'], 
                now()->addMinutes(30) 
            )->plainTextToken;

            DB::commit();

            return new ApiResponseResources(
                true,
                'Registrasi berhasil! Silakan periksa email Anda untuk verifikasi kode OTP.',
                [
                    'user' => $user->makeHidden(['password', 'remember_token']),
                    'verification_token' => $verificationToken,
                    'email' => $user->email,
                    'email_verified' => false
                ],
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Registrasi gagal. Silakan coba lagi.', null, 500);
        }
    }

    public function verifyEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|integer|digits:6',
        ], [
            'email.required' => 'Email wajib diisi!',
            'email.email' => 'Format email tidak valid!',
            'email.exists' => 'Email tidak terdaftar!',
            'otp.required' => 'Kode OTP wajib diisi!',
            'otp.integer' => 'Kode OTP harus berupa angka!',
            'otp.digits' => 'Kode OTP harus terdiri dari 6 digit!',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422); 
        }

        $user = User::where('email', $request->email)->first();

        if ($user->email_verified_at) {
            return new ApiResponseResources(true, 'Email Anda sudah terverifikasi.', [
                'user' => $user->makeHidden(['password', 'remember_token']),
                'email_verified' => true
            ]);
        }

        try {
            $succeed = $this->verifyOtpWithEmail($request->otp, $request->email);
            
            if (!$succeed) {
                return new ApiResponseResources(false, 'Kode OTP salah atau sudah kedaluwarsa!', null, 422);
            }

            DB::beginTransaction();

            $user->email_verified_at = now();
            $user->save();

            DB::commit();

            $user->tokens()->delete(); 
            $accessToken = $user->createToken('access_token', ['*'], now()->addDays(7))->plainTextToken;

            return new ApiResponseResources(true, 'Verifikasi email berhasil!', [
                'user' => $user->makeHidden(['password', 'remember_token']),
                'access_token' => $accessToken,
                'email_verified' => true,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Email verification failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Verifikasi email gagal.', null, 500);
        }
    }

    private function verifyOtpWithEmail($otpCode, $email)
    {
        try {
            $otp = \App\Models\TempToken::where('email', $email)
                ->where('token', (string) $otpCode)
                ->first();

            if (!$otp || now()->greaterThan($otp->expired_at)) {
                return false;
            }
            $otp->delete();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function resendVerificationOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'Email wajib diisi!',
            'email.email' => 'Format email tidak valid!',
            'email.exists' => 'Email tidak terdaftar!',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $user = User::where('email', $request->email)->first();
            if ($user->email_verified_at) {
                return new ApiResponseResources(true, 'Email sudah terverifikasi.');
            }
            $this->otpService->generate($user->email);
            return new ApiResponseResources(true, 'Kode OTP telah dikirim ulang ke email Anda.');
        } catch (\Exception $e) {
            return new ApiResponseResources(false, 'Gagal mengirim ulang kode OTP.', null, 500);
        }
    }

    // ==========================================
    // 2. LOGIN MANUAL & LOGOUT
    // ==========================================

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|min:8',
        ], [
            'email.required' => 'Email wajib diisi!',
            'email.email' => 'Format email tidak valid!',
            'password.required' => 'Kata sandi wajib diisi!',
            'password.min' => 'Kata sandi minimal harus 8 karakter!',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return new ApiResponseResources(false, 'Email atau kata sandi salah.', null, 422);
            }

            if (!$user->email_verified_at) {
                return new ApiResponseResources(
                    false,
                    'Akun Anda belum terverifikasi. Silakan verifikasi email Anda terlebih dahulu.',
                    ['email_verified' => false, 'email' => $user->email],
                    403
                );
            }

            $user->tokens()->delete();
            $accessToken = $user->createToken('access_token')->plainTextToken;

            return new ApiResponseResources(true, 'Login berhasil.', [
                'user' => $user->makeHidden(['password', 'remember_token']),
                'access_token' => $accessToken,
                'email_verified' => true,
            ]);

        } catch (\Exception $e) {
            return new ApiResponseResources(false, 'Login gagal. Silakan coba lagi.', null, 500);
        }
    }

    public function logout(Request $request)
    {
       try {
            $request->user()->currentAccessToken()->delete();
            return new ApiResponseResources(true, 'Logout berhasil.');
        } catch (\Exception $e) {
            return new ApiResponseResources(false, 'Logout gagal.', null, 500);
        }
    }

    // ==========================================
    // 3. OAUTH GOOGLE (GMAIL)
    // ==========================================
    
    /**
     * Mendapatkan URL redirect dari Google
     */
    public function getGoogleRedirectUrl()
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('google');
        $url = $driver->stateless()->redirect()->getTargetUrl();

        return new ApiResponseResources(true, 'URL redirect Google berhasil dibuat.', ['url' => $url]);
    }

    /**
     * Callback dari Frontend setelah user login via Google
     */
    public function handleGoogleCallback(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'required|string' // Token yang didapat dari Google
        ], [
            'access_token.required' => 'Token akses Google wajib diisi!',
            'access_token.string' => 'Token akses Google tidak valid!',
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            // Ambil data user dari Google berdasarkan token
            /** @var AbstractProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver->stateless()->userFromToken($request->access_token);

            // Cek apakah email sudah terdaftar
            $user = User::where('email', $googleUser->email)->first();

            if ($user) {
                // Jika sudah ada, update google_id dan avatar jika belum ada
                $user->update([
                    'google_id' => $googleUser->id,
                    'avatar' => $user->avatar ?? $googleUser->avatar,
                    'email_verified_at' => $user->email_verified_at ?? now(), // Email Google otomatis terverifikasi
                ]);
            } else {
                // Jika belum ada, buat user baru
                $user = User::create([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'avatar' => $googleUser->avatar,
                    'password' => null, // Tidak perlu password untuk login sosmed
                    'role' => 'customer',
                    'email_verified_at' => now(), 
                ]);
            }

            // Generate Token Sanctum
            $user->tokens()->delete();
            $accessToken = $user->createToken('access_token')->plainTextToken;

            return new ApiResponseResources(true, 'Login dengan Google berhasil.', [
                'user' => $user->makeHidden(['password', 'remember_token']),
                'access_token' => $accessToken,
                'email_verified' => true,
            ]);

        } catch (\Exception $e) {
            Log::error('Google Login failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Login dengan Google gagal: ' . $e->getMessage(), null, 500);
        }
    }

    // ==========================================
    // 4. FORGOT & RESET PASSWORD
    // ==========================================
    
    /**
     * [API Endpoint] Request password reset link (Forgot Password)
     * Endpoint: POST /api/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $messages = [
            'email.required' => 'Email wajib diisi!',
            'email.email' => 'Format email tidak valid!',
            'email.exists' => 'Email tidak terdaftar!',
        ];

        $validator = Validator::make($request->all(), [
            'email'=> 'required|email|exists:users,email',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        $user = User::where('email', $request->email)->first();

        // 1. Generate Plain Token (Token yang akan dikirim di URL)
        $plainToken = Str::random(64); 

        // 2. Hash Token sebelum disimpan ke database (Keamanan)
        $hashedToken = Hash::make($plainToken);

        // 3. Simpan token ke database (dengan created_at untuk kedaluwarsa)
        try {
             DB::table('temp_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $hashedToken,
                    'expired_at' => Carbon::now()->addMinutes($this->tokenLifetime),
                    'created_at' => Carbon::now(),
                    'updated_at' => now()
                ],
            );
        } catch (\Exception $e) {
            Log::error('Failed to save password reset token: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Gagal memproses pengaturan ulang kata sandi. Silakan coba lagi.', null, 500);
        }

        // 4. Kirim email (menggunakan token non-hashed untuk link)
        try {
            // Gunakan $user dan $plainToken
            Mail::to($user->email)->send(new PasswordChangedMail($user, $plainToken));
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email: ' . $e->getMessage());
            // Beri respons sukses meskipun email gagal dikirim (untuk menghindari enumerasi email)
        }

        // Beri respons sukses
        return new ApiResponseResources(true, 'Tautan pengaturan ulang kata sandi telah dikirim ke email Anda.');
    }

    /**
     * [API Endpoint] Reset password with token (from email link)
     * Endpoint: POST /api/reset-password
     */
    public function resetPassword(Request $request)
    {
        $messages = [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid!',
            'token.required' => 'Token wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi!',
            'password.min' => 'Kata sandi minimal harus 8 karakter!',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok dengan kata sandi baru!',
            'password_confirmation.required' => 'Konfirmasi kata sandi wajib diisi!',
            'password_confirmation.min' => 'Konfirmasi kata sandi minimal harus 8 karakter!',
        ];

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password'=> 'required|min:8|confirmed',
            'password_confirmation' => 'required|min:8',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        // 1. Cari token di database
        $reset = DB::table('temp_tokens')
                    ->where('email', $request->email)
                    ->first();

        if (!$reset) {
            return new ApiResponseResources(false, 'Email tidak ditemukan dalam antrean pengaturan ulang kata sandi.', null, 400);
        }

        // 2. Verifikasi Token (Harus menggunakan Hash::check karena kita menyimpan versi hashed)
        if (!Hash::check($request->token, $reset->token)) {
            return new ApiResponseResources(false, 'Token tidak valid.', null, 400);
        }

        // 3. Periksa Kedaluwarsa Token (60 menit)
        if (Carbon::now()->gt(Carbon::parse($reset->expired_at))) {
            // Hapus token yang sudah kedaluwarsa
            DB::table('temp_tokens')->where('email', $request->email)->delete();
            return new ApiResponseResources(false, 'Token pengaturan ulang kata sandi telah kedaluwarsa.', null, 400);
        }

        // 4. Update password
        $user = User::where('email', $reset->email)->first();
        if (!$user) {
            return new ApiResponseResources(false, 'Pengguna tidak ditemukan.', null, 404);
        }

        // Hash dan update password
        $user->update(['password' => Hash::make($request->password)]);

        // 5. Hapus Token Reset
        DB::table('temp_tokens')->where('email', $reset->email)->delete();

        return new ApiResponseResources(true, 'Kata sandi berhasil diperbarui. Anda sekarang dapat masuk (login).');
    }

    public function updatePassword(Request $request)
    {
        $messages = [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'password.min' => 'Kata sandi minimal harus 8 karakter.',
        ];

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password' => 'required|confirmed|min:8',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        /** @var \App\Models\User $user */ //diisi ini karena fungsi $user->save(); ini error
        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            return new ApiResponseResources(false, 'Kata sandi saat ini salah.', null, 400);
        }

        try {
            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            // Kirim email konfirmasi (opsional)
            try {
                Mail::to($user->email)->send(new PasswordChangedMail($user, 'profile_change'));
            } catch (\Exception $e) {
                Log::error('Failed to send password change notification: ' . $e->getMessage());
            }

            return new ApiResponseResources(true, 'Kata sandi berhasil diubah.', null, 200);

        } catch (\Exception $e) {
            Log::error('Password change failed: ' . $e->getMessage());
            return new ApiResponseResources(false, 'Gagal mengubah kata sandi.', null, 500);
        }
    }
}