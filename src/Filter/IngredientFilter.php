<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\ParameterProviderFilterInterface;
use ApiPlatform\State\ParameterProvider\IriConverterParameterProvider;
use App\Entity\RecipeIngredient;
use Doctrine\ORM\QueryBuilder;

final class IngredientFilter implements FilterInterface, ParameterProviderFilterInterface
{
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['parameter']->getValue();
        if (null === $value) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $paramName = $queryNameGenerator->generateParameterName('ingredient_id');

        $queryBuilder
            ->andWhere(\sprintf(
                'EXISTS (SELECT 1 FROM %s ri WHERE ri.recipe = %s AND ri.ingredient = :%s)',
                RecipeIngredient::class,
                $alias,
                $paramName
            ))
            ->setParameter($paramName, $value);
    }

    public static function getParameterProvider(): string
    {
        return IriConverterParameterProvider::class;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'ingredient' => [
                'property' => 'recipeIngredients.ingredient',
                'type' => 'string',
                'required' => false,
                'description' => 'Filter recipes by ingredient IRI.',
            ],
        ];
    }
}
