<?php

declare(strict_types=1);

namespace App\Shared\Form\Type;

use App\Company\Entity\ProjectDirection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Выбор проекта из переданного списка `choices` (форма не ходит в БД сама).
 *
 * До этапа 7.5 плана Cash тип наследовал `EntityType`: модули, использующие пикер,
 * получали Doctrine-тип с сущностью Company. Список всегда передавался явно, поэтому
 * `ChoiceType` с теми же значениями (id) даёт ту же разметку и тот же submit.
 * Опция `class` оставлена для совместимости вызовов и принимает только ProjectDirection.
 *
 * @extends AbstractType<ProjectDirection|null>
 */
class ProjectDirectionPickerType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => ProjectDirection::class,
            'choice_label' => 'name',
            'choice_value' => static fn (?ProjectDirection $projectDirection): ?string => $projectDirection?->getId(),
            'choice_translation_domain' => false,
            'required' => false,
        ]);
        $resolver->setAllowedValues('class', [ProjectDirection::class]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'project_direction_picker';
    }
}
