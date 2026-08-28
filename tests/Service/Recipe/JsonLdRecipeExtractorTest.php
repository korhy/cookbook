<?php

declare(strict_types=1);

namespace App\Tests\Service\Recipe;

use App\Exception\Mcp\UrlFetchRejectedException;
use App\Service\Recipe\JsonLdRecipeExtractor;
use PHPUnit\Framework\TestCase;

final class JsonLdRecipeExtractorTest extends TestCase
{
    private JsonLdRecipeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new JsonLdRecipeExtractor();
    }

    public function testExtractsATopLevelRecipe(): void
    {
        $result = $this->extractor->extract($this->page([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Chocolate Cake',
            'description' => 'Rich   and  dark.',
            'totalTime' => 'PT1H30M',
            'recipeCategory' => ['Dessert'],
            'recipeIngredient' => ['200 g flour', '2 eggs'],
            'recipeInstructions' => ['Mix.', 'Bake.'],
        ]));

        $this->assertSame('Chocolate Cake', $result['title']);
        $this->assertSame('Rich and dark.', $result['description']);
        $this->assertSame(90, $result['duration']);
        $this->assertSame('Dessert', $result['category']);
        $this->assertSame(['200 g flour', '2 eggs'], $result['ingredients']);
        $this->assertSame(['Mix.', 'Bake.'], $result['instructions']);
    }

    public function testFindsARecipeInsideAGraph(): void
    {
        $result = $this->extractor->extract($this->page([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebSite', 'name' => 'Some site'],
                ['@type' => 'Recipe', 'name' => 'Soup', 'recipeIngredient' => ['water']],
            ],
        ]));

        $this->assertSame('Soup', $result['title']);
    }

    public function testHandlesATypeArray(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => ['Recipe', 'NewsArticle'],
            'name' => 'Dual typed',
        ]));

        $this->assertSame('Dual typed', $result['title']);
    }

    public function testExtractsHowToStepInstructions(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe',
            'name' => 'Steps',
            'recipeInstructions' => [
                ['@type' => 'HowToStep', 'text' => 'Chop the onion.'],
                ['@type' => 'HowToStep', 'text' => 'Fry it.'],
            ],
        ]));

        $this->assertSame(['Chop the onion.', 'Fry it.'], $result['instructions']);
    }

    public function testFlattensHowToSections(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe',
            'name' => 'Sectioned',
            'recipeInstructions' => [
                ['@type' => 'HowToSection', 'itemListElement' => [
                    ['@type' => 'HowToStep', 'text' => 'Make the dough.'],
                    ['@type' => 'HowToStep', 'text' => 'Rest it.'],
                ]],
                ['@type' => 'HowToStep', 'text' => 'Bake.'],
            ],
        ]));

        $this->assertSame(['Make the dough.', 'Rest it.', 'Bake.'], $result['instructions']);
    }

    public function testSplitsASingleInstructionBlobOnNewlines(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe',
            'name' => 'Blob',
            'recipeInstructions' => "Mix.\nBake.\n\nServe.",
        ]));

        $this->assertSame(['Mix.', 'Bake.', 'Serve.'], $result['instructions']);
    }

    public function testFallsBackToCookTimeWhenTotalTimeIsAbsent(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe', 'name' => 'Timed', 'cookTime' => 'PT45M',
        ]));

        $this->assertSame(45, $result['duration']);
    }

    public function testAnUnparseableDurationBecomesNull(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe', 'name' => 'Bad time', 'totalTime' => 'about an hour',
        ]));

        $this->assertNull($result['duration']);
    }

    public function testDecodesHtmlEntities(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe', 'name' => 'Salt &amp; Pepper',
        ]));

        $this->assertSame('Salt & Pepper', $result['title']);
    }

    /**
     * Pages routinely carry several JSON-LD blocks and one being broken must not hide the others.
     */
    public function testSkipsAMalformedBlockAndKeepsLooking(): void
    {
        $html = '<html><head>'
            .'<script type="application/ld+json">{ this is not json }</script>'
            .'<script type="application/ld+json">'.json_encode(['@type' => 'Recipe', 'name' => 'Survivor']).'</script>'
            .'</head><body></body></html>';

        $this->assertSame('Survivor', $this->extractor->extract($html)['title']);
    }

    public function testListsAreCappedAtFifty(): void
    {
        $result = $this->extractor->extract($this->page([
            '@type' => 'Recipe',
            'name' => 'Long',
            'recipeIngredient' => array_map(static fn (int $i): string => 'item '.$i, range(1, 80)),
        ]));

        $this->assertCount(50, $result['ingredients']);
    }

    public function testAPageWithoutARecipeIsRejected(): void
    {
        $this->expectException(UrlFetchRejectedException::class);
        $this->expectExceptionMessage('does not publish a schema.org Recipe');
        $this->extractor->extract($this->page(['@type' => 'NewsArticle', 'name' => 'Not a recipe']));
    }

    public function testAPageWithNoJsonLdAtAllIsRejected(): void
    {
        $this->expectException(UrlFetchRejectedException::class);
        $this->extractor->extract('<html><body><h1>Just HTML</h1></body></html>');
    }

    /**
     * @param array<string, mixed> $jsonLd
     */
    private function page(array $jsonLd): string
    {
        return '<html><head><script type="application/ld+json">'
            .json_encode($jsonLd, \JSON_UNESCAPED_SLASHES)
            .'</script></head><body></body></html>';
    }
}
