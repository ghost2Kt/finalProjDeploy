<?php

namespace App\Controller;

use App\Entity\StockLog;
use App\Repository\StockLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live updates for the admin stock logs table (WebSocket + polling fallback).
 */
#[Route('/admin/stock-logs/live')]
final class AdminStockLogsLiveController extends AbstractController
{
    public function __construct(private StockLogRepository $stockLogRepository)
    {
    }

    #[Route('/row/{id}', name: 'app_admin_stock_logs_live_row', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function row(int $id): JsonResponse
    {
        if (!$this->denyUnlessStaff()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $log = $this->stockLogRepository->find($id);
        if (!$log instanceof StockLog) {
            return $this->json(['error' => 'Log not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->buildRowPayload($log));
    }

    #[Route('/poll', name: 'app_admin_stock_logs_live_poll', methods: ['GET'])]
    public function poll(Request $request): JsonResponse
    {
        if (!$this->denyUnlessStaff()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $afterId = max(0, (int) $request->query->get('afterId', 0));
        $logs = $this->stockLogRepository->findNewerThanId($afterId);

        $items = [];
        foreach ($logs as $log) {
            $items[] = $this->buildRowPayload($log);
        }

        return $this->json([
            'logs' => $items,
            'totalLogs' => $this->stockLogRepository->count([]),
        ]);
    }

    private function denyUnlessStaff(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
    }

    /**
     * @return array{logId: int, html: string, totalLogs: int}
     */
    private function buildRowPayload(StockLog $log): array
    {
        $logId = $log->getId() ?? 0;

        return [
            'logId' => $logId,
            'html' => $this->renderView('admin/stock_logs/_stock_log_row.html.twig', [
                'log' => $log,
            ]),
            'totalLogs' => $this->stockLogRepository->count([]),
        ];
    }
}
