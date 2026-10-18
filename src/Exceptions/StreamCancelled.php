<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Exceptions;

use RuntimeException;

/**
 * The browser went away (the user pressed "Stop" or closed the modal) while an answer was
 * streaming: generation stops and the usage is logged as failed.
 */
class StreamCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The client cancelled the stream.');
    }
}
