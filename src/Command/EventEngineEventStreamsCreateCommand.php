<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\Command;

use ADS\Bundle\EventEngineBundle\Util\EventEngineUtil;
use ArrayIterator;
use PDO;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Stream as ProophStream;
use Prooph\EventStore\StreamName;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EventEngineEventStreamsCreateCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'event-engine:event-streams:create'; // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint

    private PDO $connection;
    private EventStore $eventStore;
    /** @var array<class-string> */
    private array $aggregates;

    /**
     * @param array<class-string> $aggregates
     */
    public function __construct(
        PDO $connection,
        EventStore $eventStore,
        array $aggregates
    ) {
        parent::__construct();

        $this->connection = $connection;
        $this->eventStore = $eventStore;
        $this->aggregates = $aggregates;
    }

    protected function configure(): void
    {
        $this->setDescription('Create the event_streams table and all the current available streams.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS event_streams (
                no BIGSERIAL,
                real_stream_name VARCHAR(150) NOT NULL,
                stream_name CHAR(41) NOT NULL,
                metadata JSONB,
                category VARCHAR(150),
                PRIMARY KEY (no),
                UNIQUE (stream_name)
            );'
        );

        $this->connection->exec(
            'CREATE INDEX IF NOT EXISTS category_index on event_streams (category);'
        );

        foreach ($this->aggregates as $aggregate) {
            $reflectionClass = new ReflectionClass($aggregate);
            $streamName = EventEngineUtil::fromAggregateNameToStreamName($reflectionClass->getShortName());
            $streamNameObject = new StreamName($streamName);

            if ($this->eventStore->hasStream($streamNameObject)) {
                continue;
            }

            $this->eventStore->create(new ProophStream($streamNameObject, new ArrayIterator()));
        }

        return 0;
    }
}
