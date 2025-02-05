<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Lib\Interfaces\ORM;
use Codeception\Lib\Interfaces\RequiresPackage;
use Codeception\Module;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\ObjectFactory as BaseFactory;
use Zenstruck\Foundry\Object\Proxy;

/**
 * Foundry Module allows you to easily generate and create test data using [**Foundry**](https://github.com/zenstruck/foundry).
 * Foundry uses Doctrine ORM to define, save and cleanup data. Thus, should be used with Doctrine Module or Framework modules.
 *
 * This module requires packages installed:
 *
 * ```json
 * {
 *  "zenstruck/foundry": "^1.36 || ^2.0",
 * }
 * ```
 *
 * For Foundry v1.x:
 * ```yaml
 * modules:
 *     enabled:
 *         - Foundry:
 *             depends: Doctrine2
 *             factories:
 *                 - \App\Factory\UserFactory
 *             cleanup: true
 *         - Symfony:
 *             app_path: 'src'
 *             environment: 'test'
 *         - Doctrine2:
 *             depends: Symfony
 *             cleanup: true
 * ```
 *
 * For Foundry v2.x, additional setup is required:
 * 1. Install DAMA doctrine test bundle: `composer require --dev dama/doctrine-test-bundle`
 * 2. Add to codeception.yml:
 * ```yaml
 * extensions:
 *     enabled:
 *         - DAMA\DoctrineTestBundle\Codeception\Extension
 * ```
 */
class Foundry extends Module implements DependsOnModule, RequiresPackage
{
    protected array $config = [
        'cleanup' => false,
        'factories' => null
    ];

    protected string $dependencyMessage = <<<EOF
        ORM module (like Doctrine2) or Framework module with ActiveRecord support is required:
        --
        modules:
            enabled:
                - Foundry:
                    depends: Doctrine2
        --
    EOF;

    public Factory $foundry;
    protected bool $isVersion2;

    /**
     * ORM module on which we depend on.
     */
    public ORM|Module $ormModule;

    public function __construct($moduleContainer, $config = null)
    {
        parent::__construct($moduleContainer, $config);
        $this->isVersion2 = $this->isFoundryVersion2();
    }

    protected function isFoundryVersion2(): bool
    {
        return class_exists('Zenstruck\Foundry\Object\Proxy');
    }

    public function _afterSuite(): void
    {
        if ($this->getCleanupConfig() === false) {
            return;
        }

        if (!$this->isVersion2) {
            $this->debugSection('Foundry', 'Resetting database schema.');
            $databaseResetter = 'Zenstruck\Foundry\Test\DatabaseResetter';
            if (class_exists($databaseResetter)) {
                $databaseResetter::resetSchema($this->getSymfonyKernel());
            }
        }
    }

    public function _beforeSuite($settings = []): void
    {
        $this->debugSection('Foundry', 'Booting foundry.');
        /** @var ContainerInterface $container */
        $container = $this->getSymfonyContainer();

        if ($this->isVersion2) {
            Factory::boot($container);
        } else {
            $testState = 'Zenstruck\Foundry\Test\TestState';
            if (class_exists($testState)) {
                $testState::bootFromContainer($container);
            }
        }
    }

    public function _depends(): array
    {
        return [
            'Codeception\Lib\Interfaces\ORM' => $this->dependencyMessage,
        ];
    }

    public function _inject(ORM $orm): void
    {
        $this->ormModule = $orm;
    }

    public function _requires(): array
    {
        if ($this->isVersion2) {
            return [
                'Zenstruck\Foundry\Factory' => '"zenstruck/foundry": "^1.36 || ^2.0"',
                'Zenstruck\Foundry\Object\Proxy' => '"zenstruck/foundry": "^2.0"',
                "dama/doctrine-test-bundle" => "^8.2"
            ];
        }

        return [
            'Zenstruck\Foundry\Factory' => '"zenstruck/foundry": "^1.36 || ^2.0"',
            'Zenstruck\Foundry\Object\Proxy' => '"zenstruck/foundry": "^2.0"',
            'Zenstruck\Foundry\Test\DatabaseResetter' => '"zenstruck/foundry": "^1.36"',
        ];
    }

