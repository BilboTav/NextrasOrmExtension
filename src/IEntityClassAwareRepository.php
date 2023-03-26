<?php
declare(strict_types=1);

namespace Bilbofox\Nextras\Orm;

/**
 * Repository which have entity class given through setter method
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
interface IEntityClassAwareRepository
{

    /**
     * Sets entity class name for current repository
     * $entityClassName should be considered immutable after repository creation!
     *
     * @param string $entityClassName
     */
    public function setEntityClassName(string $entityClassName);
}