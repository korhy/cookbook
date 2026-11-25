<?php

declare(strict_types=1);

namespace App\Security;

use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants the MCP write path to an access token that actually carries the `mcp:write` scope.
 *
 * **Why a voter and not a role in `access_control`.** `league/oauth2-server-bundle` projects a
 * token's scopes onto roles by string concatenation —
 * `strtoupper(trim($rolePrefix.$scope))` in
 * `League\...\Security\Authentication\Token\OAuth2Token::__construct()` — so `mcp:write` becomes
 * the role **`ROLE_OAUTH2_MCP:WRITE`**, colon included. That identifier is an artefact of a
 * vendor's `sprintf`, not a name this application chose, and writing it into `security.yaml` or an
 * `#[IsGranted]` would mean three things at once: hard-coding a role spelling we do not own,
 * duplicating the scope name in a second, silently-transformed form, and failing *open-ended* if a
 * future `role_prefix` setting changed it — the rule would simply stop matching and deny
 * everything, with nothing to say why.
 *
 * This class instead names the scope once, in its own vocabulary, and reads it from the typed
 * accessor the bundle provides. The role projection is left alone; nothing in this project depends
 * on it.
 *
 * **Why not `#[IsGranted]`.** An MCP tool is not a controller. The attribute is resolved by
 * Symfony's controller argument metadata, so on a class the MCP bundle invokes it would be
 * decoration that never runs — the quietest possible way to ship an unprotected write tool. The
 * check therefore belongs to `access_control`, which puts it in front of the HTTP request and
 * makes `McpBearerChallenge` able to answer `403 insufficient_scope` at the status-code level,
 * where the MCP authorization specification expects it. An MCP tool error is a JSON-RPC object
 * inside a `200`, and a `200` is not a refusal a client can act on.
 */
final class McpScopeVoter extends Voter
{
    /**
     * The `access_control` attribute. Deliberately not shaped like a role: it is not one, and a
     * `ROLE_` prefix here would invite someone to grant it on the Admin entity.
     */
    public const WRITE = 'MCP_WRITE';

    /**
     * The single scope the authorization server issues (`league_oauth2_server.scopes.available`).
     */
    public const SCOPE = 'mcp:write';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::WRITE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Any other token type means the request authenticated through another firewall — the
        // Lexik JWT one, or the admin session. Those identities are real but they are not an
        // OAuth grant, and they carry no scope the resource owner ever approved, so they do not
        // open the write path. Reaching this with one would mean the firewall ordering in
        // security.yaml had drifted.
        return $token instanceof OAuth2Token && \in_array(self::SCOPE, $token->getScopes(), true);
    }
}
