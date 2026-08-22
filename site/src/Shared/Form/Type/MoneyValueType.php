<?php

declare(strict_types=1);

namespace App\Shared\Form\Type;

use App\Cash\Enum\FiatCurrency;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MoneyValueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', TextType::class, [
                'label' => 'Сумма',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'Валюта',
                'choices' => FiatCurrency::choices(),
            ])
            ->addModelTransformer(new CallbackTransformer(
                static fn (?Money $money): array => [
                    'amount' => $money?->toDecimalString() ?? '0.00',
                    'currency' => $money?->currency() ?? FiatCurrency::RUB->value,
                ],
                static function (mixed $value): Money {
                    if (!is_array($value)) {
                        throw new TransformationFailedException('Money value must be an array.', invalidMessage: 'Введите корректную сумму и валюту.');
                    }

                    $amount = $value['amount'] ?? null;
                    $currency = $value['currency'] ?? null;
                    if (!is_string($amount) || !is_string($currency)) {
                        throw new TransformationFailedException('Money amount and currency must be strings.', invalidMessage: 'Введите корректную сумму и валюту.');
                    }

                    try {
                        $money = Money::fromString(
                            $amount,
                            FiatCurrency::fromCode($currency)->value,
                        );
                    } catch (\InvalidArgumentException|\DomainException $exception) {
                        throw new TransformationFailedException('Invalid money value.', previous: $exception, invalidMessage: 'Введите корректную сумму и валюту.');
                    }

                    if ($money->isNegative()) {
                        throw new TransformationFailedException('Negative money value is not allowed.', invalidMessage: 'Минимальный остаток не может быть отрицательным.');
                    }

                    return $money;
                },
            ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'error_bubbling' => false,
            'invalid_message' => 'Введите корректную сумму и валюту.',
        ]);
    }
}
