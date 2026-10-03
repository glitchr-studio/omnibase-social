<?php

namespace Base\Social\MessageHandler;

use Base\Social\Message\RenderPostMessage;
use Base\Social\Repository\SocialPostRepository;
use Base\Social\Service\Publisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Renders a post for each of its networks (Service\Publisher::render()). */
#[AsMessageHandler]
final class RenderPostHandler
{
    public function __construct(
        private readonly SocialPostRepository $posts,
        private readonly Publisher $publisher,
    ) {
    }

    public function __invoke(RenderPostMessage $message): void
    {
        // Deleted since: nothing to do.
        if ($post = $this->posts->find($message->postId)) {
            $this->publisher->render($post, $message->publish);
        }
    }
}
