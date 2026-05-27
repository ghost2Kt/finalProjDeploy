<?php

namespace App\Service;

use App\Payment\OrderPaymentMethods;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Entity\CartLine;
use App\Repository\CartLineRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Mobile/API cart: MySQL cart_line when migrated, else Symfony cache (legacy fallback).
 */
final class ApiCartService
{
    private const CACHE_TTL = 604800;

    private ?bool $databaseCartEnabled = null;

    public function __construct(
        private CartLineRepository $cartLineRepository,
        private ProductRepository $productRepository,
        private EntityManagerInterface $entityManager,
        private ActivityLogService $activityLogService,
        private CacheItemPoolInterface $cache,
        private WebSocketNotifier $webSocketNotifier,
    ) {
    }

    public function getCartView(User $user, Request $request): array
    {
        [$items, $total, $totalItems] = $this->buildCartView($user, $request);

        return [
            'items' => $items,
            'total' => $total,
            'totalItems' => $totalItems,
        ];
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: string[]}
     */
    public function add(User $user, int $productId, int $quantityToAdd, Request $request): array
    {
        $product = $this->productRepository->find($productId);
        if (!$product instanceof Product) {
            return ['success' => false, 'message' => 'Product not found.'];
        }

        $quantityToAdd = max(1, $quantityToAdd);
        $cart = $this->readCart($user);
        $currentQty = (int) ($cart[$product->getId()] ?? 0);
        $stock = (int) ($product->getQuantity() ?? 0);

        if ($stock <= 0) {
            return ['success' => false, 'message' => 'This product is out of stock.'];
        }

        $reservedQty = min($quantityToAdd, $stock);
        $newQty = $currentQty + $reservedQty;

        if ($reservedQty <= 0) {
            return ['success' => false, 'message' => 'Unable to reserve stock for this product.'];
        }

        $userId = $user->getId();
        if ($userId === null) {
            return ['success' => false, 'message' => 'Invalid user session.'];
        }

        $cart[$product->getId()] = $newQty;

        $product->setQuantity($stock - $reservedQty);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->writeCart($userId, $cart);

        [$items, $total, $totalItems] = $this->buildCartViewFromMap($cart, $request);

        return [
            'success' => true,
            'message' => 'Added to cart.',
            'data' => [
                'productId' => $product->getId(),
                'cartQuantityForProduct' => $newQty,
                'cartTotalItems' => $totalItems,
                'totalItems' => $totalItems,
                'remainingStock' => (int) ($product->getQuantity() ?? 0),
                'items' => $items,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: string[]}
     */
    public function update(User $user, int $productId, int $requestedQty, Request $request): array
    {
        $product = $this->productRepository->find($productId);
        if (!$product instanceof Product) {
            return ['success' => false, 'message' => 'Product not found.'];
        }

        $cart = $this->readCart($user);
        $currentQty = (int) ($cart[$product->getId()] ?? 0);
        $stock = (int) ($product->getQuantity() ?? 0);

        if ($requestedQty <= 0) {
            if ($currentQty > 0) {
                $product->setQuantity($stock + $currentQty);
                $this->entityManager->persist($product);
            }
            unset($cart[$product->getId()]);
        } else {
            $delta = $requestedQty - $currentQty;
            if ($delta > 0) {
                $canReserve = min($delta, $stock);
                $requestedQty = $currentQty + $canReserve;
                if ($canReserve > 0) {
                    $product->setQuantity($stock - $canReserve);
                    $this->entityManager->persist($product);
                }
            } elseif ($delta < 0) {
                $returnQty = abs($delta);
                $product->setQuantity($stock + $returnQty);
                $this->entityManager->persist($product);
            }
            if ($requestedQty > 0) {
                $cart[$product->getId()] = $requestedQty;
            } else {
                unset($cart[$product->getId()]);
            }
        }

        $userId = $user->getId();
        if ($userId === null) {
            return ['success' => false, 'message' => 'Invalid user session.'];
        }

        $this->entityManager->flush();
        $this->writeCart($userId, $cart);

        [$items, $total, $totalItems] = $this->buildCartViewFromMap($cart, $request);

        return [
            'success' => true,
            'message' => 'Cart updated.',
            'data' => [
                'items' => $items,
                'total' => $total,
                'totalItems' => $totalItems,
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function remove(User $user, int $productId, Request $request): array
    {
        $product = $this->productRepository->find($productId);
        if (!$product instanceof Product) {
            return ['success' => false, 'message' => 'Product not found.'];
        }

        $cart = $this->readCart($user);
        $currentQty = (int) ($cart[$product->getId()] ?? 0);
        if ($currentQty > 0) {
            $stock = (int) ($product->getQuantity() ?? 0);
            $product->setQuantity($stock + $currentQty);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }
        unset($cart[$product->getId()]);

        $userId = $user->getId();
        if ($userId === null) {
            return ['success' => false, 'message' => 'Invalid user session.'];
        }

        $this->writeCart($userId, $cart);

        [$items, $total, $totalItems] = $this->buildCartViewFromMap($cart, $request);

        return [
            'success' => true,
            'message' => 'Item removed from cart.',
            'data' => [
                'items' => $items,
                'total' => $total,
                'totalItems' => $totalItems,
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public function clear(User $user, Request $request): array
    {
        $cart = $this->readCart($user);
        if ($cart !== []) {
            $productIds = array_map('intval', array_keys($cart));
            $products = $this->productRepository->findBy(['id' => $productIds]);
            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product->getId()] = $product;
            }

            foreach ($cart as $productId => $quantity) {
                $productId = (int) $productId;
                $quantity = max(0, (int) $quantity);
                if ($quantity <= 0) {
                    continue;
                }
                $product = $productMap[$productId] ?? null;
                if (!$product) {
                    continue;
                }
                $stock = (int) ($product->getQuantity() ?? 0);
                $product->setQuantity($stock + $quantity);
                $this->entityManager->persist($product);
            }
            $this->entityManager->flush();
        }

        $userId = $user->getId();
        if ($userId !== null) {
            $this->writeCart($userId, []);
        }

        return [
            'success' => true,
            'message' => 'Cart cleared.',
            'data' => [
                'items' => [],
                'total' => 0.0,
                'totalItems' => 0,
            ],
        ];
    }

    /**
     * @param array{customer_name?: string, customer_email?: string, customer_phone?: string, payment_method?: string} $formData
     *
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: string[]}
     */
    public function checkout(User $user, array $formData, Request $request): array
    {
        [$items, $total, ] = $this->buildCartView($user, $request);
        if ($items === []) {
            return ['success' => false, 'message' => 'Your cart is empty.', 'errors' => ['Your cart is empty.']];
        }

        $errors = [];
        $customerName = trim((string) ($formData['customer_name'] ?? ''));
        $customerEmail = trim((string) ($formData['customer_email'] ?? $user->getEmail() ?? ''));
        $customerPhone = trim((string) ($formData['customer_phone'] ?? ''));

        if ($customerName === '') {
            $errors[] = 'Customer name is required.';
        }
        if ($customerPhone === '') {
            $errors[] = 'Customer phone is required.';
        } elseif (!$this->isValidPhoneNumber($customerPhone)) {
            $errors[] = 'Please enter a valid phone number (digits only, optional +, spaces, or hyphens).';
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        $paymentKey = strtolower(trim((string) ($formData['payment_method'] ?? '')));
        if ($paymentKey === '' || !OrderPaymentMethods::isValidKey($paymentKey)) {
            $errors[] = 'Please select a valid payment method.';
        }

        if ($errors !== []) {
            return ['success' => false, 'message' => 'Please fix the form errors.', 'errors' => $errors];
        }

        $paymentLabel = OrderPaymentMethods::labelForKey($paymentKey);

        $order = new Order();
        $order->setCustomerName($customerName);
        $order->setCustomerEmail(mb_strtolower($customerEmail));
        $order->setCustomerPhone($customerPhone);
        $order->setStatus('Pending');
        $order->setPaymentMethod($paymentLabel);
        $order->setOrderDate(new \DateTime());
        $order->setCreatedBy($user);

        $cart = $this->readCart($user);
        $productIds = array_map('intval', array_keys($cart));
        $products = $productIds === [] ? [] : $this->productRepository->findBy(['id' => $productIds]);
        $productMap = [];
        foreach ($products as $product) {
            $productMap[$product->getId()] = $product;
        }

        foreach ($cart as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = (int) $quantity;
            $product = $productMap[$productId] ?? null;
            if (!$product || $quantity <= 0) {
                continue;
            }

            $orderItem = new OrderItem();
            $orderItem->setProduct($product);
            $orderItem->setQuantity($quantity);
            $orderItem->setPrice((string) $product->getPrice());
            $order->addOrderItem($orderItem);
        }

        if ($order->getOrderItems()->count() === 0) {
            return ['success' => false, 'message' => 'No valid items in cart.', 'errors' => ['No valid items in cart.']];
        }

        $order->calculateTotal();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $this->activityLogService->log(
            'Create',
            'Order',
            $order->getId(),
            sprintf('Order %s created from mobile API checkout', (string) $order->getOrderNumber())
        );

        $userId = $user->getId();
        if ($userId !== null) {
            $this->webSocketNotifier->notifyUser(
                $userId,
                'order.updated',
                [
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'status' => $order->getStatus(),
                ],
            );
        }

        if ($userId !== null) {
            $this->writeCart($userId, []);
        }

        return [
            'success' => true,
            'message' => sprintf('Order placed successfully! Order number: %s', (string) $order->getOrderNumber()),
            'data' => [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'total' => (float) $order->getTotal(),
                'paymentMethod' => $paymentLabel,
                'status' => $order->getStatus() ?? 'Pending',
                'items' => [],
                'cartTotalItems' => 0,
                'totalItems' => 0,
            ],
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: float, 2: int}
     */
    private function buildCartView(User $user, Request $request): array
    {
        return $this->buildCartViewFromMap($this->readCart($user), $request);
    }

    /**
     * @param array<int, int> $cart
     *
     * @return array{0: list<array<string, mixed>>, 1: float, 2: int}
     */
    private function buildCartViewFromMap(array $cart, Request $request): array
    {
        if ($cart === []) {
            return [[], 0.0, 0];
        }

        $productIds = array_map('intval', array_keys($cart));
        $products = $this->productRepository->findBy(['id' => $productIds]);
        $productMap = [];
        foreach ($products as $product) {
            $productMap[$product->getId()] = $product;
        }

        $items = [];
        $total = 0.0;
        $totalItems = 0;

        foreach ($cart as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = max(1, (int) $quantity);
            $product = $productMap[$productId] ?? null;
            if (!$product) {
                continue;
            }

            $price = (float) $product->getPrice();
            $subtotal = $price * $quantity;
            $imageUrl = null;
            if ($product->getImage()) {
                $imageUrl = $request->getSchemeAndHttpHost() . '/uploads/images/' . $product->getImage();
            }

            $items[] = [
                'productId' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => $price,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'availableStock' => (int) ($product->getQuantity() ?? 0),
                'category' => $product->getCategory()?->getName(),
                'imageUrl' => $imageUrl,
            ];
            $total += $subtotal;
            $totalItems += $quantity;
        }

        return [$items, $total, $totalItems];
    }

    private function isDatabaseCartEnabled(): bool
    {
        if ($this->databaseCartEnabled !== null) {
            return $this->databaseCartEnabled;
        }

        try {
            $this->databaseCartEnabled = $this->entityManager->getConnection()
                ->createSchemaManager()
                ->tablesExist(['cart_line']);
        } catch (\Throwable) {
            $this->databaseCartEnabled = false;
        }

        return $this->databaseCartEnabled;
    }

    /** @return array<int, int> */
    private function readCart(User $user): array
    {
        $userId = $user->getId();
        if ($userId === null) {
            return [];
        }

        if ($this->isDatabaseCartEnabled()) {
            try {
                return $this->readCartDatabase($userId);
            } catch (\Throwable) {
                $this->databaseCartEnabled = false;
            }
        }

        return $this->readCartCache($userId);
    }

    /** @return array<int, int> */
    private function readCartDatabase(int $userId): array
    {
        $cart = [];
        foreach ($this->cartLineRepository->findForUserId($userId) as $line) {
            $product = $line->getProduct();
            if ($product?->getId()) {
                $cart[$product->getId()] = $line->getQuantity();
            }
        }

        return $cart;
    }

    /** @return array<int, int> */
    private function readCartCache(int $userId): array
    {
        $item = $this->cache->getItem($this->cartCacheKey($userId));
        if (!$item->isHit()) {
            return [];
        }
        $data = $item->get();

        return is_array($data) ? $data : [];
    }

    /** @param array<int, int> $cart */
    private function writeCart(int $userId, array $cart): void
    {
        if ($this->isDatabaseCartEnabled()) {
            try {
                $this->writeCartDatabase($userId, $cart);
            } catch (\Throwable) {
                $this->databaseCartEnabled = false;
                $this->writeCartCache($userId, $cart);

                return;
            }
        }

        // Keep cache in sync so readCart fallback never shows stale items after checkout/clear.
        $this->writeCartCache($userId, $cart);
    }

    /** @param array<int, int> $cart */
    private function writeCartDatabase(int $userId, array $cart): void
    {
        $userRef = $this->entityManager->getReference(User::class, $userId);
        $existing = [];
        foreach ($this->cartLineRepository->findForUserId($userId) as $line) {
            $product = $line->getProduct();
            if ($product?->getId()) {
                $existing[$product->getId()] = $line;
            }
        }

        $seen = [];
        foreach ($cart as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = max(0, (int) $quantity);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $seen[$productId] = true;
            $line = $existing[$productId] ?? null;
            if (!$line instanceof CartLine) {
                $product = $this->productRepository->find($productId);
                if (!$product instanceof Product) {
                    continue;
                }
                $line = new CartLine();
                $line->setUser($userRef);
                $line->setProduct($product);
                $this->entityManager->persist($line);
            }
            $line->setQuantity($quantity);
        }

        foreach ($existing as $productId => $line) {
            if (!isset($seen[$productId])) {
                $this->entityManager->remove($line);
            }
        }

        $this->entityManager->flush();
    }

    /** @param array<int, int> $cart */
    private function writeCartCache(int $userId, array $cart): void
    {
        $item = $this->cache->getItem($this->cartCacheKey($userId));
        $item->set($cart);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);
    }

    private function cartCacheKey(int $userId): string
    {
        return 'api_cart_user_' . $userId;
    }

    private function isValidPhoneNumber(string $phone): bool
    {
        if (!preg_match('/^\+?[0-9][0-9\s-]*$/', $phone)) {
            return false;
        }

        $digitsOnly = preg_replace('/\D/', '', $phone);
        if (!is_string($digitsOnly)) {
            return false;
        }

        $length = strlen($digitsOnly);

        return $length >= 10 && $length <= 15;
    }
}
