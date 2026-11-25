<?php

declare(strict_types=1);

namespace App\EventListener;

use App\OAuth\ConsentDecider;
use App\OAuth\ConsentDecision;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Puts a human in front of every authorization request.
 *
 * `league/oauth2-server-bundle` ships **no** listener on
 * `OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE`, and
 * `AuthorizationRequestResolveEvent::$authorizationResolution` initialises to
 * `AUTHORIZATION_DENIED` — so without this class a valid request from a logged-in Admin was
 * answered by `AuthCodeGrant::completeAuthorizationRequest` throwing `accessDenied`, redirecting
 * the client back with `error=access_denied`. The endpoint could never issue a code at all. This
 * listener is therefore not only what makes the gate *visible*; it is what makes the
 * authorization endpoint work.
 *
 * `setResponse()` is how the bundle lets a consent screen in: `AuthorizationController` returns
 * that response verbatim and never reaches the grant, so no code is issued and no redirect
 * happens while the question is still open.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final readonly class OAuthConsentListener
{
    public function __construct(
        private ConsentDecider $consentDecider,
        private RequestStack $requestStack,
        private Environment $twig,
    ) {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            // Nothing to read a decision from. Leaving the event untouched keeps the bundle's
            // own default, which is AUTHORIZATION_DENIED: approving here would be approving on
            // nobody's behalf.
            return;
        }

        match ($this->consentDecider->decide($request)) {
            ConsentDecision::Approved => $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED),
            // RFC 6749 §4.1.2.1: a refusal belongs on the client's redirect_uri as
            // `error=access_denied`, not on an error page of ours. league builds exactly that
            // out of the denied resolution.
            ConsentDecision::Denied => $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED),
            ConsentDecision::Pending => $event->setResponse($this->consentScreen($event, false, Response::HTTP_OK)),
            // A rejected submission is answered with the screen again rather than a redirect:
            // the client must learn nothing from a forged POST, and the Admin gets a form with a
            // fresh token to retry from. 400, so "rejected" is not mistaken for "shown".
            ConsentDecision::InvalidCsrfToken => $event->setResponse($this->consentScreen($event, true, Response::HTTP_BAD_REQUEST)),
        };
    }

    private function consentScreen(AuthorizationRequestResolveEvent $event, bool $csrfFailed, int $status): Response
    {
        $client = $event->getClient();

        return new Response(
            $this->twig->render('oauth2/consent.html.twig', [
                // The name, not just the identifier: the Admin is being asked to recognise who
                // is calling, and `cookbook-mcp-authoring` is not a thing a human recognises.
                'client_name' => $client->getName(),
                'client_id' => $client->getIdentifier(),
                'scopes' => array_map(static fn (Scope $scope): string => (string) $scope, $event->getScopes()),
                'csrf_token_id' => ConsentDecider::CSRF_TOKEN_ID,
                'csrf_failed' => $csrfFailed,
            ]),
            $status
        );
    }
}
