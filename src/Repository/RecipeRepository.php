<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recipe>
 */
class RecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recipe::class);
    }

    /**
     * Published recipes only: this feeds the public MCP `recipe_search` tool, and a draft must
     * never surface there.
     *
     * @return Recipe[]
     */
    public function searchByKeywords(string $keywords): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.category', 'c')
            ->addSelect('c')
            ->where('r.title LIKE :keywords OR r.description LIKE :keywords OR c.name LIKE :keywords')
            ->andWhere('r.status = :status')
            ->setParameter('keywords', '%'.$keywords.'%')
            ->setParameter('status', RecipeStatus::Published)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }

    /**
     * Published recipes only — same reason as searchByKeywords(): this feeds the public MCP
     * `recipe_get` tool.
     */
    public function findOneBySlug(string $slug): ?Recipe
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.category', 'c')
            ->addSelect('c')
            ->andWhere('r.slug = :slug')
            ->andWhere('r.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', RecipeStatus::Published)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Slug uniqueness check for the authoring paths, across every status: a draft still occupies
     * its slug, so a duplicate must be rejected before it reaches the unique constraint.
     */
    public function existsBySlug(string $slug): bool
    {
        return null !== $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
