<?php

declare(strict_types=1);

namespace App\Security;

use App\OAuth\DiscoveryMetadataProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The `WWW-Authenticate` challenge for the MCP write path: how a client that holds no usable token
 * is told where to get one.
 *
 * **NOT WIRED YET — this is not dead code.** Phase 3 builds the discovery layer only; phase 5
 * creates the `mcp_authoring` firewall and attaches this class to it as both `entry_point` and
 * `access_denied_handler`. Until then nothing calls it, and `tests/Security/McpBearerChallengeTest`
 * is what proves it answers correctly. Do not delete it as unused, and do not wire it here: the
 * firewall it belongs to does not exist, and attaching a challenge to the current firewalls would
 * change how `/api/v1/mcp` answers today.
 *
 * The one member that matters is `resource_metadata`. RFC 9728 §5.1 makes it the pointer a client
 * follows from a 401 to the protected resource document, and from there to the authorization
 * server — it is what makes the whole flow discoverable from a single failed request, with no URL
 * configured in the client.
 *
 * Both bodies are protocol text for a machine caller, so they stay in English and out of the
 * translation catalogues.
 */
final readonly class McpBearerChallenge implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(private DiscoveryMetadataProvider $metadata)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // RFC 6750 §3.1: a challenge answering a request that carried no credentials at all
        // SHOULD NOT name an error code — nothing has failed yet, a token is simply missing. The
        // pointer below is the part such a caller needs.
        $parameters = $request->headers->has('Authorization')
            ? [
                'error' => 'invalid_token',
                'error_description' => 'The access token is missing, expired, revoked or not valid for this resource.',
            ]
            : [];

        return $this->challenge(Response::HTTP_UNAUTHORIZED, $parameters);
    }

    /**
     * Narrower than the interface's `?Response` on purpose: a null would hand the request back to
     * Symfony's default 403 page, which carries no challenge and tells a client nothing.
     */
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->challenge(Response::HTTP_FORBIDDEN, [
            'error' => 'insufficient_scope',
            'error_description' => 'The access token does not carry the scope this operation requires.',
            // Every scope this server knows about, which today is the single one the write path
            // needs. Phase 5 is where a voter decides per operation; when it does, the scope it
            // asked for is what belongs here.
            'scope' => implode(' ', $this->metadata->scopesSupported()),
        ]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function challenge(int $status, array $parameters): JsonResponse
    {
        $parameters['resource_metadata'] = $this->metadata->protectedResourceMetadataUrl();

        $challenge = 'Bearer '.implode(', ', array_map(
            // RFC 7235 quoted-string: a value may not carry a bare quote or backslash. None of
            // these values can today, all being derived from configuration — escaped anyway, so
            // that stays true of whatever is added next.
            static fn (string $name, string $value): string => \sprintf('%s="%s"', $name, addcslashes($value, '"\\')),
            array_keys($parameters),
            array_values($parameters),
        ));

        return new JsonResponse($parameters, $status, ['WWW-Authenticate' => $challenge]);
    }
}
