<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live updates for the admin users table (WebSocket + polling fallback).
 */
#[Route('/admin/users/live')]
final class AdminUsersLiveController extends AbstractController
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    #[Route('/row/{id}', name: 'app_admin_users_live_row', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function row(int $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return $this->json(['error' => 'User not found.', 'removed' => true], Response::HTTP_NOT_FOUND);
        }

        $role = $this->normalizeRole((string) $request->query->get('role', 'all'));
        if (!$this->userMatchesRoleFilter($user, $role)) {
            return $this->json(['error' => 'User not in filter.', 'removed' => true], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->buildRowPayload($user));
    }

    #[Route('/poll', name: 'app_admin_users_live_poll', methods: ['GET'])]
    public function poll(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $afterId = max(0, (int) $request->query->get('afterId', 0));
        $role = $this->normalizeRole((string) $request->query->get('role', 'all'));
        $watchIds = $this->parseWatchIds((string) $request->query->get('watchIds', ''));

        $newUsers = $this->userRepository->findNewerThanId($afterId, $role);
        $items = [];
        foreach ($newUsers as $user) {
            $items[] = $this->buildRowPayload($user);
        }

        $updates = [];
        $removed = [];
        if ($watchIds !== []) {
            $foundById = [];
            foreach ($this->userRepository->findBy(['id' => $watchIds]) as $user) {
                if ($user instanceof User && $user->getId() !== null) {
                    $foundById[$user->getId()] = $user;
                }
            }

            foreach ($watchIds as $watchId) {
                if (!isset($foundById[$watchId])) {
                    $removed[] = $watchId;
                    continue;
                }
                $user = $foundById[$watchId];
                if (!$this->userMatchesRoleFilter($user, $role)) {
                    $removed[] = $watchId;
                    continue;
                }
                $updates[] = $this->buildRowPayload($user);
            }
        }

        return $this->json([
            'users' => $items,
            'updates' => $updates,
            'removed' => array_values(array_unique($removed)),
            'totalUsers' => $this->countUsersForRole($role),
        ]);
    }

    private function normalizeRole(string $role): string
    {
        return in_array($role, ['all', 'admin', 'staff', 'user'], true) ? $role : 'all';
    }

    /**
     * @return int[]
     */
    private function parseWatchIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function userMatchesRoleFilter(User $user, string $role): bool
    {
        $roles = $user->getRoles();

        return match ($role) {
            'admin' => in_array('ROLE_ADMIN', $roles, true),
            'staff' => in_array('ROLE_STAFF', $roles, true),
            'user' => !in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_STAFF', $roles, true),
            default => true,
        };
    }

    private function countUsersForRole(string $role): int
    {
        $qb = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)');
        $this->applyRoleFilter($qb, $role);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applyRoleFilter(QueryBuilder $qb, string $role): void
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

    /**
     * @return array{userId: int, html: string, totalUsers: int}
     */
    private function buildRowPayload(User $user): array
    {
        $userId = $user->getId() ?? 0;

        return [
            'userId' => $userId,
            'html' => $this->renderView('admin/users/_user_row.html.twig', [
                'user' => $user,
            ]),
            'totalUsers' => $this->countUsersForRole('all'),
        ];
    }
}
