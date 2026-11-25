<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * What the Admin has said about an authorization request currently sitting on
 * `/oauth2/authorize`.
 *
 * `Pending` is not a refusal: it means nobody has answered yet, so the consent screen is what
 * the request gets. It is deliberately distinct from `Denied`, because the two produce opposite
 * HTTP outcomes — a screen to read versus a redirect back to the client with
 * `error=access_denied`.
 */
enum ConsentDecision
{
    /** Nothing has been submitted yet — show the screen. */
    case Pending;

    /** The Admin pressed approve, with a valid CSRF token. */
    case Approved;

    /** The Admin pressed refuse, with a valid CSRF token. */
    case Denied;

    /**
     * A submission arrived without a usable CSRF token, so it is not a decision at all: it is
     * either a stale form or a cross-site attempt to approve on the Admin's behalf. It never
     * maps to `Approved` *or* to `Denied` — a forged request must not be able to cause a
     * redirect back to a client either.
     */
    case InvalidCsrfToken;
}
