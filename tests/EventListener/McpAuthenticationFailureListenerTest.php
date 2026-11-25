<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\McpAuthenticationFailureListener;
use App\OAuth\DiscoveryMetadataProvider;
use App\OAuth\McpResourceUri;
use App\Security\McpBearerChallenge;
use League\Bundle\OAuth2ServerBundle\Security\Exception\OAuth2AuthenticationFailedException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * The listener that gives a failed authentication on the authoring firewall something a client can
 * act on.
 *
 * It exists because the firewall's `entry_point` never runs for these requests: league's
 * `OAuth2Authenticator` claims any request with a `Bearer ` header and answers its own failures
 * with `new Response($message, 401, ['WWW-Authenticate' => 'Bearer'])` — a bare challenge with no
 * `resource_metadata`. So the client whose hour-old token has just expired, the one that most needs
 * the discovery pointer, is precisely the one that would not be given it.
 */
final class McpAuthenticationFailureListenerTest extends TestCase
{
    private const RESOURCE_URI = 'https://api.example.test/api/v1/mcp-authoring';

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
    }

    public function testAFailureIsAnsweredWithTheDiscoveryChallenge(): void
    {
        $event = $this->event();

        ($this->listener())($event);

        $response = $event->getResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $challenge = (string) $response->headers->get('WWW-Authenticate');

        self::assertStringContainsString('error="invalid_token"', $challenge);
        self::assertStringContainsString('resource_metadata=', $challenge);
    }

    /**
     * Bounded, because refusing a bad token is not free: it costs an RSA signature verification
     * and a revocation lookup in Postgres every time, so an anonymous caller can make this server
     * work by presenting garbage. The budget is per client IP.
     */
    public function testRepeatedFailuresFromOneClientEventuallyGet429(): void
    {
        $listener = $this->listener(authLimit: 3);

        for ($i = 0; $i < 3; ++$i) {
            $event = $this->event();
            $listener($event);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()?->getStatusCode());
        }

        $event = $this->event();
        $listener($event);

        $response = $event->getResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
    }

    /**
     * The budget is per IP, so one noisy client must not lock out another.
     */
    public function testTheBudgetIsPerClient(): void
    {
        $listener = $this->listener(authLimit: 2);

        foreach (['198.51.100.1', '198.51.100.1', '198.51.100.1'] as $ip) {
            $event = $this->event($ip);
            $listener($event);
        }

        $other = $this->event('198.51.100.2');
        $listener($other);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $other->getResponse()?->getStatusCode());
    }

    /**
     * The trail records that a refusal happened and where from, and nothing else. League builds
     * its exception message out of the underlying JWT failure, and a caller who cannot be trusted
     * with the write path cannot be trusted with a hint about which part of their token is wrong.
     */
    public function testTheRefusalIsAuditedWithoutTheExceptionMessage(): void
    {
        ($this->listener())($this->event());

        $records = $this->logHandler->getRecords();

        self::assertNotSame([], $records);
        self::assertSame('203.0.113.7', $records[0]->context['client']);
        self::assertSame(OAuth2AuthenticationFailedException::class, $records[0]->context['reason']);

        $serialised = (string) json_encode($records);

        self::assertStringNotContainsString('expired at', $serialised);
    }

    private function listener(int $authLimit = 10): McpAuthenticationFailureListener
    {
        return new McpAuthenticationFailureListener(
            new McpBearerChallenge($this->metadataProvider()),
            new RateLimiterFactory(
                ['id' => 'mcp_write_auth', 'policy' => 'sliding_window', 'limit' => $authLimit, 'interval' => '15 minutes'],
                new InMemoryStorage(),
            ),
            new Logger('test', [$this->logHandler]),
        );
    }

    private function event(string $clientIp = '203.0.113.7'): LoginFailureEvent
    {
        $request = Request::create(
            '/api/v1/mcp-authoring',
            'POST',
            server: ['REMOTE_ADDR' => $clientIp, 'HTTP_AUTHORIZATION' => 'Bearer an-expired-token'],
        );

        return new LoginFailureEvent(
            OAuth2AuthenticationFailedException::create('The resource server rejected the request. Token expired at 2026-01-01.'),
            $this->createStub(AuthenticatorInterface::class),
            $request,
            null,
            'mcp_authoring',
        );
    }

    private function metadataProvider(): DiscoveryMetadataProvider
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/.well-known/oauth-protected-resource/api/v1/mcp-authoring');

        return new DiscoveryMetadataProvider(
            $urlGenerator,
            new McpResourceUri(self::RESOURCE_URI),
            ['authorization_code', 'refresh_token'],
            ['mcp:write'],
        );
    }
}
