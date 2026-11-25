<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\McpBearerChallenge;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Gives every failed authentication on the MCP authoring firewall the discovery challenge, and
 * bounds how often a caller may fail.
 *
 * **Why the firewall's `entry_point` is not enough.** An entry point only runs when *no*
 * authenticator claimed the request — here, when there is no `Authorization: Bearer` header at
 * all. A request that presents an expired, revoked, malformed or wrong-audience token is claimed
 * by `OAuth2Authenticator`, and that class answers the failure itself:
 * `onAuthenticationFailure()` returns `new Response($message, 401, ['WWW-Authenticate' =>
 * 'Bearer'])`. A bare `Bearer`, with no `resource_metadata` — so the client that most needs the
 * pointer, the one whose hour-old token just expired, is precisely the one league would not give
 * it to. `LoginFailureEvent` is dispatched after that response is built and its own response
 * wins, which is what lets {@see McpBearerChallenge} replace it.
 *
 * **The flood guard, and what it replaced.** The `mcp_write_auth` limiter used to blunt guessing
 * of `MCP_WRITE_TOKEN`, a 32-character shared secret. That secret is gone and guessing an
 * RSA-signed JWT is not a threat, so the original reason has expired — but the limiter has a real
 * one left. Every rejected token costs this server an RSA signature verification and a revocation
 * lookup in Postgres before it can be refused, so an anonymous caller can make us work by
 * presenting garbage. The budget is per client IP and consumed **only on failure**, so a
 * legitimate client never touches it.
 */
#[AsEventListener(event: LoginFailureEvent::class, dispatcher: 'security.event_dispatcher.mcp_authoring')]
#[WithMonologChannel('mcp_audit')]
final readonly class McpAuthenticationFailureListener
{
    public function __construct(
        private McpBearerChallenge $challenge,
        private RateLimiterFactoryInterface $mcpWriteAuthLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $client = $request->getClientIp() ?? 'unknown';

        $this->logger->warning('MCP authoring request refused: authentication failed.', [
            'client' => $client,
            // The exception class, never its message: league builds that message from the
            // underlying JWT failure, and it is of no use to a caller who cannot be trusted with
            // a hint about which part of their token is wrong.
            'reason' => $event->getException()::class,
        ]);

        $limit = $this->mcpWriteAuthLimiter->create($client)->consume(1);

        if (!$limit->isAccepted()) {
            $this->logger->warning('MCP authoring request refused: too many failed authentications.', [
                'client' => $client,
            ]);

            $event->setResponse(new JsonResponse(
                ['error' => 'too_many_requests'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
            ));

            return;
        }

        $event->setResponse($this->challenge->start($request, $event->getException()));
    }
}
