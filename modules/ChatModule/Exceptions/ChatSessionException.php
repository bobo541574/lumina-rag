<?php

declare(strict_types=1);

namespace Modules\ChatModule\Exceptions;

/**
 * A chat session cannot be used (not found, deleted, expired, or full).
 * The message is written for end users and is safe to return to the client.
 */
class ChatSessionException extends \RuntimeException {}
