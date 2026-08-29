<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\DTO\Mcp\IngredientLineInput;
use App\DTO\Mcp\RecipeDraftInput;
use App\Entity\Recipe;
use App\Enum\IngredientUnit;
use App\Exception\Mcp\McpWriteDeniedException;
use App\Exception\Mcp\RecipeDraftRejectedException;
use App\Service\Mcp\McpWriteGuard;
use App\Service\Recipe\RecipeDraftFactory;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * The one write tool on an otherwise read-only, public MCP server.
 *
 * It does not parse prose: the calling model is what turns "a chocolate cake with 200g flour" into
 * the structured arguments below. Keeping the natural-language step on the client is deliberate —
 * it means no untrusted text is ever fed to a model inside this application.
 *
 * Everything it creates is a DRAFT: invisible to /api/v1 and to the read tools until an
 * administrator publishes it in EasyAdmin. Authorization, throttling and the audit trail belong to
 * {@see McpWriteGuard}; building and persisting the entity graph belongs to
 * {@see RecipeDraftFactory}. This class only maps arguments and shapes the response.
 */
#[McpTool(
    name: 'recipe_create',
    description: <<<'TEXT'
        Create a new recipe, and any ingredient it needs that does not exist yet.

        Requires a write token: this is the only non-read-only tool on this server, and calls
        without a valid token are refused. The recipe is created as a DRAFT and is not visible
        through the public API until an administrator publishes it.

        Limits: at most 50 ingredients and 50 instructions, and at most 5 previously-unknown
        ingredients may be created in a single call — use ingredient_search first to reuse existing
        names. The category must already exist (see category_list); unknown categories are refused.
        Instruction order is taken from the array order.
        TEXT,
)]
final class RecipeCreateTool
{
    public function __construct(
        private readonly McpWriteGuard $guard,
        private readonly RecipeDraftFactory $draftFactory,
    ) {
    }

    /**
     * The two array parameters are decoded JSON from an unauthenticated caller. They are typed as
     * mixed on purpose: the shape below is what the advertised JSON Schema *asks* for, not
     * something the runtime can assume, so the mapping methods re-check every element.
     *
     * @param array<int, mixed> $ingredients  each element: {name: string, quantity?: number, unit?: string}
     * @param array<int, mixed> $instructions each element: string
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $token,
        string $title,
        string $description,
        // Individual Schema properties, NOT `definition:` — Schema::toArray() returns a
        // `definition` key verbatim, which is not a JSON Schema keyword, so a client would see an
        // untyped array and the SDK would validate nothing. These keywords are merged properly.
        #[Schema(
            type: 'array',
            description: 'The ingredient lines, e.g. {"name": "flour", "quantity": 200, "unit": "g"}.',
            items: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'quantity' => ['type' => ['number', 'null'], 'minimum' => 0],
                    'unit' => ['type' => ['string', 'null'], 'enum' => IngredientUnit::VALUES],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            minItems: 1,
            maxItems: RecipeDraftInput::MAX_INGREDIENTS,
        )]
        array $ingredients,
        #[Schema(
            type: 'array',
            description: 'The steps, in order. Step numbers are derived from this order.',
            items: ['type' => 'string', 'minLength' => 1, 'maxLength' => RecipeDraftInput::MAX_INSTRUCTION_LENGTH],
            minItems: 1,
            maxItems: RecipeDraftInput::MAX_INSTRUCTIONS,
        )]
        array $instructions,
        ?string $category = null,
        ?int $duration = null,
    ): array {
        try {
            $this->guard->assertMayWrite($token, 'recipe_create');
        } catch (McpWriteDeniedException $e) {
            return ['error' => $e->getMessage()];
        }

        try {
            $input = new RecipeDraftInput(
                title: trim($title),
                description: trim($description),
                ingredients: $this->toIngredientLines($ingredients),
                instructions: $this->toInstructions($instructions),
                category: $category,
                duration: $duration,
            );

            $result = $this->draftFactory->createDraft($input);
        } catch (RecipeDraftRejectedException $e) {
            return [
                'error' => 'The recipe was rejected.',
                'reasons' => $e->getErrors(),
            ];
        }

        /** @var Recipe $recipe */
        $recipe = $result['recipe'];

        return [
            'id' => $recipe->getId(),
            'title' => $recipe->getTitle(),
            'slug' => $recipe->getSlug(),
            'status' => $recipe->getStatus()->value,
            'created_ingredients' => $result['createdIngredients'],
            'message' => 'Created as a draft. It is not visible through the public API until an administrator publishes it.',
        ];
    }

    /**
     * @param array<int, mixed> $ingredients
     *
     * @return IngredientLineInput[]
     *
     * @throws RecipeDraftRejectedException
     */
    private function toIngredientLines(array $ingredients): array
    {
        $lines = [];

        foreach (array_values($ingredients) as $index => $raw) {
            $position = $index + 1;

            if (!\is_array($raw) || !isset($raw['name']) || !\is_string($raw['name'])) {
                throw new RecipeDraftRejectedException([\sprintf('Ingredient %d must be an object with a "name" string.', $position)]);
            }

            $unit = null;

            if (isset($raw['unit']) && '' !== $raw['unit']) {
                if (!\is_string($raw['unit']) || null === $unit = IngredientUnit::tryFrom($raw['unit'])) {
                    throw new RecipeDraftRejectedException([\sprintf('Ingredient %d has an unknown unit. Valid units: %s.', $position, implode(', ', IngredientUnit::VALUES))]);
                }
            }

            $quantity = $raw['quantity'] ?? null;

            if (null !== $quantity && !\is_int($quantity) && !\is_float($quantity)) {
                throw new RecipeDraftRejectedException([\sprintf('Ingredient %d has a non-numeric quantity.', $position)]);
            }

            $lines[] = new IngredientLineInput(
                name: $raw['name'],
                quantity: null === $quantity ? null : (float) $quantity,
                unit: $unit,
            );
        }

        return $lines;
    }

    /**
     * @param array<int, mixed> $instructions
     *
     * @return string[]
     *
     * @throws RecipeDraftRejectedException
     */
    private function toInstructions(array $instructions): array
    {
        $steps = [];

        foreach (array_values($instructions) as $index => $content) {
            if (!\is_string($content)) {
                throw new RecipeDraftRejectedException([\sprintf('Instruction %d must be a string.', $index + 1)]);
            }

            $steps[] = $content;
        }

        return $steps;
    }
}
