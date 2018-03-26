<?php

namespace Meanbee\Magedbm2\Service\Anonymiser;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use XMLReader;

class Anonymiser implements LoggerAwareInterface
{
    /** @var LoggerInterface */
    private $logger;

    private $flatTables = [];
    private $eavTables = [];

    /** @var resource */
    private $write;

    /**
     * @var FlatRowProcessor
     */
    private $flatRowProcessor;

    /**
     * @var EavRowProcessor
     */
    private $eavRowProcessor;

    public function __construct()
    {
        $this->logger = new NullLogger();
        $this->flatRowProcessor = new FlatRowProcessor();
        $this->eavRowProcessor = new EavRowProcessor();
    }

    /**
     * Adds a formatter rule to be used against flat tables.
     *
     * @param $table
     * @param $column
     * @param $formatter
     * @return array
     */
    public function addColumnRule($table, $column, $formatter)
    {
        $this->flatTables[] = $table;
        $this->flatRowProcessor->addRule($table, $column, $formatter);

        return [$table];
    }

    /**
     * Adds a formatter rule to be used against EAV tables.
     *
     * @param $eavEntity
     * @param $attributeCode
     * @param $formatter
     * @return array
     */
    public function addAttributeRule($eavEntity, $attributeCode, $formatter)
    {
        $this->eavRowProcessor->addRule($eavEntity, $attributeCode, $formatter);

        $entityTable = sprintf('%s_entity', $eavEntity);
        $tables = [$entityTable];

        $this->eavTables[] = sprintf('%s_entity', $eavEntity);

        foreach (Eav::VALUE_TYPES as $type) {
            $table = sprintf('%s_entity_%s', $eavEntity, $type);
            $tables[] = $table;
            $this->eavTables[] = $table;
        }

        return $tables;
    }

    /**
     * Anonymised the input file and output the results to a new file.
     *
     * @param $inputFile
     * @param $outputFile
     */
    public function processFile($inputFile, $outputFile)
    {
        $xml = new XMLReader();
        $xml->open($inputFile);

        $this->write = fopen($outputFile, 'wb');

        fwrite($this->write, '<?xml version="1.0"?>' . "\n");
        $this->openTag('magedbm2-export', ['version' => '1.0.0']);

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

                if (!$this->isFlatTable($tableName) && !$this->isEavTable($tableName)) {
                    $this->getLogger()->info("Skipping $tableName as we're not configured to read it");
                    continue;
                }

                if ($openTableTag) {
                    $this->closeTag('table', 1);
                }

                $openTableTag = true;

                $this->getLogger()->info("Processing $tableName");

                $this->openTag('table', ['name' => $tableName], 1);
            }

            if ($tableName && $xml->nodeType === XMLReader::ELEMENT && $xml->name === 'row') {
                $rowData = new \SimpleXMLElement($xml->readOuterXml());
                $row = new Row($rowData);
                $row->table = $tableName;

                $xml->next();

                if ($this->isFlatTable($tableName)) {
                    $this->processFlatRow($row);
                } elseif ($this->isEavTable($tableName)) {
                    $this->processEavTable($row);
                }

                continue;
            }
        }

        if ($openTableTag) {
            $this->closeTag('table', 1);
        }

        $this->closeTag('magedbm2-export');

        fclose($this->write);
    }

    /**
     * @param $table
     * @return bool
     */
    private function isFlatTable($table)
    {
        return in_array($table, $this->flatTables, true);
    }

    /**
     * @param $table
     * @return bool
     */
    private function isEavTable($table)
    {
        return in_array($table, $this->eavTables, true);
    }

    /**
     * @return LoggerInterface
     */
    private function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param \SimpleXMLElement $param
     */
    private function extractEntityTypes(\SimpleXMLElement $param)
    {
        foreach ($param->row as $row) {
            $entityCode = null;
            $entityId = null;

            foreach ($row->field as $field) {
                switch ($field['name']) {
                    case 'entity_type_id':
                        $entityId = (string)$field;
                        break;
                    case 'entity_type_code':
                        $entityCode = (string)$field;
                        break;
                }

                if ($entityCode !== null && $entityId !== null) {
                    $this->eavRowProcessor->defineEntity($entityCode, $entityId);
                    break;
                }
            }
        }
    }

    /**
     * @param \SimpleXMLElement $param
     */
    private function extractAttributes(\SimpleXMLElement $param)
    {
        foreach ($param->row as $row) {
            $id = null;
            $code = null;
            $type_id = null;

            foreach ($row->field as $field) {
                switch ($field['name']) {
                    case 'attribute_id':
                        $id = (string)$field;
                        break;
                    case 'entity_type_id':
                        $type_id = (string)$field;
                        break;
                    case 'attribute_code':
                        $code = (string)$field;
                        break;
                }

                if ($id !== null && $code !== null && $type_id !== null) {
                    $this->eavRowProcessor->defineAttribute($code, $id, $type_id);
                    break;
                }
            }
        }
    }

    /**
     * @param Row $row
     */
    private function processFlatRow(Row $row)
    {
        fwrite(
            $this->write,
            $this->flatRowProcessor->process($row)
        );
    }

    /**
     * @param Row $row
     */
    private function processEavTable(Row $row)
    {
        fwrite(
            $this->write,
            $this->eavRowProcessor->process($row)
        );
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

    /**
     * @param $name
     * @param array $attributes
     * @param int $depth
     * @param bool $newline
     */
    private function openTag($name, $attributes = [], $depth = 0, $newline = true)
    {
        $attributeString = '';

        foreach ($attributes as $key => $value) {
            $attributeString .= sprintf(' %s="%s"', $key, $value);
        }

        if ($depth > 0) {
            fwrite($this->write, str_repeat("\t", $depth));
        }

        fwrite($this->write, '<' . $name . $attributeString . '>');

        if ($newline) {
            fwrite($this->write, "\n");
        }
    }

    /**
     * @param $name
     * @param int $depth
     */
    private function closeTag($name, $depth = 0)
    {
        if ($depth > 0) {
            fwrite($this->write, str_repeat("\t", $depth));
        }

        fwrite($this->write, '</' . $name . '>');
        fwrite($this->write, "\n");
    }
}
