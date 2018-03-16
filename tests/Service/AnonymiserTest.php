<?php

namespace Meanbee\Magedbm2\Tests\Service;

use Meanbee\Magedbm2\Service\Anonymiser\Anonymiser;
use PHPUnit\Framework\TestCase;
use VirtualFileSystem\FileSystem as VirtualFileSystem;

class AnonymiserTest extends TestCase
{
    /** @var Anonymiser */
    private $subject;

    /** @var VirtualFileSystem */
    private $vfs;

    private $inputFile;
    private $outputFile;

    public function setUp()
    {
        $this->subject = new Anonymiser();
        $this->vfs = new VirtualFileSystem();

        $this->inputFile = $this->getDataFilePath('test.xml');
        $this->outputFile = $this->vfs->path('/output.xml');

        @unlink($this->outputFile);
    }

    public function testProcessFile()
    {
        try {
            $this->subject->processFile($this->inputFile, $this->outputFile);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }

        $this->assertGreaterThan(
            0,
            filesize($this->outputFile),
            sprintf('Expected %s to have a non-zero file size', $this->outputFile)
        );

        $inputXml = new \SimpleXMLElement(file_get_contents($this->inputFile));
        $outputXml = new \SimpleXMLElement(file_get_contents($this->outputFile));

        $tablesToCheck = [
            'sales_order',
            'sales_order_address',
            'customer_entity',
            'customer_entity_datetime',
            'customer_entity_decimal',
            'customer_entity_int',
            'customer_entity_text',
            'customer_entity_varchar',
            'customer_address_entity',
            'customer_address_entity_datetime',
            'customer_address_entity_decimal',
            'customer_address_entity_int',
            'customer_address_entity_text',
            'customer_address_entity_varchar',
        ];

        foreach ($tablesToCheck as $table) {
            $rowCountPath = sprintf("//table_data[@name='%s']/row", $table);

            $inputRowCount  = count($inputXml->xpath($rowCountPath));
            $outputRowCount = count($outputXml->xpath($rowCountPath));

            $this->assertEquals(
                $inputRowCount,
                $outputRowCount,
                sprintf('Expected same number of rows for %s in input and output', $table)
            );
        }
    }

    public function testFormatterApplied()
    {
        $this->subject->addColumnRule(
            'sales_order',
            'customer_email',
            'Meanbee\Magedbm2\Anonymizer\Formatter\Rot13'
        );

        $this->subject->processFile($this->inputFile, $this->outputFile);

        $inputXml = new \SimpleXMLElement(file_get_contents($this->inputFile));
        $outputXml = new \SimpleXMLElement(file_get_contents($this->outputFile));

        $orderIdPath = "//table_data[@name='sales_order']/row[1]/field[@name='increment_id']/text()";
        $emailPath = "//table_data[@name='sales_order']/row[1]/field[@name='customer_email']/text()";

        $inputOrderId = (string) $inputXml->xpath($orderIdPath)[0];
        $inputEmail = (string) $inputXml->xpath($emailPath)[0];

        $outputOrderId = (string) $outputXml->xpath($orderIdPath)[0];
        $outputEmail = (string) $outputXml->xpath($emailPath)[0];

        $this->assertEquals('200000001', $inputOrderId);
        $this->assertEquals($inputOrderId, $outputOrderId);

        $this->assertEquals('order_1@example.com', $inputEmail);
        $this->assertEquals('beqre_1@rknzcyr.pbz', $outputEmail);
    }

    /**
     * Return the file path to a data file.
     *
     * @param $name
     * @return string
     */
    private function getDataFilePath($name)
    {
        $filePath = implode(DIRECTORY_SEPARATOR, [__DIR__, 'AnonymiserTest', '_data', $name]);

        if (!file_exists($filePath)) {
            $this->fail(sprintf('Unable to load data file %s, file doesn\'t exist at %s', $name, $filePath));
        }

        return $filePath;
    }
}
