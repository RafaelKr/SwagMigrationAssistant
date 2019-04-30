<?php declare(strict_types=1);

namespace SwagMigrationNext\Test\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagMigrationNext\Migration\Connection\SwagMigrationConnectionEntity;
use SwagMigrationNext\Migration\Data\SwagMigrationDataEntity;
use SwagMigrationNext\Migration\DataSelection\DefaultEntities;
use SwagMigrationNext\Migration\Logging\LogType;
use SwagMigrationNext\Migration\Mapping\MappingService;
use SwagMigrationNext\Migration\Media\MediaFileService;
use SwagMigrationNext\Migration\MigrationContext;
use SwagMigrationNext\Migration\Run\SwagMigrationRunEntity;
use SwagMigrationNext\Migration\Service\MigrationDataFetcherInterface;
use SwagMigrationNext\Migration\Service\MigrationDataWriter;
use SwagMigrationNext\Migration\Service\MigrationDataWriterInterface;
use SwagMigrationNext\Migration\Writer\CustomerWriter;
use SwagMigrationNext\Migration\Writer\ProductWriter;
use SwagMigrationNext\Migration\Writer\WriterRegistry;
use SwagMigrationNext\Profile\Shopware55\DataSelection\DataSet\CategoryDataSet;
use SwagMigrationNext\Profile\Shopware55\DataSelection\DataSet\CustomerDataSet;
use SwagMigrationNext\Profile\Shopware55\DataSelection\DataSet\MediaDataSet;
use SwagMigrationNext\Profile\Shopware55\DataSelection\DataSet\OrderDataSet;
use SwagMigrationNext\Profile\Shopware55\DataSelection\DataSet\ProductDataSet;
use SwagMigrationNext\Profile\Shopware55\Gateway\Local\Shopware55LocalGateway;
use SwagMigrationNext\Profile\Shopware55\Premapping\DeliveryTimeReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\OrderStateReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\PaymentMethodReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\SalutationReader;
use SwagMigrationNext\Profile\Shopware55\Premapping\TransactionStateReader;
use SwagMigrationNext\Profile\Shopware55\Shopware55Profile;
use SwagMigrationNext\Test\Migration\Services\MigrationProfileUuidService;
use SwagMigrationNext\Test\MigrationServicesTrait;
use SwagMigrationNext\Test\Mock\Migration\Logging\DummyLoggingService;
use SwagMigrationNext\Test\Mock\Migration\Media\DummyMediaFileService;

class MigrationDataWriterTest extends TestCase
{
    use MigrationServicesTrait,
        IntegrationTestBehaviour;

    /**
     * @var EntityRepositoryInterface
     */
    private $productRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $categoryRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $productTranslationRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $currencyRepo;

    /**
     * @var MigrationDataFetcherInterface
     */
    private $migrationDataFetcher;

    /**
     * @var MigrationDataWriterInterface
     */
    private $migrationDataWriter;

    /**
     * @var string
     */
    private $runUuid;

    /**
     * @var MigrationProfileUuidService
     */
    private $profileUuidService;

    /**
     * @var MigrationDataWriterInterface
     */
    private $dummyDataWriter;

    /**
     * @var DummyLoggingService
     */
    private $loggingService;

    /**
     * @var EntityRepositoryInterface
     */
    private $loggingRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $migrationDataRepo;

    /**
     * @var string
     */
    private $connectionId;

    /**
     * @var SwagMigrationConnectionEntity
     */
    private $connection;

    /**
     * @var EntityRepositoryInterface
     */
    private $stateMachineStateRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $stateMachineRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $connectionRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $runRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $profileRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $paymentRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $salutationRepo;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var MappingService
     */
    private $mappingService;

    /**
     * @var EntityWriter
     */
    private $entityWriter;

    /**
     * @var Connection
     */
    private $dbConnection;

