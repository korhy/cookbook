<?php

namespace App\Tests\Mcp\Tool;

use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\IngredientUnit;
use App\Mcp\Tool\RecipeGetTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RecipeGetToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecipeGetTool $tool;
    private string $slug;
    private Category $category;
    private Ingredient $ingredient;
    private Recipe $recipe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tool = self::getContainer()->get(RecipeGetTool::class);

        $this->category = new Category();
        $this->category->setName('Test category '.uniqid());
        $this->em->persist($this->category);

        $this->ingredient = new Ingredient();
        $this->ingredient->setName('Test flour');
        $this->em->persist($this->ingredient);

        $this->recipe = new Recipe();
        $this->recipe->setTitle('Chocolate cake test '.uniqid())
            ->setDescription('A rich chocolate cake.')
            ->setDuration(45)
            ->setCategory($this->category);

        $recipeIngredient = new RecipeIngredient();
        $recipeIngredient->setIngredient($this->ingredient)->setQuantity(200)->setUnit(IngredientUnit::Gram);
        $this->recipe->addRecipeIngredient($recipeIngredient);

        $instruction = new Instruction();
        $instruction->setPosition(1)->setContent('Mix everything.');
        $this->recipe->addInstruction($instruction);

        $this->em->persist($this->recipe);
        $this->em->flush();

        // The slug is regenerated from the title by App\EventListener\SlugListener on prePersist.
        $this->slug = $this->recipe->getSlug();
    }

    public function testGetReturnsFullRecipeDetail(): void
    {
        $result = ($this->tool)($this->slug);

        $this->assertSame($this->recipe->getTitle(), $result['title']);
        $this->assertSame($this->category->getName(), $result['category']);
        $this->assertCount(1, $result['ingredients']);
        $this->assertSame('Test flour', $result['ingredients'][0]['name']);
        $this->assertCount(1, $result['instructions']);
        $this->assertSame('Mix everything.', $result['instructions'][0]['content']);
    }

    public function testGetReturnsErrorForUnknownSlug(): void
    {
        $result = ($this->tool)('zzz-no-such-slug-zzz');

        $this->assertArrayHasKey('error', $result);
    }

    protected function tearDown(): void
    {
        $this->em->remove($this->recipe);
        $this->em->remove($this->ingredient);
        $this->em->remove($this->category);
        $this->em->flush();
        parent::tearDown();
        $this->em->close();
    }
}
