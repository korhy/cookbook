<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\IngredientUnit;
use App\Enum\RecipeStatus;

/**
 * The draft quarantine has to hold on every road that leads to a recipe, not just on `/recipes`.
 *
 * `Instruction` and `RecipeIngredient` are exposed as resources of their own, and they carry the
 * parts of a recipe that actually matter — the step text, the quantities. A draft whose recipe
 * 404s but whose instructions are readable one URL over is not quarantined, it is merely
 * inconvenient to read.
 *
 * @see RecipeDraftVisibilityTest for the `Recipe` resource itself
 */
class DraftLeakTest extends AuthenticatedApiTestCase
{
    private const INSTRUCTIONS_URL = '/api/v1/instructions';
    private const RECIPE_INGREDIENTS_URL = '/api/v1/recipe_ingredients';

    private const DRAFT_STEP = 'Draft step: this must never leave the back-office.';
    private const PUBLISHED_STEP = 'Published step: this is public.';

    private int $draftInstructionId;
    private int $publishedInstructionId;
    private int $draftIngredientLineId;
    private int $publishedIngredientLineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    /**
     * The ingredients have to go after the lines that reference them: `recipe_ingredient.
     * ingredient_id` is a plain foreign key, so deleting an ingredient still joined to a line is a
     * constraint violation. Hooking the parent's cleanup rather than tearDown() keeps that order in
     * both directions — setUp clears too.
     */
    protected function clearRecipes(): void
    {
        parent::clearRecipes();

        $this->em->createQuery('DELETE FROM App\Entity\Ingredient i WHERE i.name LIKE :name')
            ->setParameter('name', 'leaktest%')
            ->execute();
    }

    public function testDraftInstructionsAreAbsentFromTheCollection(): void
    {
        $data = $this->apiRequest('GET', self::INSTRUCTIONS_URL);

        $this->assertSame(1, $data['totalItems']);
        $this->assertSame(self::PUBLISHED_STEP, $data['member'][0]['content']);
    }

    public function testTheDraftStepTextIsNowhereInTheInstructionCollection(): void
    {
        $data = $this->apiRequest('GET', self::INSTRUCTIONS_URL);

        $this->assertStringNotContainsString(
            self::DRAFT_STEP,
            json_encode($data, \JSON_THROW_ON_ERROR),
            'A draft instruction reached the public instruction collection.'
        );
    }

    public function testADraftInstructionItemReturns404(): void
    {
        $this->apiRequest('GET', self::INSTRUCTIONS_URL.'/'.$this->draftInstructionId, 404);
    }

    public function testAPublishedInstructionItemIsStillReachable(): void
    {
        $data = $this->apiRequest('GET', self::INSTRUCTIONS_URL.'/'.$this->publishedInstructionId);

        $this->assertSame(self::PUBLISHED_STEP, $data['content']);
    }

    public function testDraftIngredientLinesAreAbsentFromTheCollection(): void
    {
        $data = $this->apiRequest('GET', self::RECIPE_INGREDIENTS_URL);

        $this->assertSame(1, $data['totalItems']);
        $this->assertSame(12.5, $data['member'][0]['quantity']);
    }

    public function testADraftIngredientLineItemReturns404(): void
    {
        $this->apiRequest('GET', self::RECIPE_INGREDIENTS_URL.'/'.$this->draftIngredientLineId, 404);
    }

    public function testAPublishedIngredientLineItemIsStillReachable(): void
    {
        $data = $this->apiRequest('GET', self::RECIPE_INGREDIENTS_URL.'/'.$this->publishedIngredientLineId);

        $this->assertSame(12.5, $data['quantity']);
    }

    /**
     * One draft and one published recipe, each with exactly one instruction and one ingredient
     * line. The quantities differ (1.5 vs 12.5) so an assertion cannot pass by picking the wrong
     * row — and both are fractional, because a whole number comes back from JSON as an int.
     */
    private function seed(): void
    {
        $category = new Category();
        $category->setName('Test');
        $this->em->persist($category);

        $draftIngredient = new Ingredient();
        $draftIngredient->setName('leaktest draft ingredient');
        $this->em->persist($draftIngredient);

        $publishedIngredient = new Ingredient();
        $publishedIngredient->setName('leaktest published ingredient');
        $this->em->persist($publishedIngredient);

        $draft = new Recipe();
        $draft->setTitle('Draft recipe test')
            ->setDescription('Created through an untrusted path.')
            ->setDuration(30)
            ->setCategory($category)
            ->setStatus(RecipeStatus::Draft);

        $draftStep = new Instruction();
        $draftStep->setPosition(1)->setContent(self::DRAFT_STEP);
        $draft->addInstruction($draftStep);

        $draftLine = new RecipeIngredient();
        $draftLine->setIngredient($draftIngredient)
            ->setQuantity(1.5)
            ->setUnit(IngredientUnit::Gram);
        $draft->addRecipeIngredient($draftLine);

        $this->em->persist($draft);

        $published = new Recipe();
        $published->setTitle('Published recipe test')
            ->setDescription('Authored in EasyAdmin.')
            ->setDuration(30)
            ->setCategory($category);

        $publishedStep = new Instruction();
        $publishedStep->setPosition(1)->setContent(self::PUBLISHED_STEP);
        $published->addInstruction($publishedStep);

        $publishedLine = new RecipeIngredient();
        $publishedLine->setIngredient($publishedIngredient)
            ->setQuantity(12.5)
            ->setUnit(IngredientUnit::Gram);
        $published->addRecipeIngredient($publishedLine);

        $this->em->persist($published);

        $this->em->flush();

        $this->draftInstructionId = $draftStep->getId();
        $this->publishedInstructionId = $publishedStep->getId();
        $this->draftIngredientLineId = $draftLine->getId();
        $this->publishedIngredientLineId = $publishedLine->getId();
    }
}
