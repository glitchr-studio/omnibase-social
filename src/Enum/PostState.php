<?php

namespace Base\Social\Enum;

/** Where a post is, all its networks together. */
enum PostState: string
{
    case DRAFT = 'draft';
    case RENDERING = 'rendering';
    case READY = 'ready';
    case PUBLISHING = 'publishing';
    case DONE = 'done';
    case FAILED = 'failed';
}
