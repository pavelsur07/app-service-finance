<?php

declare(strict_types=1);

namespace App\Catalog\Form;

use App\Catalog\DTO\CreateProductCommand;
use App\Catalog\Enum\ProductStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('sku', TextType::class, [
                'label' => 'SKU',
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add('status', EnumType::class, [
                'class' => ProductStatus::class,
                'label' => 'Статус',
                'choices' => ProductStatus::cases(),
                'choice_label' => static fn (ProductStatus $status): string => match ($status) {
                    ProductStatus::ACTIVE => 'Активный',
                    ProductStatus::INACTIVE => 'Неактивный',
                    ProductStatus::DISCONTINUED => 'Снят с продажи',
                },
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Описание',
                'required' => false,
            ])
            ->add('purchasePrice', TextType::class, [
                'label' => 'Закупочная цена',
                'constraints' => [new NotBlank(), new Length(max: 10)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateProductCommand::class]);
    }
}
