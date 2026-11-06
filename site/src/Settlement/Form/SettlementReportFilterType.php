<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use App\Company\Form\Type\CounterpartyPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementReportFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('from', DateType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'С', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('to', DateType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'По', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('counterparty', CounterpartyPickerType::class, ['company_id' => $options['company_id'], 'browse' => true, 'required' => false, 'keep_id' => $options['keep_id']])
            ->add('limit', ChoiceType::class, ['constraints' => [new Assert\NotNull()], 'label' => 'На странице', 'choices' => ['25' => 25, '50' => 50, '100' => 100]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company_id')->setAllowedTypes('company_id', 'string')->setDefaults(['method' => 'GET', 'csrf_protection' => false, 'data_class' => null, 'allow_extra_fields' => true, 'keep_id' => null]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
