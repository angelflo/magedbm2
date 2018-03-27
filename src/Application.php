<?php

namespace Meanbee\Magedbm2;

use Composer\Autoload\ClassLoader;
use Meanbee\Magedbm2\Application\ConfigInterface;
use Meanbee\Magedbm2\Service\ConfigurableServiceInterface;
use Meanbee\Magedbm2\Service\DatabaseInterface;
use Meanbee\Magedbm2\Service\FilesystemInterface;
use Meanbee\Magedbm2\Service\StorageInterface;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Application
 * @package Meanbee\Magedbm2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends \Symfony\Component\Console\Application
{

    const APP_NAME = "magedbm2";
    const APP_VERSION = "1.0.0";

    /** @var ClassLoader $autoloader */
    protected $autoloader;

    /** @var ConfigInterface $config */
    protected $config;

    /** @var array */
    protected $services;

    public function __construct(ClassLoader $autoloader = null)
    {
        parent::__construct(static::APP_NAME, static::APP_VERSION);

        $this->autoloader = $autoloader;
    }

    /**
     * Set the autoloader.
     *
     * @param ClassLoader $autoloader
     *
     * @return $this
     */
    public function setAutoloader(ClassLoader $autoloader)
    {
        $this->autoloader = $autoloader;

        return $this;
    }

    /**
     * Get the autoloader.
     *
     * @return ClassLoader
     */
    public function getAutoloader()
    {
        return $this->autoloader;
    }

    /**
     * Get the application config.
     *
     * @return ConfigInterface
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get a service singleton instance.
     *
     * @param string $name
     *
     * @return DatabaseInterface|StorageInterface|FilesystemInterface|null
     */
    public function getService($name)
    {
        if (!isset($this->services[$name])) {
            throw new LogicException(sprintf("Requested service '%s' not found.", $name));
        }

        return $this->services[$name];
    }

    /**
     * @param $name
     * @return mixed
     */
    public function createService($name, OutputInterface $output = null)
    {
        $serviceMap = [
            'storage' => $this->getStorageImplementation(),
            'database' => $this->getDatabaseImplementation(),
            'filesystem' => $this->getFileSystemImplementation(),
        ];

        if (array_key_exists($name, $serviceMap)) {
            $instance = $serviceMap[$name];
        }

        if ($output && $instance instanceof LoggerAwareInterface) {
            $instance->setLogger(new ConsoleLogger($output));
        }

        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->init($input, $output);

        return parent::doRun($input, $output);
    }

    /**
     * Initialise the application, including configuration, services and available commands.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * return void
     */
    public function init(InputInterface $input, OutputInterface $output)
    {
        $this->initConfig($input, $output);
        $this->initServices($output);
        $this->initCommands($output);
    }

    /**
     * Initialise the application config.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function initConfig(InputInterface $input, OutputInterface $output)
    {
        $this->config = new Application\Config\Combined($this, $input, new Yaml());
        $this->config->setLogger(new ConsoleLogger($output));
    }

    /**
     * Initialise the available services.
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function initServices(OutputInterface $output)
    {
        $this->services = [
            'storage' => $this->createService('storage', $output),
            'database' => $this->createService('database', $output),
            'filesystem' => $this->createService('filesystem', $output),
        ];

        foreach ($this->services as $service) {
            if ($service instanceof LoggerAwareInterface) {
                $service->setLogger(new ConsoleLogger($output));
            }
        }
    }

    /**
     * @return StorageInterface
     */
    protected function getStorageImplementation()
    {
        switch ($this->config->getServicePreference('storage')) {
            case 'local':
                return new Service\Storage\Local();
            case 's3':
            default:
                return new Service\Storage\S3($this);
        }
    }

    /**
     * @return DatabaseInterface
     */
    protected function getDatabaseImplementation()
    {
        switch ($this->config->getServicePreference('database')) {
            case 'shell':
            default:
                return new Service\Database\Shell($this, $this->getConfig());
        }
    }

    /**
     * @return FilesystemInterface
     */
    protected function getFileSystemImplementation()
    {
        switch ($this->config->getServicePreference('filesystem')) {
            default:
                return new Service\Filesystem\Simple();
        }
    }

    /**
     * Initialise the available commands.
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function initCommands(OutputInterface $output)
    {
        $this->add(new Command\ConfigureCommand(
            $this->getConfig(),
            $this->getService("filesystem"),
            new Yaml()
        ));

        $this->add(new Command\GetCommand(
            $this->getService("database"),
            $this->createService("storage", $output),
            $this->getService("filesystem")
        ));

        $this->add(new Command\LsCommand(
            $this->createService("storage", $output),
            $this->createService("storage", $output)
        ));

        $this->add(new Command\PutCommand(
            $this->getConfig(),
            $this->getService("database"),
            $this->createService("storage", $output),
            $this->getService("filesystem")
        ));

        $this->add(new Command\RmCommand(
            $this->getService("storage")
        ));

        $this->add(new Command\ExportCommand(
            $this->getConfig(),
            $this->createService("storage", $output),
            $this->getService("filesystem")
        ));

        $this->add(new Command\ImportCommand(
            $this->getConfig(),
            $this->createService("storage", $output),
            $this->getService("filesystem")
        ));
    }
}
