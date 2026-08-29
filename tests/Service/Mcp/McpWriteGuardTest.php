<?php

declare(strict_types=1);

namespace App\Tests\Service\Mcp;

use App\Exception\Mcp\McpWriteDeniedException;
use App\Service\Mcp\McpWriteGuard;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The guard is the only thing standing between the public MCP endpoint and a write, so these are
 * security tests rather than behaviour tests: each one names the attack it forecloses.
 *
 * Built by hand rather than pulled from the container so the configured token and both limits are
 * controllable per case, and so the limiter storage is in-memory and deterministic.
 */
final class McpWriteGuardTest extends TestCase
{
    private const VALID_TOKEN = 'c2f4a1b8e07d46339a5c1e8b7f20d64a91c3e5b7d8a06f24913b7c5e0a2d4f68';

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
    }

    public function testValidTokenIsAuthorized(): void
    {
        $guard = $this->guard();

        $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');

        $this->assertTrue($this->logHandler->hasInfoThatContains('MCP write authorized.'));
    }

    public function testWrongTokenIsDenied(): void
    {
        $guard = $this->guard();

        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Write access denied.');

        $guard->assertMayWrite('wrong-token', 'recipe_create');
    }

    public function testWritesAreDeniedWhenNoTokenIsConfigured(): void
    {
        $guard = $this->guard(configuredToken: '');

        $this->expectException(McpWriteDeniedException::class);
        $guard->assertMayWrite('', 'recipe_create');
    }

    public function testAShortConfiguredTokenIsTreatedAsMisconfigurationAndDenied(): void
    {
        $guard = $this->guard(configuredToken: 'too-short');

        try {
            $guard->assertMayWrite('too-short', 'recipe_create');
            $this->fail('Expected the guard to refuse a sub-minimum token.');
        } catch (McpWriteDeniedException) {
            $this->assertTrue($this->logHandler->hasErrorThatContains('shorter than'));
        }
    }

    /**
     * The refusal message must not tell an unauthenticated caller whether a write surface exists:
     * "disabled" and "wrong token" have to be indistinguishable from outside.
     */
    public function testDisabledAndWrongTokenAreIndistinguishableToTheCaller(): void
    {
        $disabled = $this->denialMessage($this->guard(configuredToken: ''), 'anything');
        $wrong = $this->denialMessage($this->guard(), 'wrong-token');

        $this->assertSame($disabled, $wrong);
    }

    /**
     * Regression test for a real defect: SlidingWindowLimiter::reserve() returns accepted=true
     * unconditionally for a zero-token request, so peeking with isAccepted() made this lockout
     * dead code and left token guessing unthrottled.
     */
    public function testBruteForceLockoutStopsGuessingAfterTheAuthLimit(): void
    {
        $guard = $this->guard(authLimit: 3);

        for ($i = 0; $i < 3; ++$i) {
            $this->denialMessage($guard, 'guess-'.$i);
        }

        // Even the correct token is refused now: the IP is locked out.
        $this->expectException(McpWriteDeniedException::class);
        $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');
    }

    /**
     * The auth limiter is charged only on failure. If it were charged on every attempt, a
     * legitimate client would lock itself out with ordinary use.
     */
    public function testSuccessfulWritesDoNotConsumeTheBruteForceBudget(): void
    {
        $guard = $this->guard(authLimit: 3, writeLimit: 100);

        for ($i = 0; $i < 10; ++$i) {
            $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');
        }

        $this->assertCount(10, $this->logHandler->getRecords());
    }

    public function testWriteRateLimitStopsAnAuthenticatedFlood(): void
    {
        $guard = $this->guard(writeLimit: 3);

        for ($i = 0; $i < 3; ++$i) {
            $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');
        }

        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Write rate limit reached.');
        $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');
    }

    /**
     * The audit trail must be usable without becoming a place the secret leaks to.
     */
    public function testTheTokenNeverReachesTheLog(): void
    {
        $guard = $this->guard();

        $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');
        $this->denialMessage($guard, 'some-wrong-token-value');

        $serialisedLog = json_encode($this->logHandler->getRecords());

        $this->assertNotFalse($serialisedLog);
        $this->assertStringNotContainsString(self::VALID_TOKEN, $serialisedLog);
        $this->assertStringNotContainsString('some-wrong-token-value', $serialisedLog);
        // The fingerprint is what makes the entry correlatable instead.
        $this->assertStringContainsString(substr(hash('sha256', self::VALID_TOKEN), 0, 8), $serialisedLog);
    }

    /**
     * assertMayFetch() shares the token check but not the budget: importing a page must not spend
     * the allowance for creating the recipe it produced.
     */
    public function testFetchAndWriteHaveSeparateBudgets(): void
    {
        $guard = $this->guard(writeLimit: 2);

        for ($i = 0; $i < 2; ++$i) {
            $guard->assertMayFetch(self::VALID_TOKEN, 'recipe_import_from_url');
        }

        // The write budget is untouched by those fetches.
        $guard->assertMayWrite(self::VALID_TOKEN, 'recipe_create');

        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Import rate limit reached.');
        $guard->assertMayFetch(self::VALID_TOKEN, 'recipe_import_from_url');
    }

    public function testFetchIsDeniedWithoutAValidToken(): void
    {
        $guard = $this->guard();

        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Write access denied.');
        $guard->assertMayFetch('wrong-token', 'recipe_import_from_url');
    }

    public function testIsEnabledReflectsTheConfiguredToken(): void
    {
        $this->assertTrue($this->guard()->isEnabled());
        $this->assertFalse($this->guard(configuredToken: '')->isEnabled());
        $this->assertFalse($this->guard(configuredToken: 'too-short')->isEnabled());
    }

    private function guard(
        string $configuredToken = self::VALID_TOKEN,
        int $writeLimit = 20,
        int $authLimit = 10,
    ): McpWriteGuard {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

        return new McpWriteGuard(
            $configuredToken,
            $this->limiterFactory('mcp_write', $writeLimit),
            $this->limiterFactory('mcp_import', $writeLimit),
            $this->limiterFactory('mcp_write_auth', $authLimit),
            new Logger('test', [$this->logHandler]),
            $requestStack,
        );
    }

    private function limiterFactory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    private function denialMessage(McpWriteGuard $guard, string $token): string
    {
        try {
            $guard->assertMayWrite($token, 'recipe_create');
        } catch (McpWriteDeniedException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected the guard to deny the write.');
    }
}
