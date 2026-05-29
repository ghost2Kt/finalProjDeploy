<?php

namespace App\Controller;

use App\Entity\Order;
use App\Payment\OrderPaymentMethods;
use App\Repository\OrderRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live updates for the admin orders table (WebSocket + polling fallback).
 */
#[Route('/admin/orders/live')]
final class AdminOrdersLiveController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository,
        private JWTTokenManagerInterface $jwtManager,
        private string $websocketPublicUrl = '',
        private string $websocketBroadcastUrl = '',
    ) {
    }

    #[Route('/ws-config', name: 'app_admin_orders_live_ws_config', methods: ['GET'])]
    public function wsConfig(): JsonResponse
    {
        if (!$this->denyUnlessStaff()) {
            return $this->json(['enabled' => false], Response::HTTP_FORBIDDEN);
        }

        $origin = trim($this->websocketPublicUrl);
        $broadcastConfigured = trim($this->websocketBroadcastUrl) !== '';
        $enabled = $origin !== '' && $broadcastConfigured;

        $token = null;
        if ($enabled) {
            $user = $this->getUser();
            if ($user !== null) {
                $token = $this->jwtManager->create($user);
            }
        }

        return $this->json([
            'enabled' => $enabled && $token !== null,
            'origin' => $origin,
            'token' => $token,
        ]);
    }

    #[Route('/row/{id}', name: 'app_admin_orders_live_row', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function row(int $id): JsonResponse
    {
        if (!$this->denyUnlessStaff()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $order = $this->orderRepository->find($id);
        if (!$order instanceof Order) {
            return $this->json(['error' => 'Order not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->buildRowPayload($order));
    }

    #[Route('/poll', name: 'app_admin_orders_live_poll', methods: ['GET'])]
    public function poll(Request $request): JsonResponse
    {
        if (!$this->denyUnlessStaff()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $afterId = max(0, (int) $request->query->get('afterId', 0));
        $orders = $this->orderRepository->findNewerThanId($afterId);

        $items = [];
        foreach ($orders as $order) {
            $items[] = $this->buildRowPayload($order);
        }

        return $this->json([
            'orders' => $items,
            'stats' => $this->computeStats(),
        ]);
    }

    private function denyUnlessStaff(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
    }

    /**
     * @return array{orderId: int, html: string, stats: array{totalOrders: int, totalRevenue: string, uniqueCustomers: int}}
     */
    private function buildRowPayload(Order $order): array
    {
        $orderId = $order->getId();
        if ($orderId === null) {
            return [
                'orderId' => 0,
                'html' => '',
                'stats' => $this->computeStats(),
            ];
        }

        $html = $this->renderView('admin/orders/_order_row.html.twig', [
            'order' => $order,
            'displayStatus' => OrderPaymentMethods::resolveStatus($order),
        ]);

        return [
            'orderId' => $orderId,
            'html' => $html,
            'stats' => $this->computeStats(),
        ];
    }

    /**
     * @return array{totalOrders: int, totalRevenue: string, uniqueCustomers: int}
     */
    private function computeStats(): array
    {
        $orders = $this->orderRepository->findBy([], ['orderDate' => 'DESC']);
        $totalRevenue = 0.0;
        $customers = [];
        foreach ($orders as $order) {
            $totalRevenue += (float) $order->getTotal();
            $customers[] = mb_strtolower(trim((string) $order->getCustomerEmail()));
        }

        return [
            'totalOrders' => \count($orders),
            'totalRevenue' => number_format($totalRevenue, 2, '.', ''),
            'uniqueCustomers' => \count(array_unique(array_filter($customers))),
        ];
    }
}
