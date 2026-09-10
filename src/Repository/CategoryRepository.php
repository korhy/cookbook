<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Case-insensitive lookup by name or slug, for callers that only know a category by its label.
     *
     * Categories are a curated taxonomy: the MCP write path resolves against this and refuses an
     * unknown value rather than minting one from untrusted input.
     */
    public function findOneByNameOrSlug(string $value): ?Category
    {
        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.name) = :value OR LOWER(c.slug) = :value')
            ->setParameter('value', mb_strtolower(trim($value)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Exact slug lookup, used by the CSV import to leave an already-imported category alone.
     */
    public function findOneBySlug(string $slug): ?Category
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The taxonomy, alphabetically, bounded.
     *
     * The limit is not defensive padding: this feeds the public, unauthenticated `category_list`
     * MCP tool, and an unbounded result set there is the whole table on one request.
     *
     * @return Category[]
     */
    public function findAllOrderedByName(int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
