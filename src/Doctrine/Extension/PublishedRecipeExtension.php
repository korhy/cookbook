<?php

declare(strict_types=1);

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Recipe;
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
 * Autoconfigured through `QueryCollectionExtensionInterface` / `QueryItemExtensionInterface` — no
 * manual service tag needed.
 */
final class PublishedRecipeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
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
        if (Recipe::class !== $resourceClass) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $parameterName = $queryNameGenerator->generateParameterName('status');

        $queryBuilder
            ->andWhere(sprintf('%s.status = :%s', $rootAlias, $parameterName))
            ->setParameter($parameterName, RecipeStatus::Published);
    }
}
