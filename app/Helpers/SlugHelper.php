<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class SlugHelper
{
    public static function generateUniqueSlug($model, $source)
    {
        $slug = Str::slug($source);
        $originalSlug = $slug;
        $counter = 1;

        while ($model::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}