<?php

declare(strict_types=1);

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\RecipeStatus;
use Doctrine\ORM\QueryBuilder;

/**
 * Keeps draft recipes out of the public `/api/v1` surface, on both the collection and the item
 * operation.
 *
 * This is the quarantine that lets an untrusted authoring path (the MCP write tools) create a
 * recipe without it reaching a consumer. It is applied here rather than in the repository because
 * API Platform builds its own query builder and never goes through `RecipeRepository`.
 *
 * **It has to cover every resource that reaches a recipe, not just `Recipe`.** `Instruction` and
 * `RecipeIngredient` are resources in their own right, and they hold the step text and the
 * quantities — so filtering `/recipes` alone left the interesting half of a draft readable one URL
 * over, at `/instructions`. Anything else that gains an association to a recipe belongs in the map
 * below.
 *
 * Autoconfigured through `QueryCollectionExtensionInterface` / `QueryItemExtensionInterface` — no
 * manual service tag needed.
 */
final class PublishedRecipeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Resource class => the association leading to its `Recipe`, or null when the resource *is* the
     * recipe. A resource absent from this map is not filtered at all.
     *
     * @var array<class-string, string|null>
     */
    private const RECIPE_ASSOCIATION = [
        Recipe::class => null,
        Instruction::class => 'recipe',
        RecipeIngredient::class => 'recipe',
    ];

    /**
     * @param array<string, mixed> $context
     */
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restrictToPublished($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param array<string, mixed> $context
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restrictToPublished($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function restrictToPublished(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
    ): void {
        if (!\array_key_exists($resourceClass, self::RECIPE_ASSOCIATION)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $association = self::RECIPE_ASSOCIATION[$resourceClass];
        $recipeAlias = $rootAlias;

        if (null !== $association) {
            // An inner join, not a left join: a row whose recipe has gone is not something to
            // publish either. Both associations are non-nullable anyway.
            $recipeAlias = $queryNameGenerator->generateJoinAlias($association);
            $queryBuilder->innerJoin(\sprintf('%s.%s', $rootAlias, $association), $recipeAlias);
        }

        $parameterName = $queryNameGenerator->generateParameterName('status');

        $queryBuilder
            ->andWhere(\sprintf('%s.status = :%s', $recipeAlias, $parameterName))
            ->setParameter($parameterName, RecipeStatus::Published);
    }
}
