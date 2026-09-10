<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

class SluggerService
{
    public function generateSlug(string $text): string
    {
        $slugger = new AsciiSlugger();

        // ->lower()->toString() rather than strtolower(): slug() returns an AbstractUnicodeString,
        // and passing that object to strtolower() only worked because weak mode coerced it through
        // __toString(). Under strict_types it is a TypeError.
        return $slugger->slug($text)->lower()->toString();
    }
}
