<?php
declare(strict_types=1);

namespace Bilbofox\Nextras\Orm;

use Nextras\Orm\Entity\IEntity;
use Nextras\Orm\Entity\Reflection\EntityMetadata;
use Nextras\Orm\Repository\Repository as ParentRepository;
use UI\Exception\InvalidArgumentException;

/**
 * Repository extending with possibility of setting entity class name
 *
 * @see IEntityClassAwareRepository
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
class Repository extends ParentRepository implements IEntityClassAwareRepository
{
    private static $entityClassNames = [];

    public function setEntityClassName(string $entityClassName)
    {
        $this->entityClassName = $entityClassName;
        return $this;
    }

    public function getEntityMetadata(string $entityClass = null): EntityMetadata
    {
        if ($entityClass !== null) {
            return parent::getEntityMetadata($entityClass);
        } else {
            return $this->getModel()->getMetadataStorage()->get($this->entityClassName ?: static::getEntityClassNames()[0]);
        }
    }

    public static function setEntityClassNames(array $entityClassNames)
    {
        self::$entityClassNames = array_values($entityClassNames);
    }

    public static function getEntityClassNames(): array
    {
        return self::$entityClassNames;
    }
}