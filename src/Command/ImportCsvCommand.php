<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Recipe;
use App\Entity\Ingredient;
use App\Entity\RecipeIngredient;
use App\Service\SluggerService;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-csv',
    description: 'Imports recipes and related data from a CSV file into the database',
)]
class ImportCsvCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SluggerService $sluggerService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('delimiter', 'd', InputOption::VALUE_OPTIONAL, 'CSV delimiter', ',')
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Number of records to process per batch', 50)
            ->addOption('skip-header', null, InputOption::VALUE_NONE, 'Skip the first row (header)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview import without saving to database')
            ->setHelp(<<<'HELP'
This command imports recipes from a CSV file.

Expected CSV format:
title,slug,description,duration,category_name,category_slug,thumbnail

Example:
    php bin/console app:import-csv
    php bin/console app:import-csv --dry-run
    php bin/console app:import-csv --batch-size=100
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $io = new SymfonyStyle($input, $output);

            $this->disableAutoIncrement(Category::class);
            $this->disableAutoIncrement(Ingredient::class);
            $this->disableAutoIncrement(Recipe::class);
        
            $delimiter = $input->getOption('delimiter');
            $batchSize = (int) $input->getOption('batch-size');
            $skipHeader = $input->getOption('skip-header');
            $dryRun = $input->getOption('dry-run');

            if ($dryRun) {
                $io->warning('DRY RUN MODE - No data will be saved to database');
            }

            /**
             * Import Categories
             */

            $categoryFilePath = __DIR__ . '/../../public/data/recipe_categories.csv';
            $this->validateFile($categoryFilePath);

            $io->title('CSV Import: Categories');
            $io->writeln(sprintf('File: %s', $categoryFilePath));
            $io->newLine();

            $categoryFile = fopen($categoryFilePath, 'r');
            if ($categoryFile === false) {
                throw new \IOException('Unable to open the categories file');
            }
            if ($skipHeader) {
                fgetcsv($categoryFile, 0, $delimiter);
            }

            $io->section('Processing CSV...');
            $io->progressStart();

            $totalCategories = 0;
            while (($row = fgetcsv($categoryFile, 0, $delimiter, '"', '\\')) !== false) {
                try {
                    // Expected format: name, id
                    if (count($row) < 2) {
                        $io->warning('Invalid row format in categories file (expected at least 2 columns)');
                        continue;
                    }

                    [$name, $id] = $row;

                    // Validate required fields
                    if (empty($name) || empty($id)) {
                        $io->warning('Missing required fields in categories file (name or id)');
                        continue;
                    }

                    # Generate slug
                    $categorySlug = $this->sluggerService->generateSlug($name);

                    // Check if category already exists
                    $existingCategory = $this->entityManager
                        ->getRepository(Category::class)
                        ->findOneBy(['slug' => $categorySlug]);

                    if ($existingCategory) {
                        continue; // Skip existing category
                    }

                    // Create new category
                    $category = new Category();
                    $category->setId((int) $id);
                    $category->setName(trim($name));
                    $category->setSlug($categorySlug);

                    if (!$dryRun) {
                        $this->entityManager->persist($category);
                    }

                    $io->progressAdvance();
                    $totalCategories++;

                } catch (\Exception $e) {
                    $io->warning(sprintf('Error processing category row: %s', $e->getMessage()));
                }
            }

            // Final flush for remaining categories
            if (!$dryRun) {
                $this->entityManager->flush();
            }
            $this->clearEntityManager(); // Clear to free memory

            fclose($categoryFile);

            $io->progressFinish();
            $io->newLine();
            $io->success(sprintf('Imported %d categories%s!', $totalCategories, $dryRun ? ' (dry run)' : ''));

            /**
             * Import Ingredients
             */
            $ingredientsFilePath = __DIR__ . '/../../public/data/ingredients.csv';
            $this->validateFile($ingredientsFilePath);
            $io->title('CSV Import: Ingredients');
            $io->writeln(sprintf('File: %s', $ingredientsFilePath));
            $io->writeln(sprintf('Batch size: %d', $batchSize));
            $io->newLine();

            $totalIngredients = 0;
            $ingredientsFile = fopen($ingredientsFilePath, 'r');
            if ($ingredientsFile === false) {
                throw new \IOException('Unable to open the ingredients file');
            }
            if ($skipHeader) {
                fgetcsv($ingredientsFile, 0, $delimiter);
            }

            $io->section('Processing ingredients CSV...');

            $io->progressStart();
            $processedCount = 0;
            while (($row = fgetcsv($ingredientsFile, 0, $delimiter, '"', '\\')) !== false) {
                try {
                    // Expected format: name, id
                    if (count($row) < 2) {
                        $io->warning('Invalid row format in ingredients file (expected at least 2 columns)');
                        continue;
                    }

                    [$name, $id] = $row;

                    // Validate required fields
                    if (empty($name) || empty($id)) {
                        $io->warning('Missing required fields in ingredients file (name or id)');
                        continue;
                    }

                    // Create new ingredient
                    $ingredient = new Ingredient();
                    $ingredient->setId((int) $id);
                    $ingredient->setName(trim($name));

                    if (!$dryRun) {
                        $this->entityManager->persist($ingredient);
                    }

                    $processedCount++;
                    $totalIngredients++;
                    $io->progressAdvance();

                    // Batch flush to optimize performance
                    if ($processedCount % $batchSize === 0 && !$dryRun) {
                        $this->entityManager->flush();
                        $this->clearEntityManager();
                    }
                    

                } catch (\Exception $e) {
                    $io->warning(sprintf('Error processing ingredient row: %s', $e->getMessage()));
                }
            }

            // Final flush for remaining ingredients
            if ($processedCount % $batchSize !== 0 && !$dryRun) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear(); // Clear to free memory
            fclose($ingredientsFile);
            $io->progressFinish();
            $io->newLine();
            $io->success(sprintf('Imported %d ingredients%s!', $totalIngredients, $dryRun ? ' (dry run)' : ''));

            /**
             * Import Recipes
             */
            $recipeFilePath = __DIR__ . '/../../public/data/recipes_final.csv';
            $this->validateFile($recipeFilePath);
            $io->title('CSV Import: Recipes');
            $io->writeln(sprintf('File: %s', $recipeFilePath));
            $io->writeln(sprintf('Batch size: %d', $batchSize));
            $io->newLine();

            $totalRecipes = 0;
            $recipeFile = fopen($recipeFilePath, 'r');
            if ($recipeFile === false) {
                throw new \IOException('Unable to open the recipes file');
            }
            if ($skipHeader) {
                fgetcsv($recipeFile, 0, $delimiter);
            }

            $io->section('Processing recipes CSV...');
            $io->progressStart();
            $processedCount = 0;
            while (($row = fgetcsv($recipeFile, 0, $delimiter, '"', '\\')) !== false) {
                try {
                    // Expected format: id, recipe_title, description, id_category
                    if (count($row) < 4) {
                        $io->warning('Invalid row format in recipes file (expected at least 4 columns)');
                        continue;
                    }

                    [$id, $title, $description, $idCategory] = $row;

                    // Validate required fields
                    if (empty($title) || empty($description)) {
                        $io->warning('Missing required fields in recipes file (title or description)');
                        continue;
                    }

                    // Create new recipe
                    $recipe = new Recipe();
                    $recipe->setId((int) $id);
                    $recipe->setTitle(trim($title));
                    $recipe->setSlug($this->sluggerService->generateSlug($title));
                    $recipe->setDescription(trim($description));
                    $recipe->setCategory(
                        $this->entityManager->getRepository(Category::class)->find($idCategory)
                    );
                    $recipe->setCreatedAt(new \DateTimeImmutable());

                    if (!$dryRun) {
                        $this->entityManager->persist($recipe);
                    }

                    $processedCount++;
                    $totalRecipes++;
                    $io->progressAdvance();

                    // Batch flush to optimize performance
                    if ($processedCount % $batchSize === 0 && !$dryRun) {
                        $this->entityManager->flush();
                        $this->clearEntityManager();
                    }

                } catch (\Exception $e) {
                    $io->warning(sprintf('Error processing recipe row: %s', $e->getMessage()));
                }
            }
            // Final flush for remaining recipes
            if ($processedCount % $batchSize !== 0 && !$dryRun) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear(); // Clear to free memory
            fclose($recipeFile);
            $io->progressFinish();
            $io->newLine();
            $io->success(sprintf('Imported %d recipes%s!', $totalRecipes, $dryRun ? ' (dry run)' : ''));

            /**
             * Import Recipe Ingredients
             */
            $recipeIngredientsFilePath = __DIR__ . '/../../public/data/recipe_ingredients.csv';
            $this->validateFile($recipeIngredientsFilePath);
            $io->title('CSV Import: Recipe Ingredients');
            $io->writeln(sprintf('File: %s', $recipeIngredientsFilePath));
            $io->writeln(sprintf('Batch size: %d', $batchSize));
            $io->newLine();

            $totalRecipeIngredients = 0;
            $recipeIngredientsFile = fopen($recipeIngredientsFilePath, 'r');
            if ($recipeIngredientsFile === false) {
                throw new \IOException('Unable to open the recipe ingredients file');
            }
            if ($skipHeader) {
                fgetcsv($recipeIngredientsFile, 0, $delimiter);
            }

            $io->section('Processing recipe ingredients CSV...');
            $io->progressStart();
            $processedCount = 0;
            while (($row = fgetcsv($recipeIngredientsFile, 0, $delimiter, '"', '\\')) !== false) {
                try {
                    // Expected format: id, quantity, id_unit, id_ingredient
                    if (count($row) < 4) {
                        $io->warning('Invalid row format in recipe ingredients file (expected at least 4 columns)');
                        continue;
                    }
                    [$idRecipe, $quantity, $idUnit, $idIngredient] = $row;
                    // Validate required fields
                    if (empty($idRecipe) || empty($idIngredient)) {
                        $io->warning('Missing required fields in recipe ingredients file (id_recipe or id_ingredient)');
                        continue;
                    }

                    // Create new recipe ingredient
                    $recipeIngredient = new RecipeIngredient();
                    $recipeIngredient->setRecipe(
                        $this->entityManager->getReference(Recipe::class, (int) $idRecipe)
                    );
                    $recipeIngredient->setIngredient(
                        $this->entityManager->getReference(Ingredient::class, (int) $idIngredient)
                    );
                    if (!empty($quantity) && is_numeric($quantity)) {
                        $recipeIngredient->setQuantity((float) $quantity);
                    }
                    if (!$dryRun) {
                        $this->entityManager->persist($recipeIngredient);
                    }

                    $processedCount++;
                    $totalRecipeIngredients++;
                    $io->progressAdvance();

                    // Batch flush to optimize performance
                    if ($processedCount % $batchSize === 0 && !$dryRun) {
                        $this->entityManager->flush();
                        $this->clearEntityManager();
                    }
                } catch (\Exception $e) {
                    $io->warning(sprintf('Error processing recipe ingredient row: %s', $e->getMessage()));
                    // Log the error and continue processing
                    $this->logger->error('Error processing recipe ingredient row', ['exception' => $e, 'row' => $row]);
                }
            }
            // Final flush for remaining recipe ingredients
            if ($processedCount % $batchSize !== 0 && !$dryRun) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear(); // Clear to free memory
            fclose($recipeIngredientsFile);
            $io->progressFinish();
            $io->newLine();
            $io->success(sprintf('Imported %d recipe ingredients%s!', $totalRecipeIngredients, $dryRun ? ' (dry run)' : ''));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function validateFile(string $filePath): bool
    {
        return file_exists($filePath) && is_readable($filePath) ?: throw new \InvalidArgumentException(sprintf('File not found or not readable: %s', $filePath));
    }


    protected function clearEntityManager(): void
    {
        $this->entityManager->clear();
        //gc_collect_cycles(); // Force garbage collection
    }

    private function disableAutoIncrement(string $entityClass): void
{
    $metadata = $this->entityManager->getClassMetadata($entityClass);
    $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    $metadata->setIdGenerator(new AssignedGenerator());
}
}


