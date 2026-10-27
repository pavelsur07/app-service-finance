<?php

declare(strict_types=1);

namespace App\BankSync\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Выбор денежного счёта для счёта банка. Варианты — из `CashFacade` через
 * `BankAccountLinkChoices`, а не EntityType с сущностью Cash.
 *
 * @extends AbstractType<array{moneyAccountId: string|null}>
 */
final class BankAccountLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('moneyAccountId', ChoiceType::class, [
            'label' => false,
            'choices' => $options['link_choices'],
            'placeholder' => 'Выберите денежный счёт',
            'constraints' => [new NotBlank(message: 'Выберите денежный счёт.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('link_choices');
        $resolver->setAllowedTypes('link_choices', 'array');
    }
}
