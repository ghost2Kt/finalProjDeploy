<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Form\OrderType;
use App\Payment\OrderPaymentMethods;
use App\Repository\OrderRepository;
use App\Entity\StockLog;
use App\Service\ActivityLogService;
use App\Service\StockLogService;
use Symfony\Component\Form\FormError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/orders')]
final class OrderController extends AbstractController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private StockLogService $stockLogService,
    ) {
    }

    #[Route(name: 'app_admin_orders_index', methods: ['GET'])]
    public function index(OrderRepository $orderRepository): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
        
        if (!$isAdmin) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_homepage');
        }

        $orders = $orderRepository->findBy([], ['orderDate' => 'DESC']);

        // Simple aggregates for header cards
        $totalOrders = count($orders);
        $totalRevenue = 0.0;
        $customers = [];
        $displayStatus = [];
        foreach ($orders as $order) {
            $totalRevenue += (float)$order->getTotal();
            $customers[] = strtolower(trim((string)$order->getCustomerEmail()));
            $displayStatus[$order->getId()] = OrderPaymentMethods::resolveStatus($order);
        }
        $uniqueCustomers = count(array_unique(array_filter($customers)));

        return $this->render('admin/orders/index.html.twig', [
            'orders' => $orders,
            'totalOrders' => $totalOrders,
            'totalRevenue' => number_format($totalRevenue, 2, '.', ''),
            'uniqueCustomers' => $uniqueCustomers,
            'displayStatus' => $displayStatus,
        ]);
    }

    #[Route('/new', name: 'app_admin_order_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
        
        if (!$isAdmin) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_homepage');
        }

        $order = new Order();
        // Add at least one order item for new orders
        if ($order->getOrderItems()->isEmpty()) {
            $orderItem = new OrderItem();
            $order->addOrderItem($orderItem);
        }
        $form = $this->createForm(OrderType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Validate stock availability for each item
            $stockErrors = [];
            foreach ($order->getOrderItems() as $item) {
                $product = $item->getProduct();
                if (!$product) {
                    continue;
                }
                $requested = $item->getQuantity() ?? 0;
                $available = $product->getQuantity() ?? 0;
                if ($requested > $available) {
                    $stockErrors[] = sprintf(
                        'Not enough stock for %s. Available: %d, requested: %d.',
                        $product->getName(),
                        $available,
                        $requested
                    );
                }
            }

            if (!empty($stockErrors)) {
                foreach ($stockErrors as $msg) {
                    $form->addError(new FormError($msg));
                }
                return $this->render('admin/orders/new.html.twig', [
                    'order' => $order,
                    'form' => $form,
                ]);
            }

            // Set the creator if user is logged in
            if ($this->getUser()) {
                $order->setCreatedBy($this->getUser());
            }

            // Set price for each order item from product
            foreach ($order->getOrderItems() as $item) {
                if ($item->getProduct() && !$item->getPrice()) {
                    $item->setPrice((string)$item->getProduct()->getPrice());
                }
            }

            // Calculate total
            $order->calculateTotal();

            // Deduct ordered quantities from product stock
            foreach ($order->getOrderItems() as $item) {
                $product = $item->getProduct();
                if ($product) {
                    $previousQuantity = (int) $product->getQuantity();
                    $newQuantity = $previousQuantity - (int) $item->getQuantity();
                    $product->setQuantity($newQuantity);
                    $entityManager->persist($product);

                    $this->stockLogService->logChange(
                        $product,
                        $previousQuantity,
                        $newQuantity,
                        StockLog::TYPE_ORDER,
                        sprintf('Stock deducted for order %s', (string) $order->getOrderNumber()),
                        null,
                        false,
                    );
                }
            }

            $entityManager->persist($order);
            $entityManager->flush();

            $this->activityLogService->log('Create', 'Order', $order->getId(), "Order {$order->getOrderNumber()} created");

            $this->addFlash('success', 'Order created successfully.');
            return $this->redirectToRoute('app_admin_orders_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/orders/new.html.twig', [
            'order' => $order,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_order_show', methods: ['GET'])]
    public function show(Order $order): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
        
        if (!$isAdmin) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_homepage');
        }

        return $this->render('admin/orders/show.html.twig', [
            'order' => $order,
            'displayStatus' => OrderPaymentMethods::resolveStatus($order),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_order_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Order $order, EntityManagerInterface $entityManager): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
        
        if (!$isAdmin) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_homepage');
        }

        $form = $this->createForm(OrderType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Set price for each order item from product
            foreach ($order->getOrderItems() as $item) {
                if ($item->getProduct() && !$item->getPrice()) {
                    $item->setPrice((string)$item->getProduct()->getPrice());
                }
            }

            // Calculate total
            $order->calculateTotal();

            $entityManager->flush();

            $this->activityLogService->log('Update', 'Order', $order->getId(), "Order {$order->getOrderNumber()} updated");

            $this->addFlash('success', 'Order updated successfully.');
            return $this->redirectToRoute('app_admin_orders_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/orders/edit.html.twig', [
            'order' => $order,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_order_delete', methods: ['POST'])]
    public function delete(Request $request, Order $order, EntityManagerInterface $entityManager): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');
        
        if (!$isAdmin) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_homepage');
        }

        if ($this->isCsrfTokenValid('delete'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $this->activityLogService->log('Delete', 'Order', $order->getId(), "Order {$order->getOrderNumber()} deleted");
            
            $entityManager->remove($order);
            $entityManager->flush();
            
            $this->addFlash('success', 'Order deleted successfully.');
        }

        return $this->redirectToRoute('app_admin_orders_index', [], Response::HTTP_SEE_OTHER);
    }
}

