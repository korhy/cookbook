<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Admin;
use App\Entity\Category;
use App\Entity\Recipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RecipePaginationTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private EntityManagerInterface $em;
    private const API_URL = '/api/v1/recipes';
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\Recipe')->execute();
        $this->seedRecipes(25);
        $this->token = $this->getJwtToken();
    }

    public function testDefaultPageReturnsConfiguredItemCount(): void
    {
        $data = $this->apiRequest('GET', self::API_URL);

        $this->assertCount(10, $data['member']);
        $this->assertEquals(25, $data['totalItems']);
    }

    public function testPageTwoReturnsCorrectItems(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?page=2');

        $this->assertCount(10, $data['member']);
        $this->assertEquals(25, $data['totalItems']);
    }

    public function testClientCanSetItemsPerPage(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?itemsPerPage=15');

        $this->assertCount(15, $data['member']);
        $this->assertEquals(25, $data['totalItems']);
    }

    public function testItemsPerPageExceedingMaximumIsCapped(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?itemsPerPage=100');

        $this->assertLessThanOrEqual(50, count($data['member']));
        $this->assertEquals(25, $data['totalItems']);
    }

    public function testPageBeyondLastReturnsEmpty(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?page=99');

        $this->assertCount(0, $data['member']);
        $this->assertEquals(25, $data['totalItems']);
    }

    public function testNegativeItemsPerPageReturns400(): void
    {
        $this->apiRequest('GET', self::API_URL.'?itemsPerPage=-5', 400);
    }

    public function testNegativePageNumberReturns400(): void
    {
        $this->apiRequest('GET', self::API_URL.'?page=-1', 400);
    }

    public function testViewContainsNavigationLinks(): void
    {
        $data = $this->apiRequest('GET', self::API_URL);

        $this->assertArrayHasKey('view', $data);
        $this->assertArrayHasKey('first', $data['view']);
        $this->assertArrayHasKey('last', $data['view']);
        $this->assertArrayHasKey('next', $data['view']);
    }

    private function apiRequest(string $method, string $url, int $expectedStatus = 200): array
    {
        $response = static::createClient()->request($method, $url, [
            'auth_bearer' => $this->token,
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertEquals($expectedStatus, $response->getStatusCode());

        return $response->toArray(false);
    }

    private function seedRecipes(int $count): void
    {
        $category = new Category();
        $category->setName('Test');
        $this->em->persist($category);

        for ($i = 1; $i <= $count; ++$i) {
            $recipe = new Recipe();
            $recipe->setTitle("Recipe $i test")
                ->setDuration(30)
                ->setCategory($category)
                ->setDescription("Description for recipe $i");
            $this->em->persist($recipe);
        }

        $this->em->flush();
    }

    private function getJwtToken(): string
    {
        $admin = $this->em->getRepository(Admin::class)->findOneBy(['username' => 'test_admin']);

        if (!$admin) {
            $admin = new Admin();
            $admin->setUsername('test_admin');
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
            $admin->setPassword($hasher->hashPassword($admin, 'password'));
            $admin->setRoles(['ROLE_ADMIN']);
            $this->em->persist($admin);
            $this->em->flush();
        }

        $response = static::createClient()->request('POST', '/api/login_check', [
            'json' => ['username' => 'test_admin', 'password' => 'password'],
        ]);

        return $response->toArray()['token'];
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Recipe r WHERE r.title LIKE :p')
            ->setParameter('p', '%test%')
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :u')
            ->setParameter('u', 'test_admin')
            ->execute();
        parent::tearDown();
        $this->em->close();
    }
}
