<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Repository\CategoryRepository;
use App\Service\SluggerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;

/**
 * The bulk CSV import, lifted out of the console command.
 *
 * Five datasets, each with the same shape: read rows, skip the malformed ones with a reason,
 * build the entity, flush in batches, clear the identity map so 120k rows fit in memory.
 * `ImportCsvCommand` is now only the console around it.
 *
 * Ids come from the CSV rather than from Postgres, which is why `useAssignedIds()` exists and why
 * `resynchroniseSequences()` has to run afterwards — otherwise the sequences stay at 1 while the
 * tables fill up, and the next ordinary insert collides.
 */
final class RecipeCsvImporter
{
    public const CATEGORIES_FILE = 'recipe_categories.csv';
    public const INGREDIENTS_FILE = 'ingredients.csv';
    public const RECIPES_FILE = 'recipes_final.csv';
    public const RECIPE_INGREDIENTS_FILE = 'recipe_ingredients.csv';
    public const INSTRUCTIONS_FILE = 'recipe_instructions.csv';

    /**
     * The entities whose ids come from the CSV files rather than from Postgres.
     */
    private const ENTITIES_WITH_ASSIGNED_IDS = [Category::class, Ingredient::class, Recipe::class];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly SluggerService $sluggerService,
        private readonly CsvRowReader $reader,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Switches the assigned-id entities off Postgres' identity generator.
     *
     * Must run before the first persist. It mutates Doctrine metadata for the rest of the process,
     * so it is an explicit call rather than something the constructor does behind your back.
     */
    public function useAssignedIds(): void
    {
        foreach (self::ENTITIES_WITH_ASSIGNED_IDS as $entityClass) {
            $metadata = $this->entityManager->getClassMetadata($entityClass);
            $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
            $metadata->setIdGenerator(new AssignedGenerator());
        }
    }

    /**
     * `category,id`. Existing categories are matched on slug and left alone.
     */
    public function importCategories(ImportOptions $options, ?callable $onRow = null): ImportSummary
    {
        return $this->import($options, self::CATEGORIES_FILE, 2, $onRow, function (array $row, ImportSummary $summary) use ($options): bool {
            [$name, $id] = $row;

            if ('' === trim((string) $name) || '' === trim((string) $id)) {
                $summary->recordSkipped('Missing required field in the categories file (category or id).');

                return false;
            }

            $slug = $this->sluggerService->generateSlug($name);

            if (null !== $this->categoryRepository->findOneBySlug($slug)) {
                $summary->recordSkipped(\sprintf('Category "%s" already exists.', trim((string) $name)));

                return false;
            }

            $category = new Category();
            $category->setId((int) $id);
            $category->setName(trim((string) $name));
            $category->setSlug($slug);

            $this->persist($category, $options);

            return true;
        });
    }

    /**
     * `ingredient,id`.
     */
    public function importIngredients(ImportOptions $options, ?callable $onRow = null): ImportSummary
    {
        return $this->import($options, self::INGREDIENTS_FILE, 2, $onRow, function (array $row, ImportSummary $summary) use ($options): bool {
            [$name, $id] = $row;

            if ('' === trim((string) $name) || '' === trim((string) $id)) {
                $summary->recordSkipped('Missing required field in the ingredients file (ingredient or id).');

                return false;
            }

            $ingredient = new Ingredient();
            $ingredient->setId((int) $id);
            $ingredient->setName(trim((string) $name));

            $this->persist($ingredient, $options);

            return true;
        });
    }

    /**
     * `id,recipe_title,description,id_category`.
     */
    public function importRecipes(ImportOptions $options, ?callable $onRow = null): ImportSummary
    {
        return $this->import($options, self::RECIPES_FILE, 4, $onRow, function (array $row, ImportSummary $summary) use ($options): bool {
            [$id, $title, $description, $categoryId] = $row;

            if ('' === trim((string) $title) || '' === trim((string) $description)) {
                $summary->recordSkipped('Missing required field in the recipes file (title or description).');

                return false;
            }

            $recipe = new Recipe();
            $recipe->setId((int) $id);
            $recipe->setTitle(trim((string) $title));
            $recipe->setSlug($this->sluggerService->generateSlug($title));
            $recipe->setDescription(trim((string) $description));
            $recipe->setCategory(
                '' === trim((string) $categoryId) ? null : $this->categoryRepository->find((int) $categoryId)
            );

            $this->persist($recipe, $options);

            return true;
        });
    }

