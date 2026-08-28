<?php

declare(strict_types=1);

namespace App\Service\Recipe;

use App\DTO\Mcp\IngredientLineInput;
use App\DTO\Mcp\RecipeDraftInput;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\RecipeStatus;
use App\Exception\Mcp\RecipeDraftRejectedException;
use App\Repository\CategoryRepository;
use App\Repository\IngredientRepository;
use App\Repository\RecipeRepository;
use App\Service\SluggerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns a validated {@see RecipeDraftInput} into a persisted **draft** Recipe, minting any
 * ingredient the payload references but the database does not yet have.
 *
 * This is where the write actually happens, so it owns three guarantees the calling tool cannot:
 *
 * 1. **Atomicity.** The whole graph is written in one transaction. A recipe that fails validation
 *    must not leave newly-minted ingredients behind — that is how an unauthenticated caller would
 *    otherwise pollute the ingredient table without ever creating a recipe.
 * 2. **A bounded number of new ingredients.** Reusing existing rows is the normal case; minting is
 *    the exception, and it is capped.
 * 3. **Entity-level validation.** The draft goes through the same constraints as any other Recipe
 *    (`BanWord`, length), which no other non-form authoring path in this project applies.
 */
final class RecipeDraftFactory
{
    /**
     * A payload referencing more unknown ingredients than this is far more likely to be a typo
     * storm or an attempt to seed the table than a real recipe.
     */
    public const MAX_NEW_INGREDIENTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly SluggerService $sluggerService,
        private readonly RecipeRepository $recipeRepository,
        private readonly IngredientRepository $ingredientRepository,
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    /**
     * @return array{recipe: Recipe, createdIngredients: string[]}
     *
     * @throws RecipeDraftRejectedException
     */
    public function createDraft(RecipeDraftInput $input): array
    {
        $this->assertInputIsValid($input);

        $names = $this->normalisedIngredientNames($input->ingredients);
        $category = $this->resolveCategory($input->category);

        // The slug is owned by SlugListener on prePersist; this only predicts it so a collision is
        // reported as a clean error instead of a unique-constraint failure mid-transaction.
        $slug = $this->sluggerService->generateSlug($input->title);

        if ($this->recipeRepository->existsBySlug($slug)) {
            throw new RecipeDraftRejectedException([\sprintf('A recipe with the slug "%s" already exists.', $slug)]);
        }

        $existing = $this->ingredientRepository->findByNamesInsensitive($names);
        $newNames = array_values(array_filter(
            $names,
            static fn (string $name): bool => !isset($existing[mb_strtolower($name)]),
        ));

        if (\count($newNames) > self::MAX_NEW_INGREDIENTS) {
            throw new RecipeDraftRejectedException([\sprintf('This draft would create %d new ingredients; at most %d may be created in one call. Reuse existing ingredients (see the ingredient_search tool) or split the recipe.', \count($newNames), self::MAX_NEW_INGREDIENTS)]);
        }

        return $this->entityManager->wrapInTransaction(
            function () use ($input, $names, $category, $existing, $newNames): array {
                $ingredients = $existing;

                foreach ($newNames as $name) {
                    $ingredient = new Ingredient();
                    $ingredient->setName($name);
                    $this->entityManager->persist($ingredient);
                    $ingredients[mb_strtolower($name)] = $ingredient;
                }

                $recipe = new Recipe();
                $recipe->setTitle($input->title)
                    ->setDescription($input->description)
                    ->setDuration($input->duration)
                    ->setCategory($category)
                    ->setStatus(RecipeStatus::Draft);

                foreach ($input->ingredients as $index => $line) {
                    $recipeIngredient = new RecipeIngredient();
                    $recipeIngredient->setIngredient($ingredients[mb_strtolower($names[$index])])
                        ->setQuantity($line->quantity)
                        ->setUnit($line->unit);
                    $recipe->addRecipeIngredient($recipeIngredient);
                }

                foreach (array_values($input->instructions) as $index => $content) {
                    $instruction = new Instruction();
                    $instruction->setPosition($index + 1)
                        ->setContent(trim($content));
                    $recipe->addInstruction($instruction);
                }

                $violations = $this->validator->validate($recipe);

                if (\count($violations) > 0) {
                    // Rolls back the whole transaction, newly-minted ingredients included.
                    throw new RecipeDraftRejectedException($this->messages($violations));
                }

                $this->entityManager->persist($recipe);
                $this->entityManager->flush();

                return ['recipe' => $recipe, 'createdIngredients' => $newNames];
            }
        );
    }

    /**
     * @throws RecipeDraftRejectedException
     */
    private function assertInputIsValid(RecipeDraftInput $input): void
    {
        $violations = $this->validator->validate($input);

        if (\count($violations) > 0) {
            throw new RecipeDraftRejectedException($this->messages($violations));
        }
    }

    /**
     * @param IngredientLineInput[] $lines
     *
     * @return string[] normalised names, positionally aligned with $lines
     *
     * @throws RecipeDraftRejectedException
     */
    private function normalisedIngredientNames(array $lines): array
    {
        $names = [];
        $seen = [];

        foreach (array_values($lines) as $line) {
            // Collapse internal whitespace too: "  olive   oil " and "olive oil" must not become
            // two rows in the ingredient table.
            $name = trim((string) preg_replace('/\s+/u', ' ', $line->name));

            if ('' === $name) {
                throw new RecipeDraftRejectedException(['An ingredient name cannot be empty.']);
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                throw new RecipeDraftRejectedException([\sprintf('The ingredient "%s" is listed more than once.', $name)]);
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @throws RecipeDraftRejectedException
     */
    private function resolveCategory(?string $category): ?\App\Entity\Category
    {
        if (null === $category || '' === trim($category)) {
            return null;
        }

        $resolved = $this->categoryRepository->findOneByNameOrSlug($category);

        if (null === $resolved) {
            throw new RecipeDraftRejectedException([\sprintf('Unknown category "%s". Use the category_list tool to see the available categories.', $category)]);
        }

        return $resolved;
    }

    /**
     * @param \Symfony\Component\Validator\ConstraintViolationListInterface<\Symfony\Component\Validator\ConstraintViolationInterface> $violations
     *
     * @return string[]
     */
    private function messages(\Symfony\Component\Validator\ConstraintViolationListInterface $violations): array
    {
        $messages = [];

        foreach ($violations as $violation) {
            $property = $violation->getPropertyPath();
            $messages[] = '' === $property
                ? (string) $violation->getMessage()
                : \sprintf('%s: %s', $property, $violation->getMessage());
        }

        return $messages;
    }
}
