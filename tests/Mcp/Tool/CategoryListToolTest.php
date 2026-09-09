<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\Category;
use App\Mcp\Tool\CategoryListTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CategoryListToolTest extends KernelTestCase
{
    /**
     * More than the tool's cap, so the bound is actually exercised rather than assumed.
     */
    private const SEEDED = 55;
    private const CAP = 50;

    private EntityManagerInterface $em;
    private CategoryListTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tool = self::getContainer()->get(CategoryListTool::class);

        $this->removeSeededCategories();

        for ($i = 1; $i <= self::SEEDED; ++$i) {
            $category = new Category();
            // Zero-padded: the ordering assertion below compares strings, and "Captest 10" sorts
            // before "Captest 9".
            $category->setName(\sprintf('Captest %02d', $i));
            $this->em->persist($category);
        }

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeSeededCategories();

        parent::tearDown();

        $this->em->close();
    }

    public function testListReturnsCategories(): void
    {
        $result = ($this->tool)();

        $this->assertNotEmpty($result['categories']);

        foreach ($result['categories'] as $category) {
            $this->assertArrayHasKey('id', $category);
            $this->assertArrayHasKey('name', $category);
            $this->assertArrayHasKey('slug', $category);
        }
    }

    /**
     * The endpoint is public and unauthenticated, so "the taxonomy is small" is not a bound.
     */
    public function testResultsAreCappedAtFifty(): void
    {
        $result = ($this->tool)();

        $this->assertCount(self::CAP, $result['categories']);
    }

    public function testCategoriesComeBackAlphabetically(): void
    {
        $names = array_column(($this->tool)()['categories'], 'name');

        $sorted = $names;
        sort($sorted);

        $this->assertSame($sorted, $names);
    }

    public function testTheToolExposesNoFieldBeyondTheRestContract(): void
    {
        $result = ($this->tool)();

        $this->assertSame(['id', 'name', 'slug'], array_keys($result['categories'][0]));
    }

    private function removeSeededCategories(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :name')
            ->setParameter('name', 'Captest%')
            ->execute();
    }
}
