<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\StockLog;
use App\Repository\ActivityLogRepository;
use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\StockLogRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live stat and timeline updates for admin and staff dashboards.
 */
final class AdminDashboardLiveController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private ProductRepository $productRepository,
        private CategoryRepository $categoryRepository,
        private SupplierRepository $supplierRepository,
        private OrderRepository $orderRepository,
        private ActivityLogRepository $activityLogRepository,
        private StockLogRepository $stockLogRepository,
    ) {
    }

    #[Route('/admin/dashboard/live/poll', name: 'app_admin_dashboard_live_poll', methods: ['GET'])]
    public function adminPoll(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $afterActivityId = max(0, (int) $request->query->get('afterActivityId', 0));
        $afterStockId = max(0, (int) $request->query->get('afterStockId', 0));

        return $this->json(array_merge(
            $this->buildAdminStats(),
            $this->buildRecentActivityPayload($afterActivityId),
            $this->buildRecentStockPayload($afterStockId),
        ));
    }

    #[Route('/staff/dashboard/live/poll', name: 'app_staff_dashboard_live_poll', methods: ['GET'])]
    public function staffPoll(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_STAFF')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $afterStockId = max(0, (int) $request->query->get('afterStockId', 0));

        return $this->json(array_merge(
            $this->buildStaffStats(),
            $this->buildRecentStockPayload($afterStockId),
        ));
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

    /**
     * @return array{activityLogs: list<array{logId: int, html: string}>}
     */
    private function buildRecentActivityPayload(int $afterActivityId): array
    {
        $logs = $this->activityLogRepository->findNewerThanId($afterActivityId);
        $items = [];
        foreach ($logs as $log) {
            if (!$log instanceof ActivityLog) {
                continue;
            }
            $logId = $log->getId();
            if ($logId === null) {
                continue;
            }
            $items[] = [
                'logId' => $logId,
                'html' => $this->renderView('admin/logs/_activity_log_timeline_item.html.twig', [
                    'log' => $log,
                ]),
            ];
        }

        return ['activityLogs' => array_reverse($items)];
    }

    /**
     * @return array{stockLogs: list<array{logId: int, html: string}>}
     */
    private function buildRecentStockPayload(int $afterStockId): array
    {
        $logs = $this->stockLogRepository->findNewerThanId($afterStockId);
        $items = [];
        foreach ($logs as $log) {
            if (!$log instanceof StockLog) {
                continue;
            }
            $logId = $log->getId();
            if ($logId === null) {
                continue;
            }
            $items[] = [
                'logId' => $logId,
                'html' => $this->renderView('admin/stock_logs/_stock_log_timeline_item.html.twig', [
                    'log' => $log,
                ]),
            ];
        }

        return ['stockLogs' => array_reverse($items)];
    }
}
