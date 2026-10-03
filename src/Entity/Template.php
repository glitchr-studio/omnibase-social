<?php

namespace Base\Social\Entity;

use Base\Database\Attribute\Uploader;
use Base\Social\Enum\Fit;
use Base\Social\Enum\LogoPosition;
use Base\Social\Repository\TemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnipost\Platform;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The site's identity on a reel: its logo in a corner, a band across the
 * lower third with a name on it, its colours, its font, a clip before and
 * a clip after. One template is the site's (default); another may be kept
 * for one network alone (platform), and a post or one of its networks may
 * name its own.
 */
#[ORM\Entity(repositoryClass: TemplateRepository::class)]
#[ORM\Table(name: 'social_template')]
class Template
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-clapperboard'];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    protected ?string $name = null;

    /** The site's own: what a post gets when nothing else is said. */
    #[ORM\Column(name: 'is_default', type: 'boolean')]
    protected bool $default = false;

    /** An Omnipost\Platform value: the template of that network alone; null: any. */
    #[ORM\Column(length: 32, nullable: true)]
    protected ?string $platform = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['image/*'])]
    protected $logo = null;

    #[ORM\Column(type: 'string', length: 16, enumType: LogoPosition::class)]
    protected LogoPosition $logoPosition = LogoPosition::TOP_RIGHT;

    /** The logo's width, as a part of the frame's (0.1 to 0.5). */
    #[ORM\Column(type: 'float')]
    #[Assert\Range(min: 0.1, max: 0.5)]
    protected float $logoScale = 0.2;

    /** The band across the lower third. */
    #[ORM\Column(type: 'boolean')]
    protected bool $band = false;

    /** What the band says: the artist's name, the shop's. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    protected ?string $bandText = null;

    #[ORM\Column(length: 9)]
    #[Assert\Regex('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/')]
    protected string $bandColor = '#000000';

    #[ORM\Column(length: 9)]
    #[Assert\Regex('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/')]
    protected string $textColor = '#ffffff';

    /** A TTF or OTF; none: the renderer's own (DejaVu Sans). */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['font/*', 'application/x-font-ttf', 'application/octet-stream'])]
    protected $font = null;

    /** Behind a source that is not 9:16 and is shown whole (Fit::CONTAIN). */
    #[ORM\Column(length: 9)]
    #[Assert\Regex('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/')]
    protected string $background = '#000000';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '200MB', mime_types: ['video/*'])]
    protected $intro = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Uploader(max_size: '200MB', mime_types: ['video/*'])]
    protected $outro = null;

    #[ORM\Column(type: 'string', length: 16, enumType: Fit::class)]
    protected Fit $fit = Fit::COVER;

    /** Seconds the film takes to come in and to go out; 0: none. */
    #[ORM\Column(type: 'float')]
    #[Assert\Range(min: 0, max: 5)]
    protected float $fadeSeconds = 0.5;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name ? trim($name) : null; return $this; }

    public function isDefault(): bool { return $this->default; }
    public function setDefault(bool $default): self { $this->default = $default; return $this; }

    public function getPlatform(): ?string { return $this->platform; }
    public function setPlatform(Platform|string|null $platform): self
    {
        $this->platform = $platform instanceof Platform ? $platform->value : ($platform ?: null);

        return $this;
    }

    public function getLogo(): ?string { return Uploader::getPublic($this, 'logo'); }
    public function getLogoFile(): ?File { return Uploader::get($this, 'logo'); }
    public function setLogo($logo): self { $this->logo = $logo; return $this; }

    public function getLogoPosition(): LogoPosition { return $this->logoPosition; }
    public function setLogoPosition(LogoPosition $position): self { $this->logoPosition = $position; return $this; }

    public function getLogoScale(): float { return $this->logoScale; }
    public function setLogoScale(float $scale): self { $this->logoScale = max(0.1, min(0.5, $scale)); return $this; }

    public function hasBand(): bool { return $this->band; }
    public function getBand(): bool { return $this->band; }
    public function setBand(bool $band): self { $this->band = $band; return $this; }

    public function getBandText(): ?string { return $this->bandText; }
    public function setBandText(?string $text): self { $this->bandText = $text ? trim($text) : null; return $this; }

    public function getBandColor(): string { return $this->bandColor; }
    public function setBandColor(?string $color): self { $this->bandColor = $color ?: '#000000'; return $this; }

    public function getTextColor(): string { return $this->textColor; }
    public function setTextColor(?string $color): self { $this->textColor = $color ?: '#ffffff'; return $this; }

    public function getFont(): ?string { return Uploader::getPublic($this, 'font'); }
    public function getFontFile(): ?File { return Uploader::get($this, 'font'); }
    public function setFont($font): self { $this->font = $font; return $this; }

    public function getBackground(): string { return $this->background; }
    public function setBackground(?string $color): self { $this->background = $color ?: '#000000'; return $this; }

    public function getIntro(): ?string { return Uploader::getPublic($this, 'intro'); }
    public function getIntroFile(): ?File { return Uploader::get($this, 'intro'); }
    public function setIntro($intro): self { $this->intro = $intro; return $this; }

    public function getOutro(): ?string { return Uploader::getPublic($this, 'outro'); }
    public function getOutroFile(): ?File { return Uploader::get($this, 'outro'); }
    public function setOutro($outro): self { $this->outro = $outro; return $this; }

    public function getFit(): Fit { return $this->fit; }
    public function setFit(Fit $fit): self { $this->fit = $fit; return $this; }

    public function getFadeSeconds(): float { return $this->fadeSeconds; }
    public function setFadeSeconds(float $seconds): self { $this->fadeSeconds = max(0.0, $seconds); return $this; }
}
