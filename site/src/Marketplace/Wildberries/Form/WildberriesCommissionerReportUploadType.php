<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

final class WildberriesCommissionerReportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'required' => true,
                'label' => 'XLSX файл отчёта комиссионера',
                'constraints' => [
                    new File([
                        'maxSize' => '20M',
                        'mimeTypes' => [
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/octet-stream',
                        ],
                        'mimeTypesMessage' => 'Загрузи файл XLSX (не XLS, не CSV).',
                    ]),
                ],
            ])
            ->add('periodStart', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'Начало периода',
            ])
            ->add('periodEnd', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'Конец периода',
            ]);
    }
}
