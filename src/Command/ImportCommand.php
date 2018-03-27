<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Application\ConfigInterface;
use Meanbee\Magedbm2\Exception\ConfigurationException;
use Meanbee\Magedbm2\Service\FilesystemInterface;
use Meanbee\Magedbm2\Service\StorageInterface;
use Meanbee\Magedbm2\Shell\Command\Gunzip;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XMLReader;

class ImportCommand extends BaseCommand
{
    const NAME            = 'import';
    const OPT_NO_PROGRESS = 'no-progress';
    const ARG_PROJECT     = "project";
    const ARG_FILE        = "file";

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var \PDOStatement[]
     */
    private $statementCache = [];

    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * @var FilesystemInterface
     */
    private $filesystem;

    /**
     * @param ConfigInterface $config
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct(ConfigInterface $config, StorageInterface $storage, FilesystemInterface $filesystem)
    {
        parent::__construct(self::NAME);
        $this->config = $config;
        $this->storage = $storage;
        $this->filesystem = $filesystem;

        $storage->setPurpose(StorageInterface::PURPOSE_ANONYMISED_DATA);

        $this->ensureServiceConfigurationValidated('storage', $this->storage);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (($parentExitCode = parent::execute($input, $output)) !== self::RETURN_CODE_NO_ERROR) {
            return $parentExitCode;
        }

        $this->readXml();

        return static::RETURN_CODE_NO_ERROR;
    }

    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Import a magedbm2-generated anonymised data export.');

        $this->addOption(
            self::OPT_NO_PROGRESS,
            null,
            InputOption::VALUE_NONE,
            'Hide the progress bar.'
        );

        $this->addArgument(
            self::ARG_PROJECT,
            InputArgument::REQUIRED,
            "Project identifier."
        );

        $this->addArgument(
            self::ARG_FILE,
            InputArgument::OPTIONAL,
            "Backup file to import. If not specified, imports the latest available file."
        );
    }

    /**
     * @throws ConfigurationException
     * @throws \Exception
     */
    private function readXml()
    {
        $this->checkDatabaseCredentials();

        $file = $this->downloadFileToImport();
        $decompressedFile = $this->decompressFile($file);
        $this->filesystem->delete($file);

        if ($this->showProgressBar()) {
            $progressBar = new ProgressBar($this->output, $this->getRowCount($decompressedFile));
        }

        $xml = new XMLReader();
        $xml->open($decompressedFile);

        $table = null;
        $row = [];

        $this->getPdo()->beginTransaction();

        while ($xml->read()) {
            $tag = $xml->name;
            $isOpeningTag = $xml->nodeType === XMLReader::ELEMENT;
            $isClosingTag = $xml->nodeType === XMLReader::END_ELEMENT;

            if ($isOpeningTag && $tag === 'table') {
                $xml->moveToAttribute('name');
                $table = $xml->value;
            }

            if ($isOpeningTag && $tag === 'column') {
                $xml->moveToAttribute('name');
                $column = $xml->value;

                $value = $xml->readInnerXml();

                $row[$column] = $value;
            }

            if ($isClosingTag && $tag === 'row') {
                $this->submitRow($table, $row);

                if ($this->showProgressBar()) {
                    $progressBar->advance();
                }

                $row = [];
            }
        }

        $xml->close();
        $this->getPdo()->commit();

        if ($this->showProgressBar()) {
            $progressBar->finish();
            $this->output->writeln('');
        }
    }

    /**
     * @param $table
     * @param $row
     */
    private function submitRow($table, $row)
    {
        $statement = $this->getStatement($table, $row);

        $result = $statement->execute(array_values($row));

        if (!$result) {
            $this->getLogger()->warning(
                'There was a problem inserting a row into ' . $table
            );
        }
    }

    private function getPdo()
    {
        if ($this->pdo === null) {
            $this->pdo = $this->config->getDatabaseCredentials()->createPDO();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    /**
     * @param $table
     * @param $row
     * @return \PDOStatement
     */
    private function getStatement($table, $row)
    {
        if (!array_key_exists($table, $this->statementCache)) {
            $this->statementCache[$table] = $this->getPdo()->prepare($this->generateSql($table, $row));
        }

        return $this->statementCache[$table];
    }

    /**
     * @param $table
     * @param $row
     * @return string
     */
    private function generateSql($table, $row)
    {
        $columns = array_map(function ($item) {
            return '`' . $item . '`';
        }, array_keys($row));

        $valuePlaceholders = trim(str_repeat('?, ', count($row)), ', ');

        return sprintf(
            'INSERT IGNORE INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            $valuePlaceholders
        );
    }

    /**
     * @throws ConfigurationException
     */
    private function checkDatabaseCredentials()
    {
        try {
            $statement = $this->getPdo()->prepare('SELECT 1');
            $statement->execute();
            $statement->fetchAll();
            $statement->closeCursor();
        } catch (\PDOException $e) {
            $this->getLogger()->emergency('Unable to validate database credentials.');
            throw new ConfigurationException(implode(' ', $this->getPdo()->errorInfo()));
        }
    }

    /**
     * @param $file
     * @return int
     */
    private function getRowCount($file)
    {
        $this->getLogger()->info("Starting row count for $file");

        $count = 0;
        $xml = new XMLReader();
        $xml->open($file);

        while ($xml->read()) {
            $tag = $xml->name;
            $isOpeningTag = $xml->nodeType === XMLReader::ELEMENT;

            if ($isOpeningTag && $tag === 'row') {
                $count++;
            }
        }

        $xml->close();

        $this->getLogger()->info("Finished row count for $file, it was $count");

        return $count;
    }

    /**
     * @return bool
     */
    private function showProgressBar()
    {
        return !$this->input->getOption(self::OPT_NO_PROGRESS);
    }

    /**
     * @return string
     */
    private function downloadFileToImport()
    {
        $project = $this->input->getArgument(self::ARG_PROJECT);
        $userSpecifiedFile = $this->input->getArgument(self::ARG_FILE);

        if (!$userSpecifiedFile) {
            $file = $this->storage->getLatestFile($project);
            $this->getLogger()->info('File to download identified as ' . $file);
        } else {
            $file = $userSpecifiedFile;
        }

        $this->getLogger()->info('Starting file download');
        $downloadLocation = $this->storage->download($project, $file);
        $this->getLogger()->info('File downloaded to ' . $downloadLocation);

        return $downloadLocation;
    }

    /**
     * Generate a temporary file.
     *
     * @return string
     * @throws \Exception
     */
    private function generateTemporaryFile()
    {
        $file = tempnam($this->config->getTmpDir(), 'export');

        if ($file === false) {
            throw new \RuntimeException('Unable to create temporary file');
        }

        return $file;
    }

    /**
     * @param $file
     * @return string
     * @throws \Exception
     */
    private function decompressFile($file)
    {
        $uncompressedFile = $this->generateTemporaryFile();

        $this->getLogger()->info("Decompressing $file to $uncompressedFile");

        $uncompress = (new Gunzip())
            ->argument('-c')
            ->argument($file)
            ->output($uncompressedFile);

        $this->getLogger()->debug($uncompress->toString());

        $uncompressProcess = $uncompress->toProcess();
        $uncompressProcess->start();
        $uncompressProcess->wait();

        return $uncompressedFile;
    }
}
