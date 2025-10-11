<?php

namespace App\Form;

use App\Entity\Counterparty;
use App\Entity\Document;
use App\Enum\DocumentType as DocumentTypeEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateTimeType::class, [
                'widget' => 'single_text',
                'label' => 'Дата',
            ])
            ->add('number', TextType::class, [
                'required' => false,
                'label' => 'Номер',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Тип',
                'choices' => DocumentTypeEnum::choices(),
                'choice_value' => static fn (?DocumentTypeEnum $type) => $type?->value,
            ])
            ->add('counterparty', EntityType::class, [
                'class' => Counterparty::class,
                'choices' => $options['counterparties'],
                'choice_label' => 'name',
                'placeholder' => '—',
                'required' => false,
                'label' => 'Контрагент',
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
        ]);
    }
}
