<?php

namespace App\Tests\Service;

use App\Service\SluggerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SluggerServiceTest extends TestCase
{
    private SluggerService $slugger;

    protected function setUp(): void
    {
        $this->slugger = new SluggerService();
    }

    public function testGenerateSlugFromSimpleString(): void
    {
        $slug = $this->slugger->generateSlug('Croissant au jambon');
        $this->assertEquals('croissant-au-jambon', $slug);
    }

    public function testGenerateSlugWithAccents(): void
    {
        $slug = $this->slugger->generateSlug('Crème brûlée');
        $this->assertEquals('creme-brulee', $slug);
    }

    public function testGenerateSlugWithSpecialCharacters(): void
    {
        $slug = $this->slugger->generateSlug('Fondant à la crème de marrons & au chocolat!');
        $this->assertEquals('fondant-a-la-creme-de-marrons-au-chocolat', $slug);
    }

    #[DataProvider('slugProvider')]
    public function testVariousSlugGenerations(string $input, string $expected): void
    {
        $this->assertEquals($expected, $this->slugger->generateSlug($input));
    }
    
    static function slugProvider(): array
    {
        return [
            ['Pizza Margherita', 'pizza-margherita'],
            ['Tarte aux pommes', 'tarte-aux-pommes'],
            ['   Spaces   ', 'spaces'],
            ['UPPERCASE', 'uppercase'],
        ];
    }
}