<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool\Write;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use App\Mcp\Tool\Write\RecipeCreateTool;
use App\Repository\IngredientRepository;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * recipe_create is the only tool that writes, so most of these are refusal tests: what the tool
 * declines matters more than what it accepts.
 *
 * The *authorization* of a call is no longer this class's business — it belongs to the
 * `mcp_authoring` firewall and is covered end to end by App\Tests\Api\McpAuthoringEndpointTest,
 * which is the only place a 401 or a 403 can actually be observed. What is set up here is the
 * state a call has already reached by the time the tool runs: an authenticated OAuth token in
 * storage, which App\Service\Mcp\McpWriteGuard reads for its rate limit and its audit entry.
 */
final class RecipeCreateToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecipeCreateTool $tool;
    private RecipeRepository $recipes;
    private IngredientRepository $ingredients;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tool = self::getContainer()->get(RecipeCreateTool::class);
        $this->recipes = self::getContainer()->get(RecipeRepository::class);
        $this->ingredients = self::getContainer()->get(IngredientRepository::class);
        $this->suffix = substr(uniqid(), -6);

        $this->authenticate();

        $category = new Category();
        $category->setName('Draft test category '.$this->suffix);
        $this->em->persist($category);

        $flour = new Ingredient();
        $flour->setName('Draft test flour '.$this->suffix);
        $this->em->persist($flour);

        $this->em->flush();
    }

    public function testCreatesARecipeAsADraft(): void
    {
        $result = $this->create();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('draft', $result['status']);
        $this->assertNotNull($result['id']);

        $recipe = $this->em->getRepository(Recipe::class)->find($result['id']);
        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertSame(RecipeStatus::Draft, $recipe->getStatus());
    }

    public function testTheCreatedDraftIsInvisibleToTheReadTools(): void
    {
        $result = $this->create();

        // findOneBySlug backs the public recipe_get tool.
        $this->assertNull($this->recipes->findOneBySlug($result['slug']));
    }

    /**
     * There is no credential parameter left to get wrong — removing it is the whole point of the
     * OAuth work — so the refusal this covers is the defence-in-depth one: a call that somehow
     * arrives with nothing authenticated must write nothing. Reaching this state means the
     * firewall stopped protecting the endpoint, and the tool must not make that silent.
     */
    public function testACallWithNothingAuthenticatedIsRefusedAndWritesNothing(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);

        $before = $this->recipeCount();

        $result = ($this->tool)(
            title: 'Unauthorized cake '.$this->suffix,
            description: 'Should never be stored.',
            ingredients: [['name' => 'Draft test flour '.$this->suffix]],
            instructions: ['Mix.'],
        );

        $this->assertSame('Write access denied.', $result['error']);
        $this->assertSame($before, $this->recipeCount());
    }

    public function testExistingIngredientsAreReusedRatherThanDuplicated(): void
    {
        // Differing case and padding must still resolve to the one existing row.
        $result = $this->create(ingredients: [
            ['name' => '  DRAFT TEST FLOUR '.strtoupper($this->suffix).' ', 'quantity' => 200, 'unit' => 'g'],
        ]);

        $this->assertSame([], $result['created_ingredients']);
        $this->assertCount(1, $this->ingredients->findByNamesInsensitive(['Draft test flour '.$this->suffix]));
    }

    public function testUnknownIngredientsAreCreated(): void
    {
        $result = $this->create(ingredients: [
            ['name' => 'Draft test flour '.$this->suffix],
            ['name' => 'Draft test cocoa '.$this->suffix, 'quantity' => 50, 'unit' => 'g'],
        ]);

        $this->assertSame(['Draft test cocoa '.$this->suffix], $result['created_ingredients']);
    }

    public function testCreatingTooManyNewIngredientsIsRefused(): void
    {
        $lines = [];
        for ($i = 0; $i < 6; ++$i) {
            $lines[] = ['name' => \sprintf('Draft test novel %d %s', $i, $this->suffix)];
        }

        $result = $this->create(ingredients: $lines);

        $this->assertArrayHasKey('reasons', $result);
        $this->assertStringContainsString('at most 5', implode(' ', $result['reasons']));
    }

    /**
     * The ingredient table must not be polluted by a payload that never becomes a recipe.
     *
     * This asserts the outcome, not the mechanism: the property is currently held by the deferred
     * flush (nothing is written until the recipe validates) with wrapInTransaction() as the
     * backstop if a future change introduces an earlier flush.
     */
    public function testARejectedDraftCreatesNoIngredients(): void
    {
        $before = $this->ingredientCount();

        // "spam" trips the BanWord constraint on Recipe::$title, which fires at entity validation,
        // i.e. after the new ingredients have been persisted inside the transaction.
        $result = ($this->tool)(
            title: 'A spam cake '.$this->suffix,
            description: 'Rejected by the BanWord constraint.',
            ingredients: [['name' => 'Draft test rollback '.$this->suffix]],
            instructions: ['Mix.'],
        );

        $this->assertArrayHasKey('reasons', $result);
        $this->em->clear();
        $this->assertSame($before, $this->ingredientCount());
    }

    public function testDuplicateSlugIsRefused(): void
    {
        $this->create();
        $result = $this->create();

        $this->assertArrayHasKey('reasons', $result);
        $this->assertStringContainsString('already exists', implode(' ', $result['reasons']));
    }

    public function testUnknownCategoryIsRefused(): void
    {
        $result = $this->create(category: 'No such category '.$this->suffix);

        $this->assertStringContainsString('Unknown category', implode(' ', $result['reasons']));
    }

    public function testKnownCategoryIsResolvedCaseInsensitively(): void
    {
        $result = $this->create(category: strtoupper('Draft test category '.$this->suffix));

        $this->assertArrayNotHasKey('error', $result);
    }

    public function testUnknownUnitIsRefused(): void
    {
        $result = $this->create(ingredients: [
            ['name' => 'Draft test flour '.$this->suffix, 'quantity' => 1, 'unit' => 'furlong'],
        ]);

        $this->assertStringContainsString('unknown unit', implode(' ', $result['reasons']));
    }

    public function testDuplicateIngredientLinesAreRefused(): void
    {
        $result = $this->create(ingredients: [
            ['name' => 'Draft test flour '.$this->suffix],
            ['name' => 'DRAFT TEST FLOUR '.$this->suffix],
        ]);

        $this->assertStringContainsString('listed more than once', implode(' ', $result['reasons']));
    }

    public function testTooManyInstructionsIsRefused(): void
    {
        $result = $this->create(instructions: array_fill(0, 51, 'Stir.'));

        $this->assertArrayHasKey('reasons', $result);
    }

    public function testInstructionPositionsFollowTheArrayOrder(): void
    {
        $result = $this->create(instructions: ['First.', 'Second.', 'Third.']);

        $recipe = $this->em->getRepository(Recipe::class)->find($result['id']);
        $this->assertInstanceOf(Recipe::class, $recipe);

        $steps = $recipe->getInstructions()->toArray();
        $this->assertSame([1, 2, 3], array_map(static fn ($i) => $i->getPosition(), $steps));
        $this->assertSame('First.', $steps[0]->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The identity a request carries by the time a tool runs: the single pre-registered public
     * client, acting for the Admin account, with the one scope this server issues.
     */
    private function authenticate(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new OAuth2Token(
            new InMemoryUser('admin', null, ['ROLE_ADMIN']),
            'test-access-token-id',
            'cookbook-mcp-authoring',
            ['mcp:write'],
            'ROLE_OAUTH2_',
        ));
    }

    private function create(?array $ingredients = null, ?array $instructions = null, ?string $category = null): array
    {
        return ($this->tool)(
            title: 'Draft test cake '.$this->suffix,
            description: 'A cake created through the MCP write path.',
            ingredients: $ingredients ?? [['name' => 'Draft test flour '.$this->suffix, 'quantity' => 200, 'unit' => 'g']],
            instructions: $instructions ?? ['Mix everything.', 'Bake.'],
            category: $category,
            duration: 45,
        );
    }

    private function recipeCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(r.id) FROM App\Entity\Recipe r')->getSingleScalarResult();
    }

    private function ingredientCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(i.id) FROM App\Entity\Ingredient i')->getSingleScalarResult();
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Instruction i WHERE i.recipe IN (SELECT r.id FROM App\Entity\Recipe r WHERE r.title LIKE :p)')
            ->setParameter('p', '%'.$this->suffix)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RecipeIngredient ri WHERE ri.recipe IN (SELECT r2.id FROM App\Entity\Recipe r2 WHERE r2.title LIKE :p)')
            ->setParameter('p', '%'.$this->suffix)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Recipe r WHERE r.title LIKE :p')
            ->setParameter('p', '%'.$this->suffix)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Ingredient i WHERE i.name LIKE :p')
            ->setParameter('p', '%'.$this->suffix)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :p')
            ->setParameter('p', '%'.$this->suffix)->execute();

        parent::tearDown();
        $this->em->close();
    }
}
