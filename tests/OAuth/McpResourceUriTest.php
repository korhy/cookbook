<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\McpResourceUri;
use PHPUnit\Framework\TestCase;

/**
 * The invariants of the one value three mechanisms compare by exact string equality: the RFC 9728
 * document's `resource` member, the `aud` claim on every access token, and phase 5's audience
 * validation.
 *
 * A wrong value here does not fail loudly on its own — it rejects every token with nothing in the
 * logs that says why, and an empty one stamps an empty audience into an otherwise valid JWT. This
 * is where that is made impossible, which is why the rules live on the value rather than in
 * whichever consumer happened to be written first.
 */
final class McpResourceUriTest extends TestCase
{
    public function testTheValueIsReturnedUnchanged(): void
    {
        // Byte-for-byte: everything downstream compares by equality, so normalising anything here
        // would be a silent contract change.
        self::assertSame(
            'https://api.example.test/api/v1/mcp',
            (new McpResourceUri('https://api.example.test/api/v1/mcp'))->value(),
        );
    }

    public function testTheIssuerIsTheOriginWithThePathDropped(): void
    {
        self::assertSame('https://api.example.test', (new McpResourceUri('https://api.example.test/api/v1/mcp'))->issuer());
    }

    public function testTheIssuerKeepsANonDefaultPort(): void
    {
        // The dev stack publishes the app on :8001, so dropping the port would point the
        // published document at a server that is not listening.
        self::assertSame('http://localhost:8001', (new McpResourceUri('http://localhost:8001/api/v1/mcp'))->issuer());
    }

    public function testThePathIsTheSuffixRfc9728Appends(): void
    {
        self::assertSame('/api/v1/mcp', (new McpResourceUri('https://api.example.test/api/v1/mcp'))->path());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableValueProvider(): iterable
    {
        yield 'a trailing slash — the exact-match comparison would reject every token' => ['https://api.example.test/api/v1/mcp/'];
        yield 'no scheme' => ['api.example.test/api/v1/mcp'];
        yield 'a scheme that is not http(s)' => ['ftp://api.example.test/api/v1/mcp'];
        yield 'a query string' => ['https://api.example.test/api/v1/mcp?debug=1'];
        yield 'a fragment' => ['https://api.example.test/api/v1/mcp#mcp'];
        yield 'embedded credentials' => ['https://user:pass@api.example.test/api/v1/mcp'];
        // The quiet one: nothing downstream complains about an empty audience.
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableValueProvider')]
    public function testAnUnusableValueIsRefused(string $value): void
    {
        $this->expectException(\LogicException::class);

        new McpResourceUri($value);
    }
}
