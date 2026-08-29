<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\IngredientUnit;
use PHPUnit\Framework\TestCase;

final class IngredientUnitTest extends TestCase
{
    /**
     * IngredientUnit::VALUES is a hand-maintained literal because a PHP attribute argument cannot
     * call cases(); RecipeCreateTool embeds it in the JSON Schema it advertises to MCP clients.
     * If the two drift, the tool silently rejects or advertises a unit that does not exist.
     */
    public function testValuesConstantMatchesTheEnumCases(): void
    {
        $this->assertSame(
            array_column(IngredientUnit::cases(), 'value'),
            IngredientUnit::VALUES,
        );
    }
}
