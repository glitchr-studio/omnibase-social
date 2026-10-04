<?php

namespace Base\Social\Tests\Controller\Admin;

use Base\Field\FieldDescriptor;
use Base\Field\Type\AssociationType;
use Base\Field\Type\CollectionType;
use Base\Social\Controller\Admin\Crud\SocialPostCrudController;
use Base\Social\Controller\Admin\Crud\TemplateCrudController;
use Base\Social\Entity\Template;
use Base\Social\Enum\Fit;
use Base\Social\Enum\LogoPosition;
use Base\Social\Form\TargetType;
use Base\Social\Service\Accounts;
use Base\Social\Service\Publisher;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "new" and "edit" forms of the social screens, as their fields declare
 * them - no kernel here, the screens themselves are opened by the host
 * application's tests. What made a form answer 500:
 *
 * - a PHP enum behind a SelectField, which guesses its choices from an
 *   entity or one of omnibase's Doctrine enum types and found none (the
 *   template's logoPosition and fit);
 * - a collection of entities without allow_object (the post's targets);
 * - a related record through AssociationType, which embeds that record's
 *   own form - its fields guessed from the mapping, an enum among them as a
 *   text input (the post's template).
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

    public function testThePostTakesItsTargetsAsObjectsAndChoosesItsTemplateInAList(): void
    {
        foreach (self::formFields($this->posts()) as $page => $fields) {
            self::assertSame(CollectionType::class, $fields['targets']->getFormType(), $page);
            self::assertSame(TargetType::class, $fields['targets']->getFormTypeOption('entry_type'), $page);
            self::assertTrue($fields['targets']->getFormTypeOption('allow_object'), 'the targets are entities: the collection is told so ('.$page.')');

            self::assertNotSame([], self::enumsOf(Template::class));
            self::assertNotSame(AssociationType::class, $fields['template']->getFormType(), 'AssociationType embeds the template\'s form, whose enums it prints in a text input ('.$page.')');
            self::assertSame(EntityType::class, $fields['template']->getFormType(), $page);
            self::assertSame(Template::class, $fields['template']->getFormTypeOption('class'), $page);
            self::assertFalse($fields['template']->isRequired(), 'a post may name no template');

            self::assertArrayNotHasKey('state', $fields, 'the state is not written by hand');
        }
    }

    private function templates(): TemplateCrudController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $id) => '['.$id.']');
        $crud = (new \ReflectionClass(TemplateCrudController::class))->newInstanceWithoutConstructor();
        $crud->setSocialServices($this->accounts(), $translator);

        return $crud;
    }

    private function posts(): SocialPostCrudController
    {
        $crud = (new \ReflectionClass(SocialPostCrudController::class))->newInstanceWithoutConstructor();
        $crud->setSocialServices($this->createStub(MessageBusInterface::class), $this->createStub(Publisher::class), $this->accounts());

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

    /** @return list<string> the properties of an entity mapped to a PHP enum */
    private static function enumsOf(string $entity): array
    {
        return array_values(array_filter(
            array_map(fn (\ReflectionProperty $property) => $property->getName(), (new \ReflectionClass($entity))->getProperties()),
            fn (string $property) => null !== self::enumOf($entity, $property),
        ));
    }
}
