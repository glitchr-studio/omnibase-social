<?php

namespace Base\Social\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\BooleanField;
use Base\Field\ColorPickerField;
use Base\Field\FileField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\NumberField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Field\VideoField;
use Base\Social\Entity\Template;
use Base\Social\Service\Accounts;
use Doctrine\ORM\EntityManagerInterface;
use Omnipost\Platform;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The site's identity on a reel: the logo and its corner, the band and its
 * name, the colours, the font, the clips before and after, how a film that
 * is not upright is fitted. One is the site's (default); another may be a
 * network's alone.
 */
class TemplateCrudController extends AbstractCrudController
{
    private Accounts $accounts;

    #[Required]
    public function setSocialServices(Accounts $accounts): void
    {
        $this->accounts = $accounts;
    }

    public static function getEntityFqcn(): string
    {
        return Template::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-clapperboard';
    }

    public function configureFields(string $pageName): iterable
    {
        $platforms = [];
        foreach ($this->accounts->names() as $name) {
            $platforms[Platform::tryFrom($name)?->label() ?? $name] = $name;
        }

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@social.admin.template.name')->setColumns(6);
        yield BooleanField::new('default', '@social.admin.template.default')->setColumns(2)->setHelp('@social.admin.template.default_help');
        yield SelectField::new('platform', '@social.admin.template.platform')->setChoices($platforms)->setRequired(false)->setColumns(4)->setHelp('@social.admin.template.platform_help');
        yield ImageField::new('logo', '@social.admin.template.logo')->setColumns(4)->setRequired(false);
        yield SelectField::new('logoPosition', '@social.admin.template.logo_position')->setColumns(4)->hideOnIndex();
        yield NumberField::new('logoScale', '@social.admin.template.logo_scale')->setColumns(4)->hideOnIndex()->setHelp('@social.admin.template.logo_scale_help');
        yield BooleanField::new('band', '@social.admin.template.band')->setColumns(2);
        yield TextField::new('bandText', '@social.admin.template.band_text')->setColumns(4)->setRequired(false);
        yield ColorPickerField::new('bandColor', '@social.admin.template.band_color')->setColumns(2)->hideOnIndex();
        yield ColorPickerField::new('textColor', '@social.admin.template.text_color')->setColumns(2)->hideOnIndex();
        yield FileField::new('font', '@social.admin.template.font')->setColumns(2)->hideOnIndex()->setRequired(false)->setHelp('@social.admin.template.font_help');
        yield SelectField::new('fit', '@social.admin.template.fit')->setColumns(4)->hideOnIndex()->setHelp('@social.admin.template.fit_help');
        yield ColorPickerField::new('background', '@social.admin.template.background')->setColumns(4)->hideOnIndex();
        yield NumberField::new('fadeSeconds', '@social.admin.template.fade')->setColumns(4)->hideOnIndex();
        yield VideoField::new('intro', '@social.admin.template.intro')->setColumns(6)->hideOnIndex()->setRequired(false);
        yield VideoField::new('outro', '@social.admin.template.outro')->setColumns(6)->hideOnIndex()->setRequired(false);
    }

    /** One site's template: the one saved as such takes the place of the others. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->onlyDefault($entityManager, $entity);
        parent::persistEntity($entityManager, $entity);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->onlyDefault($entityManager, $entity);
        parent::updateEntity($entityManager, $entity);
    }

    private function onlyDefault(EntityManagerInterface $entityManager, object $entity): void
    {
        if (!$entity instanceof Template || !$entity->isDefault()) {
            return;
        }
        foreach ($entityManager->getRepository(Template::class)->findBy(['default' => true, 'platform' => $entity->getPlatform()]) as $other) {
            if ($other !== $entity) {
                $other->setDefault(false);
            }
        }
    }
}
