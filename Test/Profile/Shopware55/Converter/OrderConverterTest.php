<?php declare(strict_types=1);

namespace SwagMigrationNext\Test\Profile\Shopware55\Converter;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\PriceRounding;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleCalculator;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\Checkout\Cart\Tax\TaxRuleCalculator;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use SwagMigrationNext\Profile\Shopware55\Converter\AssociationEntityRequiredMissingException;
use SwagMigrationNext\Profile\Shopware55\Converter\CustomerConverter;
use SwagMigrationNext\Profile\Shopware55\Converter\OrderConverter;
use SwagMigrationNext\Profile\Shopware55\ConverterHelperService;
use SwagMigrationNext\Test\Mock\Migration\Mapping\DummyMappingService;

class OrderConverterTest extends TestCase
{
    /**
     * @var OrderConverter
     */
    private $orderConverter;

    /**
     * @var CustomerConverter
     */
    private $customerConverter;

    protected function setUp()
    {
        $mappingService = new DummyMappingService();
        $converterHelperService = new ConverterHelperService();
        $rounding = new PriceRounding(2);
        $taxRuleCalculator = new TaxRuleCalculator($rounding);

        $taxCalculator = new TaxCalculator(
            $rounding,
            [
                $taxRuleCalculator,
                new PercentageTaxRuleCalculator($taxRuleCalculator),
            ]
        );
        $this->orderConverter = new OrderConverter($mappingService, $converterHelperService, $taxCalculator);
        $this->customerConverter = new CustomerConverter($mappingService, $converterHelperService);
    }

    public function testSupports(): void
    {
        $supportsDefinition = $this->orderConverter->supports();

        static::assertSame(OrderDefinition::getEntityName(), $supportsDefinition);
    }

    public function testConvert(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';
        $context = Context::createDefaultContext(Defaults::TENANT_ID);

        $this->customerConverter->convert(
            $customerData[0],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $convertResult = $this->orderConverter->convert(
            $orderData[0],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('orderCustomer', $converted);
        static::assertArrayHasKey('deliveries', $converted);
        static::assertSame(Defaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('test@example.com', $converted['orderCustomer']['email']);
    }

    public function testConvertWithoutCustomer(): void
    {
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';

        $context = Context::createDefaultContext(Defaults::TENANT_ID);
        $this->expectException(AssociationEntityRequiredMissingException::class);
        $this->expectExceptionMessage('Mapping of "customer" is missing, but it is a required association for "order". Import "customer" first');
        $this->orderConverter->convert($orderData[0], $context, Defaults::CATALOG, Defaults::SALES_CHANNEL);
    }

    public function testConvertNetOrder(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';
        $context = Context::createDefaultContext(Defaults::TENANT_ID);

        $this->customerConverter->convert(
            $customerData[1],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $convertResult = $this->orderConverter->convert(
            $orderData[1],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayHasKey('orderCustomer', $converted);
        static::assertArrayHasKey('deliveries', $converted);
        static::assertSame(Defaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('mustermann@b2b.de', $converted['orderCustomer']['email']);
        static::assertTrue($converted['isNet']);
    }

    /**
     * @dataProvider requiredProperties
     */
    public function testConvertWithoutRequiredProperties(string $missingProperty): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';
        $orderData = $orderData[0];
        unset($orderData[$missingProperty]);
        $context = Context::createDefaultContext(Defaults::TENANT_ID);

        $this->customerConverter->convert(
            $customerData[0],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $convertResult = $this->orderConverter->convert(
            $orderData,
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        static::assertNull($convertResult->getConverted());
    }

    public function requiredProperties(): array
    {
        return [
            ['billingaddress'],
            ['payment'],
            ['customer'],
        ];
    }

    public function testConvertWithoutOrderDetails(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';
        $orderData = $orderData[0];
        unset($orderData['details']);
        $context = Context::createDefaultContext(Defaults::TENANT_ID);

        $this->customerConverter->convert(
            $customerData[0],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $convertResult = $this->orderConverter->convert(
            $orderData,
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertArrayNotHasKey('lineItems', $converted);
        static::assertArrayNotHasKey('transactions', $converted);
        static::assertSame(Defaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('test@example.com', $converted['orderCustomer']['email']);
    }

    public function testConvertWithoutShippingMethod(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';
        $orderData = $orderData[0];
        unset($orderData['shippingMethod']);
        $context = Context::createDefaultContext(Defaults::TENANT_ID);

        $this->customerConverter->convert(
            $customerData[0],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $convertResult = $this->orderConverter->convert(
            $orderData,
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertSame(Defaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('test@example.com', $converted['orderCustomer']['email']);
        static::assertSame([], $converted['deliveries']);
    }

    public function testConvertWithoutShippingAddress(): void
    {
        $customerData = require __DIR__ . '/../../../_fixtures/customer_data.php';
        $orderData = require __DIR__ . '/../../../_fixtures/order_data.php';
        $orderData = $orderData[0];
        unset($orderData['shippingaddress']);
        $context = Context::createDefaultContext(Defaults::TENANT_ID);

        $this->customerConverter->convert(
            $customerData[0],
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $convertResult = $this->orderConverter->convert(
            $orderData,
            $context,
            Defaults::CATALOG,
            Defaults::SALES_CHANNEL
        );

        $converted = $convertResult->getConverted();

        static::assertNull($convertResult->getUnmapped());
        static::assertArrayHasKey('id', $converted);
        static::assertSame(Defaults::SALES_CHANNEL, $converted['salesChannelId']);
        static::assertSame('test@example.com', $converted['orderCustomer']['email']);
        static::assertSame($converted['billingAddress'], $converted['deliveries'][0]['shippingOrderAddress']);
    }
}