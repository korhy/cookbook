<?php

namespace App\Tool;

use App\Entity\Recipe;
use App\Repository\RecipeRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'recipe_search',
    description: 'Search for recipes in the database based on keywords. Returns matching recipes or indicates none were found.',
)]
class RecipeSearchTool
{
    /** @var Recipe[] */
    private array $recipes = [];

    public function __construct(private readonly RecipeRepository $recipeRepository) {}

    public function __invoke(string $keywords): string
    {
        $recipes = $this->recipeRepository->searchByKeywords($keywords);

        if (empty($recipes)) {
            $this->recipes = [];
            return sprintf('Aucune recette trouvée pour "%s". Génère une recette originale en respectant ce format : titre, description, durée en minutes, catégorie, liste des ingrédients avec quantité et unité, et étapes de préparation numérotées.', $keywords);
        }

        $this->recipes = $recipes;

        $result = array_map(fn(Recipe $r) => [
            'id' => $r->getId(),
            'title' => $r->getTitle(),
            'description' => $r->getDescription(),
            'duration' => $r->getDuration(),
            'category' => $r->getCategory()?->getName(),
            'ingredients' => array_map(fn($ri) => [
                'name'     => $ri->getIngredient()->getName(),
                'quantity' => $ri->getQuantity(),
                'unit'     => $ri->getUnit()?->value,
            ], $r->getRecipeIngredients()->toArray()),
            'instructions' => array_map(fn($i) => [
                'position' => $i->getPosition(),
                'content'  => $i->getContent(),
            ], $r->getInstructions()->toArray()),
        ], $recipes);

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /** @return Recipe[] */
    public function getRecipes(): array
    {
        return $this->recipes;
    }
}
