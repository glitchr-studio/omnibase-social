<?php

namespace Base\Social\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\BooleanField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Social\Entity\SocialMedia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account's posts as the last sync read them: what the wall shows.
 * Nothing to write here - the network is the source -, only a post to keep
 * off the wall, or to put back.
 */
class SocialMediaCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SocialMedia::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-table-cells';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('provider')->add('hidden');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield ImageField::new('thumbnail', '@social.admin.media.thumbnail')->hideOnForm();
        yield TextField::new('provider', '@social.admin.media.provider')->hideOnForm();
        yield TextField::new('kind', '@social.admin.media.kind')->hideOnForm();
        yield TextareaField::new('caption', '@social.admin.media.caption')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('publishedAt', '@social.admin.media.published_at')->hideOnForm();
        yield IntegerField::new('likes', '@social.admin.media.likes')->hideOnForm();
        yield IntegerField::new('comments', '@social.admin.media.comments')->hideOnForm();
        yield TextField::new('permalink', '@social.admin.media.permalink')->onlyOnDetail();
        yield BooleanField::new('hidden', '@social.admin.media.hidden');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions)->disable(Action::NEW);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            $actions
                ->add($page, Action::new('visibility', '@social.admin.media.action.toggle', 'fa-solid fa-eye-slash')->linkToCrudAction('visibility'))
                ->add($page, Action::new('open', '@social.admin.media.action.open', 'fa-solid fa-arrow-up-right-from-square')
                    ->linkToUrl(fn (SocialMedia $media) => (string) $media->getPermalink())->targetBlank());
        }

        return $actions;
    }

    #[AdminAction('/{entityId}/visibility')]
    public function visibility(string $entityId): Response
    {
        /** @var SocialMedia $media */
        $media = $this->findEntity($entityId);
        $media->setHidden(!$media->isHidden());
        $this->entityManager->flush();
        $this->addFlash('success', $media->isHidden() ? '@social.admin.media.flash.hidden' : '@social.admin.media.flash.shown');

        return $this->redirectToIndex();
    }
}
