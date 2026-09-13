<?php

declare(strict_types=1);

namespace App\Api\Form;

use App\Api\Domain\ApiScopeCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<array{selectedScopes: list<string>, enabledResources: list<string>, version: string}> */
final class ApiKeyPermissionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $scopes = ApiScopeCatalog::scopes();
        $connected = ApiScopeCatalog::connectedResources();
        $builder
            ->add('selectedScopes', ChoiceType::class, [
                'label' => 'Подготовленные права',
                'choices' => array_combine($scopes, $scopes),
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'invalid_message' => 'Выбрано неизвестное право API.',
            ])
            ->add('enabledResources', ChoiceType::class, [
                'label' => 'Включённые разделы',
                'choices' => array_combine($connected, $connected),
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'invalid_message' => 'Нельзя включить неизвестный или ещё не подключённый раздел.',
            ])
            ->add('version', HiddenType::class, [
                'constraints' => [
                    new NotBlank(message: 'Обновите страницу перед сохранением.'),
                    new Regex(pattern: '/^[1-9][0-9]*$/D', message: 'Некорректная версия настроек. Обновите страницу.'),
                    new Range(min: 1, max: 2147483647, notInRangeMessage: 'Некорректная версия настроек. Обновите страницу.'),
                ],
            ]);
    }
}
