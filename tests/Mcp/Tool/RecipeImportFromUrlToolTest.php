<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Mcp\Tool\RecipeImportFromUrlTool;
use App\Service\Http\SsrfGuardedFetcher;
use App\Service\Mcp\McpWriteGuard;
use App\Service\Recipe\JsonLdRecipeExtractor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Built from real collaborators with a mocked transport, so the guard, the SSRF checks and the
 * extractor are all genuinely exercised — only the network is faked.
 */
final class RecipeImportFromUrlToolTest extends TestCase
{
    private const TOKEN = 'c2f4a1b8e07d46339a5c1e8b7f20d64a91c3e5b7d8a06f24913b7c5e0a2d4f68';
    private const HOST = '93.184.216.34';

    public function testImportsARecipeWithoutStoringAnything(): void
    {
        $result = ($this->tool($this->pageWithRecipe()))(self::TOKEN, 'https://'.self::HOST.'/cake');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Chocolate Cake', $result['title']);
        $this->assertSame(['200 g flour', '2 eggs'], $result['ingredients']);
        $this->assertSame(['Mix.', 'Bake.'], $result['instructions']);
        $this->assertSame('https://'.self::HOST.'/cake', $result['source_url']);
        // No id and no slug: this tool creates nothing.
        $this->assertArrayNotHasKey('id', $result);
        $this->assertStringContainsString('Nothing has been stored', $result['message']);
    }

    public function testAnUnauthenticatedCallCannotMakeTheServerFetchAnything(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse($this->pageWithRecipe());
        });

        $result = ($this->tool(client: $client))('wrong-token', 'https://'.self::HOST.'/cake');

        $this->assertSame('Write access denied.', $result['error']);
        $this->assertSame(0, $requests, 'The guard must run before any outbound request.');
    }

    public function testANonAllowlistedHostIsRefused(): void
    {
        $result = ($this->tool($this->pageWithRecipe()))(self::TOKEN, 'https://evil.example.com/cake');

        $this->assertStringContainsString('not on the import allowlist', $result['error']);
    }

    public function testTheMetadataServiceIsRefused(): void
    {
        $result = ($this->tool($this->pageWithRecipe()))(self::TOKEN, 'https://169.254.169.254/latest/meta-data/');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('meta-data', $result['error']);
    }

    public function testAPageWithoutARecipeIsReportedCleanly(): void
    {
        $result = ($this->tool('<html><body>no recipe here</body></html>'))(self::TOKEN, 'https://'.self::HOST.'/x');

        $this->assertStringContainsString('does not publish a schema.org Recipe', $result['error']);
    }

    private function tool(?string $body = null, ?MockHttpClient $client = null): RecipeImportFromUrlTool
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));

        $guard = new McpWriteGuard(
            self::TOKEN,
            $this->limiter('mcp_write'),
            $this->limiter('mcp_import'),
            $this->limiter('mcp_write_auth'),
            new Logger('test', [new TestHandler()]),
            $requestStack,
        );

        return new RecipeImportFromUrlTool(
            $guard,
            new SsrfGuardedFetcher(
                $client ?? new MockHttpClient(new MockResponse((string) $body)),
                new Logger('test', [new TestHandler()]),
                self::HOST,
            ),
            new JsonLdRecipeExtractor(),
        );
    }

    private function limiter(string $id): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function pageWithRecipe(): string
    {
        return '<html><head><script type="application/ld+json">'.json_encode([
            '@type' => 'Recipe',
            'name' => 'Chocolate Cake',
            'recipeIngredient' => ['200 g flour', '2 eggs'],
            'recipeInstructions' => ['Mix.', 'Bake.'],
        ]).'</script></head><body></body></html>';
    }
}
