<?php

namespace App\Repository;

use App\Entity\Recipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * @return Recipe[]
     */
    public function getAllWithCategory(): array
    {
        return $this->getRecipesWithCategoryQueryBuilder()
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Recipe[] Returns an array of Recipe objects
     */
    public function findWithDurationLowerThan(int $duration): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.duration <= :duration')
            ->setParameter('duration')
            ->orderBy('r.duration', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Base query builder for recipes with category joined
     */
    public function getRecipesWithCategoryQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'c')
            ->leftJoin('r.category', 'c')
            ->orderBy('r.id', 'DESC');
    }

    //    /**
    //     * @return Recipe[] Returns an array of Recipe objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Recipe
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
