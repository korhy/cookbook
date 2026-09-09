<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;

/**
 * The one place a Recipe becomes an MCP payload.
 *
 * `recipe_search` and `recipe_get` were each mapping the entity to an array by hand, with an
 * overlapping key set, and a third read tool would have copied it a third time. The rule the MCP
 * server has to keep — *a read tool may not expose a field the REST API does not* — is only
 * checkable if there is a single place to check.
 *
 * Nothing here filters by publication status: that belongs to the repository query, so that a
 * caller cannot reach a draft in the first place. See `RecipeRepository::searchByKeywords()`.
 */
final class RecipePresenter
{
    /**
     * The shape `recipe_search` returns per row: enough to decide which recipe to fetch, and no
     * more. Deliberately without ingredients or instructions — a keyword search returning five
     * fully-expanded recipes is how a bounded tool stops being bounded.
     *
     * @return array{id: ?int, title: ?string, slug: ?string, description: ?string, duration: ?int, category: ?string}
     */
    public function summary(Recipe $recipe): array
    {
        return [
            'id' => $recipe->getId(),
            'title' => $recipe->getTitle(),
            'slug' => $recipe->getSlug(),
            'description' => $recipe->getDescription(),
            'duration' => $recipe->getDuration(),
            'category' => $recipe->getCategory()?->getName(),
        ];
    }

    /**
     * The shape `recipe_get` returns: the summary plus the two collections that make a recipe
     * usable.
     *
     * @return array{id: ?int, title: ?string, slug: ?string, description: ?string, duration: ?int, category: ?string, ingredients: array<int, array{name: ?string, quantity: ?float, unit: ?string}>, instructions: array<int, array{position: ?int, content: ?string}>}
     */
    public function detail(Recipe $recipe): array
    {
        return [
            ...$this->summary($recipe),
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
