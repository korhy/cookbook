<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\IngredientUnit;

/**
 * The serialized shape of a recipe *is* the contract — Radiant renders these keys straight into
 * Twig, so a change here breaks a page rather than a test.
 *
 * Three behaviours in particular are produced by code rather than by attributes, which makes them
 * the easiest things in the project to break by accident:
 *
 * 1. `thumbnail` is rewritten into an absolute URL by {@see \App\Serializer\RecipeNormalizer}.
 * 2. `unit` is rewritten into a translated label by {@see \App\Serializer\IngredientUnitNormalizer},
 *    which means the response depends on the request's `Accept-Language`.
 * 3. `instructions` come back ordered by position, from the mapping's `#[ORM\OrderBy]`.
 */
class RecipeSerializationTest extends AuthenticatedApiTestCase
{
    private const API_URL = '/api/v1/recipes';

    private int $recipeId;
    private int $plainRecipeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    protected function clearRecipes(): void
    {
        parent::clearRecipes();

        $this->em->createQuery('DELETE FROM App\Entity\Ingredient i WHERE i.name LIKE :name')
            ->setParameter('name', 'sertest%')
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :name')
            ->setParameter('name', 'Sertest%')
            ->execute();
    }

    public function testTheReadContractExposesExactlyTheseKeys(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId);

        foreach (['id', 'title', 'slug', 'description', 'createdAt', 'updatedAt', 'duration', 'status', 'category', 'thumbnail', 'recipeIngredients', 'instructions'] as $key) {
            $this->assertArrayHasKey($key, $data, \sprintf('The read contract lost the "%s" property.', $key));
        }
    }

    public function testNullsAreSerializedRatherThanOmitted(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->plainRecipeId);

        // skip_null_values is false globally so consumers get a stable key set.
        $this->assertArrayHasKey('thumbnail', $data);
        $this->assertNull($data['thumbnail']);
        $this->assertArrayHasKey('updatedAt', $data);
    }

    public function testThumbnailIsRewrittenIntoAnAbsoluteUrl(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId);

        $this->assertMatchesRegularExpression(
            '#^https?://[^/]+/images/recipes_thumbnails/example\.jpg$#',
            $data['thumbnail'],
            'Radiant renders this straight into an <img src>, so it has to be absolute.'
        );
    }

    public function testCategoryIsEmbeddedRatherThanAnIri(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId);

        $this->assertIsArray($data['category']);
        $this->assertSame('Sertest dessert', $data['category']['name']);
        $this->assertArrayHasKey('slug', $data['category']);
    }

    /**
     * The nested collections are hydra collections, not plain arrays — Radiant iterates
     * `recipe.recipeIngredients.member` and `recipe.instructions.member` in Twig, so flattening
     * either of them into a bare list would empty both loops without erroring.
     */
    public function testNestedCollectionsKeepTheHydraMemberShape(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId);

        foreach (['recipeIngredients', 'instructions'] as $key) {
            $this->assertArrayHasKey('member', $data[$key], \sprintf('"%s" lost its hydra member wrapper.', $key));
            $this->assertArrayHasKey('totalItems', $data[$key]);
        }

        $this->assertSame(1, $data['recipeIngredients']['totalItems']);
        $this->assertSame(2, $data['instructions']['totalItems']);
    }

    public function testIngredientLinesEmbedTheIngredientAndItsQuantity(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId);

        $lines = $data['recipeIngredients']['member'];

        $this->assertCount(1, $lines);
        $this->assertSame(2.5, $lines[0]['quantity']);
        $this->assertIsArray($lines[0]['ingredient']);
        $this->assertSame('sertest flour', $lines[0]['ingredient']['name']);
    }

    public function testUnitIsTranslatedIntoFrench(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId, 200, ['Accept-Language' => 'fr']);

        $this->assertSame('Cuillère à soupe', $data['recipeIngredients']['member'][0]['unit']);
    }

    public function testUnitIsTranslatedIntoEnglish(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId, 200, ['Accept-Language' => 'en']);

        $this->assertSame('Tablespoon', $data['recipeIngredients']['member'][0]['unit']);
    }

    public function testInstructionsComeBackOrderedByPosition(): void
    {
        $data = $this->apiRequest('GET', self::API_URL.'/'.$this->recipeId);

        $steps = $data['instructions']['member'];

        $this->assertSame([1, 2], array_column($steps, 'position'));
        $this->assertSame(
            ['Sertest first step.', 'Sertest second step.'],
            array_column($steps, 'content')
        );
    }

    /**
     * The instructions are added in reverse order so that "ordered by position" cannot pass just
     * because the rows happen to come back in insertion order.
     */
    private function seed(): void
    {
        $category = new Category();
        $category->setName('Sertest dessert');
        $this->em->persist($category);

        $flour = new Ingredient();
        $flour->setName('sertest flour');
        $this->em->persist($flour);

        $recipe = new Recipe();
        $recipe->setTitle('Sertest full recipe')
            ->setDescription('Every serialization concern in one row.')
            ->setDuration(45)
            ->setCategory($category)
            // The filename only — VichUploader resolves the URI from the mapping's uri_prefix and
            // never touches the filesystem on read, so no fixture file is needed.
            ->setThumbnail('example.jpg');

        $second = new Instruction();
        $second->setPosition(2)->setContent('Sertest second step.');
        $recipe->addInstruction($second);

        $first = new Instruction();
        $first->setPosition(1)->setContent('Sertest first step.');
        $recipe->addInstruction($first);

        $line = new RecipeIngredient();
        $line->setIngredient($flour)
            ->setQuantity(2.5)
            ->setUnit(IngredientUnit::Tablespoon);
        $recipe->addRecipeIngredient($line);

        $this->em->persist($recipe);

        $plain = new Recipe();
        $plain->setTitle('Sertest bare recipe')
            ->setDescription('No thumbnail, no category, no children.');
        $this->em->persist($plain);

        $this->em->flush();

        $this->recipeId = $recipe->getId();
        $this->plainRecipeId = $plain->getId();
    }
}
