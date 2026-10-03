<?php

namespace Base\Social\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Social\Repository\SocialMediaRepository;
use Base\Social\Repository\SocialPostTargetRepository;
use Base\Social\Service\Accounts;
use Omnipost\Platform;

/**
 * The dashboard's tile: per network, the last post that left and what became
 * of it, when the feed was last read, and a word when the token dies soon.
 * `yield MenuItem::block('social_overview', ...)` in the dashboard's
 * configureWidgetItems() places it.
 */
final class SocialWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly SocialPostTargetRepository $targets,
        private readonly SocialMediaRepository $media,
    ) {
    }

    public static function getName(): string
    {
        return 'social_overview';
    }

    public function getTemplate(): string
    {
        return '@Social/admin/widget/overview.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $rows = [];
        foreach ($this->accounts->names() as $name) {
            $token = $this->accounts->token($name);
            $rows[] = [
                'name' => $name,
                'label' => Platform::tryFrom($name)?->label() ?? $name,
                'connected' => $this->accounts->connected($name),
                'username' => $this->accounts->account($name)['username'] ?? null,
                'last' => $this->targets->findLastFor($name),
                'synced' => $this->media->lastSync($name),
                'expires' => $token?->expiresAt,
                'expiring' => null !== $token && null === $token->refreshToken && $token->isExpiring(10),
            ];
        }

        return ['rows' => $rows];
    }
}
