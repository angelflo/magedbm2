<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Application\ConfigInterface;
use Meanbee\Magedbm2\Service\Anonymiser\Anonymiser;
use Meanbee\Magedbm2\Shell\Command\Gzip;
use Meanbee\Magedbm2\Shell\Command\Mysqldump;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends BaseCommand
{
    const NAME            = 'export';
    const ARG_OUTPUT_FILE = 'output-file';
    const OPT_NO_COMPRESS = 'no-compress';

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var Anonymiser
     */
    private $anonymiser;

    /**
     * @param ConfigInterface $config
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct(ConfigInterface $config)
    {
        parent::__construct(self::NAME);
        $this->config = $config;
        $this->anonymiser = new Anonymiser();
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

        $this->anonymiser->setLogger($this->getLogger());

        $tablesToExport = $this->configureAnonymiser();

        $outputFileName = $this->input->getArgument(self::ARG_OUTPUT_FILE);
        $temporaryFileName = $this->generateTemporaryFile();

        $exportFile = $this->generateDatabaseExport($tablesToExport);

        $this->anonymiser->processFile($exportFile, $temporaryFileName);

        unlink($exportFile);

        $this->getLogger()->info('Anonymised dump at ' . $temporaryFileName);

        if ($this->shouldCompress()) {
            $this->compressFile($temporaryFileName, $outputFileName);

            unlink($temporaryFileName);
        } else {
            rename($temporaryFileName, $outputFileName);
        }

        $this->output->writeln("Exported to $outputFileName");

        return static::RETURN_CODE_NO_ERROR;
    }

    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Create an anonymised data export of sensitive tables');

        $this->addArgument(self::ARG_OUTPUT_FILE, InputArgument::REQUIRED, 'Location to output the anonymised export.');
        $this->addOption(self::OPT_NO_COMPRESS, 'Z', InputOption::VALUE_NONE, 'Disable gzipping of export file.');
    }

    /**
     *
     */
    private function getCredentialOptions()
    {
        $map = [
            'host' => $this->config->getDatabaseCredentials()->getHost(),
            'user' => $this->config->getDatabaseCredentials()->getUsername(),
            'password' => $this->config->getDatabaseCredentials()->getPassword(),
            'port' => $this->config->getDatabaseCredentials()->getPort()
        ];

        $args = [];

        foreach ($map as $key => $value) {
            if ($value) {
                $args[] = sprintf('--%s=%s', $key, $value);
            }
        }

        return $args;
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
     * @return bool
     */
    private function shouldCompress()
    {
        return ((bool) $this->input->getOption(self::OPT_NO_COMPRESS)) === false;
    }

    /**
     * @param $tables
     * @return string
     * @throws \Exception
     */
    protected function generateDatabaseExport($tables): string
    {
        $exportFile = $this->generateTemporaryFile();

        $this->getLogger()->info('Generating XML dump from database');

        $command = (new Mysqldump())
            ->arguments($this->getCredentialOptions())
            ->argument('--xml')
            ->argument($this->config->getDatabaseCredentials()->getName())
            ->arguments($tables)
            ->output($exportFile);

        $this->getLogger()->debug($command->toString());

        $process = $command->toProcess();
        $process->start();
        $process->wait();

        if ($process->getExitCode() !== 0) {
            throw new \RuntimeException(sprintf(
                'Mysqldump: Process failed with code %s',
                $process->getExitCode()
            ));
        }

        $this->getLogger()->info('Dump completed');

        if (!file_exists($exportFile)) {
            throw new \RuntimeException('Dump file was not created');
        }

        if (!is_readable($exportFile)) {
            throw new \RuntimeException('Created dump file was not readable');
        }
        return $exportFile;
    }

    /**
     * @param $inputFile
     * @param $outputFile
     */
    protected function compressFile($inputFile, $outputFile): void
    {
        $gzip = (new Gzip())
            ->argument('-9')
            ->argument('--force')
            ->argument('--to-stdout')
            ->argument($inputFile)
            ->output($outputFile);

        $gzipProcess = $gzip->toProcess();

        $this->getLogger()->debug($gzip->toString());

        $gzipProcess->start();
        $gzipProcess->wait();
    }

    /**
     * @return array
     */
    protected function configureAnonymiser(): array
    {
        $config = $this->config->get('anonymizer');

        $tables = [
            'eav_entity_type',
            'eav_attribute'
        ];

        foreach ($config['tables'] as $tableConfig) {
            $tableName = $tableConfig['name'];

            foreach ($tableConfig['columns'] as $columnName => $formatter) {
                $newTables = $this->anonymiser->addColumnRule($tableName, $columnName, $formatter);

                foreach ($newTables as $table) {
                    $tables[] = $table;
                }
            }

            $tables[] = $tableName;
        }

        foreach ($config['eav'] as $eavConfig) {
            $entityName = $eavConfig['entity'];

            foreach ($eavConfig['attributes'] as $attributeName => $formatter) {
                $newTables = $this->anonymiser->addAttributeRule($entityName, $attributeName, $formatter);

                foreach ($newTables as $table) {
                    $tables[] = $table;
                }
            }
        }

        return array_unique($tables);
    }
}
