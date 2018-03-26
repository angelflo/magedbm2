<?php

namespace Meanbee\Magedbm2\Command;

use Meanbee\Magedbm2\Application\ConfigInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XMLReader;

class ImportCommand extends BaseCommand
{
    const NAME = 'import';

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

        $this->readXml();

        return static::RETURN_CODE_NO_ERROR;
    }

    protected function configure()
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Import a magedbm2-generated anonymised data export.');
    }

    private function readXml()
    {
        $xml = new XMLReader();
        $xml->open('test.xml');

        $table = null;
        $row = [];

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
                $row = [];
            }
        }
    }

    private function submitRow($currentTable, $row)
    {
        $columns = array_map(function ($item) {
            return '`' . $item . '`';
        }, array_keys($row));

        $values = array_map(function ($item) {
            if ($item === null) {
                return 'NULL';
            }

            if (is_numeric($item)) {
                return $item;
            }

            $item = str_replace("\n", '\n', $item);

            return "'" . addslashes($item) . "'";
        }, array_values($row));

        echo 'INSERT IGNORE INTO `' . $currentTable . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');' . "\n";
    }
}
