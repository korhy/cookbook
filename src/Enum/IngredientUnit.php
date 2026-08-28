<?php

declare(strict_types=1);

namespace App\Enum;

enum IngredientUnit: string
{
    /**
     * The case values as a literal list, because a PHP attribute argument must be a constant
     * expression and cannot call IngredientUnit::cases(). RecipeCreateTool embeds this in its JSON
     * Schema enum. IngredientUnitTest asserts it stays in sync with the cases below.
     *
     * @var string[]
     */
    public const VALUES = ['g', 'kg', 'ml', 'cl', 'L', 'tsp', 'tbsp', 'cup', 'unit', 'pinch', 'slice'];

    // Masse
    case Gram = 'g';
    case Kilogram = 'kg';

    // Volume
    case Milliliter = 'ml';
    case Centiliter = 'cl';
    case Liter = 'L';

    // Cuisine
    case Teaspoon = 'tsp';
    case Tablespoon = 'tbsp';
    case Cup = 'cup';

    // Quantité
    case Unit = 'unit';
    case Pinch = 'pinch';
    case Slice = 'slice';

    public function label(): string
    {
        return 'ingredient_unit.'.$this->value;
    }
}
