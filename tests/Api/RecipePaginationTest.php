<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Recipe;

class RecipePaginationTest extends AuthenticatedApiTestCase
{
    private const API_URL = '/api/v1/recipes';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRecipes(25);
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
}
