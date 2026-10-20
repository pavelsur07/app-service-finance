<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Enum\BusinessProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Выбор профиля бизнеса при создании компании: регистрация, `/company/new`, админка.
 * Варианты — радио-карточки; подпись и пояснение берутся из enum.
 *
 * @extends AbstractType<BusinessProfile|null>
 */
final class BusinessProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Пустую строку ChoiceType считает известным значением и превращает в null мимо
        // empty_data: регистрация падала бы на null-профиле. Как отсутствие поля — к умолчанию.
        // Приоритет выше, чем у PRE_SUBMIT самого ChoiceType.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            if ('' === $event->getData()) {
                $event->setData(null);
            }
        }, 1);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => BusinessProfile::class,
            'expanded' => true,
            'label' => 'Как вы продаёте?',
            'choice_label' => static fn (BusinessProfile $profile): string => $profile->label(),
            'choice_attr' => static fn (BusinessProfile $profile): array => ['data-description' => $profile->description()],
            // Без значения — профиль по умолчанию: так ведут себя страница, открытая до
            // появления вопроса, и клиенты без поля. Неизвестное значение — ошибка формы.
            'empty_data' => BusinessProfile::DEFAULT->value,
            'invalid_message' => 'Выберите, как вы продаёте',
        ]);
    }

    public function getParent(): string
    {
        return EnumType::class;
    }
}
