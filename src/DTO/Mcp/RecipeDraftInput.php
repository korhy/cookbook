<?php

declare(strict_types=1);

namespace App\DTO\Mcp;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A recipe as proposed by an MCP client, before it becomes a draft Recipe entity.
 *
 * The caps are the point of this class. There are 13k+ recipes and a public write path, so every
 * collection is bounded and every string has a ceiling — an unbounded field on an unauthenticated
 * endpoint is a storage-exhaustion vector, not a convenience.
 */
final class RecipeDraftInput
{
    public const MAX_INGREDIENTS = 50;
    public const MAX_INSTRUCTIONS = 50;
    public const MAX_DESCRIPTION_LENGTH = 5000;
    public const MAX_INSTRUCTION_LENGTH = 2000;

    /**
     * @param IngredientLineInput[] $ingredients
     * @param string[]              $instructions ordered; position is derived from the array order
     *                                            rather than supplied, so a caller cannot produce
     *                                            duplicate or missing step numbers
     */
    public function __construct(
        #[Assert\NotBlank(message: 'A title is required.')]
        #[Assert\Length(min: 5, max: 255)]
        public readonly string $title,
        #[Assert\NotBlank(message: 'A description is required.')]
        #[Assert\Length(min: 5, max: self::MAX_DESCRIPTION_LENGTH)]
        public readonly string $description,
        #[Assert\Valid]
        #[Assert\Count(
            min: 1,
            max: self::MAX_INGREDIENTS,
            minMessage: 'A recipe needs at least one ingredient.',
            maxMessage: 'A recipe cannot have more than {{ limit }} ingredients.',
        )]
        public readonly array $ingredients,
        #[Assert\Count(
            min: 1,
            max: self::MAX_INSTRUCTIONS,
            minMessage: 'A recipe needs at least one instruction.',
            maxMessage: 'A recipe cannot have more than {{ limit }} instructions.',
        )]
        #[Assert\All([
            new Assert\NotBlank(message: 'An instruction cannot be empty.'),
            new Assert\Length(max: self::MAX_INSTRUCTION_LENGTH),
        ])]
        public readonly array $instructions,
        public readonly ?string $category = null,
        #[Assert\Range(
            min: 1,
            max: 10080,
            notInRangeMessage: 'A duration must be between {{ min }} and {{ max }} minutes.',
        )]
        public readonly ?int $duration = null,
    ) {
    }
}
