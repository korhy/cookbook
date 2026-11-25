<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\McpScopeVoter;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The voter is what `access_control` consults for the MCP authoring path, so a wrong answer here
 * is either an open write surface or a write path nobody can reach.
 */
final class McpScopeVoterTest extends TestCase
{
    public function testATokenCarryingTheScopeIsGranted(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->oauthToken(['mcp:write'])),
        );
    }

    /**
     * The 403 `insufficient_scope` case: the credential is valid, the grant behind it is not wide
     * enough.
     */
    public function testATokenWithoutTheScopeIsDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->oauthToken([])),
        );
    }

    /**
     * A scope that merely *contains* the right name is not the right scope. Were this a prefix or
     * substring match, inventing `mcp:write-nothing` would be enough.
     */
    public function testASimilarlyNamedScopeIsDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->oauthToken(['mcp:write-nothing', 'mcp:read'])),
        );
    }

    /**
     * The admin session and the Lexik JWT firewall both produce real, authenticated identities —
     * and neither is an OAuth grant, so neither carries a scope a resource owner ever approved.
     * Reaching this case means the firewall ordering in security.yaml has drifted, and the answer
     * must be "no" rather than "whoever you are will do".
     */
    public function testAnAuthenticatedNonOAuthTokenIsDenied(): void
    {
        $sessionToken = new UsernamePasswordToken(
            new InMemoryUser('admin', null, ['ROLE_ADMIN']),
            'admin',
            ['ROLE_ADMIN'],
        );

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($sessionToken));
    }

    public function testAnUnauthenticatedRequestIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new NullToken()));
    }

    /**
     * Abstaining on everything else is what keeps this voter out of the admin firewall's
     * decisions: an affirmative strategy means one voter granting is enough, so a voter that
     * answered on attributes it knows nothing about would be a hole somewhere else entirely.
     */
    public function testItAbstainsOnEveryOtherAttribute(): void
    {
        $voter = new McpScopeVoter();
        $token = $this->oauthToken(['mcp:write']);

        foreach (['ROLE_ADMIN', 'PUBLIC_ACCESS', 'IS_AUTHENTICATED_FULLY', 'MCP_READ'] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_ABSTAIN,
                $voter->vote($token, null, [$attribute]),
                \sprintf('Expected the voter to abstain on "%s".', $attribute),
            );
        }
    }

    private function vote(TokenInterface $token): int
    {
        return (new McpScopeVoter())->vote($token, null, [McpScopeVoter::WRITE]);
    }

    /**
     * @param list<string> $scopes
     */
    private function oauthToken(array $scopes): OAuth2Token
    {
        return new OAuth2Token(
            new InMemoryUser('admin', null, ['ROLE_ADMIN']),
            'access-token-id',
            'cookbook-mcp-authoring',
            $scopes,
            'ROLE_OAUTH2_',
        );
    }
}
