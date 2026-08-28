<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Admin;
use App\Entity\Category;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The draft quarantine is the control that lets an untrusted authoring path create a recipe
 * without it reaching a consumer. Radiant reads this collection, so a leak here is the failure
 * that matters most.
 */
class RecipeDraftVisibilityTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private const API_URL = '/api/v1/recipes';

    private EntityManagerInterface $em;
    private string $token;
    private int $draftId;
    private int $publishedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\Recipe')->execute();
        $this->seed();
        $this->token = $this->getJwtToken();
    }

    public function testDraftIsAbsentFromTheCollection(): void
    {
        $data = $this->apiRequest('GET', self::API_URL);

        $this->assertSame(1, $data['totalItems']);
        $this->assertCount(1, $data['member']);
        $this->assertSame('Published recipe test', $data['member'][0]['title']);
    }

    public function testDraftItemReturns404(): void
    {
        $this->apiRequest('GET', self::API_URL.'/'.$this->draftId, 404);
    }

    public function testPublishedItemIsStillReachable(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->publishedId);

        $this->assertSame('Published recipe test', $data['title']);
    }

    public function testStatusIsExposedOnTheReadContract(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->publishedId);

        $this->assertArrayHasKey('status', $data);
        $this->assertSame('published', $data['status']);
    }

    public function testDraftIsNotReachableThroughTheTitleFilter(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?title=Draft');

        $this->assertSame(0, $data['totalItems']);
    }

    private function seed(): void
    {
        $category = new Category();
        $category->setName('Test');
        $this->em->persist($category);

        $draft = new Recipe();
        $draft->setTitle('Draft recipe test')
            ->setDescription('Created through an untrusted path.')
            ->setDuration(30)
            ->setCategory($category)
            ->setStatus(RecipeStatus::Draft);
        $this->em->persist($draft);

        $published = new Recipe();
        $published->setTitle('Published recipe test')
            ->setDescription('Authored in EasyAdmin.')
            ->setDuration(30)
            ->setCategory($category);
        $this->em->persist($published);

        $this->em->flush();

        $this->draftId = $draft->getId();
        $this->publishedId = $published->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function apiRequest(string $method, string $url, int $expectedStatus = 200): array
    {
        $response = static::createClient()->request($method, $url, [
            'auth_bearer' => $this->token,
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertEquals($expectedStatus, $response->getStatusCode());

        return $response->toArray(false);
    }

    private function getJwtToken(): string
    {
        $admin = $this->em->getRepository(Admin::class)->findOneBy(['username' => 'draft_test_admin']);

        if (!$admin) {
            $admin = new Admin();
            $admin->setUsername('draft_test_admin');
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
            $admin->setPassword($hasher->hashPassword($admin, 'password'));
            $admin->setRoles(['ROLE_ADMIN']);
            $this->em->persist($admin);
            $this->em->flush();
        }

        $response = static::createClient()->request('POST', '/api/login_check', [
            'json' => ['username' => 'draft_test_admin', 'password' => 'password'],
        ]);

        return $response->toArray()['token'];
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Recipe r WHERE r.title LIKE :p')
            ->setParameter('p', '%recipe test%')
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :u')
            ->setParameter('u', 'draft_test_admin')
            ->execute();
        parent::tearDown();
        $this->em->close();
    }
}
