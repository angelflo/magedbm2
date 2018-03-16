<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Application\ConfigInterface;
use Meanbee\Magedbm2\Service\Anonymiser\Anonymiser;
use Meanbee\Magedbm2\Shell\Command\Gzip;
use Meanbee\Magedbm2\Shell\Command\Mysqldump;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends BaseCommand
{
    const NAME            = 'export';
    const ARG_OUTPUT_FILE = 'output-file';

    /**
     * @var ConfigInterface
     */
    private $config;


    /**
     * @param ConfigInterface $config
     * @throws \Symfony\Component\Console\Exception\LogicException
     */
    public function __construct(ConfigInterface $config)
    {
        parent::__construct(self::NAME);
        $this->config = $config;
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

        $anonymiser = new Anonymiser();
        $anonymiser->setLogger($this->getLogger());

        $config = $this->config->get('anonymizer');

        $tables = [
            'eav_entity_type',
            'eav_attribute'
        ];

        foreach ($config['tables'] as $tableConfig) {
            $tableName = $tableConfig['name'];

            foreach ($tableConfig['columns'] as $columnName => $formatter) {
                $anonymiser->addColumnRule($tableName, $columnName, $formatter);
            }

            $tables[] = $tableName;
        }

        foreach ($config['eav'] as $eavConfig) {
            $entityName = $eavConfig['entity'];

            foreach ($eavConfig['attributes'] as $attributeName => $formatter) {
                $anonymiser->addAttributeRule($entityName, $attributeName, $formatter);
            }

            foreach (['datetime', 'decimal', 'int', 'text', 'varchar'] as $type) {
                $tables[] = sprintf('%s_entity_%s', $entityName, $type);
            }
        }

        $tables = array_unique($tables);

        $exportFile = $this->generateTemporaryFile();
        $temporaryFileName = $this->generateTemporaryFile();
        $outputFileName = $this->input->getArgument(self::ARG_OUTPUT_FILE);

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

        $this->getLogger()->info('Dump completed');

        $anonymiser->processFile($exportFile, $temporaryFileName);

        unlink($exportFile);

        $this->getLogger()->info('Anonymised dump at ' . $temporaryFileName);

        $gzip = (new Gzip())
            ->argument('-9')
            ->argument('--force')
            ->argument('--to-stdout')
            ->argument($temporaryFileName)
            ->output($outputFileName);

        $gzipProcess = $gzip->toProcess();

        $this->getLogger()->debug($gzip->toString());

        $gzipProcess->start();
        $gzipProcess->wait();

        unlink($temporaryFileName);

        $this->output->writeln("Exported to $outputFileName");

        return static::RETURN_CODE_NO_ERROR;
    }

    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Create an anonymised data export of sensitive tables');

        $this->addArgument(self::ARG_OUTPUT_FILE, InputArgument::REQUIRED, 'Location to output the anonymised export');
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
}
