<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;

/**
 * The draft quarantine is the control that lets an untrusted authoring path create a recipe
 * without it reaching a consumer. Radiant reads this collection, so a leak here is the failure
 * that matters most.
 *
 * This covers the `Recipe` resource itself. The join resources that reach a recipe through an
 * association are covered by {@see DraftLeakTest}.
 */
class RecipeDraftVisibilityTest extends AuthenticatedApiTestCase
{
    private const API_URL = '/api/v1/recipes';

    private int $draftId;
    private int $publishedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
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
}
