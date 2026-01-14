<?php

namespace App\Tests\Entity;

use App\Entity\Recipe;
use App\Entity\Category;
use PHPUnit\Framework\TestCase;

class RecipeTest extends TestCase
{
    public function testRecipeEntity(): void
    {
        $category = new Category();
        $category->setName('Dessert');

        $recipe = new Recipe();
        $recipe->setTitle('Chocolate Cake');
        $recipe->setDescription('A delicious chocolate cake recipe.');
        $recipe->setDuration(60);
        $recipe->setCategory($category);

        $this->assertEquals('Chocolate Cake', $recipe->getTitle());
        $this->assertEquals('A delicious chocolate cake recipe.', $recipe->getDescription());
        $this->assertEquals(60, $recipe->getDuration());
        $this->assertEquals($category, $recipe->getCategory());
    }

    public function testRecipeGettersAndSetters(): void
    {
        $recipe = new Recipe();

        $recipe->setTitle('Pasta');
        $this->assertEquals('Pasta', $recipe->getTitle());

        $recipe->setDescription('A simple pasta recipe.');
        $this->assertEquals('A simple pasta recipe.', $recipe->getDescription());

        $recipe->setDuration(30);
        $this->assertEquals(30, $recipe->getDuration());
    }

    public function testRecipeCategoryRelation(): void
    {
        $category = new Category();
        $category->setName('Cookies');

        $recipe = new Recipe();
        $recipe->setCategory($category);

        $this->assertEquals($category, $recipe->getCategory());
    }
}