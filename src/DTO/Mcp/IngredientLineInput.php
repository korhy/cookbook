<?php

declare(strict_types=1);

namespace App\DTO\Mcp;

use App\Enum\IngredientUnit;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One "200 g of flour" line of an incoming recipe draft.
 *
 * Every value here arrives from an unauthenticated MCP caller. The constraints are deliberately
 * duplicated with the tool's JSON Schema: the schema is what the client sees and can be bypassed by
 * a caller that ignores it, so this is the layer that actually holds.
 */
final class IngredientLineInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'An ingredient name is required.')]
        #[Assert\Length(max: 255)]
        public readonly string $name,
        #[Assert\PositiveOrZero(message: 'A quantity cannot be negative.')]
        #[Assert\LessThanOrEqual(100000, message: 'That quantity is not plausible.')]
        public readonly ?float $quantity = null,
        public readonly ?IngredientUnit $unit = null,
    ) {
    }
}
