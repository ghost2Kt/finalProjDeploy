<?php

namespace App\Repository;

use App\Entity\ActivityLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityLog>
 */
class ActivityLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityLog::class);
    }

    /**
     * @return ActivityLog[]
     */
    public function findNewerThanId(int $afterId, int $limit = 50): array
    {
        return $this->createQueryBuilder('log')
            ->andWhere('log.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('log.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
