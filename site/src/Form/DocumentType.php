<?php

namespace App\Form;

use App\Entity\Counterparty;
use App\Entity\Document;
use App\Entity\ProjectDirection;
use App\Enum\DocumentType as DocumentTypeEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'Дата',
            ])
            ->add('number', TextType::class, [
                'required' => false,
                'label' => 'Номер',
            ])
            ->add('type', ChoiceType::class, [
                'choices' => DocumentTypeEnum::cases(),
                'choice_label' => fn (DocumentTypeEnum $type) => $type->label(),
                'choice_value' => 'value',
                'label' => 'Тип документа',
            ])
            ->add('counterparty', EntityType::class, [
                'class' => Counterparty::class,
                'choices' => $options['counterparties'],
                'choice_label' => 'name',
                'placeholder' => '—',
                'required' => false,
                'label' => 'Контрагент',
                'attr' => [
                    'data-document-counterparty' => 'true',
                ],
            ])
            ->add('projectDirection', EntityType::class, [
                'class' => ProjectDirection::class,
                'choices' => $options['project_directions'],
                'choice_label' => function (ProjectDirection $item) {
                    return str_repeat('—', $item->getLevel() - 1).' '.$item->getName();
                },
                'choice_attr' => fn (ProjectDirection $item) => !$item->getChildren()->isEmpty() ? ['disabled' => 'disabled'] : [],
                'placeholder' => '—',
                'required' => false,
                'label' => 'Проект',
                'attr' => [
                    'data-document-project-direction' => 'true',
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'Описание',
            ])
            ->add('operations', CollectionType::class, [
                'entry_type' => DocumentOperationType::class,
                'entry_options' => [
                    'categories' => $options['categories'],
                    'counterparties' => $options['counterparties'],
                    'project_directions' => $options['project_directions'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'Операции',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'categories' => [],
            'counterparties' => [],
            'project_directions' => [],
        ]);
    }
}
