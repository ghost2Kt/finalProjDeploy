<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Repository\ActivityLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live updates for the admin activity logs table (WebSocket + polling fallback).
 */
#[Route('/admin/logs/live')]
final class AdminActivityLogsLiveController extends AbstractController
{
    public function __construct(private ActivityLogRepository $activityLogRepository)
    {
    }

    #[Route('/row/{id}', name: 'app_admin_logs_live_row', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function row(int $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $log = $this->activityLogRepository->find($id);
        if (!$log instanceof ActivityLog) {
            return $this->json(['error' => 'Log not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->buildRowPayload($log));
    }

    #[Route('/poll', name: 'app_admin_logs_live_poll', methods: ['GET'])]
    public function poll(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $afterId = max(0, (int) $request->query->get('afterId', 0));
        $logs = $this->activityLogRepository->findNewerThanId($afterId);

        $items = [];
        foreach ($logs as $log) {
            $items[] = $this->buildRowPayload($log);
        }

        return $this->json([
            'logs' => $items,
            'totalLogs' => $this->activityLogRepository->count([]),
        ]);
    }

    /**
     * @return array{logId: int, html: string, totalLogs: int}
     */
    private function buildRowPayload(ActivityLog $log): array
    {
        $logId = $log->getId() ?? 0;

        return [
            'logId' => $logId,
            'html' => $this->renderView('admin/logs/_activity_log_row.html.twig', [
                'log' => $log,
            ]),
            'totalLogs' => $this->activityLogRepository->count([]),
        ];
    }
}
