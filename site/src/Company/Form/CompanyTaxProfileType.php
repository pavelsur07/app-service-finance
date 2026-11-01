<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Период налогового профиля: режим, режим НДС и месяц начала (пусто — начальный профиль).
 * Данные — массив {taxRegime: ?TaxRegime, vatMode: ?VatMode, validFrom: ?string 'YYYY-MM'}.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class CompanyTaxProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $required = $options['require_profile'];

        $builder
            ->add('taxRegime', ChoiceType::class, [
                'label' => 'Режим налогообложения',
                'choices' => TaxRegime::cases(),
                'choice_value' => static fn (?TaxRegime $choice) => $choice?->value,
                'choice_label' => static fn (TaxRegime $choice) => $choice->label(),
                'placeholder' => $required ? 'Выберите режим' : 'Укажу позже',
                'required' => $required,
                'constraints' => $required ? [new Assert\NotNull(message: 'Выберите режим налогообложения.')] : [],
                'attr' => ['class' => 'form-select', 'data-tax-regime' => ''],
            ])
            ->add('vatMode', ChoiceType::class, [
                'label' => 'НДС',
                'choices' => VatMode::cases(),
                'choice_value' => static fn (?VatMode $choice) => $choice?->value,
                'choice_label' => static fn (VatMode $choice) => $choice->label(),
                'placeholder' => $required ? 'Выберите режим НДС' : 'Укажу позже',
                'required' => $required,
                'constraints' => $required ? [new Assert\NotNull(message: 'Выберите режим НДС.')] : [],
                'attr' => ['class' => 'form-select', 'data-vat-mode' => ''],
            ]);

        if ($options['with_valid_from']) {
            $builder->add('validFrom', TextType::class, [
                'label' => 'С месяца',
                'required' => false,
                'help' => 'Пусто — режим с начала учёта.',
                'attr' => ['type' => 'month', 'class' => 'form-control', 'placeholder' => 'ГГГГ-ММ'],
                'constraints' => [new Assert\Regex(pattern: '/^\d{4}-(0[1-9]|1[0-2])$/', message: 'Укажите месяц в формате ГГГГ-ММ.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'require_profile' => true,
            'with_valid_from' => true,
            'constraints' => [new Assert\Callback(self::validateCombination(...))],
        ]);
        $resolver->setAllowedTypes('require_profile', 'bool');
        $resolver->setAllowedTypes('with_valid_from', 'bool');
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function validateCombination(?array $data, ExecutionContextInterface $context): void
    {
        $regime = $data['taxRegime'] ?? null;
        $vatMode = $data['vatMode'] ?? null;

        if (null === $regime && null === $vatMode) {
            return;
        }
        if (!$regime instanceof TaxRegime || !$vatMode instanceof VatMode) {
            $context->buildViolation('Укажите и режим налогообложения, и режим НДС — или оставьте оба пустыми.')->addViolation();

            return;
        }
        if (!$regime->allows($vatMode)) {
            $context->buildViolation(sprintf('Режим «%s» не допускает «%s».', $regime->label(), $vatMode->label()))
                ->atPath('[vatMode]')
                ->addViolation();
        }
    }

    /**
     * Первое число месяца из значения поля 'YYYY-MM'; null — начальный профиль.
     */
    public static function parseValidFrom(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');

        return false === $date ? null : $date;
    }
}
