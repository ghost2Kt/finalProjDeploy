<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @return User[]
     */
    public function findNewerThanId(int $afterId, string $role = 'all', int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults($limit);

        $this->applyRoleFilter($qb, $role);

        return $qb->getQuery()->getResult();
    }

    private function applyRoleFilter(\Doctrine\ORM\QueryBuilder $qb, string $role): void
    {
        if ($role === 'admin') {
            $qb->andWhere('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_ADMIN%');
        } elseif ($role === 'staff') {
            $qb->andWhere('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_STAFF%');
        } elseif ($role === 'user') {
            $qb->andWhere('u.roles NOT LIKE :adminRole')
                ->andWhere('u.roles NOT LIKE :staffRole')
                ->setParameter('adminRole', '%ROLE_ADMIN%')
                ->setParameter('staffRole', '%ROLE_STAFF%');
        }
    }
}
