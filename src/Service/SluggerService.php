<?php

namespace App\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

class SluggerService
{
    public function generateSlug(string $text): string
    {
        $slugger = new AsciiSlugger();
        return strtolower($slugger->slug($text));
    }
}