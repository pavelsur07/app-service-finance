<?php

declare(strict_types=1);

namespace App\Tests\Unit\Company\Form;

use App\Company\Entity\CompanyRole;
use App\Company\Form\CompanyRoleType;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class CompanyRoleTypeTest extends TestCase
{
    private function createFormFactory(): FormFactoryInterface
    {
        $validator = Validation::createValidator();

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
    }

    public function testSubmitCreatesRoleWithSelectedPermissions(): void
    {
        $factory = $this->createFormFactory();
        $role = new CompanyRole(
            '99999999-9999-4999-9999-999999999999',
            'Draft',
            [],
        );

        $form = $factory->create(CompanyRoleType::class, $role);
        $form->submit([
            'name' => 'Бухгалтер',
            'permissions' => [
                Module::FINANCE->value => AccessLevel::WRITE->value,
                Module::MARKETPLACE->value => AccessLevel::READ->value,
                Module::DEALS->value => AccessLevel::NONE->value,
                Module::CATALOG->value => AccessLevel::READ->value,
                Module::ADMIN->value => AccessLevel::NONE->value,
            ],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertSame('Бухгалтер', $role->getName());

        $permissions = [];
        foreach (Module::cases() as $module) {
            $permissions[$module->value] = (string) $form->get('permissions')->get($module->value)->getData();
        }
        $role->setPermissions($permissions);

        self::assertSame(AccessLevel::WRITE->value, $role->getPermissions()[Module::FINANCE->value]);
        self::assertSame(AccessLevel::READ->value, $role->getPermissions()[Module::MARKETPLACE->value]);
        self::assertSame(AccessLevel::NONE->value, $role->getPermissions()[Module::DEALS->value]);
        self::assertSame(AccessLevel::READ->value, $role->getPermissions()[Module::CATALOG->value]);
        self::assertSame(AccessLevel::NONE->value, $role->getPermissions()[Module::ADMIN->value]);
    }

    public function testPreSetDataFillsExistingPermissions(): void
    {
        $factory = $this->createFormFactory();
        $role = new CompanyRole(
            '99999999-9999-4999-9999-999999999999',
            'Existing',
            [Module::FINANCE->value => AccessLevel::READ->value],
        );

        $form = $factory->create(CompanyRoleType::class, $role);
        $financeField = $form->get('permissions')->get(Module::FINANCE->value);

        self::assertSame(AccessLevel::READ->value, $financeField->getData());
    }
}
