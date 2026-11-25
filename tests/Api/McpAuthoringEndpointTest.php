<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Admin;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use App\OAuth\AudienceAwareAccessToken;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Entity\Client as ClientEntity;
use League\Bundle\OAuth2ServerBundle\Entity\Scope as ScopeEntity;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The MCP authoring endpoint, at the only level where its refusals are observable.
 *
 * **Why this test exists where it does.** An MCP tool error is a JSON-RPC object inside a `200`,
 * so none of the behaviours below can be seen from a unit test on a tool: `401` with a
 * `WWW-Authenticate` challenge, `403 insufficient_scope`, and the refusal of a signed token minted
 * for another audience are all *transport* answers. They are the whole reason the write tools were
 * moved onto a second MCP server with a firewall of its own, and this is the only place that
 * arrangement is proved to work.
 *
 * It is also the test that stands in for the shared secret. Before OAuth, reaching the write path
 * took a 32-character string a human ended up pasting into a conversation; the path is now open to
 * anything bearing a valid token, which makes "what is refused" the contract that matters.
 */
final class McpAuthoringEndpointTest extends WebTestCase
{
    private const AUTHORING_URL = '/api/v1/mcp-authoring';

    private const PUBLIC_URL = '/api/v1/mcp';

    /**
     * Must match MCP_RESOURCE_URI in .env.test byte for byte — the point of the audience check is
     * that it compares by exact equality, so deriving this from the code would prove nothing.
     * McpResourceUriPathTest is what ties the path half of it to the route that really exists.
     */
    private const RESOURCE_URI = 'http://localhost/api/v1/mcp-authoring';

    private const CLIENT_IDENTIFIER = 'authoring-test-client';

    private const USERNAME = 'mcp_authoring_test_admin';

    private const TITLE_PREFIX = 'OAuth authored cake ';

    private const INGREDIENT = 'Authoring test flour';

    private KernelBrowser $browser;

    private TestHandler $auditTrail;

    protected function setUp(): void
    {
        $this->browser = static::createClient();

        // The test environment persists oauth2_* in memory, and KernelBrowser reboots the kernel
        // between requests by default — which would throw away the client and the access token
        // minted below before the request presenting them is handled. It is also what lets the
        // handler pushed onto the audit channel below still be listening during that request.
        $this->browser->disableReboot();

        // A handler of our own on the real channel, rather than reading Monolog's debug logger:
        // what matters is that the record McpWriteGuard emits reaches the `mcp_audit` channel in
        // the shape an operator reads from var/log/mcp_audit.log.
        $auditLogger = static::getContainer()->get('monolog.logger.mcp_audit');
        \assert($auditLogger instanceof Logger);
        $auditLogger->pushHandler($this->auditTrail = new TestHandler());

        $this->registerClient();
        $this->admin();
    }

