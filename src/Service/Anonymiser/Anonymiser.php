<?php

namespace Meanbee\Magedbm2\Service\Anonymiser;

use Meanbee\Magedbm2\Anonymizer\FormatterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use XMLReader;

class Anonymiser implements LoggerAwareInterface
{
    /** @var LoggerInterface */
    private $logger;

    private $tableConfig = [];
    private $eavConfig = [];
    private $formatterCache = [];
    private $faker;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->faker = \Faker\Factory::create();
    }

    /**
     * @param $table
     * @param $column
     * @param $formatter
     */
    public function addColumnRule($table, $column, $formatter)
    {
        if (!array_key_exists($table, $this->tableConfig)) {
            $this->tableConfig[$table] = [];
        }

        $this->tableConfig[$table][$column] = $formatter;
    }

    /**
     * @param $eavEntity
     * @param $attributeCode
     * @param $formatter
     */
    public function addAttributeRule($eavEntity, $attributeCode, $formatter)
    {
        if (!array_key_exists($eavEntity, $this->eavConfig)) {
            $this->eavConfig[$eavEntity] = [];
        }

        $this->eavConfig[$eavEntity][$attributeCode] = $formatter;
    }

    /**
     * @param $inputFile
     * @param $outputFile
     */
    public function processFile($inputFile, $outputFile)
    {
        $xml = new XMLReader();
        $xml->open($inputFile);

        $write = fopen($outputFile, 'wb');

        fwrite($write, '<?xml version="1.0"?>' . "\n");
        fwrite($write, '<mysqldump xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n");
        fwrite($write, '<database name="TODO">' . "\n");

        $tableName = null;

        $openTableTag = false;

        $this->getLogger()->info("Starting stream");

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'table_data') {
                $xml->moveToAttribute('name');
                $tableName = $xml->value;

                if ($tableName === 'eav_entity_type') {
                    $this->extractEntityTypes(new \SimpleXMLElement($xml->readOuterXml()));
                    $xml->next();
                    $tableName = null;
                    continue;
                }

                if ($tableName === 'eav_attribute') {
                    $this->extractAttributes(new \SimpleXMLElement($xml->readOuterXml()));
                    $xml->next();
                    $tableName = null;
                    continue;
                }

                if ($openTableTag) {
                    fwrite($write, '</table_data>' . "\n");
                }

                $openTableTag = true;

                $this->getLogger()->info("Processing $tableName");

                fwrite($write, '<table_data name="' . $tableName . '">');
            }

            if ($tableName && $xml->nodeType === XMLReader::ELEMENT && $xml->name === 'row') {
                $rowData = new \SimpleXMLElement($xml->readOuterXml());
                $xml->next();

                if (array_key_exists($tableName, $this->tableConfig)) {
                    $this->processRow($tableName, $rowData);
                }

                $rowDataXml = $rowData->asXML();
                $rowDataXml = str_replace(array(
                    '<?xml version="1.0"?>',
                    ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
                ), '', $rowDataXml);

                fwrite($write, $rowDataXml);
                continue;
            }
        }

        if ($openTableTag) {
            fwrite($write, '</table_data>' . "\n");
            $openTableTag = false;
        }

        fwrite($write, '</database>' . "\n");
        fwrite($write, '</mysqldump>' . "\n");
        fclose($write);
    }

    /**
     * @return LoggerInterface
     */
    private function getLogger()
    {
        return $this->logger;
    }

    private function extractEntityTypes($param)
    {
    }

    private function extractAttributes($param)
    {
    }

    private function processRow($tableName, $rowData)
    {
        $tableConfig = $this->tableConfig[$tableName];

        foreach ($rowData->field as $field) {
            $name = (string)$field['name'];
            $value = (string)$field;

            if ($value === '') {
                continue;
            }

            if (array_key_exists($name, $tableConfig)) {
                $formatterSpec = $tableConfig[$name];
                $newValue = $this->runFormatter($value, $formatterSpec);

                $field[0][0] = $newValue;
            }
        }
    }

    private function runFormatter($value, $formatterSpec)
    {
        if (!array_key_exists($formatterSpec, $this->formatterCache)) {
            $class = null;
            $method = null;

            if (strpos($formatterSpec, '::')) {
                list($class, $method) = explode('::', $formatterSpec);
            } else {
                $class = $formatterSpec;
            }

            if (!class_exists($class)) {
                throw new \RuntimeException(sprintf("Formatter class %s does not exist", $class));
            }

            if (in_array('Faker\Provider\Base', class_parents($class), true)) {
                $instance = new $class($this->faker);
            } else {
                $instance = new $class;
            }

            if ($method) {
                $this->formatterCache[$formatterSpec] = function ($value) use ($instance, $method) {
                    return $instance->$method();
                };
            } elseif ($instance instanceof FormatterInterface) {
                $this->formatterCache[$formatterSpec] = function ($value) use ($instance) {
                    return $instance->format($value, []);
                };
            } else {
                throw new \RuntimeException("Unable to process formatter spec: $formatterSpec");
            }
        }

        return $this->formatterCache[$formatterSpec]($value);
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
