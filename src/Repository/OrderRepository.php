<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * @return Order[]
     */
    public function findForCustomer(User $user): array
    {
        $email = mb_strtolower(trim((string) $user->getEmail()));

        return $this->createQueryBuilder('o')
            ->leftJoin('o.orderItems', 'oi')
            ->addSelect('oi')
            ->leftJoin('oi.product', 'p')
            ->addSelect('p')
            ->where('o.createdBy = :user OR LOWER(o.customerEmail) = :email')
            ->setParameter('user', $user)
            ->setParameter('email', $email)
            ->orderBy('o.orderDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Orders with id greater than $afterId (for admin live poll).
     *
     * @return Order[]
     */
    public function findNewerThanId(int $afterId, int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneForCustomer(int $id, User $user): ?Order
    {
        $email = mb_strtolower(trim((string) $user->getEmail()));

        return $this->createQueryBuilder('o')
            ->leftJoin('o.orderItems', 'oi')
            ->addSelect('oi')
            ->leftJoin('oi.product', 'p')
            ->addSelect('p')
            ->where('o.id = :id')
            ->andWhere('o.createdBy = :user OR LOWER(o.customerEmail) = :email')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
