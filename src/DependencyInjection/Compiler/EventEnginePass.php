<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\DependencyInjection\Compiler;

use ADS\Bundle\EventEngineBundle\Aggregate\AggregateRoot;
use ADS\Bundle\EventEngineBundle\Command\Command;
use ADS\Bundle\EventEngineBundle\Command\ControllerCommand;
use ADS\Bundle\EventEngineBundle\Event\Event;
use ADS\Bundle\EventEngineBundle\Event\Listener;
use ADS\Bundle\EventEngineBundle\PreProcessor\PreProcessor;
use ADS\Bundle\EventEngineBundle\Projector\Projector;
use ADS\Bundle\EventEngineBundle\Query\Query;
use ADS\Bundle\EventEngineBundle\Repository\Repository;
use ADS\Bundle\EventEngineBundle\Repository\StateRepository;
use ADS\Bundle\EventEngineBundle\Type\Type;
use ADS\Bundle\EventEngineBundle\Util\EventEngineUtil;
use ADS\Util\StringUtil;
use ADS\ValueObjects\Implementation\ListValue\IterableListValue;
use ADS\ValueObjects\ValueObject;
use EventEngine\DocumentStore\DocumentStore;
use EventEngine\EventEngineDescription;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;
use EventEngine\Messaging\MessageProducer;
use Exception;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Config\Resource\ReflectionClassResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_map;
use function array_merge;
use function array_reduce;
use function array_unique;
use function array_values;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

