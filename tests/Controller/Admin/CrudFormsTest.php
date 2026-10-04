<?php

namespace Base\Social\Tests\Controller\Admin;

use Base\Field\FieldDescriptor;
use Base\Social\Controller\Admin\Crud\TemplateCrudController;
use Base\Social\Entity\Template;
use Base\Social\Enum\Fit;
use Base\Social\Enum\LogoPosition;
use Base\Social\Service\Accounts;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "new" and "edit" forms of the social screens, as their fields declare
 * them - no kernel here, the screens themselves are opened by the host
 * application's tests. What made a form answer 500: a PHP enum behind a
 * SelectField, which guesses its choices from an entity or one of omnibase's
 * Doctrine enum types and found none (the template's logoPosition and fit).
 */
final class CrudFormsTest extends TestCase
{
    public function testTheTemplateChoosesItsEnumsThroughEnumType(): void
    {
        foreach (self::formFields($this->templates()) as $page => $fields) {
            foreach (['logoPosition' => LogoPosition::class, 'fit' => Fit::class] as $property => $enum) {
                self::assertSame($enum, self::enumOf(Template::class, $property));
                self::assertSame(EnumType::class, $fields[$property]->getFormType(), $property.' on "'.$page.'"');
                self::assertSame($enum, $fields[$property]->getFormTypeOption('class'), $property.' on "'.$page.'"');
            }
        }
    }

    /** Built and rendered as the screen does it: the template's case selected, each case a choice with its own text. */
    public function testTheTemplateEnumFieldsRender(): void
    {
        $template = (new Template())->setName('Site')->setLogoPosition(LogoPosition::BOTTOM_LEFT)->setFit(Fit::CONTAIN);
        $fields = self::formFields($this->templates())[FieldDescriptor::PAGE_EDIT];

        $builder = Forms::createFormFactory()->createNamedBuilder('crud_form', FormType::class, $template, ['data_class' => Template::class]);
        foreach (['logoPosition', 'fit'] as $property) {
            $builder->add($property, $fields[$property]->getFormType(), $fields[$property]->getFormTypeOptions());
        }
        $view = $builder->getForm()->createView();

        self::assertSame('bottom_left', $view['logoPosition']->vars['value']);
        self::assertSame('contain', $view['fit']->vars['value']);
        self::assertSame(
            array_map(fn (LogoPosition $position) => '@social.admin.template.logo_position_'.$position->value, LogoPosition::cases()),
            array_values(array_map(fn ($choice) => $choice->label, $view['logoPosition']->vars['choices'])),
        );
        self::assertSame(
            ['@social.admin.template.fit_cover', '@social.admin.template.fit_contain'],
            array_values(array_map(fn ($choice) => $choice->label, $view['fit']->vars['choices'])),
        );
    }

    /** Every case has its text in every language the bundle speaks. */
    public function testEachEnumCaseIsTranslated(): void
    {
        foreach (glob(\dirname(__DIR__, 3).'/translations/social+intl-icu.*.yaml') as $file) {
            $yaml = (string) file_get_contents($file);
            foreach (LogoPosition::cases() as $position) {
                self::assertMatchesRegularExpression('/^    logo_position_'.$position->value.': "/m', $yaml, basename($file));
            }
            foreach (Fit::cases() as $fit) {
                self::assertMatchesRegularExpression('/^    fit_'.$fit->value.': "/m', $yaml, basename($file));
            }
        }
    }

    public function testTheTemplateDetailNamesTheCase(): void
    {
        $fields = self::formFields($this->templates())[FieldDescriptor::PAGE_EDIT];
        $format = $fields['fit']->getFormatValueCallable();

        self::assertSame('[@social.admin.template.fit_contain]', $format(Fit::CONTAIN, new Template()));
    }

    private function templates(): TemplateCrudController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $id) => '['.$id.']');
        $crud = (new \ReflectionClass(TemplateCrudController::class))->newInstanceWithoutConstructor();
        $crud->setSocialServices($this->accounts(), $translator);

        return $crud;
    }

    private function accounts(): Accounts
    {
        $accounts = $this->createStub(Accounts::class);
        $accounts->method('names')->willReturn(['instagram', 'youtube']);

        return $accounts;
    }

    /** @return array<string, array<string, FieldDescriptor>> the fields of the "new" and "edit" forms, by property */
    private static function formFields(object $crud): array
    {
        $pages = [];
        foreach ([FieldDescriptor::PAGE_NEW, FieldDescriptor::PAGE_EDIT] as $page) {
            $pages[$page] = [];
            foreach ($crud->configureFields($page) as $field) {
                $descriptor = $field->getAsDto();
                if ($descriptor->isDisplayedOn($page)) {
                    $pages[$page][(string) $descriptor->getProperty()] = $descriptor;
                }
            }
        }

        return $pages;
    }

    /** The PHP enum a property is mapped to, if any. */
    private static function enumOf(string $entity, string $property): ?string
    {
        foreach ((new \ReflectionProperty($entity, $property))->getAttributes(ORM\Column::class) as $column) {
            return $column->newInstance()->enumType;
        }

        return null;
    }
}
