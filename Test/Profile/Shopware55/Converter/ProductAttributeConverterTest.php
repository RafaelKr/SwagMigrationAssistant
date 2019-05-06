<?php declare(strict_types=1);

namespace SwagMigrationNext\Test\Profile\Shopware55\Converter;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagMigrationAssistant\Migration\Connection\SwagMigrationConnectionEntity;
use SwagMigrationAssistant\Migration\MigrationContext;
use SwagMigrationAssistant\Migration\Profile\SwagMigrationProfileEntity;
use SwagMigrationAssistant\Profile\Shopware55\Converter\ProductAttributeConverter;
use SwagMigrationAssistant\Profile\Shopware55\DataSelection\DataSet\ProductAttributeDataSet;
use SwagMigrationAssistant\Profile\Shopware55\Shopware55Profile;
use SwagMigrationAssistant\Test\Mock\Migration\Logging\DummyLoggingService;
use SwagMigrationAssistant\Test\Mock\Migration\Mapping\DummyMappingService;

class ProductAttributeConverterTest extends TestCase
{
    /**
     * @var DummyLoggingService
     */
    private $loggingService;

    /**
     * @var ProductAttributeConverter
     */
    private $converter;

    /**
     * @var string
     */
    private $runId;

    /**
     * @var SwagMigrationConnectionEntity
     */
    private $connection;

    /**
     * @var MigrationContext
     */
    private $migrationContext;

    protected function setUp(): void
    {
        $mappingService = new DummyMappingService();
        $this->loggingService = new DummyLoggingService();
        $this->converter = new ProductAttributeConverter($mappingService);

        $this->runId = Uuid::randomHex();
        $this->connection = new SwagMigrationConnectionEntity();
        $this->connection->setProfile(new SwagMigrationProfileEntity());
        $this->connection->setId(Uuid::randomHex());
        $this->connection->setName('ConntectionName');

        $this->migrationContext = new MigrationContext(
            $this->connection,
            $this->runId,
            new ProductAttributeDataSet(),
            0,
            250
        );
    }

    public function testSupports(): void
    {
        $supportsDefinition = $this->converter->supports(Shopware55Profile::PROFILE_NAME, new ProductAttributeDataSet());

        static::assertTrue($supportsDefinition);
    }

    public function testConvertDateAttribute(): void
    {
        $categoryData = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($categoryData[0], $context, $this->migrationContext);
        $this->converter->writeMapping($context);
        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_categorydate1', $converted['customFields'][0]['name']);
        static::assertSame('date', $converted['customFields'][0]['type']);
        static::assertSame('date', $converted['customFields'][0]['config']['type']);
        static::assertSame('date', $converted['customFields'][0]['config']['dateType']);
        static::assertSame('date', $converted['customFields'][0]['config']['customFieldType']);
    }

    public function testConvertIntegerAttribute(): void
    {
        $integerAttribute = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($integerAttribute[4], $context, $this->migrationContext);

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_ganzzahlattr', $converted['customFields'][0]['name']);
        static::assertSame('integer', $converted['customFields'][0]['type']);
        static::assertSame('int', $converted['customFields'][0]['config']['numberType']);
        static::assertSame('number', $converted['customFields'][0]['config']['customFieldType']);
    }

    public function testConvertFloatAttribute(): void
    {
        $floatAttribute = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($floatAttribute[5], $context, $this->migrationContext);

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_dezimalzahl1', $converted['customFields'][0]['name']);
        static::assertSame('float', $converted['customFields'][0]['type']);
        static::assertSame('float', $converted['customFields'][0]['config']['numberType']);
        static::assertSame('number', $converted['customFields'][0]['config']['customFieldType']);
    }

    public function testConvertHtmlAttribute(): void
    {
        $htmlAttribute = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($htmlAttribute[6], $context, $this->migrationContext);

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_mediumtext1', $converted['customFields'][0]['name']);
        static::assertSame('text', $converted['customFields'][0]['type']);
        static::assertSame('textEditor', $converted['customFields'][0]['config']['customFieldType']);
    }

    public function testConvertComboboxAttribute(): void
    {
        $comboboxAttribute = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($comboboxAttribute[7], $context, $this->migrationContext);

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_combobox1', $converted['customFields'][0]['name']);
        static::assertSame('text', $converted['customFields'][0]['type']);
        static::assertSame('select', $converted['customFields'][0]['config']['customFieldType']);
        static::assertArrayHasKey('options', $converted['customFields'][0]['config']);
        static::assertCount(2, $converted['customFields'][0]['config']['options']);
        static::assertSame('key1', $converted['customFields'][0]['config']['options'][0]['value']);
        static::assertSame('key2', $converted['customFields'][0]['config']['options'][1]['value']);
    }

    public function testConvertIntegerAttributeWithoutConfiguration(): void
    {
        $integerAttribute = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($integerAttribute[8], $context, $this->migrationContext);

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_integer2', $converted['customFields'][0]['name']);
        static::assertSame('integer', $converted['customFields'][0]['type']);
        static::assertSame('int', $converted['customFields'][0]['config']['numberType']);
        static::assertSame('number', $converted['customFields'][0]['config']['customFieldType']);
    }

    public function testConvertFloatAttributeWithoutConfiguration(): void
    {
        $floatAttribute = require __DIR__ . '/../../../_fixtures/attribute_data.php';

        $context = Context::createDefaultContext();
        $convertResult = $this->converter->convert($floatAttribute[9], $context, $this->migrationContext);

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('relations', $converted);
        static::assertSame('product', $converted['relations'][0]['entityName']);
        static::assertSame('product_migration_ConntectionName_float2', $converted['customFields'][0]['name']);
        static::assertSame('float', $converted['customFields'][0]['type']);
        static::assertSame('float', $converted['customFields'][0]['config']['numberType']);
        static::assertSame('number', $converted['customFields'][0]['config']['customFieldType']);
    }
}
