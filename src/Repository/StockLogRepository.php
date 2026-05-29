<?php

namespace App\Repository;

use App\Entity\StockLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockLog>
 */
class StockLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockLog::class);
    }

    /**
     * @return StockLog[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return StockLog[]
     */
    public function findNewerThanId(int $afterId, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
