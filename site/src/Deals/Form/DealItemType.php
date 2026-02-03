<?php

declare(strict_types=1);

namespace App\Deals\Form;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Deals\DTO\DealItemFormData;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DealItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company|null $company */
        $company = $options['company'];

        $builder
            ->add('productId', EntityType::class, [
                'class' => Product::class,
                'label' => 'Товар',
                'placeholder' => 'Выберите товар',
                'choice_label' => static fn (Product $product): string => (string) $product->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    $qb = $repository->createQueryBuilder('product')
                        ->orderBy('product.name', 'ASC');

                    if ($company) {
                        $qb->andWhere('product.company = :company')
                            ->setParameter('company', $company);
                    }

                    return $qb;
                },
            ])
            ->add('qty', NumberType::class, [
                'label' => 'Количество',
                'scale' => 2,
                'input' => 'string',
            ])
            ->add('price', NumberType::class, [
                'label' => 'Цена',
                'scale' => 2,
                'input' => 'string',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DealItemFormData::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
    }
}
