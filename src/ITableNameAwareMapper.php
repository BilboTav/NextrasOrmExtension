<?php
declare(strict_types=1);

namespace Bilbofox\Nextras\Orm;

/**
 * Mapper which have table name given through setter method
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
interface ITableNameAwareMapper
{

    /**
     * Set mapper table name
     * $tableName should be considered immutable after mapper creation!
     *
     * @param string $tableName
     */
    public function setTableName(string $tableName);
}