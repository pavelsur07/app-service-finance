<?php

declare(strict_types=1);

namespace App\MoySklad\Form;

use App\MoySklad\Application\DTO\CatalogTargetDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

/** @extends AbstractType<array<string, mixed>> */
final class MoySkladListingMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('operation', HiddenType::class, ['constraints' => [new Choice(choices: [$options['operation']])]]);
        $builder->add('expectedRevision', HiddenType::class, ['constraints' => [new NotBlank(), new Regex('/^(0|[1-9][0-9]*)$/D'), new Range(min: 0, max: 2147483646)]]);
        $builder->add('requestId', HiddenType::class, ['constraints' => [new NotBlank(), new Regex('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD')]]);
        if ('set' === $options['operation']) {
            /** @var array<string,CatalogTargetDTO> $targets */
            $targets = $options['targets'];
            $builder->add('target', ChoiceType::class, [
                'label' => 'Позиция МойСклад', 'choices' => array_keys($targets), 'expanded' => true, 'multiple' => false,
                'choice_label' => static function (string $value) use ($targets): string {
                    $target = $targets[$value];
                    $characteristics = implode(', ', array_map(static fn (array $item): string => $item['name'].': '.$item['value'], $target->characteristics));

                    return $target->name.' · '.$target->getTypeLabel().' · '.($target->article ?? $target->code ?? '').('' === $characteristics ? '' : ' · '.$characteristics);
                },
                'choice_attr' => static fn (string $value): array => $targets[$value]->selectable ? [] : ['disabled' => 'disabled'],
                'constraints' => [new NotBlank(message: 'Выберите позицию из списка.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'csrf_protection' => false, 'allow_extra_fields' => true, 'operation' => 'set', 'targets' => []]);
        $resolver->setAllowedValues('operation', ['set', 'clear']);
        $resolver->setAllowedTypes('targets', 'array');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
