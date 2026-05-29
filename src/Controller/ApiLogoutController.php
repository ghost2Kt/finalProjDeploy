<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ActivityLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ApiLogoutController extends AbstractController
{
    public function __construct(private ActivityLogService $activityLogService)
    {
    }

    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'message' => 'Not authenticated.',
            ], 401);
        }

        $this->activityLogService->log('Logout', 'User', $user->getId(), json_encode([
            'source' => 'API Mobile App',
            'email' => $user->getEmail(),
        ], JSON_PRETTY_PRINT));

        return $this->json([
            'success' => true,
            'message' => 'Logged out.',
        ]);
    }
}
