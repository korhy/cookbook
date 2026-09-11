<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Category;
use App\Entity\Recipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `SlugListener` owns the slug — rule 11 says never to set one by hand — and nothing covered it.
 *
 * The `preUpdate` half is the part worth pinning. Setting a property with a plain setter inside a
 * `preUpdate` listener is a well-known way to write code that silently does nothing, because the
 * change set has already been computed by then. It works here because
 * `UnitOfWork::executeUpdates()` calls `recomputeSingleEntityChangeSet()` right after invoking the
 * listeners — but that is a guarantee of the ORM's, not of ours, so it deserves a test rather than
 * an assumption.
 */
class SlugListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();

        $this->em->close();
    }

    public function testTheSlugIsGeneratedOnPersist(): void
    {
        $recipe = $this->persistRecipe('Sluglistener crème brûlée');

        $this->assertSame('sluglistener-creme-brulee', $recipe->getSlug());
    }

    public function testAHandSetSlugIsOverwrittenByTheListener(): void
    {
        $recipe = new Recipe();
        $recipe->setTitle('Sluglistener tarte tatin')
            ->setDescription('A description long enough.')
            ->setSlug('a-slug-i-chose-myself');

        $this->em->persist($recipe);
        $this->em->flush();

        $this->assertSame(
            'sluglistener-tarte-tatin',
            $recipe->getSlug(),
            'Rule 11: SluggerService owns the slug, so a hand-set value must not survive.'
        );
    }

    public function testRenamingARecipeRegeneratesTheSlugOnUpdate(): void
    {
        $recipe = $this->persistRecipe('Sluglistener first title');
        $id = $recipe->getId();

        $recipe->setTitle('Sluglistener second title');
        $this->em->flush();

        // Re-read from the database rather than trusting the in-memory object: the question is
        // whether the new slug reached the UPDATE statement.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Recipe::class)->find($id);

        $this->assertSame('sluglistener-second-title', $reloaded->getSlug());
    }

    public function testTouchingSomethingOtherThanTheTitleLeavesTheSlugAlone(): void
    {
        $recipe = $this->persistRecipe('Sluglistener stable title');
        $id = $recipe->getId();

        $recipe->setDescription('A different description entirely.');
        $this->em->flush();

        $this->em->clear();
        $reloaded = $this->em->getRepository(Recipe::class)->find($id);

        $this->assertSame('sluglistener-stable-title', $reloaded->getSlug());
    }

    /**
     * Category implements the same interface through getTitle() returning its name, which is easy
     * to break when someone "tidies up" that method.
     */
    public function testCategoriesAreSluggedToo(): void
    {
        $category = new Category();
        $category->setName('Sluglistener pâtisserie');

        $this->em->persist($category);
        $this->em->flush();

        $this->assertSame('sluglistener-patisserie', $category->getSlug());
    }

    private function persistRecipe(string $title): Recipe
    {
        $recipe = new Recipe();
        $recipe->setTitle($title)
            ->setDescription('A description long enough.');

        $this->em->persist($recipe);
        $this->em->flush();

        return $recipe;
    }

    private function clear(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Recipe r WHERE r.title LIKE :marker')
            ->setParameter('marker', 'Sluglistener%')
            ->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :marker')
            ->setParameter('marker', 'Sluglistener%')
            ->execute();
    }
}
