<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ApiCartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/cart')]
final class ApiCartController extends AbstractController
{
    public function __construct(private ApiCartService $apiCartService)
    {
    }

    #[Route('', name: 'api_cart_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        try {
            $view = $this->apiCartService->getCartView($user, $request);
        } catch (\Throwable) {
            return $this->json([
                'success' => true,
                'message' => 'Cart fetched successfully.',
                'data' => ['items' => [], 'total' => 0.0, 'totalItems' => 0],
                'errors' => [],
            ]);
        }

        return $this->json([
            'success' => true,
            'message' => 'Cart fetched successfully.',
            'data' => $view,
            'errors' => [],
        ]);
    }

    #[Route('/add', name: 'api_cart_add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $productId = (int) ($data['productId'] ?? 0);
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        if ($productId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'Product id is required.',
            ], 400);
        }

        try {
            $result = $this->apiCartService->add($user, $productId, $quantity, $request);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Could not add to cart. Please try again.',
                'errors' => [],
            ], 500);
        }

        $status = $result['success'] ? 200 : 409;

        return $this->json($result, $status);
    }

    #[Route('/update', name: 'api_cart_update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $productId = (int) ($data['productId'] ?? 0);
        $quantity = (int) ($data['quantity'] ?? 1);

        if ($productId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'Product id is required.',
            ], 400);
        }

        try {
            $result = $this->apiCartService->update($user, $productId, $quantity, $request);
        } catch (\Throwable) {
            return $this->json([
                'success' => false,
                'message' => 'Could not update cart. Please try again.',
                'errors' => [],
            ], 500);
        }

        return $this->json($result, $result['success'] ? 200 : 400);
    }

    #[Route('/remove', name: 'api_cart_remove', methods: ['POST'])]
    public function remove(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $productId = (int) ($data['productId'] ?? 0);

        if ($productId <= 0) {
            return $this->json([
                'success' => false,
                'message' => 'Product id is required.',
            ], 400);
        }

        try {
            $result = $this->apiCartService->remove($user, $productId, $request);
        } catch (\Throwable) {
            return $this->json([
                'success' => false,
                'message' => 'Could not remove item. Please try again.',
                'errors' => [],
            ], 500);
        }

        return $this->json($result, $result['success'] ? 200 : 400);
    }

    #[Route('/clear', name: 'api_cart_clear', methods: ['POST'])]
    public function clear(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        try {
            $result = $this->apiCartService->clear($user, $request);
        } catch (\Throwable) {
            return $this->json([
                'success' => false,
                'message' => 'Could not clear cart. Please try again.',
                'errors' => [],
            ], 500);
        }

        return $this->json($result);
    }

    #[Route('/checkout', name: 'api_cart_checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true) ?? [];

        $result = $this->apiCartService->checkout($user, [
            'customer_name' => (string) ($data['customer_name'] ?? ''),
            'customer_email' => (string) ($data['customer_email'] ?? ''),
            'customer_phone' => (string) ($data['customer_phone'] ?? ''),
            'payment_method' => (string) ($data['payment_method'] ?? ''),
        ], $request);

        $status = $result['success'] ? 201 : 400;

        return $this->json($result, $status);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication required.');
        }

        return $user;
    }
}
