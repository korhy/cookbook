<?php

declare(strict_types=1);

namespace App\EventListener;

use App\OAuth\McpResourceUri;
use League\OAuth2\Server\CryptKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Turns an unreadable or undecryptable OAuth keypair into a proper OAuth `server_error` instead of
 * a bare 500.
 *
 * **Why this is needed at all.** `League\OAuth2\Server\CryptKey` reads *and decrypts* the key in
 * its **constructor** (CryptKey.php:55-78), not when it signs. So a missing file, an unreadable
 * one or a wrong `OAUTH_PASSPHRASE` fails while the `AuthorizationServer` service is being
 * instantiated — before any controller code runs — and `POST /oauth2/token` answers with
 * Symfony's generic 500. A machine client parsing `{"error": …}` gets an HTML error page and no
 * idea whether to retry, re-authorize or give up, and an operator reading the response learns
 * nothing either. The same constructor sits behind the resource server's public key, so the MCP
 * authoring endpoint fails the same way.
 *
 * `server_error` is league's own code for this (`OAuthServerException::serverError()`, 500), so a
 * client sees the same envelope it already parses for every other failure from these endpoints.
 * It stays a 500 on purpose: nothing the caller sent is wrong, and a 4xx would send them looking
 * at their own request.
 *
 * **The detail goes to the log, not to the response.** league's message carries the key path. That
 * is not a secret, but it is internal shape, and the operator who needs it has the error log.
 *
 * The exception is recognised by **where it was thrown** — `CryptKey`'s own file, resolved by
 * reflection — rather than by its message. All four of its failure messages are plain
 * `LogicException`s whose wording is a vendor's to change, and matching on that text would be a
 * silent regression waiting for the next release.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final readonly class OAuthKeyFailureListener
{
    public function __construct(
        private McpResourceUri $resourceUri,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$this->isOAuthEndpoint($event->getRequest())) {
            return;
        }

        $throwable = $this->keyFailureIn($event->getThrowable());

        if (null === $throwable) {
            return;
        }

        $this->logger->critical('The OAuth keypair could not be read: {message}', [
            'message' => $throwable->getMessage(),
            'exception' => $throwable,
        ]);

        $event->setResponse(new JsonResponse([
            'error' => 'server_error',
            'error_description' => 'The authorization server is misconfigured and cannot issue or verify tokens.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR));
    }

    /**
     * The endpoints whose callers are machines reading a JSON error envelope. Everything else —
     * the consent screen, the back-office — keeps Symfony's error handling, which is what a
     * browser and the profiler want.
     *
     * The authoring path comes from the canonical resource URI rather than being written out
     * again, so it cannot drift from `config/packages/mcp.yaml`.
     */
    private function isOAuthEndpoint(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/oauth2/')
            || str_starts_with($path, '/.well-known/oauth')
            || ('' !== $this->resourceUri->path() && str_starts_with($path, $this->resourceUri->path()));
    }

    /**
     * Walks the `previous` chain: the failure is wrapped by the time it leaves the container's
     * service instantiation.
     */
    private function keyFailureIn(\Throwable $throwable): ?\Throwable
    {
        $cryptKeyFile = (new \ReflectionClass(CryptKey::class))->getFileName();

        for ($candidate = $throwable; null !== $candidate; $candidate = $candidate->getPrevious()) {
            if ($candidate->getFile() === $cryptKeyFile) {
                return $candidate;
            }
        }

        return null;
    }
}
