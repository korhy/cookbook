<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The consent screen on `/oauth2/authorize`.
 *
 * The Admin approving a request is presented throughout this project as *the* gate on the MCP
 * write path, so the four behaviours below are the gate itself rather than decoration:
 *
 * 1. a valid request **shows the screen** instead of resolving itself — `league`'s controller
 *    returns `AuthorizationRequestResolveEvent::setResponse()` verbatim, before the grant runs,
 *    so no code can be issued while the question is open;
 * 2. approving redirects to the client's `redirect_uri` **with a code**. Worth stating plainly:
 *    before this phase the bundle's default resolution was `AUTHORIZATION_DENIED` and nothing
 *    listened on the event, so this endpoint could never issue a code at all;
 * 3. refusing redirects with `error=access_denied`, per RFC 6749 §4.1.2.1 — the client's problem
 *    to handle, not an error page of ours;
 * 4. a submission with no valid CSRF token is **rejected**. This is the one that protects the
 *    other three: an approval triggerable from another origin would remove the gate while every
 *    document still claimed it was there.
 */
final class OAuth2ConsentTest extends WebTestCase
{
    private const CLIENT_IDENTIFIER = 'consent-test-client';
    private const CLIENT_NAME = 'Cookbook consent test client';
    private const REDIRECT_URI = 'http://localhost:3118/callback';
    private const STATE = 'opaque-state-from-the-client';

    /**
     * 43 characters minimum, `[A-Za-z0-9-._~]` only, per RFC 7636 §4.1 — which league enforces
     * with that exact regex.
     */
    private const CODE_VERIFIER = 'cookbook-consent-test-code-verifier-0123456';

    private const USERNAME = 'oauth_consent_test_admin';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();

        // The test environment persists oauth2_* in memory (league_oauth2_server.yaml), so the
        // registered client lives in the container — and KernelBrowser reboots the kernel
        // between requests by default, which would throw it away between the GET that renders
        // the screen and the POST that answers it. The session carrying the CSRF token has the
        // same requirement.
        $this->browser->disableReboot();

        $this->registerClient();
        $this->browser->loginUser($this->admin(), 'admin');
    }

    public function testValidRequestShowsTheConsentScreenInsteadOfResolvingIt(): void
    {
        $crawler = $this->browser->request('GET', $this->authorizeUrl());

        self::assertResponseIsSuccessful();

        // The client's name, not only its identifier: the Admin has to recognise who is asking.
        self::assertStringContainsString(self::CLIENT_NAME, $crawler->filter('body')->text());

        // The scope, shown raw so it is verifiable, and translated so it is understandable.
        self::assertStringContainsString('mcp:write', $crawler->filter('body')->text());

        // Two explicit actions, and a CSRF token to submit either with.
        self::assertCount(1, $crawler->filter('button[name="consent"][value="approve"]'));
        self::assertCount(1, $crawler->filter('button[name="consent"][value="deny"]'));
        self::assertCount(1, $crawler->filter('form input[name="_csrf_token"]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'fr — what LocaleListener defaults a real browser to' => ['fr', "Autoriser l'acces"];
        yield 'en — framework.default_locale' => ['en-US,en;q=0.9', 'Authorize access'];
    }

    /**
     * Every key on this screen resolves in **both** catalogues.
     *
     * A key added to only one of `translations/messages.{fr,en}.yaml` renders as the bare key for
     * the other locale — a silent failure that shows up in a browser and in nothing else, and the
     * one naming.md warns about. The locale is driven by `Accept-Language` here on purpose:
     * `App\EventListener\LocaleListener` is what picks it, and a request with no header lands on
     * `en`, so asserting without the header would only ever have covered one catalogue.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testTheScreenIsTranslatedInBothCatalogues(string $acceptLanguage, string $expectedTitle): void
    {
        $crawler = $this->browser->request(
            'GET',
            $this->authorizeUrl(),
            [],
            [],
            ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]
        );

        self::assertResponseIsSuccessful();

        $body = $crawler->filter('body')->html();

        // No key rendered raw, in either locale.
        self::assertStringNotContainsString('oauth2_consent.', $body);

        // And the catalogue actually in use is the one asked for, so the assertion above is not
        // passing because both locales silently fell back to the same file. Accents are stripped
        // rather than matched, to keep the test off the exact wording of the copy.
        self::assertStringContainsString(
            $expectedTitle,
            str_replace(['é', 'è', 'ê', 'à', 'ç'], ['e', 'e', 'e', 'a', 'c'], $crawler->filter('h1')->text())
        );
    }

    public function testApprovingRedirectsToTheClientWithAnAuthorizationCode(): void
    {
        $crawler = $this->browser->request('GET', $this->authorizeUrl());

        $this->browser->submit($crawler->filter('button[value="approve"]')->form());

        $location = $this->redirectLocation();

        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        self::assertArrayHasKey('code', $this->redirectParameters($location));
        self::assertSame(self::STATE, $this->redirectParameters($location)['state'] ?? null);
    }

    public function testRefusingRedirectsToTheClientWithAccessDenied(): void
    {
        $crawler = $this->browser->request('GET', $this->authorizeUrl());

        $this->browser->submit($crawler->filter('button[value="deny"]')->form());

        $location = $this->redirectLocation();
        $parameters = $this->redirectParameters($location);

        self::assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        // RFC 6749 §4.1.2.1 fixes this error code, so the assertion is on the contract and not
        // on league's wording.
        self::assertSame('access_denied', $parameters['error'] ?? null);
        self::assertSame(self::STATE, $parameters['state'] ?? null);
        self::assertArrayNotHasKey('code', $parameters);
    }

    public function testSubmissionWithoutAValidCsrfTokenIsRejected(): void
    {
        // The shape of a cross-site approval: the right URL, the right button, a token that was
        // never minted against this session.
        $crawler = $this->browser->request('POST', $this->authorizeUrl(), [
            'consent' => 'approve',
            '_csrf_token' => 'not-the-token-this-session-was-given',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // No redirect at all, so no code reached the client — and nothing told it the request
        // had even been answered.
        self::assertResponseHeaderNotSame('Location', self::REDIRECT_URI);
        self::assertNull($this->browser->getResponse()->headers->get('Location'));

        // The screen comes back with a fresh token, so the Admin can still answer for real.
        self::assertCount(1, $crawler->filter('button[name="consent"][value="approve"]'));
    }

    private function authorizeUrl(): string
    {
        return '/oauth2/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_IDENTIFIER,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'mcp:write',
            'state' => self::STATE,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '='),
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

    private function registerClient(): void
    {
        $clientManager = static::getContainer()->get(ClientManagerInterface::class);
        \assert($clientManager instanceof ClientManagerInterface);

        // Public: no secret, so PKCE is the only proof of possession — which is what
        // `require_code_challenge_for_public_clients: true` then demands of the request above.
        $client = new Client(self::CLIENT_NAME, self::CLIENT_IDENTIFIER, null);
        $client->setRedirectUris(new RedirectUri(self::REDIRECT_URI));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('mcp:write'));

        $clientManager->save($client);
    }

    private function admin(): Admin
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

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

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        // app_test is shared with the rest of the suite: an account with ROLE_ADMIN must not
        // accumulate there. Read the container before parent::tearDown() shuts the kernel down.
        $em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :u')
            ->setParameter('u', self::USERNAME)
            ->execute();

        parent::tearDown();
        $em->close();
    }
}
