<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\Command;

use EventEngine\Aggregate\ContextProvider;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;

interface AggregateCommand extends Command
{
    public function __aggregateId(): string;

    public static function __aggregateMethod(): string;

    public static function __newAggregate(): bool;

    /** @return array<class-string<JsonSchemaAwareRecord>> */
    public static function __eventsToRecord(): array;

    /** @return array<class-string<ContextProvider>> */
    public static function __contextProviders(): array;
}
