<?php

declare(strict_types=1);

namespace ACSEO\TypesenseBundle\EventListener;

use ACSEO\TypesenseBundle\Manager\CollectionManager;
use ACSEO\TypesenseBundle\Manager\DocumentManager;
use ACSEO\TypesenseBundle\Transformer\DoctrineToTypesenseTransformer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class TypesenseIndexer
{
    private $collectionManager;
    private $transformer;
    private $managedClassNames;
    private DocumentManager $documentManager;
    private array $relatedEntities;

    private $objetsIdThatCanBeDeletedByObjectHash = [];
    private $documentsToIndex                     = [];
    private $documentsToUpdate                    = [];
    private $documentsToDelete                    = [];
    private EntityManagerInterface $entityManager;

    public function __construct(
        CollectionManager $collectionManager,
        DocumentManager $documentManager,
        DoctrineToTypesenseTransformer $transformer,
        EntityManagerInterface $entityManager,
        array $relatedEntities
    ) {
        $this->collectionManager = $collectionManager;
        $this->documentManager   = $documentManager;
        $this->transformer       = $transformer;
        $this->entityManager     = $entityManager;
        $this->relatedEntities   = $relatedEntities;

        $this->managedClassNames  = $this->collectionManager->getManagedClassNames();
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            $this->handleRelatedEntities($entity);

            return;
        }

        $collection = $this->getCollectionName($entity);
        $data       = $this->transformer->convert($entity);

        $this->documentsToIndex[] = [$collection, $data];
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            $this->handleRelatedEntities($entity);

            return;
        }

        $collectionDefinitionKey = $this->getCollectionKey($entity);
        $collectionConfig        = $this->collectionManager->getCollectionDefinitions()[$collectionDefinitionKey];

        $this->checkPrimaryKeyExists($collectionConfig);

        $collection = $this->getCollectionName($entity);
        $data       = $this->transformer->convert($entity);

        $this->documentsToUpdate[] = [$collection, $data['id'], $data];
    }

    private function handleRelatedEntities($entity): void
    {
        $entityClass = $this->getClass($entity);
        if (!isset($this->relatedEntities[$this->getClass($entity)])) {
            return;
        }

        $collections = $this->relatedEntities[$entityClass];

        $childId = $entity->getId() ?? null;

        if (null === $childId) {
            return;
        }

        foreach ($collections as $collectionName) {
            $collectionDefinition = $this->collectionManager->getCollectionDefinition($collectionName);
            $parentEntityClass = $collectionDefinition['entity'];

            $metadata = $this->entityManager->getClassMetadata($parentEntityClass);

            $parentRelationProperty = null;

            foreach ($metadata->getAssociationNames() as $fieldName) {
                $mapping = $metadata->getAssociationMapping($fieldName);

                if ($mapping['targetEntity'] === $entityClass) {
                    $parentRelationProperty = $fieldName;
                    break; // Found the property, exit the loop
                }
            }

            if (null === $parentRelationProperty) {
                //Handle error
                continue;
            }

            $repository = $this->entityManager->getRepository($parentEntityClass);

            $qb = $repository->createQueryBuilder('p');

            $parentEntities = $qb
                ->join('p.' . $parentRelationProperty, 'c')
                ->where('c.id = :childId')
                ->setParameter('childId', $childId)
                ->getQuery()
                ->getResult();

            foreach ($parentEntities as $parentEntity) {
                $collection = $this->getCollectionName($parentEntity);
                $data       = $this->transformer->convert($parentEntity);
                $this->documentsToUpdate[] = [$collection, $data['id'], $data];
            }
        }
    }

    private function checkPrimaryKeyExists($collectionConfig): void
    {
        foreach ($collectionConfig['fields'] as $config) {
            if ($config['type'] === 'primary') {
                return;
            }
        }

        throw new \Exception(sprintf('Primary key info have not been found for Typesense collection %s', $collectionConfig['typesense_name']));
    }

    public function preRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($this->entityIsNotManaged($entity)) {
            $this->handleRelatedEntities($entity);

            return;
        }

        $data = $this->transformer->convert($entity);

        $this->objetsIdThatCanBeDeletedByObjectHash[spl_object_hash($entity)] = $data['id'];
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        $entityHash = spl_object_hash($entity);

        if (!isset($this->objetsIdThatCanBeDeletedByObjectHash[$entityHash])) {
            return;
        }

        $collection = $this->getCollectionName($entity);

        $this->documentsToDelete[] = [$collection, $this->objetsIdThatCanBeDeletedByObjectHash[$entityHash]];
    }

    public function postFlush(): void
    {
        $this->indexDocuments();
        $this->deleteDocuments();

        $this->resetDocuments();
    }

    private function indexDocuments(): void
    {
        foreach ($this->documentsToIndex as $documentToIndex) {
            $this->documentManager->index(...$documentToIndex);
        }
        foreach ($this->documentsToUpdate as $documentToUpdate) {
            $this->documentManager->index($documentToUpdate[0], $documentToUpdate[2]);
        }
    }

    private function deleteDocuments(): void
    {
        foreach ($this->documentsToDelete as $documentToDelete) {
            $this->documentManager->delete(...$documentToDelete);
        }
    }

    private function resetDocuments(): void
    {
        $this->documentsToIndex = [];
        $this->documentsToUpdate = [];
        $this->documentsToDelete = [];
    }

    private function entityIsNotManaged($entity): bool
    {
        $entityClassname = $this->getClass($entity);

        return !in_array($entityClassname, array_values($this->managedClassNames), true);
    }

    private function getCollectionName($entity)
    {
        $entityClassname = $this->getClass($entity);

        return array_search($entityClassname, $this->managedClassNames, true);
    }

    private function getCollectionKey($entity)
    {
        $entityClassname = $this->getClass($entity);

        foreach ($this->collectionManager->getCollectionDefinitions() as $key => $def) {
            if ($def['entity'] === $entityClassname) {
                return $key;
            }
        }

        return null;
    }

    function getClass($entity): string
    {
        try {
            $classMetadata = $this->entityManager->getClassMetadata(get_class($entity));
            $class = $classMetadata->getName();
        } catch (\Error $e) {
            $class = get_class($entity);
        }

        return $class;
    }
}
