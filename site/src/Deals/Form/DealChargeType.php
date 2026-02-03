<?php

declare(strict_types=1);

namespace App\Deals\Form;

use App\Company\Entity\Company;
use App\Deals\DTO\DealChargeFormData;
use App\Deals\Entity\ChargeType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DealChargeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Company|null $company */
        $company = $options['company'];

        $builder
            ->add('chargeType', EntityType::class, [
                'class' => ChargeType::class,
                'label' => 'Тип начисления',
                'placeholder' => 'Выберите тип начисления',
                'choice_label' => static fn (ChargeType $type): string => (string) $type->getName(),
                'query_builder' => static function (EntityRepository $repository) use ($company) {
                    $qb = $repository->createQueryBuilder('chargeType')
                        ->orderBy('chargeType.name', 'ASC')
                        ->andWhere('chargeType.isActive = true');

                    if ($company) {
                        $qb->andWhere('chargeType.company = :company')
                            ->setParameter('company', $company);
                    }

                    return $qb;
                },
            ])
            ->add('recognizedAt', DateType::class, [
                'label' => 'Дата признания',
                'widget' => 'single_text',
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Сумма',
                'scale' => 2,
                'input' => 'string',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Комментарий',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DealChargeFormData::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
    }
}
