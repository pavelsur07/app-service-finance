<?php

declare(strict_types=1);

namespace App\Loan\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;

class LoanScheduleUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'required' => true,
            'label' => 'CSV файл с графиком',
        ]);
    }
}
