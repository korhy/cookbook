<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Service\Import\ImportOptions;
use App\Service\Import\RecipeCsvImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Characterises the bulk CSV import, which had no test at all while being 400 lines of
 * console method.
 *
 * The fixtures under tests/fixtures/import carry one deliberately malformed row per file, because
 * skipping bad rows and carrying on is the behaviour that matters on a 120k-row source file.
 */
class RecipeCsvImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecipeCsvImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(RecipeCsvImporter::class);

        $this->clear();
        $this->importer->useAssignedIds();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();

        $this->em->close();
    }

    public function testCategoriesAreImportedAndTheBlankRowIsSkipped(): void
    {
        $summary = $this->importer->importCategories($this->options());

        $this->assertSame(2, $summary->imported());
        $this->assertSame(1, $summary->skipped());

        $category = $this->em->getRepository(Category::class)->find(9101);
        $this->assertNotNull($category);
        $this->assertSame('Importtest dessert', $category->getName());
        $this->assertSame('importtest-dessert', $category->getSlug());
    }

    public function testImportingCategoriesTwiceLeavesTheExistingRowsAlone(): void
    {
        $this->importer->importCategories($this->options());
        $second = $this->importer->importCategories($this->options());

        $this->assertSame(0, $second->imported());
        $this->assertSame(3, $second->skipped());
        $this->assertCount(2, $this->fixtureCategories());
    }

    public function testTheShortIngredientRowIsSkipped(): void
    {
        $summary = $this->importer->importIngredients($this->options());

        $this->assertSame(3, $summary->imported());
        $this->assertSame(1, $summary->skipped());
        $this->assertSame('importtest flour', $this->em->getRepository(Ingredient::class)->find(9201)->getName());
    }

    public function testRecipesKeepTheirCsvIdAndResolveTheirCategory(): void
    {
        $this->importer->importCategories($this->options());
        $summary = $this->importer->importRecipes($this->options());

        $this->assertSame(2, $summary->imported());
        $this->assertSame(1, $summary->skipped());

        $recipe = $this->em->getRepository(Recipe::class)->find(9301);
        $this->assertSame('Importtest chocolate cake', $recipe->getTitle());
        $this->assertSame('importtest-chocolate-cake', $recipe->getSlug());
        $this->assertSame('Importtest dessert', $recipe->getCategory()?->getName());
        $this->assertNotNull($recipe->getCreatedAt(), 'createdAt is stamped by the PrePersist callback.');
    }

    public function testImportedRecipesArePublishedNotDrafts(): void
    {
        $this->importer->importCategories($this->options());
        $this->importer->importRecipes($this->options());

        // The CSV import is a trusted authoring path: only the MCP write tools create drafts.
        $this->assertTrue($this->em->getRepository(Recipe::class)->find(9301)->isPublished());
    }

    public function testIngredientLinesCarryTheQuantityButNoUnit(): void
    {
        $this->seedCategoriesIngredientsAndRecipes();
        $summary = $this->importer->importRecipeIngredients($this->options());

        $this->assertSame(3, $summary->imported());
        $this->assertSame(1, $summary->skipped());

        $lines = $this->em->getRepository(RecipeIngredient::class)
            ->findBy(['recipe' => $this->em->getReference(Recipe::class, 9301)]);

        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(200.0, $lines[0]->getQuantity(), 0.001);
        // id_unit is present in the source and deliberately not mapped: the numeric ids there do
        // not correspond to the IngredientUnit enum.
        $this->assertNull($lines[0]->getUnit());
    }

    public function testInstructionsKeepTheirPosition(): void
    {
        $this->seedCategoriesIngredientsAndRecipes();
        $summary = $this->importer->importInstructions($this->options());

        $this->assertSame(3, $summary->imported());
        $this->assertSame(1, $summary->skipped());

        $steps = $this->em->getRepository(Instruction::class)
            ->findBy(['recipe' => $this->em->getReference(Recipe::class, 9301)], ['position' => 'ASC']);

        $this->assertSame([1, 2], array_map(static fn (Instruction $i): ?int => $i->getPosition(), $steps));
        $this->assertSame('Melt the butter.', $steps[0]->getContent());
    }

    public function testADryRunCountsRowsAndWritesNothing(): void
    {
        $summary = $this->importer->importCategories($this->options(dryRun: true));

        $this->assertSame(2, $summary->imported());
        $this->assertCount(0, $this->fixtureCategories());
    }

    public function testTheBatchSizeDoesNotChangeTheOutcome(): void
    {
        $this->importer->importCategories($this->options());
        $summary = $this->importer->importIngredients($this->options(batchSize: 1));

        $this->assertSame(3, $summary->imported());
        $this->assertCount(3, $this->em->getRepository(Ingredient::class)->findBy(['id' => [9201, 9202, 9203]]));
    }

    public function testAMissingFileIsReportedRatherThanSwallowed(): void
    {
        $options = new ImportOptions(dataDirectory: __DIR__.'/no-such-directory');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or not readable/');

        $this->importer->importCategories($options);
    }

    /**
     * The sequences are the reason the import cannot simply stop after writing rows: ids come from
     * the CSV, so Postgres' own counters never advance and the next ordinary insert collides.
     */
    public function testResynchronisingSequencesLetsAnOrdinaryInsertFollowAnImport(): void
    {
        $this->importer->importCategories($this->options());
        $this->importer->resynchroniseSequences();

        $this->em->clear();
        // A fresh kernel, so the metadata is back to Postgres' identity generator.
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $category = new Category();
        $category->setName('Importtest sequence probe');
        $em->persist($category);
        $em->flush();

        $this->assertGreaterThan(9102, $category->getId());
    }

    private function seedCategoriesIngredientsAndRecipes(): void
    {
        $this->importer->importCategories($this->options());
        $this->importer->importIngredients($this->options());
        $this->importer->importRecipes($this->options());
    }

    private function options(bool $dryRun = false, int $batchSize = 50): ImportOptions
    {
        return new ImportOptions(
            dataDirectory: \dirname(__DIR__, 2).'/fixtures/import',
            batchSize: $batchSize,
            skipHeader: true,
            dryRun: $dryRun,
        );
    }

    /**
     * @return Category[]
     */
    private function fixtureCategories(): array
    {
        return $this->em->getRepository(Category::class)->findBy(['id' => [9101, 9102, 9103]]);
    }

    private function clear(): void
    {
        foreach ([
            'DELETE FROM App\Entity\Instruction i WHERE i.recipe IN (9301, 9302, 9303)',
            'DELETE FROM App\Entity\RecipeIngredient ri WHERE ri.recipe IN (9301, 9302, 9303)',
            'DELETE FROM App\Entity\Recipe r WHERE r.id IN (9301, 9302, 9303)',
            'DELETE FROM App\Entity\Ingredient i WHERE i.id IN (9201, 9202, 9203)',
            'DELETE FROM App\Entity\Category c WHERE c.name LIKE :marker',
        ] as $dql) {
            $query = $this->em->createQuery($dql);

            if (str_contains($dql, ':marker')) {
                $query->setParameter('marker', 'Importtest%');
            }

            $query->execute();
        }
    }
}
