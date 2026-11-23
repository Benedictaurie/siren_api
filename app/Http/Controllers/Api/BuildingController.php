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
    // Jika nanti ada BuildingAvailabilityService, bisa ditaruh di constructor seperti contoh ActivityPackageController
    // public function __construct()
    // {
    //     // 
    // }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Hanya tampilkan gedung yang statusnya bukan inactive (sesuai bisnis proses)
        $buildings = Building::where('status', '!=', 'inactive')->get();
        $buildings->load(['images', 'rates']); // Load relasi images dan rates
        return new ApiResponseResources(true, 'Buildings retrieved successfully', $buildings);
    }

    public function adminIndex()
    {
        $buildings = Building::all();
        $buildings->load(['images', 'rates']);
        return new ApiResponseResources(true, 'Buildings retrieved successfully', $buildings);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $messages = [
            'name.required' => 'Name is required',
            'name.max' => 'Name may not be greater than 255 characters!',
            'address.required' => 'Address is required',
            'description.required' => 'Fill the description here',
            'capacity.numeric' => 'Capacity must be entered as a number',
            'status.in' => 'Status must be active, maintenance, or inactive',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image' => 'The uploaded file must be an image (jpeg, png, jpg, gif, or svg).',
            'image.*.mimes' => 'The image file format is invalid. Only :values formats are allowed.',
            'image.*.max' => 'The image file size may not exceed :max kilobytes.',
            // Validasi untuk Rates (Tarif)
            'rates.required' => 'At least one rate is required',
            'rates.array' => 'Rates must be an array',
            'rates.*.category.required' => 'Rate category is required',
            'rates.*.category.in' => 'Rate category must be commercial, social, or all',
            'rates.*.price.required' => 'Rate price is required',
            'rates.*.price.numeric' => 'Rate price must be entered as a number',
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

            return new ApiResponseResources(true, 'Building Saved Successfully!', $building->load(['images', 'rates']));

        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Failed to save Building! ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return new ApiResponseResources(false, 'Building Not Found!', null, 404);
        }

        return new ApiResponseResources(true, 'Building Found!', $building->load(['images', 'rates', 'reviews']));
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
            return new ApiResponseResources(false, 'Building Not Found!', null, 404);
        }

        return new ApiResponseResources(true, 'Building Found!', $building->load(['images', 'rates', 'reviews']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return new ApiResponseResources(false, 'Building Not Found!', null, 404);
        }

        $messages = [
            'name.required' => 'Name is required',
            'name.max' => 'Name may not be greater than 255 characters!',
            'address.required' => 'Address is required',
            'description.required' => 'Fill the description here',
            'capacity.numeric' => 'Capacity must be entered as a number',
            'status.in' => 'Status must be active, maintenance, or inactive',
            'image.array' => 'The image field must be an array.',
            'image.max' => 'You can only upload a maximum of :max images.',
            'image.*.image' => 'The uploaded file must be an image.',
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

            return new ApiResponseResources(true, 'Building Successfully Updated!', $building->load(['images', 'rates']));

        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Failed to update Building! ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(string $id)
    {
        $building = Building::find($id);

        if (!$building) {
            return new ApiResponseResources(false, 'Building Not Found!', null, 404);
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
            return new ApiResponseResources(true, 'Building Successfully Deleted!', null, 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return new ApiResponseResources(false, 'Failed to delete Building! ' . $e->getMessage(), null, 500);
        }
    }
}