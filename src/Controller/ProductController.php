<?php

namespace App\Controller;

use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use App\Entity\StockLog;
use App\Service\ActivityLogService;
use App\Service\ProductImageUploader;
use App\Service\StockLogService;
use App\Service\WebSocketNotifier;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;


#[Route('/product')]
final class ProductController extends AbstractController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private StockLogService $stockLogService,
        private ProductImageUploader $productImageUploader,
        private WebSocketNotifier $webSocketNotifier,
    ) {
    }
    #[Route(name: 'app_product_index', methods: ['GET'])]
    public function index(Request $request, ProductRepository $productRepository): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');

        if ($isAdmin) {
            return $this->render('product/index.html.twig', [
                'products' => $productRepository->findAll(),
                'isAdmin' => true,
            ]);
        }

        $search = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 9);
        if (!\in_array($limit, [6, 9, 12, 18], true)) {
            $limit = 9;
        }

        $total = $productRepository->countShopListing($search !== '' ? $search : null);
        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;
        $page = min($page, $totalPages);

        $products = $productRepository->findShopListing($search !== '' ? $search : null, $page, $limit);

        return $this->render('product/index.html.twig', [
            'products' => $products,
            'isAdmin' => false,
            'shopSearch' => $search,
            'shopPage' => $page,
            'shopLimit' => $limit,
            'shopTotal' => $total,
            'shopTotalPages' => $totalPages,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                try {
                    $product->setImage(
                        $this->productImageUploader->upload($imageFile, $slugger),
                    );
                } catch (FileException $e) {
                    $this->addFlash(
                        'error',
                        'Product image could not be saved. Check that uploads/images is writable, then try again.',
                    );

                    return $this->render('product/new.html.twig', [
                        'product' => $product,
                        'form' => $form,
                        'isAdmin' => $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF'),
                    ]);
                }
            }

            // Set the creator if user is logged in
            if ($this->getUser()) {
                $product->setCreatedBy($this->getUser());
            }

            $entityManager->persist($product);
            $entityManager->flush();

            $this->stockLogService->logChange(
                $product,
                0,
                (int) $product->getQuantity(),
                StockLog::TYPE_INITIAL,
                'Product created with initial stock',
            );

            // Log the action
            $this->activityLogService->logProductCreate($product);

            $pid = $product->getId();
            if ($pid !== null) {
                $this->webSocketNotifier->notifyRoom(
                    'catalog',
                    'catalog.updated',
                    [
                        'productId' => $pid,
                        'productName' => (string) $product->getName(),
                        'quantity' => (int) $product->getQuantity(),
                        'removed' => false,
                    ],
                );
            }

            $this->addFlash('success', 'Product saved successfully.');

            // Redirect to admin route if user is admin/staff
            if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
                return $this->redirectToRoute('app_admin_products_index', [], Response::HTTP_SEE_OTHER);
            }

            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            $this->addFlash('error', 'Could not save the product. Check the highlighted fields below.');
        }

        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');

        return $this->render('product/new.html.twig', [
            'product' => $product,
            'form' => $form,
            'isAdmin' => $isAdmin,
        ]);
    }

    #[Route('/{id}', name: 'app_product_show', methods: ['GET'])]
    public function show(Product $product): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');

        if ($isAdmin) {
            return $this->render('product/show.html.twig', [
                'product' => $product,
                'isAdmin' => true,
            ]);
        }

        return $this->render('product/show_public.html.twig', [
            'product' => $product,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/edit', name: 'app_product_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
    // Check if user can edit this product (admin, staff, or creator)
    // Staff can edit any product, but regular users can only edit their own
    $currentUser = $this->getUser();
    $currentUserId = \is_object($currentUser) && method_exists($currentUser, 'getId') ? $currentUser->getId() : null;
    $ownerId = $product->getCreatedBy()?->getId();

    if (
        !$this->isGranted('ROLE_ADMIN')
        && !$this->isGranted('ROLE_STAFF')
        && ($ownerId === null || $currentUserId === null || $ownerId !== $currentUserId)
    ) {
        $this->addFlash('error', 'You can only edit products you created.');
        // Redirect to admin route if user is admin/staff
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('app_admin_products_index', [], Response::HTTP_SEE_OTHER);
        }
        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }

    $form = $this->createForm(ProductType::class, $product);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $previousQuantity = $this->stockLogService->getOriginalQuantity($product);
        $imageFile = $form->get('image')->getData();

        if ($imageFile) {
            $previousImage = $product->getImage();
            try {
                $newFilename = $this->productImageUploader->upload($imageFile, $slugger);
                $this->productImageUploader->delete($previousImage);
                $product->setImage($newFilename);
            } catch (FileException $e) {
                $this->addFlash(
                    'error',
                    'Product image could not be updated. Check that uploads/images is writable, then try again.',
                );
            }
        }

        $entityManager->flush();

        $this->stockLogService->logChange(
            $product,
            $previousQuantity,
            (int) $product->getQuantity(),
            StockLog::TYPE_ADJUSTMENT,
            'Product stock updated',
        );

        // Log the action
        $this->activityLogService->logProductUpdate($product);

        $pid = $product->getId();
        if ($pid !== null) {
            $this->webSocketNotifier->notifyRoom(
                'catalog',
                'catalog.updated',
                [
                    'productId' => $pid,
                    'productName' => (string) $product->getName(),
                    'quantity' => (int) $product->getQuantity(),
                    'removed' => false,
                ],
            );
        }

        $this->addFlash('success', 'Product updated successfully.');

        // Redirect to admin route if user is admin/staff
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('app_admin_products_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }

    if ($form->isSubmitted() && !$form->isValid()) {
        $this->addFlash('error', 'Could not update the product. Check the highlighted fields below.');
    }

    $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF');

        return $this->render('product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
            'isAdmin' => $isAdmin,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/{id}/delete', name: 'app_product_delete', methods: ['POST'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
    // Check if user can delete this product (admin, staff, or creator)
    // Staff can delete any product, but regular users can only delete their own
    $currentUser = $this->getUser();
    $currentUserId = \is_object($currentUser) && method_exists($currentUser, 'getId') ? $currentUser->getId() : null;
    $ownerId = $product->getCreatedBy()?->getId();

    if (
        !$this->isGranted('ROLE_ADMIN')
        && !$this->isGranted('ROLE_STAFF')
        && ($ownerId === null || $currentUserId === null || $ownerId !== $currentUserId)
    ) {
        $this->addFlash('error', 'You can only delete products you created.');
        // Redirect to admin route if user is admin/staff
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('app_admin_products_index', [], Response::HTTP_SEE_OTHER);
        }
        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }

    if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->getPayload()->getString('_token'))) {
        $deletedId = $product->getId();
        // Log the action before deletion
        $this->activityLogService->logProductDelete($product);

        try {
            $entityManager->remove($product);
            $entityManager->flush();
            if ($deletedId !== null) {
                $this->webSocketNotifier->notifyRoom(
                    'catalog',
                    'catalog.updated',
                    [
                        'productId' => $deletedId,
                        'productName' => (string) $product->getName(),
                        'removed' => true,
                    ],
                );
            }
            $this->addFlash('success', 'Product deleted successfully.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'This product cannot be deleted because it is already used in existing orders.');
        }
    }

    // Redirect to admin route if user is admin/staff
    if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
        return $this->redirectToRoute('app_admin_products_index', [], Response::HTTP_SEE_OTHER);
    }

    return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
} 


}