    protected function getCleanupConfig(): bool
    {
        return $this->config['cleanup'] && $this->ormModule->_getConfig('cleanup');
    }

    /**
     * @param Proxy[] $proxies
     * @return object[]
     */
    protected function getEntitiesByProxies(array $proxies): array
    {
        $entities = [];
        foreach ($proxies as $proxy) {
            $entities[] = $proxy->object();
        }
        return $entities;
    }

    protected function getFactoryClassByEntityClass(string $entity): ?string
    {
        foreach ($this->config['factories'] as $factory) {
            try {
                $modelFactory = new ReflectionClass($factory);
                $getClassMethod = $modelFactory->getMethod('getClass');
                $entityName = $getClassMethod->invoke(null);
                if ($entity === $entityName) {
                    return $factory;
                }
            } catch (ReflectionException $e) {
                $this->fail($e->getMessage());
            }
        }

        return null;
    }

    protected function createFactory(string $factoryClass, array $attributes = []): BaseFactory
    {
        if ($this->isVersion2) {
            return new $factoryClass($attributes);
        }
        return $factoryClass::new($attributes);
    }

    public function onReconfigure($settings = []): void
    {
        if ($this->getCleanupConfig() && !$this->isVersion2) {
            $databaseResetter = 'Zenstruck\Foundry\Test\DatabaseResetter';
            if (class_exists($databaseResetter)) {
                $databaseResetter::resetSchema($this->getSymfonyKernel());
            }
        }
        $this->_beforeSuite($settings);
    }

    protected function getModuleSymfony(): ?Symfony
    {
        try {
            /** @var Symfony $symfonyModule */
            $symfonyModule = $this->getModule('Symfony');
            return $symfonyModule;
        } catch (Exception $exception) {
            return null;
        }
    }

    protected function getSymfonyContainer(): SymfonyContainerInterface
    {
        return $this->getModuleSymfony()->_getContainer();
    }

    protected function getSymfonyKernel(): KernelInterface
    {
        return $this->getModuleSymfony()->kernel;
    }

    /**
     * Generates and saves a record.
     */
    public function have(string $entity, array $attributes = []): object
    {
        $factoryClass = $this->getFactoryClassByEntityClass($entity);
        /** @var BaseFactory $factory */
        $factory = $this->createFactory($factoryClass, $attributes);
        return $factory->create()->object();
    }

    /**
     * Generates and saves multiple records.
     */
    public function haveMultiple(string $entity, int $times, array $attributes = []): array
    {
        $factoryClass = $this->getFactoryClassByEntityClass($entity);
        /** @var BaseFactory $factory */
        $factory = $this->createFactory($factoryClass);
        $proxies = $factory->createMany($times, $attributes);
        return $this->getEntitiesByProxies($proxies);
    }

    /**
     * Generates a record instance without persisting.
     */
    public function make(string $entity, array $attributes = []): object
    {
        $factoryClass = $this->getFactoryClassByEntityClass($entity);
        /** @var BaseFactory $factory */
        $factory = $this->createFactory($factoryClass);
        $memoryFactory = $factory->withoutPersisting();
        return $memoryFactory->create($attributes)->object();
    }

    /**
     * Generates multiple record instances without persisting.
     */
    public function makeMultiple(string $entity, int $times, array $attributes = []): array
    {
        $factoryClass = $this->getFactoryClassByEntityClass($entity);
        /** @var BaseFactory $factory */
        $factory = $this->createFactory($factoryClass);
        $memoryFactory = $factory->withoutPersisting();
        $proxies = $memoryFactory->createMany($times, $attributes);
        return $this->getEntitiesByProxies($proxies);
    }
}
