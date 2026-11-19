<?php

declare(strict_types=1);

namespace App\Company\Form;

use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array{file: UploadedFile|null}> */
final class ProjectDirectionImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'Файл JSON',
            'attr' => ['accept' => '.json,application/json'],
            'help' => 'До 8 МиБ.',
            'constraints' => [
                new Assert\NotNull(message: 'Выберите файл JSON.'),
                new Assert\File(maxSize: ProjectDirectionExchange::MAX_FILE_BYTES, maxSizeMessage: ProjectDirectionExchange::FILE_SIZE_MESSAGE),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'project_direction_import']);
    }
}
