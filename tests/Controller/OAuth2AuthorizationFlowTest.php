<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Admin;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The browser leg of the OAuth 2.1 flow, end to end: authorization request → consent → code →
 * token → an authored draft.
 *
 * **Why this exists separately from the two tests either side of it.**
 * `OAuth2ConsentTest` stops at the redirect carrying a code, and `McpAuthoringEndpointTest` starts
 * from a token it mints itself through the issuing path. Both halves were covered and the join
 * between them was not: nothing drove `/oauth2/token`, so nothing proved that a code this server
 * issues can actually be exchanged, that the token which comes back is accepted by the resource
 * server, or that the `aud` claim arrives in the order the resource server reads it positionally.
 * Those are end-to-end properties — every component can be right while the chain is broken.
 *
 * What the happy path here asserts, in one sentence: **the Admin logs in, approves, and a draft
 * appears, with no credential typed anywhere.** That is the objective the whole OAuth build-out
 * was for, and this is the only test that observes it whole.
 *
 * The refusals carry as much weight as the happy path, because each one is a property a passing
 * happy path cannot distinguish from a broken one:
 *
 * - a wrong `code_verifier` is refused, so the code alone is not a bearer credential — the entire
 *   point of PKCE on a public client that has no secret;
 * - a `redirect_uri` that is not the registered one byte for byte gets nothing, and gets it
 *   *without a redirect*, so an attacker-chosen URI never receives anything;
 * - an authorization code is single-use, so interception followed by replay buys nothing;
 * - a refresh token is single-use too (`revoke_refresh_tokens: true`);
 * - a refused consent leaves no exchangeable code behind.
 */
final class OAuth2AuthorizationFlowTest extends WebTestCase
{
    private const CLIENT_IDENTIFIER = 'flow-test-client';

    private const CLIENT_NAME = 'Cookbook authorization flow test client';

    /**
     * league matches redirect URIs by **exact string**, which is what
     * {@see testAnUnregisteredRedirectUriIsRefusedWithoutRedirectingAnywhere} exercises.
     */
    private const REDIRECT_URI = 'http://localhost:3118/callback';

    private const STATE = 'opaque-state-the-client-must-get-back';

    /**
     * Must match `MCP_RESOURCE_URI` in `.env.test` byte for byte. Deriving it from the code would
     * prove nothing: the audience check compares by exact equality, and this test's job is to read
     * what the token endpoint really stamped.
     */
    private const RESOURCE_URI = 'http://localhost/api/v1/mcp-authoring';

    private const AUTHORING_URL = '/api/v1/mcp-authoring';

    private const USERNAME = 'oauth_flow_test_admin';

    private const TITLE_PREFIX = 'Browser flow authored cake ';

    private const INGREDIENT = 'Browser flow test flour';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();

        // Three requests share one flow here — authorize, approve, exchange — and a fourth presents
        // the result to the resource server. KernelBrowser reboots the kernel between requests by
        // default, which would throw away the in-memory client, the session holding the CSRF token,
        // and the authorization code and access token league persists in memory
        // (league_oauth2_server.yaml, when@test). The whole test *is* the chain, so the chain has
        // to survive.
        $this->browser->disableReboot();

