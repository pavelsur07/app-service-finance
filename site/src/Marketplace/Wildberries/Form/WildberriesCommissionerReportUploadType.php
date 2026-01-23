<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;

final class WildberriesCommissionerReportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'required' => true,
                'label' => 'XLSX файл отчёта комиссионера',
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
