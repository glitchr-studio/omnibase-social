<?php

namespace Base\Social\Entity;

use Base\Database\Attribute\Uploader;
use Base\Social\Enum\PostState;
use Base\Social\Enum\TargetState;
use Base\Social\Repository\SocialPostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Omnipost\Model\Post;
use Omnipost\Model\PostKind;
use Omnipost\Model\Variant;
use Omnipost\Platform;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What goes out: a film (or a picture), a caption, its hashtags, a title for
 * the networks that want one - and, per network, a target that may say it
 * otherwise. The film is rendered once per target from the template (the
 * target's, the post's, the network's, the site's), then handed over.
 */
#[ORM\Entity(repositoryClass: SocialPostRepository::class)]
#[ORM\Table(name: 'social_post')]
#[ORM\Index(columns: ['createdAt'], name: 'social_post_created_idx')]
class SocialPost
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-film'];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /** The source film, as it was shot. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '1GB', mime_types: ['video/*'])]
    protected $video = null;

    /** A picture, for a post that is one. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['image/*'])]
    protected $image = null;

    /** An Omnipost\Model\PostKind value. */
    #[ORM\Column(length: 16)]
    protected string $kind = PostKind::REEL->value;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $caption = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $title = null;

    /** Hashtags, without the '#'. */
    #[ORM\Column(type: 'json')]
    protected array $tags = [];

    /** Null: the network's template, else the site's. */
    #[ORM\ManyToOne(targetEntity: Template::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Template $template = null;

    /** False: the source goes out as it is. */
    #[ORM\Column(type: 'boolean')]
    protected bool $useTemplate = true;

    /** The second of the rendered film its cover is taken at. */
    #[ORM\Column(type: 'float')]
    #[Assert\PositiveOrZero]
    protected float $coverSecond = 0.0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $scheduledAt = null;

    /** The site's page the post speaks of. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    protected ?string $link = null;

    #[ORM\Column(type: 'datetime')]
    protected ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'string', length: 16, enumType: PostState::class)]
    protected PostState $state = PostState::DRAFT;

    #[ORM\OneToMany(targetEntity: SocialPostTarget::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    protected Collection $targets;

    public function __construct()
    {
        $this->targets = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function __toString(): string
    {
        return $this->title ?: mb_strimwidth((string) $this->caption, 0, 60, '…') ?: '#'.$this->id;
    }

    public function getId(): ?int { return $this->id; }

    public function getVideo(): ?string { return Uploader::getPublic($this, 'video'); }
    public function getVideoFile(): ?File { return Uploader::get($this, 'video'); }
    public function setVideo($video): self { $this->video = $video; return $this; }

    public function getImage(): ?string { return Uploader::getPublic($this, 'image'); }
    public function getImageFile(): ?File { return Uploader::get($this, 'image'); }
    public function setImage($image): self { $this->image = $image; return $this; }

    public function getKind(): string { return $this->kind; }
    public function getPostKind(): PostKind { return PostKind::tryFrom($this->kind) ?? PostKind::REEL; }
    public function setKind(PostKind|string|null $kind): self
    {
        $this->kind = $kind instanceof PostKind ? $kind->value : ($kind ?: PostKind::REEL->value);

        return $this;
    }

    /** A picture goes out as it is: only a film is rendered. */
    public function isImage(): bool { return PostKind::IMAGE === $this->getPostKind(); }

    public function getCaption(): ?string { return $this->caption; }
    public function setCaption(?string $caption): self { $this->caption = $caption ? trim($caption) : null; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title ? trim($title) : null; return $this; }

    /** @return list<string> */
    public function getTags(): array { return $this->tags; }
    public function setTags(?array $tags): self { $this->tags = self::cleanTags($tags ?? []); return $this; }

    public function getTemplate(): ?Template { return $this->template; }
    public function setTemplate(?Template $template): self { $this->template = $template; return $this; }

    public function isUseTemplate(): bool { return $this->useTemplate; }
    public function getUseTemplate(): bool { return $this->useTemplate; }
    public function setUseTemplate(bool $use): self { $this->useTemplate = $use; return $this; }

    public function getCoverSecond(): float { return $this->coverSecond; }
    public function setCoverSecond(?float $second): self { $this->coverSecond = max(0.0, (float) $second); return $this; }

    public function getScheduledAt(): ?\DateTimeInterface { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeInterface $at): self { $this->scheduledAt = $at; return $this; }

    public function getLink(): ?string { return $this->link; }
    public function setLink(?string $link): self { $this->link = $link ? trim($link) : null; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }

    public function getState(): PostState { return $this->state; }
    public function setState(PostState $state): self { $this->state = $state; return $this; }

    /** @return Collection<int, SocialPostTarget> */
    public function getTargets(): Collection { return $this->targets; }

    public function addTarget(SocialPostTarget $target): self
    {
        if (!$this->targets->contains($target)) {
            $this->targets->add($target);
            $target->setPost($this);
        }

        return $this;
    }

    public function removeTarget(SocialPostTarget $target): self
    {
        $this->targets->removeElement($target);

        return $this;
    }

    /** @return list<SocialPostTarget> the networks it goes to */
    public function getEnabledTargets(): array
    {
        return array_values($this->targets->filter(fn (SocialPostTarget $target) => $target->isEnabled())->toArray());
    }

    public function getTarget(Platform|string $platform): ?SocialPostTarget
    {
        $value = $platform instanceof Platform ? $platform->value : $platform;
        foreach ($this->targets as $target) {
            if ($target->getPlatform() === $value) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The post's state, read from its networks': one still on its way keeps
     * it PUBLISHING, all online make it DONE, one refused (and none on its
     * way) FAILED, all rendered READY.
     */
    public function refreshState(): self
    {
        $states = array_map(fn (SocialPostTarget $target) => $target->getState(), $this->getEnabledTargets());
        $all = fn (TargetState $state) => $states && !array_filter($states, fn (TargetState $s) => $s !== $state);
        $any = fn (TargetState ...$among) => (bool) array_filter($states, fn (TargetState $s) => \in_array($s, $among, true));

        $this->state = match (true) {
            !$states => PostState::DRAFT,
            $any(TargetState::SENT, TargetState::PROCESSING) => PostState::PUBLISHING,
            $all(TargetState::PUBLISHED) => PostState::DONE,
            $any(TargetState::FAILED) => PostState::FAILED,
            $any(TargetState::PUBLISHED) => PostState::PUBLISHING,
            $all(TargetState::RENDERED) => PostState::READY,
            default => PostState::DRAFT,
        };

        return $this;
    }

    /**
     * The canonical Omnipost post and, per enabled network, its variant: the
     * target's own caption, title and hashtags where it has them, its own
     * rendering of the film always. The networks fetch the media themselves,
     * so $urls gives each target's public address - Service\MediaUrls::for(),
     * signed and short-lived - `fn (SocialPostTarget $target, bool $cover): string`;
     * without it the local path stands in (enough to validate, not to publish).
     *
     * The hour (scheduledAt) is not passed on: the site keeps it itself, by
     * holding the message back until then.
     */
    public function toPost(?callable $urls = null): Post
    {
        $kind = $this->getPostKind();
        $media = [];
        $coverUrl = null;
        $variants = [];
        foreach ($this->getEnabledTargets() as $target) {
            $platform = Platform::tryFrom($target->getPlatform());
            if (null === $platform) {
                continue;
            }
            $own = $target->toMedia($kind, $urls);
            if ($own && !$media) {
                // The canonical media: the first network's rendering.
                $media = [$own];
                $coverUrl = $own->coverUrl;
            }
            $variants[$platform->value] = new Variant(
                $platform,
                $target->getCaption(),
                $target->getTitle(),
                $target->getTags(),
                $own ? [$own] : null,
                $own?->coverUrl,
                $target->getOptions(),
            );
        }

        return new Post($kind, $media, (string) $this->caption, $this->title, $this->tags, $coverUrl, null, $variants, [], $this->link);
    }

    /**
     * Hashtags as they are kept: no '#', no blank, no double.
     *
     * @return list<string>
     */
    public static function cleanTags(array $tags): array
    {
        $tags = array_map(static fn ($tag) => ltrim(trim((string) $tag), '#'), $tags);

        return array_values(array_unique(array_filter($tags, static fn (string $tag) => '' !== $tag)));
    }
}
