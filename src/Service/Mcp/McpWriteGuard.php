<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Exception\Mcp\McpWriteDeniedException;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Meters and records every MCP write, in front of the tool that performs it.
 *
 * **What changed when OAuth arrived.** This class used to be the *only* gate: the MCP endpoint was
 * public, so it compared a shared secret the tools took as an argument. Authentication and
 * authorization now happen at the HTTP layer — the `mcp_authoring` firewall authenticates the
 * bearer token, {@see \App\Security\McpTokenAudienceVerifier} checks it was issued for this
 * resource, and {@see \App\Security\McpScopeVoter} checks the `mcp:write` scope — so a call that
 * reaches a tool has already proved who it is. That is strictly stronger than the secret, and it
 * is why the secret is gone.
 *
 * Two of the three jobs this class did are **not** replaced by the firewall, which is why it
 * stayed:
 *
 * 1. **Rate limiting**, now keyed on the authenticated identity rather than on a token
 *    fingerprint. A valid grant is not a licence to create 10,000 drafts, and OAuth has nothing
 *    to say about volume.
 * 2. **The audit trail** on the `mcp_audit` channel: which client, acting for which user, wrote
 *    what and when. Authorization proves a caller may write; only the trail says what they did.
 *
 * It signals refusal by throwing rather than returning a status, so that ignoring the result is
 * not something a caller can do by accident.
 */
#[WithMonologChannel('mcp_audit')]
final class McpWriteGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly RateLimiterFactoryInterface $mcpWriteLimiter,
        private readonly RateLimiterFactoryInterface $mcpImportLimiter,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @throws McpWriteDeniedException when the write must not proceed
     */
    public function assertMayWrite(string $tool): void
    {
        $this->meter($tool, $this->mcpWriteLimiter, 'Write rate limit reached. Try again later.');
    }

    /**
     * Same metering, a separate budget.
     *
     * Fetching a URL is not a write, but it does make this server issue an outbound request on a
     * caller's behalf — so it is metered too rather than left unbounded. It gets its own limiter
     * so that importing a page does not consume the budget for creating the recipe it produced.
     *
     * @throws McpWriteDeniedException when the fetch must not proceed
     */
    public function assertMayFetch(string $tool): void
    {
        $this->meter($tool, $this->mcpImportLimiter, 'Import rate limit reached. Try again later.');
    }

    /**
     * @throws McpWriteDeniedException
     */
    private function meter(string $tool, RateLimiterFactoryInterface $operationLimiter, string $rateLimitMessage): void
    {
        $token = $this->security->getToken();
        $client = $this->clientKey();

        if (!$token instanceof OAuth2Token) {
            // Belt and braces, and it must stay: the firewall is what makes this unreachable, and
            // a tool must not start writing because a `security.yaml` edit quietly stopped
            // protecting its endpoint. The authoring MCP server declares `stdio: false` for the
            // same reason — a piped caller has no identity to record here.
            $this->logger->error('MCP write refused: no OAuth access token on the request.', [
                'tool' => $tool,
                'client' => $client,
            ]);

            throw new McpWriteDeniedException('Write access denied.');
        }

        $identity = $this->identityOf($token);

        if (!$operationLimiter->create($identity['key'])->consume(1)->isAccepted()) {
            // The caller is authenticated here, so naming the reason leaks nothing — and a client
            // that cannot tell "throttled" from "refused" will retry forever.
            $this->logger->warning('MCP write refused: operation rate limit reached.', [
                'tool' => $tool,
                'client' => $client,
                ...$identity['context'],
            ]);

            throw new McpWriteDeniedException($rateLimitMessage);
        }

        $this->logger->info('MCP write authorized.', [
            'tool' => $tool,
            'client' => $client,
            ...$identity['context'],
        ]);
    }

    /**
     * The rate-limit key and the audit context for an authenticated caller.
     *
     * **The key is the OAuth client plus the user, not the access token.** An access token lives
     * one hour (`access_token_ttl: PT1H`), so keying a sliding window on it would hand a caller a
     * fresh budget with every refresh — a limit that resets on demand is not a limit. The client
     * and the user both outlive the token.
     *
     * The access token id is recorded as an 8-character SHA-256 prefix, keeping the discipline
     * the shared secret established: enough to tie a burst of calls to one token, useless for
     * recovering it. The client and user identifiers are not credentials and are logged whole —
     * an audit trail that cannot say who acted is not one.
     *
     * @return array{key: string, context: array{oauth_client: string, user: string, access_token_fingerprint: string}}
     */
    private function identityOf(OAuth2Token $token): array
    {
        $client = $token->getOAuthClientId();
        $user = $token->getUserIdentifier();

        return [
            'key' => \sprintf('%s:%s', $client, $user),
            'context' => [
                'oauth_client' => $client,
                'user' => $user,
                'access_token_fingerprint' => substr(hash('sha256', $token->getCredentials()), 0, 8),
            ],
        ];
    }

    private function clientKey(): string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
    }
}
