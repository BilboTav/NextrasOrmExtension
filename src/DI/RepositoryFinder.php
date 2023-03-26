<?php
declare(strict_types=1);

namespace Bilbofox\Nextras\Orm\DI;

use Bilbofox\Nextras\Orm\IEntityClassAwareRepository;
use Bilbofox\Nextras\Orm\ITableNameAwareMapper;
use Bilbofox\Nextras\Orm\Repository as BilbofoxNextrasOrmRepository;
use DirectoryIterator;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\DI\NotAllowedDuringResolvingException;
use Nette\PhpGenerator\Literal;
use Nextras\Orm\Bridges\NetteDI\IRepositoryFinder;
use Nextras\Orm\Bridges\NetteDI\OrmExtension;
use Nextras\Orm\Bridges\NetteDI\RepositoryLoader;
use Nextras\Orm\Entity\IEntity;
use Nextras\Orm\Mapper\IMapper;
use Nextras\Orm\Mapper\Mapper as NextrasOrmMapper;
use Nextras\Orm\Repository\IdentityMap;
use Nextras\Orm\Repository\IRepository;
use Nextras\Orm\Repository\Repository as NextrasOrmRepository;
use ReflectionClass;

/**
 * Repository finder which iterates over directories with entity classes files
 * From there it maps through PSR-4 logic into entity, repository and mapper classes
 *
 * Repositories and mappers are automatically registered into DI through mapic logic
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
class RepositoryFinder implements IRepositoryFinder
{
    /** @var ContainerBuilder */
    private $builder;

    /** @var OrmExtension */
    private $extension;

    /**
     *
     * @param string $modelClass
     * @param ContainerBuilder $containerBuilder
     * @param OrmExtension $extension
     */
    public function __construct(string $modelClass, ContainerBuilder $containerBuilder, OrmExtension $extension)
    {
        $this->builder = $containerBuilder;
        $this->extension = $extension;
    }

    /**
     *
     * @param string $pattern
     * @param string $value
     * @return string
     */
    final protected static function replaceWildcard(string $pattern, string $value): string
    {
        return str_replace('*', $value, $pattern);
    }

    final protected static function getClassParents(string $className): array
    {
        return class_exists($className) ? array_merge(class_parents($className), [$className]) : [];
    }

    /**
     * Searches for entity classes for which services (repositories, mappers) will be registered
     *
     * @param array $config
     * @return array
     * @throws NotAllowedDuringResolvingException
     */
    protected function createEntitiesMapTable(array & $config): array
    {
        $entitiesMapTable = [];
        $entityDirs = $config['entityDirs'];
        foreach ($entityDirs as $entityDir) {
            $entityDirIterator = new DirectoryIterator($entityDir);
            foreach ($entityDirIterator as $entityFile) {
                if ($entityFile->isFile() && $entityFile->getExtension() === 'php') {
                    $entityName = $entityFile->getBasename('.php');
                    $entityClass = self::replaceWildcard($config['entityClasses'], $entityName);
                    $classReflection = new ReflectionClass($entityClass);

                    if ($classReflection->isInstantiable()) {
                        if (!$classReflection->implementsInterface(IEntity::class)) {
                            throw new NotAllowedDuringResolvingException(sprintf('Found entity class "%s" must implement "%s" interface',
                                        $entityClass, IEntity::class));
                        }

                        $entitiesMapTable[$entityName] = $entityClass;
                    }
                }
            }
        }

        return $entitiesMapTable;
    }

    /**
     * Builds default mapper definition
     *
     * Override this method for extension
     *
     * @param array $config
     * @return ServiceDefinition
     */
    protected function createMapperDefinition(array & $config): ServiceDefinition
    {
        extract($config);

        $mapperDefinition = new ServiceDefinition;
        if (class_exists($mapperClass)) {
            // Autowiring is enabled + unique mapper class exists
            $mapperDefinition->setType($mapperClass)->setAutowired(true);

            $mapperClassParents = self::getClassParents($mapperClass);
        } else {
            // Generic mapper - no autowiring to specific type
            $mapperDefinition
                ->setType(IMapper::class)
                ->setFactory($config['mapperGenericFactory'])
                ->setAutowired(false);
            $mapperClassParents = self::getClassParents($config['mapperGenericFactory']);
        }

        // Continuing extra logic for standard Nextras mapper using cache...
        if (in_array(NextrasOrmMapper::class, $mapperClassParents, true)) {
            $cacheDefinition = new Statement($this->extension->prefix('@cache') . '::derive', ['mapper']);
            $mapperDefinition->setArguments([
                'cache' => $cacheDefinition,
            ]);
        }

        return $mapperDefinition;
    }

    /**
     * Builds default repository definition
     *
     * Override this method for extension
     *
     * @param array $config
     * @return ServiceDefinition
     */
    protected function createRepositoryDefinition(array & $config): ServiceDefinition
    {
        extract($config);

        $repositoryDefinition = new ServiceDefinition;
        if (class_exists($repositoryClass)) {
            // Autowiring is enabled + unique repository class exists
            $repositoryDefinition->setType($repositoryClass)
                ->setAutowired(true);

            $repositoryClassParents = self::getClassParents($repositoryClass);
        } else {
            // Generic repository - no autowiring to specific type
            $repositoryDefinition->setType(IRepository::class)
                ->setFactory($config['repositoryGenericFactory'])
                ->setAutowired(false);
            $repositoryClassParents = self::getClassParents($config['repositoryGenericFactory']);
        }

        // Continuing extra logic for standard Nextras repository with mapper...
        if (in_array(NextrasOrmRepository::class, $repositoryClassParents, true)) {
            $config['mapperClass'] = $mapperClass = self::replaceWildcard($config['mapperClasses'], $entityName);

            $mapperServiceName = $this->builder->getByType($mapperClass);
            if (!$mapperServiceName) {
                // Mapper NOT registered in DI - lets create default one...
                $mapperServiceName = $this->extension->prefix('mapper.' . $entityNameLower);
                $mapperDefinition = $this->createMapperDefinition($config);
                $this->builder->addDefinition($mapperServiceName, $mapperDefinition);
            } else {
                // Mapper already registered in DI
                $mapperDefinition = $this->builder->getDefinition($mapperServiceName);
            }

            if ($config['mapperTableNameConventions'] === 'underscore') {
                $tableName = new Literal(sprintf('Nextras\Orm\StorageReflection\StringHelper::underscore(\'%s\')',
                        $entityNameLower));
            } else {
                $tableName = $entityNameLower;
            }
            // for ~ ITableNameAwareMapper -> sets table name
            $mapperDefinition->addSetup("if (? instanceof " . ITableNameAwareMapper::class . ") {\n\t?->setTableName(?);\n}",
                ['@self', '@self', $tableName]);

            $repositoryDefinition->setArguments([
                'mapper' => $mapperDefinition,
            ]);
        }

        return $repositoryDefinition;
    }

    /**
     * Override this method for extension
     *
     * @return void
     */
    protected function setupRepositories(): void
    {
        $mainConfig = $this->extension->getConfig();

        $entityConfig = [
            'entityDirs' => (array) $mainConfig->entity->dirs,
            'entityClasses' => $mainConfig->entity->classMapping,
        ];
        $mapperConfig = [
            'mapperClasses' => $mainConfig->mapper->classMapping,
            'mapperGenericFactory' => $mainConfig->mapper->genericFactory,
            'mapperTableNameConventions' => $mainConfig->mapper->tableNameConventions ?? 'auto',
        ];
        $repositoryConfig = [
            'repositoryClasses' => $mainConfig->repository->classMapping,
            'repositoryGenericFactory' => $mainConfig->repository->genericFactory,
        ];

        $entitiesMapTable = $this->createEntitiesMapTable($entityConfig);
        // Same as does Nextras\Orm\Model\Model::getConfiguration(), but for our use
        $modelConfiguration = [
            [], [], [],
        ];
        $repositoryNamesMap = [];

        foreach ($entitiesMapTable as $entityName => $entityClass) {
            // Merge with main config
            $repositoryClass = self::replaceWildcard($repositoryConfig['repositoryClasses'], $entityName);

            $entityNameLower = lcfirst($entityName);
            $config = [
                'entityName' => $entityName,
                'entityNameLower' => $entityNameLower,
                'entityClass' => $entityClass,
                'repositoryClass' => $repositoryClass,
                ] + $entityConfig + $mapperConfig + $repositoryConfig;

            $repositoryServiceName = $this->builder->getByType($repositoryClass);
            if (!$repositoryServiceName) {
                // Repository NOT registered in DI - lets create default one...
                $repositoryServiceName = $this->extension->prefix('repository.' . $entityNameLower);
                $repositoryDefinition = $this->createRepositoryDefinition($config);
                $this->builder->addDefinition($repositoryServiceName, $repositoryDefinition);
            } else {
                // Repository already registered in DI
                $repositoryDefinition = $this->builder->getDefinition($repositoryServiceName);
            }

            $repositoryDefinition
                ->addSetup('setModel', [$this->extension->prefix('@model')])
                // for ~ IEntityClassAwareRepository -> sets entity class name
                ->addSetup("if (? instanceof " . IEntityClassAwareRepository::class . ") {\n\t?->setEntityClassName(?);\n}",
                    ['@self', '@self', $entityClass])
                ->addTag('repositoryClass', $repositoryClass)
                ->addTag('entityClass', $entityClass)
                ->addTag('nette.inject', true);

            $repositoryNamesMap[$repositoryClass] = $repositoryServiceName;

            $modelConfiguration[0][$repositoryClass] = true;
            $modelConfiguration[1][$entityNameLower] = $repositoryClass;
            $modelConfiguration[2][$entityClass] = $repositoryClass;
        }

        $this->builder->addDefinition($this->extension->prefix('repositoryLoader'))
            ->setClass(RepositoryLoader::class)
            ->setArguments([
                'repositoryNamesMap' => $repositoryNamesMap,
        ]);

        // Override services definitions from OrmExtension
        // Bit of a hack... or not? Hmmmmm... probably not :-)
        $this->builder->getDefinition($this->extension->prefix('model'))
                ->getFactory()->arguments['configuration'] = $modelConfiguration;

        $this->builder->getDefinition($this->extension->prefix('metadataStorage'))
                ->getFactory()->arguments['entityClassesMap'] = $modelConfiguration[2];
    }

    /**
     * Override setupRepositories for extension
     * @see setupRepositories
     * @see OrmExtension
     *
     * @return array|null
     */
    final public function loadConfiguration(): ?array
    {
        // Return array for launch of setup model and metadataStorage in extension
        // Note: this is historical - no longer needed, since there is own extended OrmExtension class now
        return [];
    }

    /**
     * Override setupRepositories for extension
     * @see setupRepositories
     * @see OrmExtension
     * @see IdentityMap
     *
     * @return array|null
     */
    final public function beforeCompile(): ?array
    {
        $this->setupRepositories();

        // This is needed to bypass IdentityMap::check() method which uses static method logic
        $this->extension->getInitialization()->addBody(
            sprintf('%s::setEntityClassNames($this->tags[\'entityClass\']);', BilbofoxNextrasOrmRepository::class),
        );

        // Return null, everything is setup and going
        return null;
    }
}