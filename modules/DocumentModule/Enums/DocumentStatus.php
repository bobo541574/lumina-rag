<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Enums;

/**
 * Document processing lifecycle.
 *
 * pending → processing → completed | failed; only failed documents may be
 * retried (back to pending).
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether a document in this status may be queued for processing again.
     *
     * @return bool True only for failed documents. Example: DocumentStatus::Failed->canRetry() → true
     */
    public function canRetry(): bool
    {
        return $this === self::Failed;
    }
}
