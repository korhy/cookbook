<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Repository\RecipeRepository;
use App\Service\Mcp\RecipePresenter;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'recipe_get',
    description: 'Get the full detail of a single published recipe by its slug, including ingredients and step-by-step instructions. A draft slug is reported as not found.',
)]
final class RecipeGetTool
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly RecipePresenter $presenter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $slug): array
    {
        $recipe = $this->recipeRepository->findOneBySlug($slug);

        if (null === $recipe) {
            return ['error' => \sprintf('No recipe found with slug "%s".', $slug)];
        }

        return $this->presenter->detail($recipe);
    }
}
