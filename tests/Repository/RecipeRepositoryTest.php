<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The three queries that hold the draft quarantine shut on the MCP side.
 *
 * API Platform never calls this repository — it builds its own query builder, which is why
 * `PublishedRecipeExtension` exists — so these methods are the *only* thing keeping a draft out of
 * `recipe_search` and `recipe_get`. They were covered indirectly through the tool tests and
 * directly by nothing.
 */
class RecipeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecipeRepository $repository;
    private Category $category;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(RecipeRepository::class);

        $this->clear();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();

        $this->em->close();
    }

    public function testSearchMatchesTheTitle(): void
    {
        $slugs = $this->slugsOf($this->repository->searchByKeywords('Repotest chocolate'));

        $this->assertContains('repotest-chocolate-cake', $slugs);
    }

    public function testSearchMatchesTheDescription(): void
    {
        $slugs = $this->slugsOf($this->repository->searchByKeywords('kirsch'));

        $this->assertSame(['repotest-black-forest'], $slugs);
    }

    public function testSearchMatchesTheCategoryName(): void
    {
        $slugs = $this->slugsOf($this->repository->searchByKeywords('Repotest pastry'));

        $this->assertNotEmpty($slugs, 'The keyword search is documented as covering the category name.');
    }

    public function testSearchExcludesDrafts(): void
    {
        $slugs = $this->slugsOf($this->repository->searchByKeywords('Repotest'));

        $this->assertNotContains('repotest-secret-draft', $slugs);
    }

    public function testSearchIsCappedAtFiveRows(): void
    {
        // Seven published recipes match "Repotest".
        $this->assertCount(5, $this->repository->searchByKeywords('Repotest'));
    }

    public function testSearchReturnsNothingForAnUnmatchedKeyword(): void
    {
        $this->assertSame([], $this->repository->searchByKeywords('zzz_no_such_keyword_zzz'));
    }

    public function testFindOneBySlugReturnsAPublishedRecipe(): void
    {
        $recipe = $this->repository->findOneBySlug('repotest-chocolate-cake');

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertSame('Repotest chocolate cake', $recipe->getTitle());
    }

    public function testFindOneBySlugRefusesADraft(): void
    {
        $this->assertNull($this->repository->findOneBySlug('repotest-secret-draft'));
    }

    public function testFindOneBySlugReturnsNullForAnUnknownSlug(): void
    {
        $this->assertNull($this->repository->findOneBySlug('repotest-no-such-slug'));
    }

    public function testExistsBySlugSeesEveryStatus(): void
    {
        // The point of this method: a draft still occupies its slug, so the authoring paths must
        // see it even though the read paths must not.
        $this->assertTrue($this->repository->existsBySlug('repotest-chocolate-cake'));
        $this->assertTrue($this->repository->existsBySlug('repotest-secret-draft'));
        $this->assertFalse($this->repository->existsBySlug('repotest-no-such-slug'));
    }

    /**
     * Characterises a real gap rather than endorsing it.
     *
     * `recipe.slug` has no unique index — `#[UniqueEntity('slug')]` is validation-level only, and
     * the CSV import persists without running the validator. `findOneBySlug()` uses
     * `getOneOrNullResult()`, so two rows sharing a slug do not make it return the first one: they
     * make `recipe_get` raise. If a uniqueness guard is ever added, this test should fail and be
     * deleted deliberately.
     */
    public function testDuplicateSlugsMakeFindOneBySlugThrowRatherThanPickOne(): void
    {
        $duplicate = new Recipe();
        $duplicate->setTitle('Repotest chocolate cake')
            ->setDescription('A second row with the very same generated slug.')
            ->setCategory($this->category);
        $this->em->persist($duplicate);
        $this->em->flush();

        $this->assertSame('repotest-chocolate-cake', $duplicate->getSlug());

        $this->expectException(NonUniqueResultException::class);

        $this->repository->findOneBySlug('repotest-chocolate-cake');
    }

    /**
     * @param Recipe[] $recipes
     *
     * @return string[]
     */
    private function slugsOf(array $recipes): array
    {
        return array_map(static fn (Recipe $recipe): ?string => $recipe->getSlug(), $recipes);
    }

    private function seed(): void
    {
        $this->category = new Category();
        $this->category->setName('Repotest pastry');
        $this->em->persist($this->category);

        $this->publish('Repotest chocolate cake', 'A cake.');
        $this->publish('Repotest black forest', 'Cherries and kirsch.');
        $this->publish('Repotest lemon tart', 'Sharp.');
        $this->publish('Repotest apple pie', 'Warm.');
        $this->publish('Repotest pear crumble', 'Buttery.');
        $this->publish('Repotest plum galette', 'Rustic.');
        $this->publish('Repotest fig clafoutis', 'Soft.');

        $draft = new Recipe();
        $draft->setTitle('Repotest secret draft')
            ->setDescription('Created through an untrusted path.')
            ->setCategory($this->category)
            ->setStatus(RecipeStatus::Draft);
        $this->em->persist($draft);

        $this->em->flush();
    }

    private function publish(string $title, string $description): void
    {
        $recipe = new Recipe();
        $recipe->setTitle($title)
            ->setDescription($description)
            ->setCategory($this->category);

        $this->em->persist($recipe);
    }

    private function clear(): void
    {
        foreach (['App\Entity\Instruction', 'App\Entity\RecipeIngredient'] as $child) {
            $this->em->createQuery(\sprintf('DELETE FROM %s', $child))->execute();
        }

        $this->em->createQuery('DELETE FROM App\Entity\Recipe r WHERE r.title LIKE :marker')
            ->setParameter('marker', 'Repotest%')
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :marker')
            ->setParameter('marker', 'Repotest%')
            ->execute();
    }
}
