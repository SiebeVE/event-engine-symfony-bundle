<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\Response;

use EventEngine\JsonSchema\JsonSchemaAwareCollection;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

interface HasResponses
{
    /** @return class-string<JsonSchemaAwareRecord|JsonSchemaAwareCollection> */
    public static function __defaultResponseClass(): string;

    public static function __defaultStatusCode(): int;
}
