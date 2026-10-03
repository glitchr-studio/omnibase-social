<?php

namespace Base\Social\Enum;

/** What becomes of a source that is not 9:16: cropped to fill the frame, or whole between two bands of the background. */
enum Fit: string
{
    case COVER = 'cover';
    case CONTAIN = 'contain';
}
