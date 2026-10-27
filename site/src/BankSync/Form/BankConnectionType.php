<?php

declare(strict_types=1);

namespace App\BankSync\Form;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Enum\BankCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<BankConnectionFormData>
 */
final class BankConnectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Подключение по входу через банк (Альфа): среда и токены — только новым входом.
        $staticToken = $options['static_token'];
        $builder
            ->add('bankCode', EnumType::class, [
                'class' => BankCode::class,
                'choice_filter' => static fn (?BankCode $code): bool => null === $code || !$options['is_new'] || $code->supportsStaticToken(),
                'choice_label' => static fn (BankCode $code): string => $code->label(),
                'label' => 'Банк',
                'disabled' => !$options['is_new'],
            ])
            ->add('baseUrl', ChoiceType::class, [
                'choices' => [
                    'Песочница (sandbox.alfabank.ru)' => BankConnectionFormData::SANDBOX_URL,
                    'Прод (baas.alfabank.ru)' => BankConnectionFormData::PRODUCTION_URL,
                ],
                'label' => 'Base URL',
                'disabled' => !$staticToken,
            ]);
        if ($staticToken) {
            $builder->add('token', PasswordType::class, [
                'label' => 'API ключ',
                'required' => $options['is_new'],
                'help' => $options['is_new'] ? null : 'Оставьте пустым, чтобы не менять ключ.',
            ]);
        }
        $builder->add('isActive', CheckboxType::class, [
            'label' => 'Активно',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BankConnectionFormData::class,
            'is_new' => true,
            'static_token' => true,
        ]);
        $resolver->setAllowedTypes('is_new', 'bool');
        $resolver->setAllowedTypes('static_token', 'bool');
    }
}
