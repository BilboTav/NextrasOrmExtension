<?php
declare(strict_types=1);

namespace Bilbofox\Nextras\Orm;

use Nextras\Orm\Mapper\Mapper as ParentMapper;

/**
 * Mapper extending with possibility of setting DB table name
 *
 * @see ITableNameAwareMapper
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
class Mapper extends ParentMapper implements ITableNameAwareMapper
{

    public function setTableName(string $tableName)
    {
        $this->tableName = $tableName;
        return $this;
    }
}