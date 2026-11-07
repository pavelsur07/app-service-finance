<?php

declare(strict_types=1);

namespace App\Settlement\Form;

use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * RUB boundary: reject excess precision before the shared Money parser can round it.
 *
 * @extends AbstractType<Money|null>
 */
final class SettlementAmountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?Money $amount): string => $amount?->toDecimalString() ?? '',
            static function (mixed $raw): Money {
                if (!is_string($raw)) {
                    throw new TransformationFailedException('Введите сумму больше нуля, максимум два знака после запятой.');
                }
                $normalized = str_replace(["\u{00a0}", "\u{202f}", ' ', ','], ['', '', '', '.'], trim($raw));
                if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized)) {
                    throw new TransformationFailedException('Введите сумму больше нуля, максимум два знака после запятой.');
                }
                try {
                    $amount = Money::fromString($normalized, 'RUB');
                } catch (\InvalidArgumentException|\DomainException $exception) {
                    throw new TransformationFailedException('Сумма превышает допустимое значение.', previous: $exception);
                }
                if (!$amount->isPositive()) {
                    throw new TransformationFailedException('Сумма должна быть больше нуля.');
                }

                return $amount;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['label' => 'Сумма, ₽', 'attr' => ['inputmode' => 'decimal'], 'invalid_message' => 'Введите положительную сумму с точностью до копейки.', 'empty_data' => '']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
