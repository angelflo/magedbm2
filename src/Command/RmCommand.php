<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Exception\ServiceException;
use Meanbee\Magedbm2\Service\StorageInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RmCommand extends BaseCommand
{
    const RETURN_CODE_STORAGE_ERROR = 1;

    const ARG_PROJECT = "project";
    const ARG_FILE = "file";

    /** @var StorageInterface */
    protected $storage;

    public function __construct(StorageInterface $storage)
    {
        parent::__construct();

        $this->storage = $storage;

        $this->ensureServiceConfigurationValidated('storage', $this->storage);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName("rm")
            ->setDescription("Delete uploaded backup files.")
            ->addArgument(
                self::ARG_PROJECT,
                InputArgument::REQUIRED,
                "Project identifier."
            )
            ->addArgument(
                self::ARG_FILE,
                InputArgument::REQUIRED,
                "File to delete."
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (($parentExitCode = parent::execute($input, $output)) !== self::RETURN_CODE_NO_ERROR) {
            return $parentExitCode;
        }

        $project = $input->getArgument(self::ARG_PROJECT);
        $file = $input->getArgument(self::ARG_FILE);

        try {
            $this->storage->delete($project, $file);
        } catch (ServiceException $e) {
            $output->writeln(sprintf(
                "<error>Failed to delete '%s' from '%s': %s",
                $file,
                $project,
                $e->getMessage()
            ));

            return static::RETURN_CODE_STORAGE_ERROR;
        }

        $output->writeln(sprintf(
            "<info>Deleted '%s' from '%s'.</info>",
            $file,
            $project
        ));

        return static::RETURN_CODE_NO_ERROR;
    }
}