    /**
     * `id_recipe,quantity,id_unit,id_ingredient`.
     *
     * `id_unit` is read and deliberately not mapped: there is no unit lookup table in this
     * application, and `RecipeIngredient::$unit` is an `IngredientUnit` enum whose cases do not
     * correspond to the numeric ids in the source data. Imported lines therefore carry no unit.
     */
    public function importRecipeIngredients(ImportOptions $options, ?callable $onRow = null): ImportSummary
    {
        return $this->import($options, self::RECIPE_INGREDIENTS_FILE, 4, $onRow, function (array $row, ImportSummary $summary) use ($options): bool {
            [$recipeId, $quantity, , $ingredientId] = $row;

            if ('' === trim((string) $recipeId) || '' === trim((string) $ingredientId)) {
                $summary->recordSkipped('Missing required field in the recipe ingredients file (id_recipe or id_ingredient).');

                return false;
            }

            $line = new RecipeIngredient();
            $line->setRecipe($this->entityManager->getReference(Recipe::class, (int) $recipeId));
            $line->setIngredient($this->entityManager->getReference(Ingredient::class, (int) $ingredientId));

            if ('' !== trim((string) $quantity) && is_numeric($quantity)) {
                $line->setQuantity((float) $quantity);
            }

            $this->persist($line, $options);

            return true;
        });
    }

    /**
     * `id_recipe,content,position`.
     */
    public function importInstructions(ImportOptions $options, ?callable $onRow = null): ImportSummary
    {
        return $this->import($options, self::INSTRUCTIONS_FILE, 3, $onRow, function (array $row, ImportSummary $summary) use ($options): bool {
            [$recipeId, $content, $position] = $row;

            if ('' === trim((string) $recipeId) || '' === trim((string) $content)) {
                $summary->recordSkipped('Missing required field in the recipe instructions file (id_recipe or content).');

                return false;
            }

            $instruction = new Instruction();
            $instruction->setRecipe($this->entityManager->getReference(Recipe::class, (int) $recipeId));
            $instruction->setContent(trim((string) $content));
            $instruction->setPosition((int) $position);

            $this->persist($instruction, $options);

            return true;
        });
    }

    /**
     * Advances each assigned-id sequence past the rows the import just wrote.
     *
     * `setval`'s third argument is `is_called`: true means "the next id is this + 1", which is what
     * a populated table needs. For an empty table it is false, so the next id is 1 rather than 2.
     *
     * @return string[] the tables that were resynchronised
     */
    public function resynchroniseSequences(): array
    {
        $connection = $this->entityManager->getConnection();
        $tables = [];

        foreach (self::ENTITIES_WITH_ASSIGNED_IDS as $entityClass) {
            // From Doctrine metadata, never from input: a table name cannot be a bound parameter.
            $table = $this->entityManager->getClassMetadata($entityClass)->getTableName();

            $connection->executeStatement(\sprintf(
                "SELECT setval(pg_get_serial_sequence('%1\$s', 'id'),"
                .' COALESCE((SELECT MAX(id) FROM %1$s), 1),'
                .' (SELECT MAX(id) FROM %1$s) IS NOT NULL)',
                $table,
            ));

            $tables[] = $table;
        }

        return $tables;
    }

    /**
     * The shape every dataset shares.
     *
     * @param callable(string[], ImportSummary): bool $handle returns true when a row was imported
     * @param callable():void|null                    $onRow  progress tick, once per imported row
     */
    private function import(
        ImportOptions $options,
        string $fileName,
        int $expectedColumns,
        ?callable $onRow,
        callable $handle,
    ): ImportSummary {
        $summary = new ImportSummary();
        $processed = 0;

        foreach ($this->reader->rows($options->pathFor($fileName), $options->delimiter, $options->skipHeader) as $row) {
            if (\count($row) < $expectedColumns) {
                $summary->recordSkipped(\sprintf('Invalid row in %s (expected at least %d columns).', $fileName, $expectedColumns));

                continue;
            }

            try {
                if (!$handle($row, $summary)) {
                    continue;
                }
            } catch (\Throwable $e) {
                $summary->recordSkipped(\sprintf('Error processing a row in %s: %s', $fileName, $e->getMessage()));
                $this->logger->error('CSV import row failed', ['file' => $fileName, 'exception' => $e, 'row' => $row]);

                continue;
            }

            $summary->recordImported();
            ++$processed;

            if (null !== $onRow) {
                $onRow();
            }

            if (!$options->dryRun && 0 === $processed % $options->batchSize) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        if (!$options->dryRun) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return $summary;
    }

    private function persist(object $entity, ImportOptions $options): void
    {
        if (!$options->dryRun) {
            $this->entityManager->persist($entity);
        }
    }
}
