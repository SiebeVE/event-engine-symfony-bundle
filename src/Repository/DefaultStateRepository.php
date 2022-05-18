<?php

declare(strict_types=1);

namespace ADS\Bundle\EventEngineBundle\Repository;

use ADS\ValueObjects\Implementation\ListValue\IterableListValue;
use ADS\ValueObjects\ListValue;
use ADS\ValueObjects\ValueObject;
use ArrayIterator;
use EventEngine\Data\ImmutableRecord;
use EventEngine\DocumentStore\DocumentStore;
use EventEngine\DocumentStore\Filter\AnyFilter;
use EventEngine\DocumentStore\Filter\DocIdFilter;
use EventEngine\DocumentStore\Filter\Filter;
use EventEngine\DocumentStore\Filter\OrFilter;
use EventEngine\DocumentStore\OrderBy\OrderBy;
use EventEngine\DocumentStore\PartialSelect;
use EventEngine\JsonSchema\JsonSchemaAwareRecord;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Traversable;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function iterator_to_array;
use function json_encode;
use function reset;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * @template TStates of IterableListValue
 * @template TState of JsonSchemaAwareRecord
 * @template TId of ValueObject
 * @template-implements StateRepository<TStates, TState, TId>
 */
abstract class DefaultStateRepository implements StateRepository
{
    public const DOCUMENT_STORE_NOT_FOUND = 'Could not found document store \'%s\' for repository \'%s\'.';

    /** @var class-string<TState> */
    protected string $stateClass;

    /** @var class-string<TStates> */
    protected string $statesClass;

    /**
     * @param class-string<TState> $stateClass
     * @param class-string<TStates> $statesClass
     */
    public function __construct(
        protected DocumentStore $documentStore,
        protected string $documentStoreName,
        string $stateClass,
        string $statesClass,
    ) {
        $reflectionClassState = new ReflectionClass($stateClass);
        if (! $reflectionClassState->implementsInterface(ImmutableRecord::class)) {
            throw new RuntimeException(sprintf(
                'The state class "%s" doesn\'t implement the "%s" interface',
                $stateClass,
                ImmutableRecord::class
            ));
        }

        $reflectionClassStates = new ReflectionClass($statesClass);
        if (! $reflectionClassStates->implementsInterface(ListValue::class)) {
            throw new RuntimeException(sprintf(
                'The states class "%s" doesn\'t implement the "%s" interface',
                $statesClass,
                ListValue::class
            ));
        }

        $this->stateClass = $stateClass;
        $this->statesClass = $statesClass;
    }

    /**
     * phpcs:ignore Generic.Files.LineLength.TooLong
     * @param Traversable<array{state: array<string, mixed>}|null>|array<array{state: array<string, mixed>}|null> $documents
     *
     * @return TStates
     */
    private function statesFromDocuments(Traversable|array $documents)
    {
        if ($documents instanceof Traversable) {
            $documents = iterator_to_array($documents);
        }

        return $this->statesClass::fromItems(
            array_filter(
                array_map(
                    [$this, 'stateFromDocument'],
                    array_values($documents)
                )
            )
        );
    }

    /**
     * @param array{state: array<string, mixed>}|null $document
     *
     * @return TState|null
     */
    private function stateFromDocument(?array $document)
    {
        if ($document === null) {
            return null;
        }

        self::checkDocumentHasState($document);

        return $this->stateClass::fromArray($document['state']);
    }

    /**
     * @inheritDoc
     */
    public function findDocument($identifier): ?array
    {
        /** @var array{state: array<string, mixed>} $document */
        $document = $this->documentStore->getDoc(
            $this->documentStoreName,
            (string) $identifier
        );

        return $document;
    }

    /**
     * @inheritDoc
     */
    public function needDocument(
        $identifier,
        ?Throwable $exception = null
    ): array {
        $document = $this->findDocument($identifier);

        $exception ??= new NotFoundHttpException(
            sprintf(
                'Resource with id \'%s\' not found in document store \'%s\'',
                (string) $identifier,
                $this->documentStoreName
            )
        );

        if ($document === null) {
            throw $exception;
        }

        return $document;
    }

