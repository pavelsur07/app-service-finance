<?php

declare(strict_types=1);

namespace App\Deals\Form;

use App\Company\Entity\Company;
use App\Deals\DTO\DealAdjustmentFormData;
use App\Deals\Enum\DealAdjustmentType as DealAdjustmentEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DealAdjustmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Тип корректировки',
                'choices' => $this->buildTypeChoices(),
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
            'data_class' => DealAdjustmentFormData::class,
            'company' => null,
        ]);
        $resolver->setAllowedTypes('company', [Company::class, 'null']);
    }

    /**
     * @return array<string, DealAdjustmentEnum>
     */
    private function buildTypeChoices(): array
    {
        $choices = [];

        foreach (DealAdjustmentEnum::cases() as $type) {
            $choices[$type->value] = $type;
        }

        return $choices;
    }
}
