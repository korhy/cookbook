<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ingredient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ingredient>
 */
class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /**
     * Resolves many ingredient names in one bound query, indexed by their lowercased name.
     *
     * One query rather than a lookup per line: a draft may carry 50 ingredients, and the MCP write
     * path must not turn that into 50 round trips.
     *
     * @param string[] $names
     *
     * @return array<string, Ingredient> lowercased name => ingredient
     */
    public function findByNamesInsensitive(array $names): array
    {
        if ([] === $names) {
            return [];
        }

        $lowercased = array_values(array_unique(array_map(mb_strtolower(...), $names)));

        /** @var Ingredient[] $rows */
        $rows = $this->createQueryBuilder('i')
            ->andWhere('LOWER(i.name) IN (:names)')
            ->setParameter('names', $lowercased)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $ingredient) {
            $indexed[mb_strtolower((string) $ingredient->getName())] = $ingredient;
        }

        return $indexed;
    }

    /**
     * @return Ingredient[]
     */
    public function searchByName(string $name, int $limit = 10): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('LOWER(i.name) LIKE :name')
            ->setParameter('name', '%'.mb_strtolower($name).'%')
            ->orderBy('i.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Ingredient[] Returns an array of Ingredient objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('i.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Ingredient
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
