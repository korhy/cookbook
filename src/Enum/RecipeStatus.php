<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Publication state of a recipe.
 *
 * `Draft` is the quarantine used by untrusted authoring paths (the MCP write tools):
 * a draft is invisible to the public `/api/v1` surface and to the MCP read tools until
 * an administrator publishes it from EasyAdmin.
 */
enum RecipeStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return 'recipe_status.'.$this->value;
    }
}
