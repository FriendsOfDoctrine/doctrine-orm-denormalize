<?php
namespace Argayash\DenormalizedOrm;


use Argayash\DenormalizedOrm\Mapping\Annotation\DnTable;
use Argayash\DenormalizedOrm\Mapping\DnClassMetadata;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Class DnTableGroup
 * @package AppBundle\DenormalizedOrm
 */
class DnTableGroup
{
    /**
     * @var array
     */
    protected $structureSchema = [];

    /**
     * @var array
     */
    protected $columns = [];

    /**
     * @var array
     */
    protected $indexes = [];

    /**
     * @var DnClassMetadata[]
     */
    protected $dnClassMetadata = [];
    /**
     * @var bool
     */
    protected $isSetIndex = false;

    /**
     * DnTableGroup constructor.
     *
     * @param array $structureSchema
     * @param DnClassMetadata[] $dnClassMetadata
     */
    public function __construct(array $structureSchema, array $dnClassMetadata)
    {
        $this->structureSchema = $structureSchema;
        $this->dnClassMetadata = $dnClassMetadata;
    }

    /**
     * @param string $parentClassName
     * @param string $fieldName
     * @param string $childrenClassName
     *
     * @return $this
     */
    public function add(string $parentClassName, string $fieldName, string $childrenClassName)
    {
        $this->structureSchema[$parentClassName][$fieldName] = $childrenClassName;

        return $this;
    }

    /**
     * @param string $className
     *
     * @return bool
     */
    public function hasClass(string $className)
    {
        return count(array_filter($this->structureSchema, function ($value, $key) use ($className) {
                return $key === $className || in_array($className, $value, true);
            }, ARRAY_FILTER_USE_BOTH)) > 0;
    }

    /**
     * @return array
     */
    public function getStructureSchema(): array
    {
        return $this->structureSchema;
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        $tableName = [];

        foreach ($this->structureSchema as $entityClass => $entityJoins) {
            if (($classMetadata = $this->getClassMetadataByName($entityClass)) && ($dnClassMetadata = $this->getDnClassMetadataByName($entityClass))) {
                $tableName[] = $dnClassMetadata->getDnTable()->name ?: $classMetadata->reflClass->getShortName();

                foreach ($entityJoins as $joinKey => $entityJoin) {
                    if (($classMetadata = $this->getClassMetadataByName($entityJoin)) && ($dnClassMetadata = $this->getDnClassMetadataByName($entityJoin))) {
                        $tableName[] = $joinKey . DnTable::DENORMALIZE_FIELD_DELIMITER . ($dnClassMetadata->getDnTable()->name ?: $classMetadata->reflClass->getShortName());
                    }
                }
            }
        }

        return strtolower(implode(DnTable::DENORMALIZE_TABLE_DELIMITER, $tableName));
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getTableName();
    }

    /**
     * @return DnColumn[]
     */
    public function getColumns()
    {
        foreach ($this->structureSchema as $entityClass => $entityJoins) {
            if ($classMetadata = $this->getClassMetadataByName($entityClass)) {
                $columnPrefix = $classMetadata->getReflectionClass()->getShortName();
                $this->getColumnsOfClassMetadata($columnPrefix, $this->getDnClassMetadataByName($entityClass));
                foreach ($entityJoins as $joinKey => $entityJoin) {
                    if ($classMetadata = $this->getClassMetadataByName($entityJoin)) {
                        $this->getColumnsOfClassMetadata($columnPrefix . DnTable::DENORMALIZE_FIELD_DELIMITER . $joinKey, $this->getDnClassMetadataByName($entityJoin));
                    }
                }
            }
        }

        return $this->columns;
    }

    /**
     * @return array
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * @param Connection $connection
     *
     * @return string[]
     */
    public function getMigrationSQL(Connection $connection): array
    {
        $fromSchema = $connection->getSchemaManager()->createSchema();

        $toSchema = clone $fromSchema;
        $newTable = $toSchema->createTable($this->getTableName());

        /** @var DnColumn $column */
        foreach ($this->getColumns() as $column) {
            $newTable->addColumn($column->getName(), $column->getType(), $column->getOptions());
        }

        if ($this->getIndexes()) {
            $newTable->setPrimaryKey($this->getIndexes());
        }

        return $fromSchema->getMigrateToSql($toSchema, $connection->getDatabasePlatform());
    }

    /**
     * @param string $columnPrefix
     * @param DnClassMetadata $dnClassMetadata
     *
     * @return $this
     */
    protected function getColumnsOfClassMetadata(string $columnPrefix, DnClassMetadata $dnClassMetadata)
    {
        foreach ($dnClassMetadata->getClassMetadata()->fieldMappings as $fieldName => $field) {
            if (!in_array($fieldName, (array)$dnClassMetadata->getDnTable()->excludeFields, true)) {
                $dnColumn = new DnColumn($columnPrefix . DnTable::DENORMALIZE_FIELD_DELIMITER . $fieldName, $field, $dnClassMetadata->getClassMetadata()->name, $fieldName);
                if (!$this->isSetIndex && isset($field['id']) && $field['id']) {
                    $this->indexes[] = $dnColumn->getName();
                }
                $this->columns[] = $dnColumn;
            }
        }

        $this->isSetIndex = true;

        return $this;
    }

    /**
     * @param string $className
     *
     * @return ClassMetadata|null
     */
    protected function getClassMetadataByName(string $className)
    {
        return $this->getDnClassMetadataByName($className) ? $this->getDnClassMetadataByName($className)->getClassMetadata() : null;
    }

    /**
     * @param string $className
     *
     * @return DnClassMetadata|null
     */
    protected function getDnClassMetadataByName(string $className)
    {
        return $this->dnClassMetadata[$className]??null;
    }
}