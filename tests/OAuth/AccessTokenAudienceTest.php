<?php

declare(strict_types=1);

namespace App\Tests\OAuth;

use App\OAuth\AudienceStampingAccessTokenRepository;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\Bundle\OAuth2ServerBundle\Event\AccessTokenExtraClaimsResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Proves the `aud` claim reaches an issued access token.
 *
 * Without it the resource server has nothing to validate: league hard-codes the *client*
 * identifier as the audience and ignores RFC 8707 entirely, so a token minted for some other
 * resource would look exactly like one minted for the MCP endpoint. Phase 5's validation is built
 * on this claim being there.
 *
 * Two things are tested, because either alone would pass while the feature is broken: that the
 * decorator stamps the audience, and that the decoration is actually registered in the container.
 */
final class AccessTokenAudienceTest extends KernelTestCase
{
    private const RESOURCE_URI = 'https://api.example.test/api/v1/mcp';

    private const CLIENT_ID = 'cookbook-mcp-authoring';

    private static ?string $privateKey = null;

    /**
     * The stamping is useless if league is still handed its own repository, and a unit test on the
     * decorator cannot see that. `#[AsDecorator]` is one attribute away from being dropped.
     */
    public function testTheAccessTokenRepositoryIsDecorated(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AudienceStampingAccessTokenRepository::class,
            static::getContainer()->get('league.oauth2_server.repository.access_token'),
        );
    }

    public function testAnIssuedAccessTokenNamesTheMcpEndpointInItsAudience(): void
    {
        $claims = $this->issueAccessToken()->claims();

        // An array of two, in this order: ours is seeded first and league's own `permittedFor()`
        // appends the client id (Builder.php:42-48 merges with dedup rather than replacing). A
        // resource server validates by membership, so the client entry is harmless — but it is
        // asserted here so that a league release changing that behaviour is noticed.
        self::assertSame([self::RESOURCE_URI, self::CLIENT_ID], $claims->get('aud'));
    }

    /**
     * The decorator rebuilds what the bundle's own repository does, including dispatching
     * `ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE`. Nothing listens to that event in this project today,
     * which is exactly why dropping it would go unnoticed until someone added a listener and spent
     * an afternoon on it.
     */
    public function testAnExtraClaimsListenerStillReachesTheToken(): void
    {
        $claims = $this->issueAccessToken(['cookbook_marker' => 'reached'])->claims();

        self::assertSame('reached', $claims->get('cookbook_marker'));
        self::assertSame([self::RESOURCE_URI, self::CLIENT_ID], $claims->get('aud'));
    }

    /**
     * @param array<non-empty-string, mixed> $extraClaims
     */
    private function issueAccessToken(array $extraClaims = []): UnencryptedToken
    {
        $dispatcher = new EventDispatcher();

        if ([] !== $extraClaims) {
            $dispatcher->addListener(
                OAuth2Events::ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE,
                static function (AccessTokenExtraClaimsResolveEvent $event) use ($extraClaims): void {
                    $event->setExtraClaims($extraClaims);
                },
            );
        }

        $repository = new AudienceStampingAccessTokenRepository(
            // Only getNewToken() is under test; persistence belongs to the decorated repository.
            $this->createStub(AccessTokenRepositoryInterface::class),
            $dispatcher,
            self::RESOURCE_URI,
        );

        $client = $this->createStub(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn(self::CLIENT_ID);

        $accessToken = $repository->getNewToken($client, [], 'oauth_test_admin');
        // What league's grants do between building the entity and serialising it.
        $accessToken->setIdentifier(bin2hex(random_bytes(16)));
        $accessToken->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));
        $accessToken->setPrivateKey(new CryptKey($this->privateKey()));

        $token = (new Parser(new JoseEncoder()))->parse($accessToken->toString());

        self::assertInstanceOf(UnencryptedToken::class, $token);

        return $token;
    }

    /**
     * A throwaway RSA keypair, generated in memory. Not the one in `config/oauth/`: this test has
     * no business depending on a key file being present or on which passphrase it was built with.
     */
    private function privateKey(): string
    {
        if (null !== self::$privateKey) {
            return self::$privateKey;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);

        self::assertNotFalse($key, 'Could not generate an RSA keypair for the test');
        self::assertTrue(openssl_pkey_export($key, $privateKey));

        return self::$privateKey = $privateKey;
    }
}
