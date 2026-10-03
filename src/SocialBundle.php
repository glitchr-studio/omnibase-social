<?php

namespace Base\Social;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Social publishing on top of glitchr/omnipost: a reel rendered from the
 * site's own template (ffmpeg), sent to each network with its variant, and
 * the account's feed kept for a wall on the site.
 */
class SocialBundle extends AbstractBaseBundle
{
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /** Modern layout: the class lives in src/, the bundle root is the package root. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // App\-wins, as omnibase does for its own entities: an application may
        // declare App\Entity\Social\Template extending ours and take over.
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Social\Entity', 'App\Entity\Social');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Social\Repository', 'App\Repository\Social');
    }
}
