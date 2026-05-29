<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live stat updates for admin and staff dashboards (polling + optional WebSocket trigger).
 */
final class AdminDashboardLiveController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private ProductRepository $productRepository,
        private CategoryRepository $categoryRepository,
        private SupplierRepository $supplierRepository,
        private OrderRepository $orderRepository,
    ) {
    }

    #[Route('/admin/dashboard/live/poll', name: 'app_admin_dashboard_live_poll', methods: ['GET'])]
    public function adminPoll(): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->buildAdminStats());
    }

    #[Route('/staff/dashboard/live/poll', name: 'app_staff_dashboard_live_poll', methods: ['GET'])]
    public function staffPoll(): JsonResponse
    {
        if (!$this->isGranted('ROLE_STAFF')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->buildStaffStats());
    }

    /**
     * @return array<string, int>
     */
    private function buildAdminStats(): array
    {
        $totalStaff = (int) $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_STAFF%')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'totalUsers' => $this->userRepository->count([]),
            'totalStaff' => $totalStaff,
            'totalProducts' => $this->productRepository->count([]),
            'totalCategories' => $this->categoryRepository->count([]),
            'totalSuppliers' => $this->supplierRepository->count([]),
            'totalOrders' => $this->orderRepository->count([]),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildStaffStats(): array
    {
        return [
            'totalProducts' => $this->productRepository->count([]),
            'totalCategories' => $this->categoryRepository->count([]),
            'totalSuppliers' => $this->supplierRepository->count([]),
            'totalOrders' => $this->orderRepository->count([]),
        ];
    }
}
