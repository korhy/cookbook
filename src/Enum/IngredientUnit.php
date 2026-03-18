<?php

namespace App\Enum;

enum IngredientUnit: string
{
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
