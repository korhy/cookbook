<?php

namespace App\Mcp\Tool;

use App\Entity\Recipe;
use App\Repository\RecipeRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'recipe_search',
    description: 'Search for recipes by keyword, matching against title, description, or category name. Returns up to 5 matches.',
)]
class RecipeSearchTool
{
    public function __construct(private readonly RecipeRepository $recipeRepository)
    {
    }

    /**
     * @return array{recipes: array<int, array{id: ?int, title: ?string, slug: ?string, description: ?string, duration: ?int, category: ?string}>}
     */
    public function __invoke(string $keywords): array
    {
        return [
            'recipes' => array_map(
                static fn (Recipe $recipe): array => [
                    'id' => $recipe->getId(),
                    'title' => $recipe->getTitle(),
                    'slug' => $recipe->getSlug(),
                    'description' => $recipe->getDescription(),
                    'duration' => $recipe->getDuration(),
                    'category' => $recipe->getCategory()?->getName(),
                ],
                $this->recipeRepository->searchByKeywords($keywords),
            ),
        ];
    }
}
