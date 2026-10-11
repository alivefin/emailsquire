<?php

namespace App\MailServer\Exceptions;

use RuntimeException;

/** A mailbox switch needs a column of the optional schema extension (backend plan section 10) the server doesn't have; rendered as 422 against that field. */
class SchemaExtensionMissing extends RuntimeException
{
    public function __construct(public readonly string $field, public readonly string $column)
    {
        parent::__construct(trans()->string('emailsquire.mailbox.schema_extension_missing'));
    }
}
