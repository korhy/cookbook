<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base for every test that exercises `/api/v1` the way a real consumer does.
 *
 * Everything under `^/api` except `login_check`, the docs and the MCP endpoint is `ROLE_ADMIN`
 * (see the `access_control` block in `security.yaml`), so each of these tests has to mint a JWT
 * before it can assert anything at all. That bootstrapping — the admin, the token exchange, the
 * bearer request helper, the cleanup — was copied verbatim into every API test file; it lives here
 * instead.
 */
abstract class AuthenticatedApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    /**
     * The account these tests authenticate as. Removed again in tearDown, so a failing run does not
     * leave a usable admin behind in the test database.
     */
    protected const TEST_ADMIN_USERNAME = 'api_test_admin';
    protected const TEST_ADMIN_PASSWORD = 'password';

    protected EntityManagerInterface $em;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearRecipes();
        $this->token = $this->authenticate();
    }

    protected function tearDown(): void
    {
        $this->clearRecipes();

        $this->em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :username')
            ->setParameter('username', static::TEST_ADMIN_USERNAME)
            ->execute();

        parent::tearDown();

        $this->em->close();
    }

    /**
     * Empties the recipe graph so a test can assert on `totalItems` without depending on what ran
     * before it.
     *
     * Order matters: neither `instruction.recipe_id` nor `recipe_ingredient.recipe_id` is declared
     * `ON DELETE CASCADE`, and a DQL `DELETE` does not honour Doctrine's `cascade: remove` either —
     * so deleting recipes first fails on a foreign key the moment a test seeds a full recipe.
     */
    protected function clearRecipes(): void
    {
        foreach (['App\Entity\Instruction', 'App\Entity\RecipeIngredient', 'App\Entity\Recipe'] as $entity) {
            $this->em->createQuery(\sprintf('DELETE FROM %s', $entity))->execute();
        }
    }

    /**
     * Issues a request as the authenticated admin and asserts the status code before handing back
     * the decoded body.
     *
     * `toArray(false)` because the error cases are as interesting as the happy ones: a 400 or a 404
     * still has a body worth asserting on.
     *
     * @return array<string, mixed>
     */
    protected function apiRequest(string $method, string $url, int $expectedStatus = 200): array
    {
        $response = static::createClient()->request($method, $url, [
            'auth_bearer' => $this->token,
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertSame($expectedStatus, $response->getStatusCode());

        return $response->toArray(false);
    }

    private function authenticate(): string
    {
        $admin = $this->em->getRepository(Admin::class)
            ->findOneBy(['username' => static::TEST_ADMIN_USERNAME]);

        if (null === $admin) {
            $admin = new Admin();
            $admin->setUsername(static::TEST_ADMIN_USERNAME);
            $admin->setRoles(['ROLE_ADMIN']);
            $admin->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)
                    ->hashPassword($admin, static::TEST_ADMIN_PASSWORD)
            );

            $this->em->persist($admin);
            $this->em->flush();
        }

        $response = static::createClient()->request('POST', '/api/login_check', [
            'json' => [
                'username' => static::TEST_ADMIN_USERNAME,
                'password' => static::TEST_ADMIN_PASSWORD,
            ],
        ]);

        return $response->toArray()['token'];
    }
}
