<?php
declare(strict_types=1);

namespace Bilbofox\Nextras\Orm\DI;

use Bilbofox\Nextras\Orm\Mapper;
use Bilbofox\Nextras\Orm\Repository;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nextras\Orm\Bridges\NetteDI\OrmExtension as OrmExtensionParent;
use Nextras\Orm\Model\Model;

/**
 * Alternative OrmExtension - working with internal special repository finder
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
class OrmExtension extends OrmExtensionParent
{

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'model' => Expect::string()->required(),
            'entity' => Expect::structure([
                'dirs' => Expect::anyOf(Expect::string(), Expect::array())->required(),
                'classMapping' => Expect::string('App\Entity\*Entity'),
            ]),
            'mapper' => Expect::structure([
                'classMapping' => Expect::string('App\Mapper\*Mapper'),
                'tableNameConventions' => Expect::anyOf('auto', 'underscore'),
                'genericFactory' => Expect::string(Mapper::class),
            ]),
            'repository' => Expect::structure([
                'classMapping' => Expect::string('App\Repository\*Repository'),
                'genericFactory' => Expect::string(Repository::class),
            ]),
        ]);
    }

    public function loadConfiguration()
    {
        $this->builder = $this->getContainerBuilder();

        $this->repositoryFinder = new RepositoryFinder($this->config->model, $this->builder, $this);
        $repositories = $this->repositoryFinder->loadConfiguration();

        $this->setupCache();
        $this->setupDependencyProvider();
        $this->setupDbalMapperDependencies();
        $this->setupMetadataParserFactory();

        $repositoriesConfig = Model::getConfiguration($repositories);
        $this->setupMetadataStorage($repositoriesConfig[2]);
        $this->setupModel($this->config->model, $repositoriesConfig);
    }
}