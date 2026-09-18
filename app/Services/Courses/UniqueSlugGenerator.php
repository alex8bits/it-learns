<?php

declare(strict_types=1);

namespace App\Services\Courses;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UniqueSlugGenerator
{
    /**
     * Build a slug for the title that is free in the given model's
     * `slug` column.
     *
     * `Str::slug` transliterates most alphabets, but a title consisting
     * solely of symbols (`!!!`) or written in a script iconv cannot
     * transliterate yields an empty string — a lowercased uuid is used
     * as the fallback base. Collisions append `-2`, `-3`, ... suffixes
     * until the query finds a free value.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function generate(string $title, string $modelClass): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = Str::lower(Str::uuid()->toString());
        }

        $candidate = $base;
        $suffix = 1;

        while ($modelClass::query()->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = "{$base}-{$suffix}";
        }

        return $candidate;
    }
}
