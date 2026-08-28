<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Exception\Mcp\McpWriteDeniedException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The single authorization gate in front of every MCP write tool.
 *
 * The MCP endpoint is public and unauthenticated (`PUBLIC_ACCESS` in security.yaml), so a write
 * tool cannot lean on the firewall. This class is the substitute, and it exists as one service so
 * that no tool can implement four of the five checks and forget the fifth.
 *
 * It signals refusal by throwing rather than returning a status, so that ignoring the result is not
 * something a caller can do by accident.
 */
#[WithMonologChannel('mcp_audit')]
final class McpWriteGuard
{
    /**
     * A token shorter than this is treated as a misconfiguration rather than a secret. Refusing
     * outright beats quietly running with a guessable shared secret.
     */
    private const MINIMUM_TOKEN_LENGTH = 32;

    /**
     * Uniform refusal message. It must stay the same for "disabled", "wrong token" and
     * "auth throttled" — see McpWriteDeniedException.
     */
    private const PUBLIC_DENIAL = 'Write access denied.';

    public function __construct(
        #[Autowire(env: 'MCP_WRITE_TOKEN')]
        private readonly string $writeToken,
        private readonly RateLimiterFactoryInterface $mcpWriteLimiter,
        private readonly RateLimiterFactoryInterface $mcpImportLimiter,
        private readonly RateLimiterFactoryInterface $mcpWriteAuthLimiter,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * True when a usable token is configured. Lets a tool stay out of the advertised tool list
     * rather than advertising a capability that will always refuse.
     */
    public function isEnabled(): bool
    {
        return \strlen($this->writeToken) >= self::MINIMUM_TOKEN_LENGTH;
    }

    /**
     * @throws McpWriteDeniedException when the write must not proceed
     */
    public function assertMayWrite(#[\SensitiveParameter] string $token, string $tool): void
    {
        $this->assertAuthorized($token, $tool, $this->mcpWriteLimiter, 'Write rate limit reached. Try again later.');
    }

    /**
     * Same gate, a separate budget.
     *
     * Fetching a URL is not a write, but it does make this server issue an outbound request on a
     * caller's behalf — so it is gated too rather than left anonymous. It gets its own limiter so
     * that importing a page does not consume the budget for creating the recipe it produced.
     *
     * @throws McpWriteDeniedException when the fetch must not proceed
     */
    public function assertMayFetch(#[\SensitiveParameter] string $token, string $tool): void
    {
        $this->assertAuthorized($token, $tool, $this->mcpImportLimiter, 'Import rate limit reached. Try again later.');
    }

    /**
     * @throws McpWriteDeniedException
     */
    private function assertAuthorized(
        #[\SensitiveParameter] string $token,
        string $tool,
        RateLimiterFactoryInterface $operationLimiter,
        string $rateLimitMessage,
    ): void {
        $client = $this->clientKey();

        if ('' === $this->writeToken) {
            // Not an attack: the expected state of a fresh deploy. Info, not warning.
            $this->logger->info('MCP write refused: no MCP_WRITE_TOKEN configured.', [
                'tool' => $tool,
                'client' => $client,
            ]);

            throw new McpWriteDeniedException(self::PUBLIC_DENIAL);
        }

        if (!$this->isEnabled()) {
            $this->logger->error('MCP write refused: MCP_WRITE_TOKEN is shorter than the {minimum}-character minimum.', [
                'tool' => $tool,
                'client' => $client,
                'minimum' => self::MINIMUM_TOKEN_LENGTH,
            ]);

            throw new McpWriteDeniedException(self::PUBLIC_DENIAL);
        }

        // Peek without consuming: this limiter is charged only on failure, so a legitimate client's
        // writes can never exhaust its own brute-force budget.
        //
        // The peek reads getRemainingTokens(), NOT isAccepted(): SlidingWindowLimiter::reserve()
        // short-circuits on a zero-token request and returns accepted=true however exhausted the
        // window is, so isAccepted() here would silently never deny.
        $authLimiter = $this->mcpWriteAuthLimiter->create($client);

        if ($authLimiter->consume(0)->getRemainingTokens() < 1) {
            $this->logger->warning('MCP write refused: too many failed authentication attempts.', [
                'tool' => $tool,
                'client' => $client,
            ]);

            throw new McpWriteDeniedException(self::PUBLIC_DENIAL);
        }

        if (!hash_equals($this->writeToken, $token)) {
            $authLimiter->consume(1);

            $this->logger->warning('MCP write refused: invalid token.', [
                'tool' => $tool,
                'client' => $client,
                'token_fingerprint' => $this->fingerprint($token),
            ]);

            throw new McpWriteDeniedException(self::PUBLIC_DENIAL);
        }

        $fingerprint = $this->fingerprint($token);

        if (!$operationLimiter->create($fingerprint)->consume(1)->isAccepted()) {
            // The caller proved the token here, so naming the reason leaks nothing.
            $this->logger->warning('MCP write refused: operation rate limit reached.', [
                'tool' => $tool,
                'client' => $client,
                'token_fingerprint' => $fingerprint,
            ]);

            throw new McpWriteDeniedException($rateLimitMessage);
        }

        $this->logger->info('MCP write authorized.', [
            'tool' => $tool,
            'client' => $client,
            'token_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * A short SHA-256 prefix. Enough to correlate a burst of attempts in the log, useless for
     * recovering the token — which must never be logged.
     */
    private function fingerprint(#[\SensitiveParameter] string $token): string
    {
        return substr(hash('sha256', $token), 0, 8);
    }

    /**
     * The MCP server also speaks stdio, where there is no request and therefore no IP.
     */
    private function clientKey(): string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'cli';
    }
}
