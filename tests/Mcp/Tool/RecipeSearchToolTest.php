<?php

namespace App\Tests\Mcp\Tool;

use App\Entity\Category;
use App\Entity\Recipe;
use App\Mcp\Tool\RecipeSearchTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RecipeSearchToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecipeSearchTool $tool;
    private Category $category;
    private Recipe $recipe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tool = self::getContainer()->get(RecipeSearchTool::class);

        $this->category = new Category();
        $this->category->setName('Test category '.uniqid());
        $this->em->persist($this->category);

        $this->recipe = new Recipe();
        $this->recipe->setTitle('Chocolate cake test '.uniqid())
            ->setDescription('A rich chocolate cake.')
            ->setDuration(45)
            ->setCategory($this->category);
        $this->em->persist($this->recipe);

        $this->em->flush();
    }

    public function testSearchReturnsMatchingRecipes(): void
    {
        $result = ($this->tool)('chocolate');

        $this->assertNotEmpty($result['recipes']);
        foreach ($result['recipes'] as $recipe) {
            $this->assertArrayHasKey('title', $recipe);
            $this->assertArrayHasKey('slug', $recipe);
            $this->assertArrayHasKey('category', $recipe);
        }
    }

    public function testSearchReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->assertSame(['recipes' => []], ($this->tool)('zzz_no_such_keyword_zzz'));
    }

    protected function tearDown(): void
    {
        $this->em->remove($this->recipe);
        $this->em->remove($this->category);
        $this->em->flush();
        parent::tearDown();
        $this->em->close();
    }
}
