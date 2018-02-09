<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Service\ConfigurableServiceInterface;
use Meanbee\Magedbm2\Service\ConfigurationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    const RETURN_CODE_NO_ERROR = 0;
    /**
     * @var LoggerInterface
     */
    private $logger;

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output);

        return self::RETURN_CODE_NO_ERROR;
    }

    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        return $this->logger;
    }
}
