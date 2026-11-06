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
final class SettlementDocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('counterpartyId', CounterpartyPickerType::class, ['constraints' => [new Assert\NotBlank()], 'company_id' => $options['company_id'], 'required' => true, 'browse' => true])
            ->add('kind', ChoiceType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'Вид документа', 'choices' => ['Основание долга' => 'obligation', 'Начальный остаток' => 'opening']])
            ->add('bucket', ChoiceType::class, ['constraints' => [new Assert\NotBlank()], 'label' => 'Что учитываем', 'choices' => ['Нам должны' => 'receivable', 'К оплате' => 'payable', 'Выданный аванс (начальный)' => 'advance_given', 'Полученный аванс (начальный)' => 'advance_received']])
            ->add('amount', SettlementAmountType::class)
            ->add('dueDate', DateType::class, ['label' => 'Срок оплаты', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('company_id')->setAllowedTypes('company_id', 'string');
    }

    public function getParent(): string
    {
        return SettlementEventType::class;
    }
}
