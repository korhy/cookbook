<?php

namespace App\Tests\Repository;

use App\Entity\Recipe;
use App\Entity\Category;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RecipeRepositoryTest extends KernelTestCase
{
    private $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();
    }

    public function testFindByCategory(): void
    {
        $category = $this->entityManager
            ->getRepository(Category::class)
            ->findOneBy([]);
        
        if($category) {
            $recipes = $this->entityManager
                ->getRepository(Recipe::class)
                ->findBy(['category' => $category]);
            
             $this->assertIsArray($recipes);

            foreach ($recipes as $recipe) {
                $this->assertSame($category, $recipe->getCategory());
            }
        } else {
            $this->markTestSkipped('No category in database');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}