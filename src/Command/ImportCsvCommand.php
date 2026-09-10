<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\ImportOptions;
use App\Service\Import\ImportSummary;
use App\Service\Import\RecipeCsvImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-csv',
    description: 'Imports recipes and related data from CSV files into the database',
)]
final class ImportCsvCommand extends Command
{
    public function __construct(
        private readonly RecipeCsvImporter $importer,
        #[Autowire('%kernel.project_dir%/public/data')]
        private readonly string $dataDirectory,
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
            ->setHelp(
                <<<'HELP'
This command imports recipes and related data from CSV files located in public/data/.

Expected CSV files and formats:

  recipe_categories.csv   →  category,id
  ingredients.csv         →  ingredient,id
  recipes_final.csv       →  id,recipe_title,description,id_category
  recipe_ingredients.csv  →  id_recipe,quantity,id_unit,id_ingredient
  recipe_instructions.csv →  id_recipe,content,position

All five files must be present: the import is aborted before writing anything if one is missing.

Example:
    php bin/console app:import-csv
    php bin/console app:import-csv --dry-run
    php bin/console app:import-csv --skip-header
    php bin/console app:import-csv --batch-size=100
    php bin/console app:import-csv --delimiter=";"
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $options = new ImportOptions(
            dataDirectory: $this->dataDirectory,
            delimiter: (string) $input->getOption('delimiter'),
            batchSize: max(1, (int) $input->getOption('batch-size')),
            skipHeader: (bool) $input->getOption('skip-header'),
            dryRun: (bool) $input->getOption('dry-run'),
        );

        if ($options->dryRun) {
            $io->warning('DRY RUN MODE - No data will be saved to database');
        }

        // Every file is checked up front. Previously a missing file surfaced only when its stage
        // was reached, which left the first datasets written and the sequence resynchronisation —
        // the last step — unreachable.
        if (null !== $missing = $this->firstMissingFile($options)) {
            $io->error(\sprintf('File not found or not readable: %s', $missing));

            return Command::FAILURE;
        }

        $this->importer->useAssignedIds();

        $datasets = [
            ['Categories', RecipeCsvImporter::CATEGORIES_FILE, $this->importer->importCategories(...)],
            ['Ingredients', RecipeCsvImporter::INGREDIENTS_FILE, $this->importer->importIngredients(...)],
            ['Recipes', RecipeCsvImporter::RECIPES_FILE, $this->importer->importRecipes(...)],
            ['Recipe ingredients', RecipeCsvImporter::RECIPE_INGREDIENTS_FILE, $this->importer->importRecipeIngredients(...)],
            ['Recipe instructions', RecipeCsvImporter::INSTRUCTIONS_FILE, $this->importer->importInstructions(...)],
        ];

        foreach ($datasets as [$label, $fileName, $import]) {
            $io->title(\sprintf('CSV Import: %s', $label));
            $io->writeln(\sprintf('File: %s', $options->pathFor($fileName)));
            $io->writeln(\sprintf('Batch size: %d', $options->batchSize));
            $io->newLine();
            $io->progressStart();

            try {
                $summary = $import($options, $io->progressAdvance(...));
            } catch (\RuntimeException $e) {
                $io->progressFinish();
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $io->progressFinish();
            $this->report($io, $label, $summary, $options->dryRun);
        }

        if (!$options->dryRun) {
            foreach ($this->importer->resynchroniseSequences() as $table) {
                $io->text(\sprintf('Sequence for "%s" resynchronised.', $table));
            }
        }

        return Command::SUCCESS;
    }

    private function firstMissingFile(ImportOptions $options): ?string
    {
        foreach ([
            RecipeCsvImporter::CATEGORIES_FILE,
            RecipeCsvImporter::INGREDIENTS_FILE,
            RecipeCsvImporter::RECIPES_FILE,
            RecipeCsvImporter::RECIPE_INGREDIENTS_FILE,
            RecipeCsvImporter::INSTRUCTIONS_FILE,
        ] as $fileName) {
            $path = $options->pathFor($fileName);

            if (!file_exists($path) || !is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function report(SymfonyStyle $io, string $label, ImportSummary $summary, bool $dryRun): void
    {
        $io->newLine();

        // Only the first few, and a count: a malformed file otherwise buries the summary under
        // thousands of identical warnings.
        foreach (\array_slice($summary->warnings(), 0, 5) as $warning) {
            $io->warning($warning);
        }

        if ($summary->skipped() > 5) {
            $io->warning(\sprintf('... and %d further skipped rows.', $summary->skipped() - 5));
        }

        $io->success(\sprintf(
            'Imported %d %s%s (%d skipped).',
            $summary->imported(),
            strtolower($label),
            $dryRun ? ' (dry run)' : '',
            $summary->skipped(),
        ));
    }
}
