<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Exceptions;

/**
 * Thrown when an operation is not allowed in the document's current status
 * (e.g. retrying a document that has not failed). Safe to show to the user.
 */
class InvalidDocumentStateException extends \RuntimeException {}