    /**
     * @inheritDoc
     */
    public function dontNeedDocument(
        $identifier,
        ?Throwable $exception = null
    ): void {
        try {
            $this->needDocument($identifier);
        } catch (NotFoundHttpException) {
            return;
        }

        throw new ConflictHttpException(
            sprintf(
                'Resource with id \'%s\' already exists in document store \'%s\'',
                (string) $identifier,
                $this->documentStoreName
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function needDocumentState(
        $identifier,
        ?Throwable $exception = null
    ) {
        $document = $this->needDocument($identifier, $exception);

        /** @var TState $state */
        $state = $this->stateFromDocument($document);

        return $state;
    }

    /**
     * phpcs:ignore SlevomatCodingStandard.Commenting.UselessInheritDocComment.UselessInheritDocComment
     * @inheritDoc
     */
    public function findDocuments(
        ?Filter $filter = null,
        ?int $skip = null,
        ?int $limit = null,
        ?OrderBy $orderBy = null
    ): Traversable {
        if ($filter === null) {
            $filter = new AnyFilter();
        }

        return $this->documentStore->findDocs(
            $this->documentStoreName,
            $filter,
            $skip,
            $limit,
            $orderBy
        );
    }

    /**
     * phpcs:ignore SlevomatCodingStandard.Commenting.UselessInheritDocComment.UselessInheritDocComment
     * @inheritDoc
     */
    public function findDocumentsByIds(array|ListValue $identifiers): Traversable
    {
        $filter = $this->identifiersToFilter($identifiers);

        if ($filter === null) {
            /** @var Traversable<array{state: array<string, mixed>}> $iterator */
            $iterator = new ArrayIterator();

            return $iterator;
        }

        return $this->findDocuments($filter);
    }

    /**
     * @inheritDoc
     */
    public function needDocumentsByIds(array|ListValue $identifiers): array
    {
        $documents = $this->findDocumentsByIds($identifiers);
        $documentsArray = iterator_to_array($documents);
        $countIdentifiers = $identifiers instanceof ListValue
            ? $identifiers->count()
            : count($identifiers);

        if (count($documentsArray) !== $countIdentifiers) {
            $scalarIdentifiers = $this->identifiersToScalars($identifiers);

            throw new NotFoundHttpException(
                sprintf(
                    'One of the identifiers is not found: \'%s\'.',
                    json_encode($scalarIdentifiers, JSON_THROW_ON_ERROR)
                )
            );
        }

        return $documentsArray;
    }

    /**
     * @inheritDoc
     */
    public function findDocumentStatesByIds(array|ListValue $identifiers)
    {
        return $this->statesFromDocuments(
            $this->findDocumentsByIds($identifiers)
        );
    }

    /**
     * @inheritDoc
     */
    public function needDocumentStatesByIds(array|ListValue $identifiers)
    {
        return $this->statesFromDocuments(
            $this->needDocumentsByIds($identifiers)
        );
    }

    /**
     * phpcs:ignore SlevomatCodingStandard.Commenting.UselessInheritDocComment.UselessInheritDocComment
     * @inheritDoc
     */
    public function findPartialDocuments(
        PartialSelect $partialSelect,
        ?Filter $filter = null,
        ?int $skip = null,
        ?int $limit = null,
        ?OrderBy $orderBy = null
    ): Traversable {
        if ($filter === null) {
            $filter = new AnyFilter();
        }

        return $this->documentStore->findPartialDocs(
            $this->documentStoreName,
            $partialSelect,
            $filter,
            $skip,
            $limit,
            $orderBy
        );
    }

    public function countDocuments(?Filter $filter = null): int
    {
        if ($filter === null) {
            $filter = new AnyFilter();
        }

        return $this->documentStore->countDocs(
            $this->documentStoreName,
            $filter
        );
    }

    /**
     * @inheritDoc
     */
    public function findDocumentIds(?Filter $filter = null): array
    {
        if ($filter === null) {
            $filter = new AnyFilter();
        }

        return $this->documentStore->filterDocIds(
            $this->documentStoreName,
            $filter
        );
    }

    /**
     * @inheritDoc
     */
    public function findDocumentState($identifier)
    {
        return $this->stateFromDocument(
            $this->findDocument($identifier)
        );
    }

    /**
     * @inheritDoc
     */
    public function findDocumentStates(
        ?Filter $filter = null,
        ?int $skip = null,
        ?int $limit = null,
        ?OrderBy $orderBy = null
    ) {
        return $this->statesFromDocuments(
            $this->findDocuments($filter, $skip, $limit, $orderBy)
        );
    }

    public function findDocumentIdValueObjects(?Filter $filter = null): ListValue
    {
        $documentIds = $this->findDocumentIds($filter);

        if ($this->identifiersClass() === null) {
            throw new RuntimeException(
                sprintf('Could not found identifiers class for repository \'%s\'.', static::class)
            );
        }

        $identifiersClass = $this->identifiersClass();

        return $identifiersClass::fromArray($documentIds);
    }

    public function hasDocuments(?Filter $filter = null): bool
    {
        return $this->countDocuments($filter) > 0;
    }

    public function hasNoDocuments(?Filter $filter = null): bool
    {
        return $this->countDocuments($filter) === 0;
    }

    /**
     * @inheritDoc
     */
    public function hasDocument($identifier): bool
    {
        $document = $this->findDocument($identifier);

        return $document !== null;
    }

    /**
     * @inheritDoc
     */
    public function hasNoDocument($identifier): bool
    {
        return ! $this->hasDocument($identifier);
    }

    public function hasAllDocuments(array|ListValue $identifiers): bool
    {
        $filter = $this->identifiersToFilter($identifiers);

        $documentIds = $this->findDocumentIds($filter);

        return count($identifiers) === count($documentIds);
    }

    /**
     * @inheritDoc
     */
    public function upsertState($identifier, $state): void
    {
        $this->documentStore->upsertDoc(
            $this->documentStoreName,
            (string) $identifier,
            ['state' => $state->toArray()]
        );
    }

    /**
     * @inheritDoc
     */
    public function deleteDoc($identifier): void
    {
        $this->documentStore->deleteDoc(
            $this->documentStoreName,
            (string) $identifier
        );
    }

    /**
     * @param array<mixed> $document
     */
    protected static function checkDocumentHasState(array $document): void
    {
        if (! array_key_exists('state', $document)) {
            throw new RuntimeException(
                sprintf(
                    'No state key found in document: \'%s\'',
                    json_encode($document, JSON_THROW_ON_ERROR)
                )
            );
        }
    }

    /**
     * @param array<string|TId>|ListValue<TId> $identifiers
     */
    private function identifiersToFilter(array|ListValue $identifiers): ?Filter
    {
        /** @var array<string> $scalarIdentifiers */
        $scalarIdentifiers = $this->identifiersToScalars($identifiers);

        if (empty($scalarIdentifiers)) {
            return null;
        }

        $filters = array_map(
            static fn ($scalarIdentifier) => new DocIdFilter($scalarIdentifier),
            $scalarIdentifiers
        );

        return count($filters) === 1 ? reset($filters) : new OrFilter(...$filters);
    }

    /**
     * @param array<string|TId>|ListValue<TId> $identifiers
     *
     * @return array<mixed>
     */
    private function identifiersToScalars(array|ListValue $identifiers): array
    {
        if ($identifiers instanceof ListValue) {
            $identifiers = $identifiers->toArray();
        }

        if (! is_array($identifiers)) {
            throw new RuntimeException('List of identifiers is not an array.');
        }

        if (empty($identifiers)) {
            return $identifiers;
        }

        return array_map(
            static fn ($identifier) => $identifier instanceof ValueObject
                    ? $identifier->toValue()
                    : $identifier,
            $identifiers
        );
    }

    /**
     * @return class-string<TState>
     */
    public function stateClass(): string
    {
        return $this->stateClass;
    }

    /**
     * @return class-string<ListValue<TId>>|null
     */
    protected function identifiersClass(): ?string
    {
        return null;
    }
}
