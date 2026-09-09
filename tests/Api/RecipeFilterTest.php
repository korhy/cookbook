<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\IngredientUnit;

/**
 * The query parameters published by the Recipe collection operation are a contract with Radiant,
 * and until now nothing asserted them.
 *
 * Every parameter covered here is one Radiant actually sends — see
 * `radiant/src/Controller/CookbookController.php`, which maps its own `order[title]` onto
 * `order[slug]`. Renaming or dropping any of them is a breaking change; this test is what will say
 * so.
 */
class RecipeFilterTest extends AuthenticatedApiTestCase
{
    private const API_URL = '/api/v1/recipes';

    private int $dessertId;
    private int $starterId;
    private int $flourId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    protected function clearRecipes(): void
    {
        parent::clearRecipes();

        $this->em->createQuery('DELETE FROM App\Entity\Ingredient i WHERE i.name LIKE :name')
            ->setParameter('name', 'filtertest%')
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :name')
            ->setParameter('name', 'Filtertest%')
            ->execute();
    }

    public function testTitleFilterMatchesAPartialString(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['title' => 'choco']));

        $this->assertSame(1, $data['totalItems']);
        $this->assertSame('Chocolate cake test', $data['member'][0]['title']);
    }

    public function testTitleFilterIsCaseInsensitive(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['title' => 'CHOCOLATE']));

        $this->assertSame(1, $data['totalItems']);
        $this->assertSame('Chocolate cake test', $data['member'][0]['title']);
    }

    public function testTitleFilterMatchingNothingReturnsAnEmptyCollection(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['title' => 'nothingmatchesthis']));

        $this->assertSame(0, $data['totalItems']);
        $this->assertSame([], $data['member']);
    }

    public function testAnAbsentTitleFilterDoesNotTouchTheQuery(): void
    {
        $data = $this->apiRequest('GET', self::API_URL);

        $this->assertSame(3, $data['totalItems']);
    }

    public function testCategoryFilterTakesAnIri(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query([
            'category' => '/api/v1/categories/'.$this->dessertId,
        ]));

        $this->assertSame(2, $data['totalItems']);
        $this->assertSame(
            ['Apple pie test', 'Chocolate cake test'],
            $this->sortedTitles($data)
        );
    }

    public function testCategoryFilterNarrowsToTheOtherCategory(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query([
            'category' => '/api/v1/categories/'.$this->starterId,
        ]));

        $this->assertSame(1, $data['totalItems']);
        $this->assertSame('Onion soup test', $data['member'][0]['title']);
    }

    public function testIngredientFilterTakesAnIri(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query([
            'ingredient' => '/api/v1/ingredients/'.$this->flourId,
        ]));

        $this->assertSame(1, $data['totalItems']);
        $this->assertSame('Chocolate cake test', $data['member'][0]['title']);
    }

    public function testOrderBySlugAscending(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['order' => ['slug' => 'asc']]));

        $this->assertSame(
            ['apple-pie-test', 'chocolate-cake-test', 'onion-soup-test'],
            array_column($data['member'], 'slug')
        );
    }

    public function testOrderBySlugDescending(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['order' => ['slug' => 'desc']]));

        $this->assertSame(
            ['onion-soup-test', 'chocolate-cake-test', 'apple-pie-test'],
            array_column($data['member'], 'slug')
        );
    }

    public function testOrderByDurationAscending(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['order' => ['duration' => 'asc']]));

        $this->assertSame([30, 45, 60], array_column($data['member'], 'duration'));
    }

    public function testOrderByDurationDescending(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['order' => ['duration' => 'desc']]));

        $this->assertSame([60, 45, 30], array_column($data['member'], 'duration'));
    }

    public function testOrderByCreatedAtAscending(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['order' => ['createdAt' => 'asc']]));

        $this->assertSame(
            ['chocolate-cake-test', 'apple-pie-test', 'onion-soup-test'],
            array_column($data['member'], 'slug')
        );
    }

    public function testOrderByCreatedAtDescending(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query(['order' => ['createdAt' => 'desc']]));

        $this->assertSame(
            ['onion-soup-test', 'apple-pie-test', 'chocolate-cake-test'],
            array_column($data['member'], 'slug')
        );
    }

    public function testAFilterAndAnOrderCombine(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'?'.http_build_query([
            'category' => '/api/v1/categories/'.$this->dessertId,
            'order' => ['duration' => 'asc'],
        ]));

        $this->assertSame([45, 60], array_column($data['member'], 'duration'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string[]
     */
    private function sortedTitles(array $data): array
    {
        $titles = array_column($data['member'], 'title');
        sort($titles);

        return $titles;
    }

    /**
     * Three recipes across two categories, with distinct durations and distinct creation dates.
     *
     * `createdAt` is assigned in a second flush on purpose: the entity stamps it from a
     * `#[ORM\PrePersist]` callback, so three rows written in one flush land in the same second and
     * an ordering assertion on them would be a coin toss.
     */
    private function seed(): void
    {
        $dessert = new Category();
        $dessert->setName('Filtertest dessert');
        $this->em->persist($dessert);

        $starter = new Category();
        $starter->setName('Filtertest starter');
        $this->em->persist($starter);

        $flour = new Ingredient();
        $flour->setName('filtertest flour');
        $this->em->persist($flour);

        $apple = new Ingredient();
        $apple->setName('filtertest apple');
        $this->em->persist($apple);

        $onion = new Ingredient();
        $onion->setName('filtertest onion');
        $this->em->persist($onion);

        $cake = $this->recipe('Chocolate cake test', $dessert, 60, $flour);
        $pie = $this->recipe('Apple pie test', $dessert, 45, $apple);
        $soup = $this->recipe('Onion soup test', $starter, 30, $onion);

        $this->em->flush();

        $cake->setCreatedAt(new \DateTimeImmutable('2026-01-01 12:00:00'));
        $pie->setCreatedAt(new \DateTimeImmutable('2026-01-02 12:00:00'));
        $soup->setCreatedAt(new \DateTimeImmutable('2026-01-03 12:00:00'));

        $this->em->flush();

        $this->dessertId = $dessert->getId();
        $this->starterId = $starter->getId();
        $this->flourId = $flour->getId();
    }

    private function recipe(string $title, Category $category, int $duration, Ingredient $ingredient): Recipe
    {
        $recipe = new Recipe();
        $recipe->setTitle($title)
            ->setDescription($title.' description.')
            ->setDuration($duration)
            ->setCategory($category);

        $line = new RecipeIngredient();
        $line->setIngredient($ingredient)
            ->setQuantity(200.0)
            ->setUnit(IngredientUnit::Gram);
        $recipe->addRecipeIngredient($line);

        $this->em->persist($recipe);

        return $recipe;
    }
}
