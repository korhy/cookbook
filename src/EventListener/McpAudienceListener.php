<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\McpTokenAudienceVerifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Runs the RFC 8707 audience check as part of authenticating on the MCP authoring firewall.
 *
 * **Scoped to that one firewall**, through the `dispatcher` argument below: `CheckPassportEvent`
 * is dispatched on each firewall's own event dispatcher, and registering this globally would
 * demand an MCP audience from the admin form login and the Lexik JWT firewall as well — neither
 * of which issues a JWT with an `aud` claim at all.
 *
 * Placing the check *inside* authentication rather than after it is what makes a wrong-audience
 * token a `401 invalid_token` instead of a `403`. The token is cryptographically valid, so a 403
 * would be the wrong story: nothing is missing from the resource owner's grant, the credential is
 * simply not one this resource accepts. RFC 6750 §3.1 calls that `invalid_token`, and
 * {@see McpAuthenticationFailureListener} is what turns the refusal into the challenge that says
 * where a usable token comes from.
 */
#[AsEventListener(event: CheckPassportEvent::class, dispatcher: 'security.event_dispatcher.mcp_authoring')]
final readonly class McpAudienceListener
{
    public function __construct(
        private McpTokenAudienceVerifier $verifier,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            // This firewall is HTTP-only (the authoring MCP server declares `stdio: false`), so
            // there is no request-less path to it. Returning rather than throwing keeps a
            // console-booted kernel from failing here; nothing can be authorized without a
            // request anyway, since the authenticator needs an Authorization header to run.
            return;
        }

        $this->verifier->verify($request);
    }
}
