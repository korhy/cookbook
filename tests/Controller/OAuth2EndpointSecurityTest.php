<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Guards the security wiring of the OAuth 2.1 authorization server — the two things that are
 * configuration rather than code, and that therefore fail silently.
 *
 * 1. **Reachability.** `/oauth2/authorize` must sit behind the admin login, while `/oauth2/token`
 *    and `/.well-known/*` must be reachable with no session at all. The second half is the one
 *    worth a test: a client whose access token expired has to be able to re-discover the server
 *    and exchange its refresh token, and if either path answered with a 302 to the login form it
 *    could never recover. `security.yaml`'s own comment warns that only the first matching
 *    `access_control` rule applies — this is what proves the rules are in the right order.
 *
 * 2. **The enabled grant set.** `league/oauth2-server-bundle` enables the client credentials grant
 *    **by default**, and it is switched off explicitly in `league_oauth2_server.yaml`. That grant
 *    would mint an `mcp:write` token with no human in the loop, which is the opposite of the whole
 *    design — the Admin account approving the request is the gate. A dependency upgrade that reset
 *    the default, or an edit that dropped the line, is invisible in review and breaks nothing
 *    visibly; it just quietly opens a second door.
 *
 * Assertions are on status codes and the OAuth `error` code, both of which RFC 6749 §5.2 fixes, so
 * a league release that rewords a hint does not break the test.
 */
final class OAuth2EndpointSecurityTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAuthorizeEndpointSendsAnAnonymousCallerToTheAdminLogin(): void
    {
        $this->client->request('GET', '/oauth2/authorize');

        // The resource owner is the single Admin account, so there is no login UI of our own:
        // the admin firewall's form_login entry point takes over and Symfony saves the target
        // path, bringing the caller back here after a successful login.
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAuthorizeEndpointIsReachableOnceTheAdminIsLoggedIn(): void
    {
        $this->client->loginUser($this->admin(), 'admin');

        $this->client->request('GET', '/oauth2/authorize');

        // No client_id, so league refuses the request — which is the point: the response comes
        // from the authorization server rather than from the firewall. Anything in the 3xx range
        // would mean access_control is still intercepting an authenticated caller.
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTokenEndpointIsReachableWithoutAuthentication(): void
    {
        $this->client->request('POST', '/oauth2/token');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('unsupported_grant_type', $this->errorCode());
    }

    public function testWellKnownPathsAreNotInterceptedByTheFirewall(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        // 404 because the metadata documents (RFC 9728 / RFC 8414) land in the next phase. What
        // matters today is that it is a 404 and not a 302: the path is outside security, so the
        // controller added later is reachable the moment it exists.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disabledGrantTypeProvider(): iterable
    {
        yield 'client credentials — defaults to ON in the bundle, off here on purpose' => ['client_credentials'];
        yield 'password — removed by OAuth 2.1' => ['password'];
        yield 'implicit — removed by OAuth 2.1' => ['implicit'];
        yield 'device code — no device to authorize here' => ['urn:ietf:params:oauth:grant-type:device_code'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disabledGrantTypeProvider')]
    public function testDisabledGrantsAreRefused(string $grantType): void
    {
        $this->client->request('POST', '/oauth2/token', [
            'grant_type' => $grantType,
            'client_id' => 'cookbook-mcp-authoring',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('unsupported_grant_type', $this->errorCode());
    }

    /**
     * The OAuth error code from the response body, per RFC 6749 §5.2.
     */
    private function errorCode(): ?string
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        if (!\is_array($payload) || !\is_string($payload['error'] ?? null)) {
            return null;
        }

        return $payload['error'];
    }

    private function admin(): Admin
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $admin = $em->getRepository(Admin::class)->findOneBy(['username' => 'oauth_test_admin']);

        if (null === $admin) {
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
            \assert($hasher instanceof UserPasswordHasherInterface);

            $admin = new Admin();
            $admin->setUsername('oauth_test_admin');
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

        // The admin row is the only thing this test persists, and app_test is shared with the
        // rest of the suite — an account with ROLE_ADMIN must not accumulate there. Read the
        // container before parent::tearDown(), which shuts the kernel down.
        $em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :u')
            ->setParameter('u', 'oauth_test_admin')
            ->execute();

        parent::tearDown();
        $em->close();
    }
}
