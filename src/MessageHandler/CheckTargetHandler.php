<?php

namespace Base\Social\MessageHandler;

use Base\Social\Message\CheckTargetMessage;
use Base\Social\Repository\SocialPostTargetRepository;
use Base\Social\Service\Publisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Reads a publication's status again, and again later while it is processing (Service\Publisher::check()). */
#[AsMessageHandler]
final class CheckTargetHandler
{
    public function __construct(
        private readonly SocialPostTargetRepository $targets,
        private readonly Publisher $publisher,
    ) {
    }

    public function __invoke(CheckTargetMessage $message): void
    {
        if ($target = $this->targets->find($message->targetId)) {
            $this->publisher->check($target);
        }
    }
}
