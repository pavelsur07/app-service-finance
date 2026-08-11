<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Entity\CompanyRole;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class CompanyRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название шаблона',
                'required' => true,
                'empty_data' => '',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Введите название шаблона',
                    ]),
                    new Length([
                        'max' => 128,
                        'maxMessage' => 'Название шаблона не должно превышать {{ limit }} символов.',
                    ]),
                ],
            ])
            ->add('permissions', \Symfony\Component\Form\Extension\Core\Type\FormType::class, [
                'mapped' => false,
                'label' => 'Права доступа',
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $role = $event->getData();
            $permissions = [];
            if ($role instanceof CompanyRole) {
                $permissions = $role->getPermissions();
            }

            $permissionsForm = $event->getForm()->get('permissions');
            foreach (Module::cases() as $module) {
                $permissionsForm->add($module->value, ChoiceType::class, [
                    'label' => $module->label(),
                    'choices' => [
                        'Нет' => AccessLevel::NONE->value,
                        'Чтение' => AccessLevel::READ->value,
                        'Запись' => AccessLevel::WRITE->value,
                    ],
                    'data' => $permissions[$module->value] ?? AccessLevel::NONE->value,
                    'expanded' => true,
                    'multiple' => false,
                    'mapped' => false,
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompanyRole::class,
            'empty_data' => static function (FormInterface $form): CompanyRole {
                return new CompanyRole(
                    id: Uuid::uuid4()->toString(),
                    name: '',
                    permissions: [],
                );
            },
        ]);
    }
}
