<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Exception\ServiceException;
use Meanbee\Magedbm2\Service\Storage\Data\File;
use Meanbee\Magedbm2\Service\StorageInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LsCommand extends BaseCommand
{
    const RETURN_CODE_STORAGE_ERROR = 1;

    const ARG_PROJECT = "project";

    /** @var StorageInterface */
    protected $storage;
    /**
     * @var StorageInterface
     */
    private $dataStorage;

    public function __construct(StorageInterface $storage, StorageInterface $dataStorage)
    {
        parent::__construct();

        $this->storage = $storage;
        $this->storage->setPurpose(StorageInterface::PURPOSE_STRIPPED_DATABASE);

        $this->dataStorage = $dataStorage;
        $this->dataStorage->setPurpose(StorageInterface::PURPOSE_ANONYMISED_DATA);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName("ls")
            ->setDescription("List available projects or backup files.")
            ->addArgument(
                self::ARG_PROJECT,
                InputArgument::OPTIONAL,
                "Project identifier."
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

        if ($this->storage->validateConfiguration()) {
            $this->output->writeln("Storage: Stripped Databases");
            $this->output->writeln("========================================");
            $this->renderStorage($this->storage, $input, $output);
            $this->output->writeln('');
        }

        if ($this->dataStorage->validateConfiguration()) {
            $this->output->writeln("Storage: Data Exports");
            $this->output->writeln("========================================");
            $this->renderStorage($this->dataStorage, $input, $output);
        }

        return static::RETURN_CODE_NO_ERROR;
    }

    /**
     * Return the file information as a string.
     *
     * @param File $file
     * @param int  $line_length Pad the output to fit the specified length.
     *
     * @return string
     */
    protected function renderFile(File $file, $line_length = 40)
    {
        $formatted_size = $file->size / (1024 * 1024);
        $formatted_size = round($formatted_size, ($formatted_size < 1) ? 1 : 0);
        $formatted_size = sprintf("%sMB", $formatted_size);

        $line = str_pad($file->name, $line_length - strlen($formatted_size)) . $formatted_size;

        return $line;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function renderStorage(StorageInterface $storage, InputInterface $input, OutputInterface $output): int
    {
        $project = $input->getArgument(self::ARG_PROJECT);

        if (!$project) {
            try {
                $projects = $storage->listProjects();
            } catch (ServiceException $e) {
                $output->writeln(sprintf(
                    "<error>Failed to retrieve available projects: %s</error>",
                    $e->getMessage()
                ));

                return static::RETURN_CODE_STORAGE_ERROR;
            }

            $output->writeln(array_merge([
                "Projects",
                "========================================",
            ], $projects));

            return static::RETURN_CODE_NO_ERROR;
        }

        try {
            $files = $storage->listFiles($project);
        } catch (ServiceException $e) {
            $output->writeln(sprintf(
                "<error>Failed to retrieve available files for %s: %s</error>",
                $project,
                $e->getMessage()
            ));

            return static::RETURN_CODE_STORAGE_ERROR;
        }

        $output->writeln([
            sprintf("Files for '%s'", $project),
            "========================================",
        ]);

        foreach ($files as $file) {
            $output->writeln($this->renderFile($file));
        }

        if (empty($files)) {
            $output->writeln("[No files available]");
        }

        return static::RETURN_CODE_NO_ERROR;
    }
}
