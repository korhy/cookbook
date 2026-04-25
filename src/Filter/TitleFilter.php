<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

final class TitleFilter implements FilterInterface
{
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $value = $context['parameter']->getValue();
        if (null === $value) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $paramName = $queryNameGenerator->generateParameterName('title');

        $queryBuilder
            ->andWhere(\sprintf('LOWER(%s.title) LIKE LOWER(:%s)', $alias, $paramName))
            ->setParameter($paramName, '%'.$value.'%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'title' => [
                'property' => 'title',
                'type' => 'string',
                'required' => false,
                'description' => 'Filter recipes by title (case-insensitive partial match).',
            ],
        ];
    }
}