    /**
     * @var EntityRepositoryInterface
     */
    private $deliveryTimeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $localeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $languageRepo;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->initRepos();
        $this->initConnectionAndRun();
        $this->initServices();
        $this->initMapping();
    }

    public function initServices(): void
    {
        $this->loggingService = new DummyLoggingService();
        $this->mappingService = $this->getContainer()->get(MappingService::class);
        $this->migrationDataWriter = $this->getContainer()->get(MigrationDataWriter::class);
        $this->migrationDataFetcher = $this->getMigrationDataFetcher(
            $this->entityWriter,
            $this->mappingService,
            $this->getContainer()->get(MediaFileService::class),
            $this->loggingRepo
        );

        $this->dummyDataWriter = new MigrationDataWriter(
            $this->entityWriter,
            $this->migrationDataRepo,
            new WriterRegistry(
                [
                    new ProductWriter($this->entityWriter),
                    new CustomerWriter($this->entityWriter),
                ]
            ),
            new DummyMediaFileService(),
            $this->loggingService
        );
    }

    public function requiredProperties(): array
    {
        return [
            ['email'],
            ['firstName'],
            ['lastName'],
        ];
    }

    /**
     * @dataProvider requiredProperties
     */
    public function testWriteInvalidData(string $missingProperty): void
    {
        $context = Context::createDefaultContext();
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new CustomerDataSet(),
            0,
            250
        );

        $this->migrationDataFetcher->fetchData($migrationContext, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entity', 'customer'));
        $customerData = $this->migrationDataRepo->search($criteria, $context);

        /** @var SwagMigrationDataEntity $data */
        $data = $customerData->first();
        $customer = $data->jsonSerialize();
        $customer['id'] = $data->getId();
        unset($customer['run'], $customer['converted'][$missingProperty]);

        $this->migrationDataRepo->update([$customer], $context);
        $customerTotalBefore = $this->customerRepo->search(new Criteria(), $context)->getTotal();
        $context->scope(Context::USER_SCOPE, function (Context $context) use ($migrationContext) {
            $this->dummyDataWriter->writeData($migrationContext, $context);
        });
        $customerTotalAfter = $this->dbConnection->query('select count(*) from customer')->fetchColumn();

        static::assertSame(0, $customerTotalAfter - $customerTotalBefore);
        static::assertCount(1, $this->loggingService->getLoggingArray());
        $this->loggingService->resetLogging();

        $failureConvertCriteria = new Criteria();
        $failureConvertCriteria->addFilter(new EqualsFilter('writeFailure', true));
        $result = $this->migrationDataRepo->search($failureConvertCriteria, $context);
        static::assertSame(3, $result->getTotal());
    }

    public function testWriteCustomerData(): void
    {
        $context = Context::createDefaultContext();
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new CustomerDataSet(),
            0,
            250
        );

        $this->migrationDataFetcher->fetchData($migrationContext, $context);
        $criteria = new Criteria();
        $customerTotalBefore = $this->customerRepo->search($criteria, $context)->getTotal();

        $context->scope(Context::USER_SCOPE, function (Context $context) use ($migrationContext) {
            $this->migrationDataWriter->writeData($migrationContext, $context);
        });
        $customerTotalAfter = $this->dbConnection->query('select count(*) from customer')->fetchColumn();

        static::assertSame(3, $customerTotalAfter - $customerTotalBefore);
    }

    public function testWriteOrderData(): void
    {
        $context = Context::createDefaultContext();
        // Add users, who have ordered
        $userMigrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new CustomerDataSet(),
            0,
            250
        );
        $this->migrationDataFetcher->fetchData($userMigrationContext, $context);
        $this->clearCacheBefore();

        $context->scope(Context::USER_SCOPE, function (Context $context) use ($userMigrationContext) {
            $this->migrationDataWriter->writeData($userMigrationContext, $context);
            $this->clearCacheBefore();
        });

        // Add orders
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new OrderDataSet(),
            0,
            250
        );

        $criteria = new Criteria();

        // Get data before writing
        $this->migrationDataFetcher->fetchData($migrationContext, $context);
        $this->clearCacheBefore();

        $orderTotalBefore = $this->orderRepo->search($criteria, $context)->getTotal();
        // Get data after writing
        $context->scope(Context::USER_SCOPE, function (Context $context) use ($migrationContext) {
            $this->migrationDataWriter->writeData($migrationContext, $context);
            $this->clearCacheBefore();
        });
        $orderTotalAfter = $this->orderRepo->search($criteria, $context)->getTotal();

        static::assertSame(2, $orderTotalAfter - $orderTotalBefore);
    }

    public function testWriteMediaData(): void
    {
        $context = Context::createDefaultContext();
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new MediaDataSet(),
            0,
            250
        );

        $this->migrationDataFetcher->fetchData($migrationContext, $context);
        $criteria = new Criteria();
        $totalBefore = $this->mediaRepo->search($criteria, $context)->getTotal();

        $context->scope(Context::USER_SCOPE, function (Context $context) use ($migrationContext) {
            $this->migrationDataWriter->writeData($migrationContext, $context);
        });
        $totalAfter = $this->dbConnection->query('select count(*) from media')->fetchColumn();

        static::assertSame(23, $totalAfter - $totalBefore);
    }

    public function testWriteCategoryData(): void
    {
        $context = Context::createDefaultContext();
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new CategoryDataSet(),
            0,
            250
        );

        $this->migrationDataFetcher->fetchData($migrationContext, $context);
        $criteria = new Criteria();
        $totalBefore = $this->categoryRepo->search($criteria, $context)->getTotal();

        $context->scope(Context::USER_SCOPE, function (Context $context) use ($migrationContext) {
            $this->migrationDataWriter->writeData($migrationContext, $context);
        });
        $totalAfter = $this->dbConnection->query('select count(*) from category')->fetchColumn();

        static::assertSame(8, $totalAfter - $totalBefore);
    }

    public function testWriteProductData(): void
    {
        $context = Context::createDefaultContext();
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new ProductDataSet(),
            0,
            250
        );

        $this->clearCacheBefore();
        $this->migrationDataFetcher->fetchData($migrationContext, $context);

        $criteria = new Criteria();
        $productTotalBefore = $this->productRepo->search($criteria, $context)->getTotal();

        $context->scope(Context::USER_SCOPE, function (Context $context) use ($migrationContext) {
            $this->migrationDataWriter->writeData($migrationContext, $context);
        });
        $productTotalAfter = $this->dbConnection->query('select count(*) from product')->fetchColumn();

        static::assertSame(42, $productTotalAfter - $productTotalBefore);
    }

    public function testWriteDataWithUnknownWriter(): void
    {
        $context = Context::createDefaultContext();
        $migrationContext = new MigrationContext(
            $this->connection,
            $this->runUuid,
            new MediaDataSet(),
            0,
            250
        );
        $this->migrationDataFetcher->fetchData($migrationContext, $context);
        $this->dummyDataWriter->writeData($migrationContext, $context);

        $logs = $this->loggingService->getLoggingArray();

        static::assertSame(LogType::WRITER_NOT_FOUND, $logs[0]['logEntry']['code']);
        static::assertCount(1, $logs);
    }

    private function initRepos(): void
    {
        $this->dbConnection = $this->getContainer()->get(Connection::class);
        $this->entityWriter = $this->getContainer()->get(EntityWriter::class);
        $this->runRepo = $this->getContainer()->get('swag_migration_run.repository');
        $this->profileRepo = $this->getContainer()->get('swag_migration_profile.repository');
        $this->paymentRepo = $this->getContainer()->get('payment_method.repository');
        $this->mediaRepo = $this->getContainer()->get('media.repository');
        $this->productRepo = $this->getContainer()->get('product.repository');
        $this->categoryRepo = $this->getContainer()->get('category.repository');
        $this->orderRepo = $this->getContainer()->get('order.repository');
        $this->customerRepo = $this->getContainer()->get('customer.repository');
        $this->connectionRepo = $this->getContainer()->get('swag_migration_connection.repository');
        $this->migrationDataRepo = $this->getContainer()->get('swag_migration_data.repository');
        $this->loggingRepo = $this->getContainer()->get('swag_migration_logging.repository');
        $this->stateMachineRepository = $this->getContainer()->get('state_machine.repository');
        $this->stateMachineStateRepository = $this->getContainer()->get('state_machine_state.repository');
        $this->productTranslationRepo = $this->getContainer()->get('product_translation.repository');
        $this->currencyRepo = $this->getContainer()->get('currency.repository');
        $this->salutationRepo = $this->getContainer()->get('salutation.repository');
        $this->deliveryTimeRepo = $this->getContainer()->get('delivery_time.repository');
        $this->localeRepo = $this->getContainer()->get('locale.repository');
        $this->languageRepo = $this->getContainer()->get('language.repository');
    }

    private function initConnectionAndRun(): void
    {
        $this->connectionId = Uuid::randomHex();
        $this->runUuid = Uuid::randomHex();

        $this->profileUuidService = new MigrationProfileUuidService(
            $this->profileRepo,
            Shopware55Profile::PROFILE_NAME,
            Shopware55LocalGateway::GATEWAY_NAME
        );

        $this->context->scope(MigrationContext::SOURCE_CONTEXT, function (Context $context) {
            $this->connectionRepo->create(
                [
                    [
                        'id' => $this->connectionId,
                        'name' => 'myConnection',
                        'credentialFields' => [
                            'endpoint' => 'testEndpoint',
                            'apiUser' => 'testUser',
                            'apiKey' => 'testKey',
                        ],
                        'profileId' => $this->profileUuidService->getProfileUuid(),
                    ],
                ],
                $context
            );
        });
        $this->connection = $this->connectionRepo->search(new Criteria([$this->connectionId]), $this->context)->first();

        $this->runRepo->create(
            [
                [
                    'id' => $this->runUuid,
                    'status' => SwagMigrationRunEntity::STATUS_RUNNING,
                    'profileId' => $this->profileUuidService->getProfileUuid(),
                ],
            ],
            $this->context
        );
    }

    private function initMapping(): void
    {
        $orderStateUuid = $this->getOrderStateUuid(
            $this->stateMachineRepository,
            $this->stateMachineStateRepository,
            0,
            $this->context
        );
        $this->mappingService->createNewUuid($this->connectionId, OrderStateReader::getMappingName(), '0', $this->context, [], $orderStateUuid);

        $transactionStateUuid = $this->getTransactionStateUuid(
            $this->stateMachineRepository,
            $this->stateMachineStateRepository,
            17,
            $this->context
        );
        $this->mappingService->createNewUuid($this->connectionId, TransactionStateReader::getMappingName(), '17', $this->context, [], $transactionStateUuid);

        $paymentUuid = $this->getPaymentUuid(
            $this->paymentRepo,
            InvoicePayment::class,
            $this->context
        );

        $this->mappingService->createNewUuid($this->connectionId, PaymentMethodReader::getMappingName(), '3', $this->context, [], $paymentUuid);
        $this->mappingService->createNewUuid($this->connectionId, PaymentMethodReader::getMappingName(), '4', $this->context, [], $paymentUuid);
        $this->mappingService->createNewUuid($this->connectionId, PaymentMethodReader::getMappingName(), '5', $this->context, [], $paymentUuid);

        $salutationUuid = $this->getSalutationUuid(
            $this->salutationRepo,
            'mr',
            $this->context
        );

        $this->mappingService->createNewUuid($this->connectionId, SalutationReader::getMappingName(), 'mr', $this->context, [], $salutationUuid);
        $this->mappingService->createNewUuid($this->connectionId, SalutationReader::getMappingName(), 'ms', $this->context, [], $salutationUuid);

        $deliveryTimeUuid = $this->getFirstDeliveryTimeUuid($this->deliveryTimeRepo, $this->context);
        $this->mappingService->createNewUuid($this->connectionId, DeliveryTimeReader::getMappingName(), 'default_delivery_time', $this->context, [], $deliveryTimeUuid);

        $currencyUuid = $this->getCurrencyUuid(
            $this->currencyRepo,
            'EUR',
            $this->context
        );
        $this->mappingService->createNewUuid($this->connectionId, DefaultEntities::CURRENCY, 'JPY', $this->context, [], $currencyUuid);

        $currencyUuid = $this->getCurrencyUuid(
            $this->currencyRepo,
            'EUR',
            $this->context
        );
        $this->mappingService->createNewUuid($this->connectionId, DefaultEntities::CURRENCY, 'JPY', $this->context, [], $currencyUuid);

        $languageUuid = $this->getLanguageUuid(
            $this->localeRepo,
            $this->languageRepo,
            'de-DE',
            $this->context
        );
        $this->mappingService->createNewUuid($this->connectionId, DefaultEntities::LANGUAGE, 'en-US', $this->context, [], $languageUuid);

        $this->mappingService->writeMapping($this->context);
        $this->clearCacheBefore();
    }

    private function getTranslationTotal(): int
    {
        return (int) $this->getContainer()->get(Connection::class)
            ->executeQuery('SELECT count(*) FROM product_translation')
            ->fetchColumn();
    }
}
