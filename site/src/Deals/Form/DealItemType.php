<?php

declare(strict_types=1);

namespace App\Deals\Form;

use App\Company\Entity\Company;
use App\Deals\DTO\DealItemFormData;
use App\Deals\Enum\DealItemKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DealItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Наименование',
                'required' => true,
            ])
            ->add('kind', ChoiceType::class, [
                'label' => 'Тип',
                'choices' => DealItemKind::cases(),
                'choice_label' => static function (DealItemKind $kind): string {
                    return match ($kind) {
                        DealItemKind::GOOD => 'Товар',
                        DealItemKind::SERVICE => 'Услуга',
                        DealItemKind::WORK => 'Работа',
                        DealItemKind::TRIP => 'Командировка',
                    };
                },
                'choice_value' => static fn (?DealItemKind $kind) => $kind?->value,
                'required' => true,
            ])
            ->add('unit', TextType::class, [
                'label' => 'Ед. изм.',
                'required' => false,
            ])
            ->add('qty', NumberType::class, [
                'label' => 'Количество',
                'scale' => 2,
                'input' => 'string',
                'required' => true,
            ])
            ->add('price', NumberType::class, [
                'label' => 'Цена',
                'scale' => 2,
                'input' => 'string',
                'required' => true,
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
