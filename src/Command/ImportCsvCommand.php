<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Recipe;
use App\Service\SluggerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-csv',
    description: 'Imports recipes from a CSV file into the database',
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
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file')
            ->addOption('delimiter', 'd', InputOption::VALUE_OPTIONAL, 'CSV delimiter', ',')
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Number of records to process per batch', 50)
            ->addOption('skip-header', null, InputOption::VALUE_NONE, 'Skip the first row (header)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview import without saving to database')
            ->setHelp(<<<'HELP'
This command imports recipes from a CSV file.

Expected CSV format:
title,slug,description,duration,category_name,category_slug,thumbnail

Example:
    php bin/console app:import-csv data/recipes.csv
    php bin/console app:import-csv data/recipes.csv --dry-run
    php bin/console app:import-csv data/recipes.csv --batch-size=100
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $filePath = $input->getArgument('file');
        $delimiter = $input->getOption('delimiter');
        $batchSize = (int) $input->getOption('batch-size');
        $skipHeader = $input->getOption('skip-header');
        $dryRun = $input->getOption('dry-run');

        // Validate file exists
        if (!file_exists($filePath)) {
            $io->error(sprintf('File not found: %s', $filePath));
            return Command::FAILURE;
        }

        if (!is_readable($filePath)) {
            $io->error(sprintf('File is not readable: %s', $filePath));
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No data will be saved to database');
        }
        
        $io->title('CSV Import: Recipes');
        $io->writeln(sprintf('File: %s', $filePath));
        $io->writeln(sprintf('Batch size: %d', $batchSize));
        $io->newLine();

        $file = fopen($filePath, 'r');
        if ($file === false) {
            $io->error('Unable to open the file');
            return Command::FAILURE;
        }

        $lineNumber = 1;
        $processedCount = 0;
        $errorCount = 0;
        $categoryCache = []; // slug => boolean (exists or not)
        $batchCategoryCache = []; // slug => Category entity (cleared each batch)

        $io->section('Processing CSV...');
        $io->progressStart();

        try {
            if ($skipHeader) {
                fgetcsv($file, 0, $delimiter); // Skip the header row
                $lineNumber++;
            }

            while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
                try {
                    // Expected format: recipe_title, category, subcategory, description, ingredients, directions, num_ingredients, num_steps
                    if (count($row) < 8) {
                        $io->warning(sprintf('Line %d: Invalid row format (expected at least 8 columns)', $lineNumber));
                        $errorCount++;
                        continue;
                    }

                    [$title, $category, $subcategory, $description, $ingredients, $directions, $numIngredients, $numSteps] = $row;

                    // Validate required fields
                    if (empty($title) || empty($description)) {
                        $io->warning(sprintf('Line %d: Missing required fields (title or description)', $lineNumber));
                        $errorCount++;
                        $lineNumber++;
                        continue;
                    }

                    // Get or create category
                    $categoryName = trim($category);
                    $categorySlug = $this->sluggerService->generateSlug($categoryName);
                    $categoryEntity = null;
                    
                    if (!empty($categoryName) && !empty($categorySlug)) {
                        // Check batch cache first (cleared every batch)
                        if (isset($batchCategoryCache[$categorySlug])) {
                            $categoryEntity = $batchCategoryCache[$categorySlug];
                        } else {
                            // Check if we know this category exists
                            if (!isset($categoryCache[$categorySlug])) {
                                // First time seeing this category - check database
                                $categoryEntity = $this->entityManager
                                    ->getRepository(Category::class)
                                    ->findOneBy(['slug' => $categorySlug]);

                                if (!$categoryEntity && !$dryRun) {
                                    $categoryEntity = new Category();
                                    $categoryEntity->setName($categoryName);
                                    $categoryEntity->setSlug($categorySlug);
                                    $this->entityManager->persist($categoryEntity);
                                    $this->entityManager->flush(); // Flush immediately to get ID
                                }

                                $categoryCache[$categorySlug] = true; // Mark as known
                            } else {
                                // We know it exists, fetch it
                                $categoryEntity = $this->entityManager
                                    ->getRepository(Category::class)
                                    ->findOneBy(['slug' => $categorySlug]);
                            }
                            
                            // Store in batch cache
                            if ($categoryEntity) {
                                $batchCategoryCache[$categorySlug] = $categoryEntity;
                            }
                        }
                    }

                    // Create recipe
                    $recipeTitle = trim($title);
                    $recipeSlug = $this->sluggerService->generateSlug($recipeTitle);
                    $recipe = new Recipe();
                    $recipe->setTitle($recipeTitle);
                    $recipe->setSlug($recipeSlug);
                    $recipe->setDescription(trim($description));
                    $recipe->setCreatedAt(new \DateTimeImmutable());
                    
                    if (!empty($numIngredients) && is_numeric($numIngredients)) {
                        $recipe->setDuration((int) $numIngredients);
                    }
                    
                    if ($categoryEntity) {
                        $recipe->setCategory($categoryEntity);
                    }

                    if (!$dryRun) {
                        $this->entityManager->persist($recipe);
                    }

                    $processedCount++;
                    $io->progressAdvance();

                    // Batch flush to optimize performance
                    if ($processedCount % $batchSize === 0 && !$dryRun) {
                        $this->entityManager->flush();
                        $this->entityManager->clear(); // Clear to free memory
                        $batchCategoryCache = []; // Clear batch cache
                        gc_collect_cycles(); // Force garbage collection
                    }

                } catch (\Exception $e) {
                    $io->warning(sprintf('Line %d: Error - %s', $lineNumber, $e->getMessage()));
                    $errorCount++;
                }

                $lineNumber++;
            }

            // Final flush for remaining records
            if ($processedCount % $batchSize !== 0 && !$dryRun) {
                $this->entityManager->flush();
            }

        } finally {
            fclose($file);
            $io->progressFinish();
        }

        $io->newLine();
        $io->success([
            sprintf('Import completed%s!', $dryRun ? ' (dry run)' : ''),
            sprintf('Processed: %d recipes', $processedCount),
            sprintf('Errors: %d', $errorCount),
        ]);

        return Command::SUCCESS;
    }
}


