<?php

namespace App\Mcp\Tool;

use App\Entity\Instruction;
use App\Entity\RecipeIngredient;
use App\Repository\RecipeRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'recipe_get',
    description: 'Get the full detail of a single recipe by its slug, including ingredients and step-by-step instructions.',
)]
class RecipeGetTool
{
    public function __construct(private readonly RecipeRepository $recipeRepository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $slug): array
    {
        $recipe = $this->recipeRepository->findOneBySlug($slug);

        if (null === $recipe) {
            return ['error' => sprintf('No recipe found with slug "%s".', $slug)];
        }

        return [
            'id' => $recipe->getId(),
            'title' => $recipe->getTitle(),
            'slug' => $recipe->getSlug(),
            'description' => $recipe->getDescription(),
            'duration' => $recipe->getDuration(),
            'category' => $recipe->getCategory()?->getName(),
            'ingredients' => array_map(
                static fn (RecipeIngredient $recipeIngredient): array => [
                    'name' => $recipeIngredient->getIngredient()?->getName(),
                    'quantity' => $recipeIngredient->getQuantity(),
                    'unit' => $recipeIngredient->getUnit()?->value,
                ],
                $recipe->getRecipeIngredients()->toArray(),
            ),
            'instructions' => array_map(
                static fn (Instruction $instruction): array => [
                    'position' => $instruction->getPosition(),
                    'content' => $instruction->getContent(),
                ],
                $recipe->getInstructions()->toArray(),
            ),
        ];
    }
}
