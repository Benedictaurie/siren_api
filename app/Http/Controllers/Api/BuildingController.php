<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResources;
use App\Models\Building;
use App\Models\BuildingRate; // Tambahan untuk handle rates
use App\Helpers\SlugHelper; // Pastikan helper ini sudah dibuat
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class BuildingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Hanya tampilkan gedung yang statusnya bukan inactive (sesuai bisnis proses)
        $buildings = Building::where('status', '!=', 'inactive')->get();
        $buildings->load(['images', 'rates']); // Load relasi images dan rates
        return new ApiResponseResources(true, 'Data gedung berhasil diambil.', $buildings);
    }

    public function adminIndex()
    {
        $buildings = Building::all();
        $buildings->load(['images', 'rates']);
        return new ApiResponseResources(true, 'Data gedung berhasil diambil.', $buildings);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $messages = [
            'name.required' => 'Nama gedung wajib diisi.',
            'name.max' => 'Nama gedung tidak boleh lebih dari 255 karakter!',
            'address.required' => 'Alamat wajib diisi.',
            'description.required' => 'Deskripsi wajib diisi.',
            'capacity.numeric' => 'Kapasitas harus berupa angka.',
            'status.in' => 'Status harus berupa active, maintenance, atau inactive.',
            'image.array' => 'Format gambar harus berupa array.',
            'image.max' => 'Anda hanya dapat mengunggah maksimal :max gambar.',
            'image.*.image' => 'File yang diunggah harus berupa gambar (jpeg, png, jpg, gif, svg, atau webp).',
            'image.*.mimes' => 'Format file gambar tidak valid. Hanya format :values yang diperbolehkan.',
            'image.*.max' => 'Ukuran file gambar tidak boleh melebihi :max kilobyte.',
            // Validasi untuk Rates (Tarif)
            'rates.required' => 'Minimal satu tarif sewa wajib diisi.',
            'rates.array' => 'Format tarif sewa harus berupa array.',
            'rates.min' => 'Minimal satu tarif sewa wajib diisi.',
            'rates.*.category.required' => 'Kategori tarif wajib diisi.',
            'rates.*.category.in' => 'Kategori tarif harus berupa commercial, social, atau all.',
            'rates.*.price.required' => 'Harga tarif wajib diisi.',
            'rates.*.price.numeric' => 'Harga tarif harus berupa angka.',
            'rates.*.min_hours.integer' => 'Minimal jam harus berupa bilangan bulat.',
            'rates.*.max_hours.integer' => 'Maksimal jam harus berupa bilangan bulat.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'address' => 'required|string',
            'description' => 'required|string',
            'capacity' => 'nullable|numeric',
            'status' => 'nullable|in:active,maintenance,inactive',
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            // Validasi Rates (Wajib diisi minimal 1)
            'rates' => 'required|array|min:1',
            'rates.*.category' => 'required|in:commercial,social,all',
            'rates.*.price' => 'required|numeric',
            'rates.*.min_hours' => 'nullable|integer',
            'rates.*.max_hours' => 'nullable|integer',
            'rates.*.duration_type' => 'nullable|string',
            'rates.*.note' => 'nullable|string',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        DB::beginTransaction();
        try {
            // 1. Create Building
            $building = Building::create([
                'name' => $request->name,
                'slug' => SlugHelper::generateUniqueSlug(Building::class, $request->name),
                'address' => $request->address,
                'description' => $request->description,
                'capacity' => $request->capacity,
                'status' => $request->status ?? 'active',
            ]);

            // 2. Simpan Rates (Tarif Sewa)
            if ($request->has('rates')) {
                foreach ($request->rates as $rateData) {
                    $building->rates()->create([
                        'category' => $rateData['category'],
                        'price' => $rateData['price'],
                        'min_hours' => $rateData['min_hours'] ?? null,
                        'max_hours' => $rateData['max_hours'] ?? null,
                        'duration_type' => $rateData['duration_type'] ?? 'per_day',
                        'note' => $rateData['note'] ?? null,
                    ]);
                }
            }

            // 3. Simpan Gambar Menggunakan Relasi Polimorfik
            if ($request->hasFile("image")) {
                foreach ($request->file('image') as $imageFile) {
                    $path = $imageFile->store('buildings/images', 'public');

                    $building->images()->create([
                        'image' => $path,
                    ]);
                }
            }

            DB::commit();

            return new ApiResponseResources(true, 'Gedung berhasil disimpan!', $building->load(['images', 'rates']));
        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Gagal menyimpan data gedung! ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return new ApiResponseResources(false, 'Gedung tidak ditemukan!', null, 404);
        }

        return new ApiResponseResources(true, 'Data gedung ditemukan!', $building->load(['images', 'rates', 'reviews']));
    }

    /**
     * Display the specified resource by Slug.
     */
    public function showBySlug($slug)
    {
        $building = Building::where('slug', $slug)
            ->where('status', '!=', 'inactive') // Hanya tampilkan yang tidak inactive
            ->first();

        if (!$building) {
            return new ApiResponseResources(false, 'Gedung tidak ditemukan!', null, 404);
        }

        return new ApiResponseResources(true, 'Data gedung ditemukan!', $building->load(['images', 'rates', 'reviews']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return new ApiResponseResources(false, 'Gedung tidak ditemukan!', null, 404);
        }

        $messages = [
            'name.required' => 'Nama gedung wajib diisi.',
            'name.max' => 'Nama gedung tidak boleh lebih dari 255 karakter!',
            'address.required' => 'Alamat wajib diisi.',
            'description.required' => 'Deskripsi wajib diisi.',
            'capacity.numeric' => 'Kapasitas harus berupa angka.',
            'status.in' => 'Status harus berupa active, maintenance, atau inactive.',
            'image.array' => 'Format gambar harus berupa array.',
            'image.max' => 'Anda hanya dapat mengunggah maksimal :max gambar.',
            'image.*.image' => 'File yang diunggah harus berupa gambar.',
            'image.*.mimes' => 'Format file gambar tidak valid. Hanya format :values yang diperbolehkan.',
            'image.*.max' => 'Ukuran file gambar tidak boleh melebihi :max kilobyte.',
            'rates.array' => 'Format tarif sewa harus berupa array.',
            'rates.*.category.required_with' => 'Kategori tarif wajib diisi.',
            'rates.*.category.in' => 'Kategori tarif harus berupa commercial, social, atau all.',
            'rates.*.price.required_with' => 'Harga tarif wajib diisi.',
            'rates.*.price.numeric' => 'Harga tarif harus berupa angka.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'address' => 'required|string',
            'description' => 'required|string',
            'capacity' => 'nullable|numeric',
            'status' => 'nullable|in:active,maintenance,inactive',
            'image' => 'nullable|array|max:6',
            'image.*' => 'image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            // Validasi Rates (Opsional saat update, jika dikirim akan ditimpa)
            'rates' => 'nullable|array',
            'rates.*.category' => 'required_with:rates|in:commercial,social,all',
            'rates.*.price' => 'required_with:rates|numeric',
        ], $messages);

        if ($validator->fails()) {
            return new ApiResponseResources(false, $validator->errors(), null, 422);
        }

        DB::beginTransaction();
        try {
            // 1. Update Building Data
            $building->update([
                'name' => $request->name,
                'address' => $request->address,
                'description' => $request->description,
                'capacity' => $request->capacity,
                'status' => $request->status ?? $building->status,
            ]);

            // 2. Update Rates (Hapus yang lama, buat yang baru jika ada request)
            if ($request->has('rates') && is_array($request->rates)) {
                $building->rates()->delete(); // Hapus tarif lama
                foreach ($request->rates as $rateData) {
                    $building->rates()->create([
                        'category' => $rateData['category'],
                        'price' => $rateData['price'],
                        'min_hours' => $rateData['min_hours'] ?? null,
                        'max_hours' => $rateData['max_hours'] ?? null,
                        'duration_type' => $rateData['duration_type'] ?? 'per_day',
                        'note' => $rateData['note'] ?? null,
                    ]);
                }
            }

            // 3. Perbarui Gambar Menggunakan Relasi Polimorfik
            if ($request->hasFile("image")) {
                // Hapus gambar lama
                foreach ($building->images as $oldImage) {
                    Storage::disk('public')->delete($oldImage->image);
                    $oldImage->delete();
                }

                // Simpan gambar baru
                foreach ($request->file('image') as $imageFile) {
                    $path = $imageFile->store('buildings/images', 'public');
                    $building->images()->create([
                        'image' => $path,
                    ]);
                }
            }

            DB::commit();

            return new ApiResponseResources(true, 'Data gedung berhasil diperbarui!', $building->load(['images', 'rates']));
        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Gagal memperbarui data gedung! ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return new ApiResponseResources(false, 'Gedung tidak ditemukan!', null, 404);
        }

        DB::beginTransaction();
        try {
            // Hapus gambar dari storage dan database menggunakan relasi
            foreach ($building->images as $image) {
                Storage::disk('public')->delete($image->image);
                $image->delete();
            }

            // Hapus rates (Foreign key biasanya sudah onDelete cascade, tapi explicit lebih aman)
            $building->rates()->delete();

            $building->delete();

            DB::commit();
            return new ApiResponseResources(true, 'Gedung berhasil dihapus!', null, 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Gagal menghapus data gedung! ' . $e->getMessage(), null, 500);
        }
    }
}