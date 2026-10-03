<?php

namespace Base\Social\MessageHandler;

use Base\Social\Message\PublishTargetMessage;
use Base\Social\Repository\SocialPostTargetRepository;
use Base\Social\Service\Publisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Hands a rendered post to one network (Service\Publisher::publish()). */
#[AsMessageHandler]
final class PublishTargetHandler
{
    public function __construct(
        private readonly SocialPostTargetRepository $targets,
        private readonly Publisher $publisher,
    ) {
    }

    public function __invoke(PublishTargetMessage $message): void
    {
        if ($target = $this->targets->find($message->targetId)) {
            $this->publisher->publish($target);
        }
    }
}