    /**
     * RFC 9728 §5.1: the `resource_metadata` pointer is what makes the whole flow discoverable
     * from one failed request, with no URL configured in the client. A 401 without it tells a
     * caller only that it is unwelcome.
     */
    public function testACallWithNoTokenIs401WithTheDiscoveryChallenge(): void
    {
        $this->initialize(self::AUTHORING_URL, null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $challenge = $this->challenge();

        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString(
            'resource_metadata="http://localhost/.well-known/oauth-protected-resource/api/v1/mcp-authoring"',
            $challenge,
        );

        // RFC 6750 §3.1: a request that carried no credentials at all has not *failed*
        // authentication, so the challenge names no error code.
        self::assertStringNotContainsString('error=', $challenge);
    }

    /**
     * The pointer has to be there for an unusable token too — that client needs it most. League's
     * own authenticator answers this case itself, with a bare `WWW-Authenticate: Bearer` and no
     * pointer, which is why App\EventListener\McpAuthenticationFailureListener exists.
     */
    public function testAnUnusableTokenIs401WithTheDiscoveryChallengeAndAnErrorCode(): void
    {
        $this->initialize(self::AUTHORING_URL, 'not-a-jwt-at-all');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('error="invalid_token"', $this->challenge());
        self::assertStringContainsString('resource_metadata="http://localhost/.well-known/', $this->challenge());
    }

    /**
     * RFC 8707 and the MCP authorization specification: a resource server MUST refuse a token not
     * issued for it. This one is signed by this very server and perfectly valid — it simply names
     * another resource. Without the check it would open the write path, which is a confused deputy
     * the moment a second protected resource sits behind this issuer.
     */
    public function testASignedTokenIssuedForAnotherAudienceIsRefused(): void
    {
        $this->initialize(
            self::AUTHORING_URL,
            $this->accessToken(audience: 'https://somewhere-else.example.test/api/v1/mcp-authoring'),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('error="invalid_token"', $this->challenge());
    }

    /**
     * A token that authenticates but carries no `mcp:write` deserves a different answer from one
     * that does not authenticate: nothing is wrong with the credential, the grant behind it is
     * simply narrower than this operation needs. RFC 6750 §3.1 calls that `insufficient_scope`;
     * answering 401 would send the client off to re-authenticate for no reason.
     */
    public function testATokenWithoutTheScopeIs403InsufficientScope(): void
    {
        $this->initialize(self::AUTHORING_URL, $this->accessToken(scopes: []));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringContainsString('error="insufficient_scope"', $this->challenge());
        self::assertStringContainsString('scope="mcp:write"', $this->challenge());
    }

    public function testAValidTokenReachesTheAuthoringServerAndSeesOnlyTheWriteTools(): void
    {
        self::assertSame(
            ['recipe_create', 'recipe_import_from_url'],
            $this->toolNames(self::AUTHORING_URL, $this->accessToken()),
        );
    }

    /**
     * **No credential in the advertised schema.** This is the assertion the whole OAuth build-out
     * was for: the bundle derives each tool's input schema from its PHP signature, so a
     * `string $token` parameter announced the shared secret to the client as a required field —
     * which is how it ended up pasted in clear into a conversation. If a credential parameter ever
     * comes back, this fails.
     */
    public function testNoWriteToolAdvertisesACredentialParameter(): void
    {
        $token = $this->accessToken();
        $tools = $this->tools(self::AUTHORING_URL, $token, $this->session(self::AUTHORING_URL, $token));

        self::assertNotSame([], $tools);

        foreach ($tools as $tool) {
            $properties = array_keys($tool['inputSchema']['properties'] ?? []);

            foreach (['token', 'secret', 'password', 'api_key', 'access_token'] as $forbidden) {
                self::assertNotContains($forbidden, $properties, \sprintf('Tool "%s" advertises a credential parameter.', $tool['name']));
            }
        }
    }

    /**
     * The draft quarantine is a defence *independent* of authentication, and it has to survive
     * OAuth: an authorized caller is not a reviewed one. A recipe created here stays invisible to
     * /api/v1 and to the read tools until a human publishes it in EasyAdmin.
     */
    public function testAnAuthorizedCallCreatesADraft(): void
    {
        $token = $this->accessToken();
        $sessionId = $this->session(self::AUTHORING_URL, $token);
        $title = self::TITLE_PREFIX.substr(uniqid(), -6);

        $response = $this->createRecipe($token, $sessionId, $title);

        self::assertArrayNotHasKey('error', $response, json_encode($response) ?: '');

        $recipe = $this->entityManager()->getRepository(Recipe::class)->findOneBy(['title' => $title]);

        self::assertInstanceOf(Recipe::class, $recipe);
        self::assertSame(RecipeStatus::Draft, $recipe->getStatus());
    }

    /**
     * The audit trail has to follow the write path to its new endpoint. Authorization proves a
     * caller may write; only the record says what they did, and a trail that stopped recording
     * when the endpoint moved would have failed silently.
     */
    public function testAnAuthorizedWriteIsRecordedInTheAuditTrail(): void
    {
        $token = $this->accessToken();
        $sessionId = $this->session(self::AUTHORING_URL, $token);

        $this->createRecipe($token, $sessionId, self::TITLE_PREFIX.substr(uniqid(), -6));

        self::assertTrue(
            $this->auditTrail->hasInfoThatContains('MCP write authorized.'),
            'No authorization record reached the mcp_audit channel.',
        );

        $context = $this->auditTrail->getRecords()[0]->context;

        self::assertSame('recipe_create', $context['tool']);
        self::assertSame(self::CLIENT_IDENTIFIER, $context['oauth_client']);
        self::assertSame(self::USERNAME, $context['user']);
        // The access token id is fingerprinted, never recorded whole.
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $context['access_token_fingerprint']);
    }

    /**
     * The public read server is a contract Radiant and every configured client depends on. Adding
     * an authenticated endpoint beside it must not have made it ask for anything, and must not
     * have moved a tool off it.
     */
    public function testThePublicReadServerStaysAnonymousAndUnchanged(): void
    {
        self::assertSame(
            ['category_list', 'ingredient_search', 'recipe_get', 'recipe_search'],
            $this->toolNames(self::PUBLIC_URL, null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createRecipe(string $token, string $sessionId, string $title): array
    {
        return $this->rpc(self::AUTHORING_URL, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'recipe_create',
                'arguments' => [
                    'title' => $title,
                    'description' => 'Created through the OAuth-protected authoring endpoint.',
                    'ingredients' => [['name' => self::INGREDIENT]],
                    'instructions' => ['Mix.'],
                ],
            ],
        ], $token, $sessionId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function rpc(string $url, array $payload, ?string $token, ?string $sessionId = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->browser->request('POST', $url, [], [], $server, json_encode($payload) ?: '');

        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function initialize(string $url, ?string $token): void
    {
        $this->rpc($url, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test-client', 'version' => '0.0.1'],
            ],
        ], $token);
    }

    private function session(string $url, ?string $token): string
    {
        $this->initialize($url, $token);

        $sessionId = $this->browser->getResponse()->headers->get('mcp-session-id');

        self::assertNotNull($sessionId, 'Expected an Mcp-Session-Id response header after initialize.');

        return $sessionId;
    }

    private function challenge(): string
    {
        return $this->browser->getResponse()->headers->get('WWW-Authenticate') ?? '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(string $url, ?string $token, string $sessionId): array
    {
        $tools = $this->rpc($url, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $token, $sessionId)['result']['tools'] ?? [];

        return \is_array($tools) ? array_values($tools) : [];
    }

    /**
     * Sorted, so the assertion is about which tools a server publishes rather than the order a
     * registry happened to walk a namespace in.
     *
     * @return list<string>
     */
    private function toolNames(string $url, ?string $token): array
    {
        $names = array_column($this->tools($url, $token, $this->session($url, $token)), 'name');
        sort($names);

        return $names;
    }

    /**
     * A real access token, signed by this server's own test keypair and registered as un-revoked,
     * so the resource server accepts it exactly as it would one from `/oauth2/token`. The audience
     * and the scopes are parameters because they are what the refusal tests vary.
     *
     * @param list<string> $scopes
     */
    private function accessToken(string $audience = self::RESOURCE_URI, array $scopes = ['mcp:write']): string
    {
        $client = new ClientEntity();
        $client->setIdentifier(self::CLIENT_IDENTIFIER);

        $token = new AudienceAwareAccessToken($audience);
        $token->setClient($client);
        $token->setIdentifier(bin2hex(random_bytes(16)));
        $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));
        $token->setUserIdentifier(self::USERNAME);

        foreach ($scopes as $scope) {
            $scopeEntity = new ScopeEntity();
            $scopeEntity->setIdentifier($scope);
            $token->addScope($scopeEntity);
        }

        $token->setPrivateKey(new CryptKey(
            static::getContainer()->getParameter('kernel.project_dir').'/config/oauth/private_test.pem',
            'test',
            false,
        ));

        // Required: the resource server treats an unknown jti as revoked, because
        // AccessTokenRepository::isAccessTokenRevoked() returns true for a token it cannot find.
        $repository = static::getContainer()->get('league.oauth2_server.repository.access_token');
        \assert($repository instanceof AccessTokenRepositoryInterface);
        $repository->persistNewAccessToken($token);

        // toString(), not a cast: AccessTokenTrait names the method rather than implementing
        // __toString(), and it is what serialises and signs the JWT.
        return $token->toString();
    }

    private function registerClient(): void
    {
        $clientManager = static::getContainer()->get(ClientManagerInterface::class);
        \assert($clientManager instanceof ClientManagerInterface);

        // Public, no secret: PKCE is the only proof of possession. See
        // docs/decisions/mcp-oauth-client-registration.md.
        $client = new Client('Cookbook authoring test client', self::CLIENT_IDENTIFIER, null);
        $client->setRedirectUris(new RedirectUri('http://localhost:3119/callback'));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('mcp:write'));

        $clientManager->save($client);
    }

    private function admin(): Admin
    {
        $em = $this->entityManager();
        $admin = $em->getRepository(Admin::class)->findOneBy(['username' => self::USERNAME]);

        if (null === $admin) {
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
            \assert($hasher instanceof UserPasswordHasherInterface);

            $admin = new Admin();
            $admin->setUsername(self::USERNAME);
            $admin->setPassword($hasher->hashPassword($admin, 'password'));
            $admin->setRoles(['ROLE_ADMIN']);

            $em->persist($admin);
            $em->flush();
        }

        return $admin;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected function tearDown(): void
    {
        // app_test is shared with the rest of the suite, so neither the drafts authored here nor
        // an account with ROLE_ADMIN may accumulate in it. The join rows go first: neither foreign
        // key is ON DELETE CASCADE, and a DQL delete does not honour Doctrine's cascade: remove.
        // Read the container before parent::tearDown() shuts the kernel down.
        $em = $this->entityManager();
        $authored = 'SELECT r.id FROM App\Entity\Recipe r WHERE r.title LIKE :title';

        foreach ([
            'DELETE FROM App\Entity\Instruction i WHERE i.recipe IN ('.$authored.')',
            'DELETE FROM App\Entity\RecipeIngredient ri WHERE ri.recipe IN ('.$authored.')',
            'DELETE FROM App\Entity\Recipe r2 WHERE r2.title LIKE :title',
        ] as $dql) {
            $em->createQuery($dql)->setParameter('title', self::TITLE_PREFIX.'%')->execute();
        }

        $em->createQuery('DELETE FROM App\Entity\Ingredient i WHERE i.name = :name')
            ->setParameter('name', self::INGREDIENT)
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :username')
            ->setParameter('username', self::USERNAME)
            ->execute();

        parent::tearDown();
        $em->close();
    }
}
