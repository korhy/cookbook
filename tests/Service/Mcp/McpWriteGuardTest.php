<?php

declare(strict_types=1);

namespace App\Tests\Service\Mcp;

use App\Exception\Mcp\McpWriteDeniedException;
use App\Service\Mcp\McpWriteGuard;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The guard no longer decides *whether* a caller may write — the `mcp_authoring` firewall does
 * that, and the shared secret this class used to compare is gone. What it still owns is metering
 * and the audit trail, and both are security properties in their own right: a valid OAuth grant
 * must not be a licence to create an unbounded number of drafts, and an authorized write that
 * leaves no record is not auditable.
 *
 * Built by hand rather than pulled from the container so the limits are controllable per case and
 * the limiter storage is in-memory and deterministic.
 */
final class McpWriteGuardTest extends TestCase
{
    private const CLIENT_ID = 'cookbook-mcp-authoring';

    private const USER = 'admin';

    private const ACCESS_TOKEN_ID = 'f3c1a9d47b2e40558c6a1d93e07b2f84';

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
    }

    public function testAnAuthenticatedWriteIsRecorded(): void
    {
        $this->guard()->assertMayWrite('recipe_create');

        $this->assertTrue($this->logHandler->hasInfoThatContains('MCP write authorized.'));
    }

    /**
     * The audit trail is the point of the `mcp_audit` channel: authorization proves a caller
     * *may* write, only the record says what they did. "Someone wrote this" is not an audit
     * entry — the client and the user it acted for are.
     */
    public function testTheAuditEntryNamesTheClientAndTheUser(): void
    {
        $this->guard()->assertMayWrite('recipe_create');

        $context = $this->logHandler->getRecords()[0]['context'];

        $this->assertSame('recipe_create', $context['tool']);
        $this->assertSame(self::CLIENT_ID, $context['oauth_client']);
        $this->assertSame(self::USER, $context['user']);
        $this->assertSame('203.0.113.7', $context['client']);
    }

    /**
     * The discipline the shared secret established, kept now that the credential is a JWT: the
     * trail must be correlatable without becoming a place a credential leaks to.
     */
    public function testTheAccessTokenIdIsFingerprintedRatherThanLogged(): void
    {
        $this->guard()->assertMayWrite('recipe_create');

        $serialisedLog = json_encode($this->logHandler->getRecords());

        $this->assertNotFalse($serialisedLog);
        $this->assertStringNotContainsString(self::ACCESS_TOKEN_ID, $serialisedLog);
        $this->assertStringContainsString(substr(hash('sha256', self::ACCESS_TOKEN_ID), 0, 8), $serialisedLog);
    }

    public function testWriteRateLimitStopsAnAuthorizedFlood(): void
    {
        $guard = $this->guard(writeLimit: 3);

        for ($i = 0; $i < 3; ++$i) {
            $guard->assertMayWrite('recipe_create');
        }

        // Named, not uniform: the caller is authenticated here, so the reason leaks nothing — and
        // a client that cannot tell "throttled" from "refused" retries forever.
        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Write rate limit reached.');
        $guard->assertMayWrite('recipe_create');
    }

    /**
     * The window is keyed on the client and the user, NOT on the access token — which lives one
     * hour. Keying it on the token would hand a caller a fresh budget with every refresh, which
     * is not a limit at all.
     */
    public function testTheRateLimitSurvivesARefreshedAccessToken(): void
    {
        $storage = new InMemoryStorage();
        $writeLimiter = $this->limiterFactory('mcp_write', 2, $storage);

        $first = $this->guard(writeLimit: 2, writeLimiter: $writeLimiter);
        $first->assertMayWrite('recipe_create');
        $first->assertMayWrite('recipe_create');

        // Same client, same user, brand new access token id — as after a refresh.
        $afterRefresh = $this->guard(
            writeLimit: 2,
            writeLimiter: $writeLimiter,
            accessTokenId: 'a-completely-different-token-id',
        );

        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Write rate limit reached.');
        $afterRefresh->assertMayWrite('recipe_create');
    }

    /**
     * assertMayFetch() shares the metering but not the budget: importing a page must not spend
     * the allowance for creating the recipe it produced.
     */
    public function testFetchAndWriteHaveSeparateBudgets(): void
    {
        $guard = $this->guard(writeLimit: 2);

        for ($i = 0; $i < 2; ++$i) {
            $guard->assertMayFetch('recipe_import_from_url');
        }

        // The write budget is untouched by those fetches.
        $guard->assertMayWrite('recipe_create');

        $this->expectException(McpWriteDeniedException::class);
        $this->expectExceptionMessage('Import rate limit reached.');
        $guard->assertMayFetch('recipe_import_from_url');
    }

    /**
     * Defence in depth, and it must stay: the firewall is what makes this unreachable, so this
     * case is what a `security.yaml` edit that quietly stopped protecting the endpoint would
     * look like from inside a tool. It must not be a write.
     */
    public function testAWriteWithNoOAuthTokenIsRefusedAndLoggedAsAnError(): void
    {
        $guard = $this->guard(token: false);

        try {
            $guard->assertMayWrite('recipe_create');
            $this->fail('Expected the guard to refuse a write with no OAuth token on the request.');
        } catch (McpWriteDeniedException $e) {
            $this->assertSame('Write access denied.', $e->getMessage());
            $this->assertTrue($this->logHandler->hasErrorThatContains('no OAuth access token'));
        }
    }

    /**
     * The admin session and the Lexik JWT firewall both produce real identities, but neither is
     * an OAuth grant and neither carries a scope a resource owner approved. Only an OAuth2Token
     * opens this path.
     */
    public function testANonOAuthTokenIsRefused(): void
    {
        $sessionToken = new UsernamePasswordToken(
            new InMemoryUser(self::USER, null, ['ROLE_ADMIN']),
            'admin',
            ['ROLE_ADMIN'],
        );

        $this->expectException(McpWriteDeniedException::class);
        $this->guard(token: $sessionToken)->assertMayWrite('recipe_create');
    }

    /**
     * @param TokenInterface|false|null $token the authenticated token: an OAuth2Token by default,
     *                                         false for "nothing authenticated at all"
     */
    private function guard(
        int $writeLimit = 20,
        TokenInterface|false|null $token = null,
        ?RateLimiterFactory $writeLimiter = null,
        string $accessTokenId = self::ACCESS_TOKEN_ID,
    ): McpWriteGuard {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/v1/mcp-authoring', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

        $tokenStorage = new TokenStorage();

        if (false !== $token) {
            $tokenStorage->setToken($token ?? $this->oauthToken($accessTokenId));
        }

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);

        return new McpWriteGuard(
            new Security($container),
            $writeLimiter ?? $this->limiterFactory('mcp_write', $writeLimit),
            $this->limiterFactory('mcp_import', $writeLimit),
            new Logger('test', [$this->logHandler]),
            $requestStack,
        );
    }

    private function oauthToken(string $accessTokenId): OAuth2Token
    {
        return new OAuth2Token(
            new InMemoryUser(self::USER, null, ['ROLE_ADMIN']),
            $accessTokenId,
            self::CLIENT_ID,
            ['mcp:write'],
            'ROLE_OAUTH2_',
        );
    }

    private function limiterFactory(string $id, int $limit, ?InMemoryStorage $storage = null): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            $storage ?? new InMemoryStorage(),
        );
    }
}
