<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Repository\CompanyRoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class CompanyInviteOperatorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email',
            'required' => true,
            'constraints' => [
                new NotBlank([
                    'message' => 'Введите email',
                ]),
            ],
        ]);

        /** @var Company|null $company */
        $company = $options['company'];
        if ($company instanceof Company) {
            $builder->add('accessRole', EntityType::class, [
                'class' => CompanyRole::class,
                'query_builder' => static fn (CompanyRoleRepository $repository) => $repository->createAssignableForCompanyQueryBuilder($company),
                'choice_label' => static fn (CompanyRole $role): string => sprintf(
                    '%s (%s)',
                    $role->getName(),
                    null === $role->getCompany() ? 'системный' : 'наш',
                ),
                'data' => $options['full_access_role'],
                'placeholder' => 'Полный доступ',
                'required' => false,
                'label' => 'Шаблон доступа',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'company' => null,
            'full_access_role' => null,
        ]);
        $resolver->setAllowedTypes('company', ['null', Company::class]);
        $resolver->setAllowedTypes('full_access_role', ['null', CompanyRole::class]);
    }
}
