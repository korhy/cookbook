<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Recipe;
use App\Repository\RecipeRepository;
use App\Service\Mcp\RecipePresenter;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'recipe_search',
    description: 'Search for recipes by keyword, matching against title, description, or category name. Returns up to 5 published matches; drafts are never returned. Use recipe_get with a slug from the result for the full detail.',
)]
final class RecipeSearchTool
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly RecipePresenter $presenter,
    ) {
    }

    /**
     * @return array{recipes: array<int, array{id: ?int, title: ?string, slug: ?string, description: ?string, duration: ?int, category: ?string}>}
     */
    public function __invoke(string $keywords): array
    {
        return [
            'recipes' => array_map(
                fn (Recipe $recipe): array => $this->presenter->summary($recipe),
                $this->recipeRepository->searchByKeywords($keywords),
            ),
        ];
    }
}
