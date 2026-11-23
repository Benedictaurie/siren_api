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
            'name.required' => 'Nama wajib diisi',
            'name.min' => 'Nama minimal harus 3 karakter!',
            'name.max' => 'Nama tidak boleh lebih dari 100 karakter!',
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid!',
            'email.unique' => 'Email sudah terdaftar!',
            'password.required' => 'Kata sandi wajib diisi!',
            'password.min' => 'Kata sandi minimal harus 8 karakter!',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok',
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

            // GENERATE OTP untuk email verification
            //$this->otpService->generate($request->email);

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
            'otp.required' => 'Kode OTP wajib diisi!',
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
        // Menggunakan stateless karena ini API (Frontend yang akan handle session)
        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        return new ApiResponseResources(true, 'URL redirect Google berhasil dibuat.', ['url' => $url]);
    }

    /**
     * Callback dari Frontend setelah user login via Google
     */
    public function handleGoogleCallback(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access_token' => 'required|string' // Token yang didapat dari Google
        ]);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        try {
            // Ambil data user dari Google berdasarkan token
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->access_token);

            // Cek apakah email sudah terdaftar
            $user = User::where('email', $googleUser->email)->first();

            if ($user) {
                // Jika sudah ada, update google_id dan avatar jika belum ada
                $user->update([
                    'google_id' => $googleUser->id,
                    'avatar' => $user->avatar ?? $googleUser->avatar,
                    'email_verified_at' => $user->email_verified_at ?? now(), // Google email is verified
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
    
    // (Kode forgotPassword, resetPassword, dan updatePassword Anda sebelumnya sudah benar, 
    // cukup biarkan seperti aslinya. Tidak ada perubahan struktur DB yang mengganggu fungsi ini).
}