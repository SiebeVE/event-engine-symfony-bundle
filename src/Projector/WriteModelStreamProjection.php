<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\Projector;

use EventEngine\EventEngine;
use LogicException;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\Projection\ProjectionManager;
use Prooph\EventStore\Projection\ReadModelProjector;

use function array_keys;
use function count;

class WriteModelStreamProjection
{
    final public const NAME = 'ee_write_model_projection';

    /** @readonly */
    private readonly ReadModelProjector $projection;

    /** @param array<string, mixed>|null $projectionOptions */
    public function __construct(
        ProjectionManager $projectionManager,
        EventEngine $eventEngine,
        array|null $projectionOptions = null,
        private readonly bool $testMode = false,
    ) {
        if ($projectionOptions === null) {
            $projectionOptions = [ReadModelProjector::OPTION_PERSIST_BLOCK_SIZE => 1];
        }

        $sourceStreams = [];

        foreach ($eventEngine->projectionInfo()->projections() as $projectionInfo) {
            foreach ($projectionInfo->sourceStreams()->items() as $sourceStream) {
                if (! $sourceStream->isLocalService()) {
                    continue;
                }

                $sourceStreams[$sourceStream->streamName()] = null;
            }
        }

        $sourceStreams = array_keys($sourceStreams);
        $totalSourceStreams = count($sourceStreams);

        if ($totalSourceStreams === 0) {
            throw new LogicException('No source streams found for write model projection');
        }

        $this->projection = $projectionManager->createReadModelProjection(
            self::NAME,
            new ReadModelProxy($eventEngine),
            $projectionOptions,
        );

        $this->projection->fromStreams(...$sourceStreams);

        $this->projection->whenAny(function ($state, Message $event): void {
            /** @phpstan-ignore-next-line */
            $this->readModel()->stack('handle', $this->streamName(), $event);
        });
    }

    public function run(bool $keepRunning = true): void
    {
        $this->projection->run(! $this->testMode && $keepRunning);
    }

    public function reset(): void
    {
        $this->projection->reset();
    }
}
