<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Reads the Admin's answer to an authorization request off the HTTP request.
 *
 * This is the whole decision logic of the consent screen, kept out of the listener that renders
 * it: the listener translates a decision into an HTTP outcome, this class establishes what the
 * decision *is*.
 *
 * **The CSRF check is the point of this class, not a detail of it.** The Admin approving a
 * request is presented everywhere in this project as *the* gate on the MCP write path — so a
 * page on another origin that could POST an approval would remove that gate entirely, silently,
 * while every document still claimed it was there. Hence the invariant below: *any* POST to the
 * authorization endpoint must carry a valid token, whatever it claims to be submitting.
 *
 * The token id is intentionally **absent** from `framework.csrf_protection.stateless_token_ids`
 * (`config/packages/csrf.yaml`), so `SameOriginCsrfTokenManager` delegates it to the
 * session-backed manager. Stateless double-submit needs a JavaScript snippet to plant the
 * cookie, and this screen has no JavaScript — while a session is guaranteed here, since the
 * caller has just authenticated against the stateful `admin` firewall to get this far.
 */
final readonly class ConsentDecider
{
    /**
     * Also read by the Twig template, through the listener, to mint the token it renders.
     */
    public const CSRF_TOKEN_ID = 'oauth2_consent';

    private const FIELD_DECISION = 'consent';
    private const FIELD_CSRF_TOKEN = '_csrf_token';
    private const DECISION_APPROVE = 'approve';
    private const DECISION_DENY = 'deny';

    public function __construct(private CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function decide(Request $request): ConsentDecision
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return ConsentDecision::Pending;
        }

        $submittedToken = $request->request->get(self::FIELD_CSRF_TOKEN);

        if (!\is_string($submittedToken)
            || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submittedToken))
        ) {
            return ConsentDecision::InvalidCsrfToken;
        }

        return match ($request->request->get(self::FIELD_DECISION)) {
            self::DECISION_APPROVE => ConsentDecision::Approved,
            self::DECISION_DENY => ConsentDecision::Denied,
            // A verified POST that names neither button is not an answer: show the screen again
            // rather than guessing which way the Admin meant it.
            default => ConsentDecision::Pending,
        };
    }
}
