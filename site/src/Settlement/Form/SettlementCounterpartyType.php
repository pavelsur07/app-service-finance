<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use App\Company\Form\Type\CounterpartyPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string,mixed>> */
final class SettlementCounterpartyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('counterparty', CounterpartyPickerType::class, ['constraints' => [new Assert\NotBlank()], 'company_id' => $options['company_id'], 'browse' => true, 'required' => true, 'keep_id' => $options['keep_id']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company_id')->setAllowedTypes('company_id', 'string')->setDefaults(['method' => 'GET', 'csrf_protection' => false, 'data_class' => null, 'keep_id' => null]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