        $this->registerClient();
        $this->browser->loginUser($this->admin(), 'admin');
    }

    /**
     * The flow, whole, with the assertion that closes it: a draft exists.
     *
     * Nothing in this method types a credential. The Admin's session is all that is presented to
     * `/oauth2/authorize`, the `code_verifier` never leaves the client until the exchange, and the
     * `Authorization` header on the MCP call carries a token the client was handed rather than a
     * secret someone pasted.
     */
    public function testTheFlowFromAuthorizationRequestToAnAuthoredDraft(): void
    {
        $verifier = self::codeVerifier();

        $code = $this->approve($this->authorize($verifier));
        $token = $this->exchange($code, $verifier);

        $title = self::TITLE_PREFIX.substr(uniqid(), -6);
        $response = $this->createRecipe($token['access_token'], $title);

        self::assertArrayNotHasKey('error', $response, json_encode($response) ?: '');

        $recipe = $this->entityManager()->getRepository(Recipe::class)->findOneBy(['title' => $title]);

        self::assertInstanceOf(Recipe::class, $recipe);

        // Authorization is not review. The quarantine is independent of how the caller got in, and
        // a token obtained through the real browser flow buys no more than a minted one does.
        self::assertSame(RecipeStatus::Draft, $recipe->getStatus());
    }

    /**
     * RFC 6749 §4.1.2: `state` is the client's CSRF defence, and it only works if the server
     * returns it untouched — a client that cannot match the redirect it receives against the
     * request it sent has no way to tell its own callback from an injected one.
     */
    public function testTheRedirectReturnsTheClientStateUnchanged(): void
    {
        $parameters = $this->redirectParameters($this->approveRaw($this->authorize(self::codeVerifier())));

        self::assertSame(self::STATE, $parameters['state'] ?? null);
        self::assertArrayHasKey('code', $parameters);
    }

    /**
     * The shape of the token response, which is the contract a client parses.
     *
     * `refresh_token` is the member worth naming: it is only there because
     * `enable_refresh_token_grant` is on *and* the client was registered with that grant, and a
     * client that never receives one has to send the Admin back to the browser every hour.
     */
    public function testTheTokenResponseCarriesTheMembersAClientNeeds(): void
    {
        $verifier = self::codeVerifier();
        $token = $this->exchange($this->approve($this->authorize($verifier)), $verifier);

        self::assertSame('Bearer', $token['token_type']);
        // PT1H, stated explicitly in league_oauth2_server.yaml rather than inherited.
        self::assertSame(3600, $token['expires_in']);
        self::assertNotSame('', $token['access_token']);
        self::assertNotSame('', $token['refresh_token']);
    }

    /**
     * **The order of `aud` is the assertion**, on a token that came out of the real endpoint.
     *
     * `BearerTokenValidator` recovers the OAuth client identifier from `aud[0]` *positionally*
     * (BearerTokenValidator.php:137), so a token whose resource URI came first makes every
     * authenticated request report the resource URI as its client — which is what the audit trail
     * and the write path's rate-limit key are built on. `AccessTokenAudienceTest` pins this on the
     * decorator in isolation; only here is it read off a token the endpoint actually issued, with
     * the real repository, the real client entity and the real signing key in the chain.
     */
    public function testTheIssuedTokenNamesTheClientBeforeTheResourceInItsAudience(): void
    {
        $verifier = self::codeVerifier();
        $token = $this->exchange($this->approve($this->authorize($verifier)), $verifier);

        self::assertSame(
            [self::CLIENT_IDENTIFIER, self::RESOURCE_URI],
            $this->claims($token['access_token'])->get('aud'),
        );
    }

    /**
     * The refresh grant, which is what keeps the Admin out of the browser for a week.
     *
     * The new access token is *used*, not merely received: a refreshed token has to pass the same
     * audience and scope checks as the first one, and the refresh grant is a separate code path
     * from the authorization code grant — including the `aud` stamping, which is the claim most
     * likely to be lost on a path nobody looked at.
     */
    public function testTheRefreshTokenBuysANewUsableAccessToken(): void
    {
        $verifier = self::codeVerifier();
        $first = $this->exchange($this->approve($this->authorize($verifier)), $verifier);

        $second = $this->refresh($first['refresh_token']);

        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertSame(
            [self::CLIENT_IDENTIFIER, self::RESOURCE_URI],
            $this->claims($second['access_token'])->get('aud'),
        );

        // Usable, not merely well-formed: the resource server accepts it.
        self::assertSame(
            ['recipe_create', 'recipe_import_from_url'],
            $this->toolNames($second['access_token']),
        );
    }

    /**
     * `revoke_refresh_tokens: true`, so a refresh token is single-use.
     *
     * Rotation is the only thing bounding a *stolen* refresh token here, because the client is
     * public: it holds no secret, so nothing else distinguishes the thief's request from the
     * client's own. Without this, a quietly copied refresh token would mint access tokens for a
     * week.
     */
    public function testARotatedRefreshTokenIsRefusedASecondTime(): void
    {
        $verifier = self::codeVerifier();
        $token = $this->exchange($this->approve($this->authorize($verifier)), $verifier);

        $this->refresh($token['refresh_token']);

        $this->post('/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => self::CLIENT_IDENTIFIER,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_grant', $this->errorCode());
    }

    /**
     * PKCE doing its one job. This is the shape of the attack it exists for: the code has been
     * intercepted — off the loopback redirect, out of a log, from a browser history — and the
     * verifier has not. The verifier below is RFC 7636-conformant, so what league refuses is the
     * *mismatch* and not the format; a malformed one is a different error (`invalid_request`) and
     * would satisfy this assertion for the wrong reason.
     */
    public function testACodeExchangedWithTheWrongVerifierIsRefused(): void
    {
        $code = $this->approve($this->authorize(self::codeVerifier()));

        $this->post('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => self::codeVerifier(),
            'client_id' => self::CLIENT_IDENTIFIER,
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_grant', $this->errorCode());
    }

    /**
     * An authorization code is single-use. league revokes it on exchange, so a replay is refused
     * even when it carries the right verifier — which matters because the first exchange may well
     * have been the attacker's and the second the client's, or the other way round.
     */
    public function testAnAuthorizationCodeCannotBeExchangedTwice(): void
    {
        $verifier = self::codeVerifier();
        $code = $this->approve($this->authorize($verifier));

        $this->exchange($code, $verifier);

        $this->post('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => self::CLIENT_IDENTIFIER,
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_grant', $this->errorCode());
    }

    /**
     * The `redirect_uri` is bound into the code, and the exchange has to present the same one.
     *
     * Note the error code: league answers **`invalid_request`** here, not `invalid_grant`, because
     * it treats the mismatch as a malformed request parameter (AuthCodeGrant.php:228). That
     * distinction is a vendor's classification rather than a rule of the spec, so it is pinned
     * here — a client branching on the code would otherwise break on an upgrade with nothing to
     * point at.
     */
    public function testACodeRedeemedWithADifferentRedirectUriIsRefused(): void
    {
        $verifier = self::codeVerifier();
        $code = $this->approve($this->authorize($verifier));

        $this->post('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => self::CLIENT_IDENTIFIER,
            // Registered — so the client is not the problem — but not the URI this code was issued
            // against. A trailing slash is enough: league compares strings.
            'redirect_uri' => self::REDIRECT_URI.'/',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_request', $this->errorCode());
    }

    /**
     * An unregistered `redirect_uri` is refused **and nothing is redirected anywhere**.
     *
     * The absent `Location` header is the security assertion, not the status code: the danger of a
     * loose redirect URI is that the server sends something to an address the attacker chose, so
     * even a redirect carrying an error would leak that a valid client and a logged-in Admin are on
     * the other end. league refuses before the consent screen is built, which is also why this case
     * never reaches the Admin to be approved.
     */
    public function testAnUnregisteredRedirectUriIsRefusedWithoutRedirectingAnywhere(): void
    {
        $this->browser->request('GET', $this->authorizeUrl(self::codeVerifier(), 'http://attacker.example.test/callback'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('invalid_client', $this->errorCode());
        self::assertNull($this->browser->getResponse()->headers->get('Location'));
    }

    /**
     * A refused consent leaves nothing behind to exchange.
     *
     * The redirect carries `error=access_denied` and no `code` — `OAuth2ConsentTest` asserts that
     * much already. What is added here is the other side of the pairing: the token endpoint takes
     * nothing in a code's place, so "no code in the redirect" really does mean no token is
     * reachable. The two assertions together are what makes the Admin's refusal final.
     */
    public function testARefusedConsentLeavesNoExchangeableCode(): void
    {
        $verifier = self::codeVerifier();
        $crawler = $this->browser->request('GET', $this->authorizeUrl($verifier));

        $this->browser->submit($crawler->filter('button[value="deny"]')->form());

        $parameters = $this->redirectParameters($this->redirectLocation());

        self::assertSame('access_denied', $parameters['error'] ?? null);
        self::assertArrayNotHasKey('code', $parameters);
        self::assertArrayNotHasKey('access_token', $parameters);

        // And the endpoint does not accept a guess in place of the code it never issued.
        $this->post('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => 'a-code-this-server-never-issued',
            'code_verifier' => $verifier,
            'client_id' => self::CLIENT_IDENTIFIER,
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertContains($this->errorCode(), ['invalid_grant', 'invalid_request']);
    }

    /**
     * A fresh RFC 7636 §4.1 code verifier: 43 characters of the unreserved set, which is the regex
     * league enforces. Generated per call rather than fixed, so a test needing two different
     * verifiers simply asks twice.
     */
    private static function codeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * `GET /oauth2/authorize`, which must answer with the consent screen rather than resolve
     * itself. Returns the crawler so the caller can submit one of its two buttons.
     */
    private function authorize(string $verifier): Crawler
    {
        $crawler = $this->browser->request('GET', $this->authorizeUrl($verifier));

        self::assertResponseIsSuccessful('Expected the consent screen, not a resolution.');

        return $crawler;
    }

    /**
     * Presses "authorize" and returns the authorization code off the `Location` header — the one
     * value a real client reads out of its callback.
     */
    private function approve(Crawler $crawler): string
    {
        $code = $this->redirectParameters($this->approveRaw($crawler))['code'] ?? null;

        self::assertIsString($code, 'The authorization endpoint issued no code.');

        return $code;
    }

    private function approveRaw(Crawler $crawler): string
    {
        $this->browser->submit($crawler->filter('button[value="approve"]')->form());

        $location = $this->redirectLocation();

        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);

        return $location;
    }

    /**
     * `POST /oauth2/token`, asserting the exchange succeeded and returning the decoded body.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    private function exchange(string $code, string $verifier): array
    {
        $this->post('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            // No client_secret: the client is public, and PKCE is the proof of possession.
            'client_id' => self::CLIENT_IDENTIFIER,
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        return $this->tokenResponse();
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    private function refresh(string $refreshToken): array
    {
        $this->post('/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::CLIENT_IDENTIFIER,
        ]);

        return $this->tokenResponse();
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    private function tokenResponse(): array
    {
        self::assertResponseIsSuccessful((string) $this->browser->getResponse()->getContent());

        $payload = json_decode((string) $this->browser->getResponse()->getContent(), true);

        self::assertIsArray($payload);

        foreach (['access_token', 'token_type', 'expires_in', 'refresh_token'] as $member) {
            self::assertArrayHasKey($member, $payload, 'Missing from the token response: '.$member);
        }

        self::assertIsString($payload['access_token']);
        self::assertIsString($payload['token_type']);
        self::assertIsInt($payload['expires_in']);
        self::assertIsString($payload['refresh_token']);

        return [
            'access_token' => $payload['access_token'],
            'token_type' => $payload['token_type'],
            'expires_in' => $payload['expires_in'],
            'refresh_token' => $payload['refresh_token'],
        ];
    }

    /**
     * The claims of an issued access token, parsed rather than assumed. No signature check here:
     * the resource server does that, and the tests calling the authoring endpoint with these very
     * tokens are what prove it passes.
     */
    private function claims(string $accessToken): DataSet
    {
        $token = (new Parser(new JoseEncoder()))->parse($accessToken);

        self::assertInstanceOf(UnencryptedToken::class, $token);

        return $token->claims();
    }

    /**
     * @param array<string, string> $parameters
     */
    private function post(string $url, array $parameters): void
    {
        $this->browser->request('POST', $url, $parameters);
    }

    private function authorizeUrl(string $verifier, ?string $redirectUri = null): string
    {
        return '/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_IDENTIFIER,
            'redirect_uri' => $redirectUri ?? self::REDIRECT_URI,
            'scope' => 'mcp:write',
            'state' => self::STATE,
            'code_challenge' => self::codeChallenge($verifier),
            // Never `plain`: it would put the verifier where whoever intercepted the code can read
            // it, which is the one thing PKCE is here to prevent.
            'code_challenge_method' => 'S256',
        ]);
    }

    private function redirectLocation(): string
    {
        $location = $this->browser->getResponse()->headers->get('Location');

        self::assertIsString($location, 'Expected a redirect back to the client.');

        return $location;
    }

    /**
     * @return array<string, string>
     */
    private function redirectParameters(string $location): array
    {
        $query = parse_url($location, \PHP_URL_QUERY);

        self::assertIsString($query);

        /** @var array<string, string> $parameters */
        $parameters = [];
        parse_str($query, $parameters);

        return $parameters;
    }

    /**
     * The OAuth error code from the response body, per RFC 6749 §5.2.
     */
    private function errorCode(): ?string
    {
        $payload = json_decode((string) $this->browser->getResponse()->getContent(), true);

        if (!\is_array($payload) || !\is_string($payload['error'] ?? null)) {
            return null;
        }

        return $payload['error'];
    }

    /**
     * @return array<string, mixed>
     */
    private function createRecipe(string $accessToken, string $title): array
    {
        return $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'recipe_create',
                'arguments' => [
                    'title' => $title,
                    'description' => 'Authored with a token obtained through the browser flow.',
                    'ingredients' => [['name' => self::INGREDIENT]],
                    'instructions' => ['Mix.'],
                ],
            ],
        ], $accessToken, $this->session($accessToken));
    }

    /**
     * Sorted, so the assertion is about which tools the authoring server publishes rather than the
     * order a registry happened to walk a namespace in.
     *
     * @return list<string>
     */
    private function toolNames(string $accessToken): array
    {
        $tools = $this->rpc(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $accessToken,
            $this->session($accessToken),
        )['result']['tools'] ?? [];

        $names = \is_array($tools) ? array_column($tools, 'name') : [];
        sort($names);

        return $names;
    }

    private function session(string $accessToken): string
    {
        $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'browser-flow-test-client', 'version' => '0.0.1'],
            ],
        ], $accessToken);

        $sessionId = $this->browser->getResponse()->headers->get('mcp-session-id');

        self::assertNotNull($sessionId, 'Expected an Mcp-Session-Id response header after initialize.');

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function rpc(array $payload, string $accessToken, ?string $sessionId = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ];

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->browser->request('POST', self::AUTHORING_URL, [], [], $server, json_encode($payload) ?: '');

        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function registerClient(): void
    {
        $clientManager = static::getContainer()->get(ClientManagerInterface::class);
        \assert($clientManager instanceof ClientManagerInterface);

        // Public: no secret, so PKCE is the only proof of possession — which is what
        // `require_code_challenge_for_public_clients: true` then demands of every request here.
        $client = new Client(self::CLIENT_NAME, self::CLIENT_IDENTIFIER, null);
        $client->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
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
        // app_test is shared with the rest of the suite, so neither the draft authored here nor an
        // account with ROLE_ADMIN may accumulate in it. The join rows go first: neither foreign key
        // is ON DELETE CASCADE, and a DQL delete does not honour Doctrine's cascade: remove. Read
        // the container before parent::tearDown() shuts the kernel down.
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
