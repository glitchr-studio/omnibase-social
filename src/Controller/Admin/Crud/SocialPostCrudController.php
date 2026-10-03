<?php

namespace Base\Social\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\CollectionField;
use Base\Field\DateTimeField;
use Base\Field\DateTimePickerField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\NumberField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Field\VideoField;
use Base\Social\Entity\SocialPost;
use Base\Social\Entity\SocialPostTarget;
use Base\Social\Enum\TargetState;
use Base\Social\Form\TargetType;
use Base\Social\Message\RenderPostMessage;
use Base\Social\Service\Accounts;
use Base\Social\Service\Publisher;
use Omnipost\Exception\OmnipostException;
use Omnipost\Model\PostKind;
use Omnipost\PublisherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Writing a post for the networks: the film as shot (or a picture), the
 * caption and its hashtags, a title for the networks that want one, the
 * template, the hour; below, each network it goes to and what it says
 * there. Then: render (the reels made, to look at in the preview), publish
 * (checked first against what each network takes), retry a network that
 * failed.
 */
class SocialPostCrudController extends AbstractCrudController
{
    private MessageBusInterface $bus;
    private Publisher $publisher;
    private Accounts $accounts;

    #[Required]
    public function setSocialServices(MessageBusInterface $bus, Publisher $publisher, Accounts $accounts): void
    {
        $this->bus = $bus;
        $this->publisher = $publisher;
        $this->accounts = $accounts;
    }

    public static function getEntityFqcn(): string
    {
        return SocialPost::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-film';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('state')->add('template');
    }

