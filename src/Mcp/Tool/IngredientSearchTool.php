<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Ingredient;
use App\Repository\IngredientRepository;
use Mcp\Capability\Attribute\McpTool;

/**
 * Read-only companion to recipe_create: it lets a client reuse an existing ingredient instead of
 * minting a near-duplicate ("Tomato" / "tomatoes" / "Tomatoes ").
 */
#[McpTool(
    name: 'ingredient_search',
    description: 'Search the ingredient catalogue by name, case-insensitively. Use this before recipe_create to reuse existing ingredient names instead of creating near-duplicates. Returns up to 10 matches.',
)]
final class IngredientSearchTool
{
    private const MAX_RESULTS = 10;

    public function __construct(private readonly IngredientRepository $ingredientRepository)
    {
    }

    /**
     * @return array{ingredients: array<int, array{id: ?int, name: ?string}>}
     */
    public function __invoke(string $name): array
    {
        return [
            'ingredients' => array_map(
                static fn (Ingredient $ingredient): array => [
                    'id' => $ingredient->getId(),
                    'name' => $ingredient->getName(),
                ],
                $this->ingredientRepository->searchByName($name, self::MAX_RESULTS),
            ),
        ];
    }
}
