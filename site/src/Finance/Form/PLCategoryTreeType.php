<?php

declare(strict_types=1);

namespace App\Finance\Form;

use App\Finance\Application\Service\PLCategoryTreeBuilder;
use App\Finance\Entity\PLCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Выбор статьи ОПиУ: обычный select (`EntityType` по переданным `choices`; он же
 * работает без JS и ограничивает значения при submit) + дерево для виджета
 * «Категория ОПиУ» (`form/pl_category_tree_theme.html.twig`).
 *
 * Дерево кладётся в `vars.tree`, либо, если задан `tree_source`, виджет читает
 * его со страницы по CSS-селектору: так коллекция операций отдаёт одно дерево
 * на все строки, а не копию в каждой (см. `DocumentType::buildView`).
 *
 * @extends AbstractType<PLCategory|null>
 */
final class PLCategoryTreeType extends AbstractType
{
    public function __construct(private readonly PLCategoryTreeBuilder $treeBuilder)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => PLCategory::class,
            'choice_label' => static fn (PLCategory $c): string => str_repeat("\u{a0}\u{a0}", $c->getLevel() - 1).$c->getName(),
            'choice_translation_domain' => false,
            'tree_source' => null,
        ]);
        $resolver->setAllowedTypes('tree_source', ['null', 'string']);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['tree_source'] = $options['tree_source'];
        $view->vars['tree'] = null;
        if (null === $options['tree_source']) {
            /** @var iterable<PLCategory> $choices */
            $choices = $options['choices'] ?? [];
            $view->vars['tree'] = $this->treeBuilder->build($choices);
        }
    }

    public function getParent(): string
    {
        return EntityType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'pl_category_tree';
    }
}