final class EventEnginePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var string $domainNamespace */
        $domainNamespace = $container->getParameter('event_engine.domain_namespace');
        $filter = sprintf('reflection.%s', $domainNamespace);

        $resources = array_filter(
            $container->getResources(),
            static fn (ResourceInterface $resource) => $resource instanceof ReflectionClassResource
                && str_starts_with($resource . '', $filter)
        );

        $resources = array_filter(
            array_map(
                static function (ReflectionClassResource $resource) {
                    /** @var class-string $class */
                    $class = substr($resource . '', 11);

                    return new ReflectionClass($class);
                },
                $resources
            ),
            static fn (ReflectionClass $reflectionClass) => ! $reflectionClass->isInterface()
        );

        $mappers = [
            'commands' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(Command::class)
                    ? $reflectionClass->name
                    : null,
            'queries' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(Query::class)
                    ? $reflectionClass->name
                    : null,
            'resolvers' => static function (ReflectionClass $reflectionClass) {
                /** @var class-string $className */
                $className = $reflectionClass->name;

                return $reflectionClass->implementsInterface(Query::class)
                    ? $className::__resolver()
                    : null;
            },
            'events' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(Event::class)
                    ? $reflectionClass->name
                    : null,
            'aggregates' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(AggregateRoot::class)
                    ? $reflectionClass->name
                    : null,
            'pre_processors' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(PreProcessor::class)
                    ? $reflectionClass->name
                    : null,
            'listeners' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(Listener::class) && ! $reflectionClass->isAbstract()
                    ? $reflectionClass->name
                    : null,
            'controllers' => static function (ReflectionClass $reflectionClass) {
                /** @var class-string $className */
                $className = $reflectionClass->name;

                return $reflectionClass->implementsInterface(ControllerCommand::class)
                    ? $className::__controller()
                    : null;
            },
            'descriptions' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(EventEngineDescription::class)
                    ? $reflectionClass->name
                    : null,
            'child_repositories' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(StateRepository::class)
                    ? $reflectionClass->name
                    : null,
            'types' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(Type::class)
                    ? $reflectionClass->name
                    : null,
            'projectors' => static fn (ReflectionClass $reflectionClass) => $reflectionClass
                ->implementsInterface(Projector::class)
                    ? $reflectionClass->name
                    : null,
        ];

        /** @var ?Definition $eventQueueDefinition */
        $eventQueueDefinition = null;
        foreach ($resources as $resourceReflectionClass) {
            if (! $resourceReflectionClass->implementsInterface(MessageProducer::class)) {
                continue;
            }

            if ($eventQueueDefinition instanceof Definition) {
                throw new Exception('You can only have 1 event queue.');
            }

            if ($container->hasDefinition($resourceReflectionClass->name)) {
                $eventQueueDefinition = $container->getDefinition($resourceReflectionClass->name);
            } else {
                $eventQueueDefinition = new Definition($resourceReflectionClass->name);
            }
        }

        if ($eventQueueDefinition instanceof Definition) {
            $container->setDefinition('event_engine.event_queue', $eventQueueDefinition);
        }

        foreach ($mappers as $name => $mapper) {
            $container->setParameter(
                sprintf('event_engine.%s', $name),
                array_values(array_unique(array_filter(array_map($mapper, $resources))))
            );
        }

        $this->buildRepositories($container);
        $this->buildProjectors($container);
        $container->removeDefinition(Repository::class);
        $this->makePublic($container);
    }

    private function buildRepositories(ContainerBuilder $container): void
    {
        $repository = $container->getDefinition(Repository::class);
        /** @var array<class-string<StateRepository<IterableListValue<JsonSchemaAwareRecord>, JsonSchemaAwareRecord, ValueObject>>> $childRepositories */
        $childRepositories = $container->getParameter('event_engine.child_repositories');
        /** @var array<class-string<AggregateRoot<JsonSchemaAwareRecord>>> $aggregates */
        $aggregates = $container->getParameter('event_engine.aggregates');
        /** @var string $entityNamespace */
        $entityNamespace = $container->getParameter('event_engine.entity_namespace');

        $aggregateRepositoryDefinitions = array_reduce(
            $aggregates,
            static function (array $result, $aggregate) use ($entityNamespace, $repository) {
                /** @var class-string $aggregate */
                $reflectionClass = new ReflectionClass($aggregate);
                $aggregate = $reflectionClass->getShortName();

                $key = sprintf('event_engine.repository.%s', StringUtil::decamelize($aggregate));

                $result[$key] = (new Definition(
                    $repository->getClass(),
                    [
                        new Reference(DocumentStore::class),
                        EventEngineUtil::fromAggregateNameToDocumentStoreName($aggregate),
                        EventEngineUtil::fromAggregateNameToStateClass($aggregate, $entityNamespace),
                        EventEngineUtil::fromAggregateNameToStatesClass($aggregate, $entityNamespace),
                    ]
                ))
                    ->setPublic(true);

                return $result;
            },
            [],
        );

        $container->addDefinitions($aggregateRepositoryDefinitions);

        foreach ($childRepositories as $childRepository) {
            preg_match_all('/\\\([^\\\]+)Repository$/', $childRepository, $matches);

            $container->getDefinition($childRepository)
                ->setArguments(
                    $container
                        ->getDefinition(
                            sprintf(
                                'event_engine.repository.%s',
                                strtolower(StringUtil::decamelize($matches[1][0]))
                            )
                        )
                        ->getArguments()
                )
                ->setPublic(true);
        }
    }

    private function buildProjectors(ContainerBuilder $container): void
    {
        $repository = $container->getDefinition(Repository::class);
        /** @var array<class-string<Projector>> $projectors */
        $projectors = $container->getParameter('event_engine.projectors');

        $projectorRepositoryDefinitions = array_reduce(
            $projectors,
            static function (array $result, $projector) use ($repository): array {
                /** @var class-string $projector */
                $reflectionClass = new ReflectionClass($projector);

                $key = str_replace(
                    '_projector',
                    '',
                    sprintf(
                        'event_engine.repository.%s',
                        StringUtil::decamelize($reflectionClass->getShortName())
                    )
                );

                $result[$key] = (new Definition(
                    $repository->getClass(),
                    [
                        new Reference(DocumentStore::class),
                        $projector::generateOwnCollectionName(),
                        $projector::stateClassName(),
                        $projector::statesClassName(),
                    ]
                ))->setPublic(true);

                return $result;
            },
            []
        );

        $container->addDefinitions($projectorRepositoryDefinitions);
    }

    private function makePublic(ContainerBuilder $container): void
    {
        /** @var array<class-string> $resolvers */
        $resolvers = $container->getParameter('event_engine.resolvers');
        /** @var array<class-string> $listeners */
        $listeners = $container->getParameter('event_engine.listeners');
        /** @var array<class-string> $preProcessors */
        $preProcessors = $container->getParameter('event_engine.pre_processors');
        /** @var array<class-string> $controllers */
        $controllers = $container->getParameter('event_engine.controllers');
        /** @var array<class-string> $projectors */
        $projectors = $container->getParameter('event_engine.projectors');

        $classes = array_merge($resolvers, $listeners, $preProcessors, $controllers, $projectors);

        foreach ($classes as $class) {
            if (! $container->hasDefinition($class)) {
                throw new RuntimeException(
                    sprintf(
                        'Class \'%s\' can\'t be made public because it\'s not found.',
                        $class
                    )
                );
            }

            $container->getDefinition($class)->setPublic(true);
        }
    }
}
