<?php

namespace App\Form;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class OrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerName', TextType::class, [
                'label' => 'Customer Name',
                'constraints' => [
                    new NotBlank(['message' => 'Customer name is required.']),
                ],
            ])
            ->add('customerEmail', EmailType::class, [
                'label' => 'Customer Email',
                'constraints' => [
                    new NotBlank(['message' => 'Customer email is required.']),
                ],
            ])
            ->add('customerPhone', TextType::class, [
                'label' => 'Customer Phone',
                'constraints' => [
                    new NotBlank(['message' => 'Customer phone is required.']),
                ],
            ])
            ->add('orderDate', DateTimeType::class, [
                'label' => 'Order Date',
                'widget' => 'single_text',
                'required' => true,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Pending' => 'Pending',
                    'Paid' => 'Paid',
                ],
                'required' => false,
            ])
            ->add('orderItems', CollectionType::class, [
                'entry_type' => OrderItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Order Items',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Order::class,
        ]);
    }
}