    public function configureFields(string $pageName): iterable
    {
        $kinds = [];
        foreach ([PostKind::REEL, PostKind::IMAGE] as $kind) {
            $kinds['@social.admin.post.kind_'.$kind->value] = $kind->value;
        }

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', '@social.admin.post.title')->setColumns(6)->setHelp('@social.admin.post.title_help');
        yield SelectField::new('kind', '@social.admin.post.kind')->setChoices($kinds)->setColumns(3);
        yield SelectField::new('state', '@social.admin.post.state')->setColumns(3)->hideOnForm();
        yield VideoField::new('video', '@social.admin.post.video')->setColumns(6)->hideOnIndex()->setHelp('@social.admin.post.video_help');
        yield ImageField::new('image', '@social.admin.post.image')->setColumns(6)->hideOnIndex()->setRequired(false);
        yield TextareaField::new('caption', '@social.admin.post.caption')->hideOnIndex();
        yield SelectField::new('tags', '@social.admin.post.tags')->allowMultipleChoices()->allowTags([',', ' ', ';'])->setRequired(false)->setColumns(12)->hideOnIndex()->setHelp('@social.admin.post.tags_help');
        yield AssociationField::new('template', '@social.admin.post.template')->setRequired(false)->setColumns(4)->setHelp('@social.admin.post.template_help');
        yield BooleanField::new('useTemplate', '@social.admin.post.use_template')->setColumns(2)->hideOnIndex();
        yield NumberField::new('coverSecond', '@social.admin.post.cover_second')->setColumns(2)->hideOnIndex()->setHelp('@social.admin.post.cover_second_help');
        yield DateTimePickerField::new('scheduledAt', '@social.admin.post.scheduled_at')->setColumns(4)->setRequired(false)->setHelp('@social.admin.post.scheduled_at_help');
        yield TextField::new('link', '@social.admin.post.link')->setColumns(12)->hideOnIndex()->setRequired(false);
        yield CollectionField::new('targets', '@social.admin.post.targets')->setEntryType(TargetType::class)->allowAdd()->allowDelete()->hideOnIndex()
            ->setFormTypeOptions(['by_reference' => false]);
        yield DateTimeField::new('createdAt', '@social.admin.post.created_at')->onlyOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL, Actions::PAGE_EDIT] as $page) {
            $actions
                ->add($page, Action::new('preview', '@social.admin.post.action.preview', 'fa-solid fa-eye')->linkToCrudAction('preview'))
                ->add($page, Action::new('render', '@social.admin.post.action.render', 'fa-solid fa-wand-magic-sparkles')->linkToCrudAction('renderReel'))
                ->add($page, Action::new('publish', '@social.admin.post.action.publish', 'fa-solid fa-paper-plane')->linkToCrudAction('publish')
                    ->askConfirmation('@social.admin.post.action.publish_confirm'));
        }

        return $actions;
    }

    /** A post written gets the site's networks, each enabled: the author takes away what it does not go to. */
    public function createEntity(string $entityFqcn): object
    {
        $post = new SocialPost();
        foreach ($this->accounts->names() as $name) {
            $post->addTarget(new SocialPostTarget($name));
        }

        return $post;
    }

    /** The reels made again (Messenger: the worker renders), to be looked at before they leave. */
    #[AdminAction('/{entityId}/render')]
    public function renderReel(string $entityId): Response
    {
        /** @var SocialPost $post */
        $post = $this->findEntity($entityId);
        if (!$post->getEnabledTargets()) {
            $this->addFlash('danger', '@social.admin.post.flash.no_target');

            return $this->redirectToPreview($entityId);
        }
        $this->bus->dispatch(new RenderPostMessage((int) $post->getId()));
        $this->addFlash('success', '@social.admin.post.flash.rendering');

        return $this->redirectToPreview($entityId);
    }

    /**
     * Sent to every enabled network - after each was checked against what
     * the network takes: one refusal and nothing leaves, the reasons shown.
     * Rendered first when it is not yet.
     */
    #[AdminAction('/{entityId}/publish')]
    public function publish(string $entityId): Response
    {
        /** @var SocialPost $post */
        $post = $this->findEntity($entityId);
        $targets = $post->getEnabledTargets();
        if (!$targets) {
            $this->addFlash('danger', '@social.admin.post.flash.no_target');

            return $this->redirectToPreview($entityId);
        }

        $refusals = [];
        foreach ($targets as $target) {
            foreach ($this->publisher->validate($target) as $violation) {
                $refusals[] = $target->getPlatformLabel().' · '.$violation;
            }
        }
        if ($refusals) {
            // The admin prints flashes as HTML: the reasons are escaped here, one a line.
            $this->addFlash('danger', implode('<br>', array_map('htmlspecialchars', $refusals)));

            return $this->redirectToPreview($entityId);
        }

        $rendered = array_filter($targets, fn (SocialPostTarget $target) => TargetState::RENDERED === $target->getState() && $target->hasRendering());
        if (\count($rendered) === \count($targets)) {
            $this->publisher->dispatchPublish($post);
        } else {
            $this->bus->dispatch(new RenderPostMessage((int) $post->getId(), true));
        }
        $this->addFlash('success', $post->getScheduledAt() && $post->getScheduledAt() > new \DateTime() ? '@social.admin.post.flash.scheduled' : '@social.admin.post.flash.publishing');

        return $this->redirectToPreview($entityId);
    }

    /** One network sent again - its film rendered again first if it is gone. */
    #[AdminAction('/{entityId}/retry/{target}', requirements: ['target' => '\d+'])]
    public function retry(string $entityId, int $target): Response
    {
        /** @var SocialPost $post */
        $post = $this->findEntity($entityId);
        $found = null;
        foreach ($post->getTargets() as $candidate) {
            if ($candidate->getId() === $target) {
                $found = $candidate;
            }
        }
        if (null === $found) {
            throw $this->createNotFoundException();
        }
        $this->publisher->retry($found);
        $this->addFlash('success', '@social.admin.post.flash.retried');

        return $this->redirectToPreview($entityId);
    }

    /** Each network: its reel, its cover, what the network would refuse, where it is. */
    #[AdminAction('/{entityId}/preview', methods: ['GET'])]
    public function preview(string $entityId): Response
    {
        /** @var SocialPost $post */
        $post = $this->findEntity($entityId);
        $rows = [];
        foreach ($post->getTargets() as $target) {
            $capabilities = null;
            try {
                $provider = $this->accounts->provider((string) $target->getPlatform());
                $capabilities = $provider instanceof PublisherInterface ? $provider->capabilities() : null;
            } catch (OmnipostException) {
            }
            $rows[] = [
                'target' => $target,
                'violations' => $target->isEnabled() ? $this->publisher->validate($target) : [],
                'capabilities' => $capabilities,
                'connected' => $this->accounts->has((string) $target->getPlatform()) && $this->accounts->connected((string) $target->getPlatform()),
            ];
        }

        return $this->renderCrud('@Social/admin/post/preview.html.twig', [
            'post' => $post,
            'rows' => $rows,
        ]);
    }

    /** The rendered film or its cover, for the preview: the back office's own way to it, open whatever the state. */
    #[AdminAction('/{entityId}/reel/{target}/{what}', methods: ['GET'], requirements: ['target' => '\d+', 'what' => 'video|cover'])]
    public function reel(string $entityId, int $target, string $what): Response
    {
        /** @var SocialPost $post */
        $post = $this->findEntity($entityId);
        foreach ($post->getTargets() as $candidate) {
            if ($candidate->getId() !== $target) {
                continue;
            }
            $path = 'cover' === $what ? $candidate->getCoverPath() : $candidate->getRenderedPath();
            if ($path && is_file($path)) {
                return new BinaryFileResponse($path, 200, ['Cache-Control' => 'private, no-cache'], false, ResponseHeaderBag::DISPOSITION_INLINE);
            }
        }

        throw $this->createNotFoundException();
    }

    private function redirectToPreview(string $entityId): Response
    {
        return $this->redirect($this->adminUrlGenerator->setController(static::class)->setAction('preview')->setEntityId($entityId)->generateUrl());
    }
}
