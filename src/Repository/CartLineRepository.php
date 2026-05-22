<?php

namespace App\Repository;

use App\Entity\CartLine;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CartLine>
 */
class CartLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartLine::class);
    }

    /**
     * @return CartLine[]
     */
    public function findForUser(User $user): array
    {
        $userId = $user->getId();
        if ($userId === null) {
            return [];
        }

        return $this->findForUserId($userId);
    }

    /**
     * @return CartLine[]
     */
    public function findForUserId(int $userId): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.product', 'p')->addSelect('p')
            ->innerJoin('c.user', 'u')
            ->andWhere('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function deleteForUserId(int $userId): void
    {
        $userRef = $this->getEntityManager()->getReference(User::class, $userId);
        $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.user = :user')
            ->setParameter('user', $userRef)
            ->getQuery()
            ->execute();
    }
}
