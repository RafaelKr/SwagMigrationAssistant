<?php declare(strict_types=1);

namespace SwagMigrationNext\Test\Migration\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use SwagMigrationNext\Controller\StatusController;
use SwagMigrationNext\Exception\MigrationContextPropertyMissingException;
use SwagMigrationNext\Exception\MigrationIsRunningException;
use SwagMigrationNext\Migration\Connection\SwagMigrationConnectionEntity;
use SwagMigrationNext\Migration\DataSelection\DataSelectionRegistry;
use SwagMigrationNext\Migration\Mapping\MappingService;
use SwagMigrationNext\Migration\Media\MediaFileService;
use SwagMigrationNext\Migration\MigrationContext;
use SwagMigrationNext\Migration\Run\RunService;
use SwagMigrationNext\Migration\Run\SwagMigrationRunEntity;
use SwagMigrationNext\Migration\Service\MigrationProgressService;
use SwagMigrationNext\Migration\Service\ProgressState;
use SwagMigrationNext\Migration\Service\SwagMigrationAccessTokenService;
use SwagMigrationNext\Profile\Shopware55\DataSelection\CustomerAndOrderDataSelection;
use SwagMigrationNext\Profile\Shopware55\DataSelection\ProductCategoryTranslationDataSelection;
use SwagMigrationNext\Profile\Shopware55\Gateway\Local\Shopware55LocalGateway;
use SwagMigrationNext\Profile\Shopware55\Shopware55Profile;
use SwagMigrationNext\Test\Migration\Services\MigrationProfileUuidService;
use SwagMigrationNext\Test\MigrationServicesTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StatusControllerTest extends TestCase
{
    use MigrationServicesTrait,
        IntegrationTestBehaviour;

    /**
     * @var StatusController
     */
    private $controller;

    /**
     * @var string
     */
    private $runUuid;

    /**
     * @var MigrationProfileUuidService
     */
    private $profileUuidService;

    /**
     * @var EntityRepositoryInterface
     */
    private $runRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $generalSettingRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $profileRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $connectionRepo;

    /**
     * @var string
     */
    private $connectionId;

    protected function setUp(): void
    {
        $context = Context::createDefaultContext();
        $mediaFileRepo = $this->getContainer()->get('swag_migration_media_file.repository');
        $dataRepo = $this->getContainer()->get('swag_migration_data.repository');
        $this->profileRepo = $this->getContainer()->get('swag_migration_profile.repository');
        $this->connectionRepo = $this->getContainer()->get('swag_migration_connection.repository');
        $this->profileUuidService = new MigrationProfileUuidService($this->profileRepo, Shopware55Profile::PROFILE_NAME, Shopware55LocalGateway::GATEWAY_NAME);
        $this->generalSettingRepo = $this->getContainer()->get('swag_migration_general_setting.repository');
        $this->runRepo = $this->getContainer()->get('swag_migration_run.repository');

        $context->scope(MigrationContext::SOURCE_CONTEXT, function (Context $context) {
            $this->connectionId = Uuid::uuid4()->getHex();
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

        $this->runUuid = Uuid::uuid4()->getHex();
        $this->runRepo->create(
            [
                [
                    'id' => $this->runUuid,
                    'connectionId' => $this->connectionId,
                    'progress' => require __DIR__ . '/../../_fixtures/run_progress_data.php',
                    'status' => SwagMigrationRunEntity::STATUS_RUNNING,
                    'accessToken' => 'testToken',
                ],
            ],
            Context::createDefaultContext()
        );

        $mappingService = $this->getContainer()->get(MappingService::class);
        $accessTokenService = new SwagMigrationAccessTokenService(
            $this->runRepo
        );
        $dataFetcher = $this->getMigrationDataFetcher(
            $dataRepo,
            $mappingService,
            $this->getContainer()->get(MediaFileService::class),
            $this->getContainer()->get('swag_migration_logging.repository')
        );
        $this->controller = new StatusController(
            $dataFetcher,
            $this->getContainer()->get(MigrationProgressService::class),
            new RunService(
                $this->runRepo,
                $this->connectionRepo,
                $dataFetcher,
                $this->getContainer()->get(MappingService::class),
                $accessTokenService,
                new DataSelectionRegistry([
                    new ProductCategoryTranslationDataSelection(),
                    new CustomerAndOrderDataSelection(),
                ]),
                $dataRepo,
                $mediaFileRepo
            ),
            new DataSelectionRegistry([
                new ProductCategoryTranslationDataSelection(),
                new CustomerAndOrderDataSelection(),
            ]),
            $this->connectionRepo
        );
    }

    public function testUpdateConnectionCredentials(): void
    {
        $context = Context::createDefaultContext();
        $params = [
            'connectionId' => $this->connectionId,
            'credentialFields' => [
                'testCredentialField1' => 'field1',
                'testCredentialField2' => 'field2',
            ],
        ];

        $this->runRepo->update([
            [
                'id' => $this->runUuid,
                'status' => SwagMigrationRunEntity::STATUS_ABORTED,
            ],
        ], $context);

        $request = new Request([], $params);
        $this->controller->updateConnectionCredentials($request, $context);

        /** @var SwagMigrationConnectionEntity $connection */
        $connection = $this->connectionRepo->search(new Criteria([$this->connectionId]), $context)->first();
        static::assertSame($connection->getCredentialFields(), $params['credentialFields']);
    }

    public function testUpdateConnectionCredentialsWithRunningMigration(): void
    {
        $this->expectException(MigrationIsRunningException::class);
        $context = Context::createDefaultContext();
        $params = [
            'connectionId' => $this->connectionId,
            'credentialFields' => [
                'testCredentialField1' => 'field1',
                'testCredentialField2' => 'field2',
            ],
        ];

        $request = new Request([], $params);
        $this->controller->updateConnectionCredentials($request, $context);
    }

    public function testGetDataSelection(): void
    {
        $context = Context::createDefaultContext();
        $request = new Request(['connectionId' => $this->connectionId]);
        $result = $this->controller->getDataSelection($request, $context);
        $state = json_decode($result->getContent(), true);

        static::assertSame($state[0]['id'], 'categoriesProducts');
        static::assertSame($state[0]['entityNames'][0], CategoryDefinition::getEntityName());
        static::assertSame($state[0]['entityNames'][1], CustomerGroupDefinition::getEntityName());
        static::assertSame($state[0]['entityNames'][2], ProductDefinition::getEntityName());

        static::assertSame($state[1]['id'], 'customersOrders');
        static::assertSame($state[1]['entityNames'][0], CustomerDefinition::getEntityName());
        static::assertSame($state[1]['entityNames'][1], OrderDefinition::getEntityName());
    }

    public function testGetState(): void
    {
        $context = Context::createDefaultContext();
        $result = $this->controller->getState(new Request(), $context);
        $state = json_decode($result->getContent(), true);
        static::assertSame(ProgressState::class, $state['_class']);
    }

    public function testGetStateWithCreateMigration(): void
    {
        $context = Context::createDefaultContext();
        $customerId = Uuid::uuid4()->getHex();
        $context->getSourceContext()->setUserId($customerId);

        $params = [
            'connectionId' => $this->connectionId,
            'dataSelectionIds' => [
                'categories_products',
                'customers_orders',
                'media',
            ],
        ];
        $requestWithoutToken = new Request([], $params);
        $params[SwagMigrationAccessTokenService::ACCESS_TOKEN_NAME] = 'testToken';
        $requestWithToken = new Request([], $params);

        $abortedCriteria = new Criteria();
        $abortedCriteria->addFilter(new EqualsFilter('status', SwagMigrationRunEntity::STATUS_ABORTED));

        $runningCriteria = new Criteria();
        $runningCriteria->addFilter(new EqualsFilter('status', SwagMigrationRunEntity::STATUS_RUNNING));

        // Get state migration with invalid accessToken
        $totalAbortedBefore = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalBefore = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $result = $this->controller->getState($requestWithoutToken, $context);
        $state = json_decode($result->getContent(), true);
        $totalAfter = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $totalAbortedAfter = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalProcessing = $this->runRepo->search($runningCriteria, $context)->getTotal();
        static::assertSame(ProgressState::class, $state['_class']);
        static::assertFalse($state['migrationRunning']);
        static::assertFalse($state['validMigrationRunToken']);
        static::assertSame(ProgressState::STATUS_FETCH_DATA, $state['status']);
        static::assertSame(0, $totalAfter - $totalBefore);
        static::assertSame(0, $totalAbortedAfter - $totalAbortedBefore);
        static::assertSame(1, $totalProcessing);

        // Get state migration with valid accessToken and abort running migration
        $totalAbortedBefore = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalBefore = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $result = $this->controller->getState($requestWithToken, $context);
        $state = json_decode($result->getContent(), true);
        $totalAfter = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $totalAbortedAfter = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalProcessing = $this->runRepo->search($runningCriteria, $context)->getTotal();
        static::assertSame(ProgressState::class, $state['_class']);
        static::assertFalse($state['migrationRunning']);
        static::assertTrue($state['validMigrationRunToken']);
        static::assertSame(ProgressState::STATUS_FETCH_DATA, $state['status']);
        static::assertSame(0, $totalAfter - $totalBefore);
        static::assertSame(1, $totalAbortedAfter - $totalAbortedBefore);
        static::assertSame(0, $totalProcessing);

        // Create new migration without abort a running migration
        $totalAbortedBefore = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalBefore = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $result = $this->controller->createMigration($requestWithToken, $context);
        $state = json_decode($result->getContent(), true);
        $totalAfter = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $totalAbortedAfter = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalProcessing = $this->runRepo->search($runningCriteria, $context)->getTotal();
        static::assertSame(ProgressState::class, $state['_class']);
        static::assertFalse($state['migrationRunning']);
        static::assertTrue($state['validMigrationRunToken']);
        static::assertSame(1, $totalAfter - $totalBefore);
        static::assertSame(0, $totalAbortedAfter - $totalAbortedBefore);
        static::assertSame(1, $totalProcessing);

        // Call createMigration without accessToken and without abort running migration
        $totalAbortedBefore = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalBefore = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $result = $this->controller->createMigration($requestWithoutToken, $context);
        $state = json_decode($result->getContent(), true);
        $totalAfter = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $totalAbortedAfter = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalProcessing = $this->runRepo->search($runningCriteria, $context)->getTotal();
        static::assertSame(ProgressState::class, $state['_class']);
        static::assertFalse($state['migrationRunning']);
        static::assertFalse($state['validMigrationRunToken']);
        static::assertSame(0, $totalAfter - $totalBefore);
        static::assertSame(0, $totalAbortedAfter - $totalAbortedBefore);
        static::assertSame(1, $totalProcessing);

        // Get current accessToken and refresh token in request
        /** @var SwagMigrationRunEntity $currentRun */
        $currentRun = $this->runRepo->search($runningCriteria, $context)->first();
        $accessToken = $currentRun->getAccessToken();
        $params[SwagMigrationAccessTokenService::ACCESS_TOKEN_NAME] = $accessToken;
        $requestWithToken = new Request([], $params);

        // Call createMigration with accessToken and with abort running migration
        $totalAbortedBefore = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalBefore = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $result = $this->controller->createMigration($requestWithToken, $context);
        $state = json_decode($result->getContent(), true);
        $totalAfter = $this->runRepo->search(new Criteria(), $context)->getTotal();
        $totalAbortedAfter = $this->runRepo->search($abortedCriteria, $context)->getTotal();
        $totalProcessing = $this->runRepo->search($runningCriteria, $context)->getTotal();
        static::assertSame(ProgressState::class, $state['_class']);
        static::assertFalse($state['migrationRunning']);
        static::assertTrue($state['validMigrationRunToken']);
        static::assertSame(0, $totalAfter - $totalBefore);
        static::assertSame(1, $totalAbortedAfter - $totalAbortedBefore);
        static::assertSame(0, $totalProcessing);
    }

    public function testTakeoverMigration(): void
    {
        $params = [
            'runUuid' => $this->runUuid,
        ];

        $context = Context::createDefaultContext();
        $customerId = Uuid::uuid4()->getHex();
        $context->getSourceContext()->setUserId($customerId);
        $request = new Request([], $params);
        $result = $this->controller->takeoverMigration($request, $context);
        $resultArray = json_decode($result->getContent(), true);
        static::assertArrayHasKey('accessToken', $resultArray);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('accessToken', $resultArray['accessToken']));
        /** @var SwagMigrationRunEntity $run */
        $run = $this->runRepo->search($criteria, $context)->first();
        static::assertSame($run->getUserId(), mb_strtoupper($customerId));

        $this->expectException(MigrationContextPropertyMissingException::class);
        $this->expectExceptionMessage('Required property "runUuid" for migration context is missing');
        $this->controller->takeoverMigration(new Request(), $context);
    }

    public function testCheckConnection(): void
    {
        $context = Context::createDefaultContext();

        $request = new Request([], [
            'connectionId' => $this->connectionId,
        ]);

        $result = $this->controller->checkConnection($request, $context);
        $environmentInformation = json_decode($result->getContent(), true);

        static::assertSame($environmentInformation['totals']['product'], 37);
        static::assertSame($environmentInformation['totals']['customer'], 2);
        static::assertSame($environmentInformation['totals']['category'], 8);
        static::assertSame($environmentInformation['totals']['media'], 23);
        static::assertSame($environmentInformation['totals']['order'], 0);
        static::assertSame($environmentInformation['totals']['translation'], 0);

        static::assertSame($environmentInformation['warningCode'], -1);
        static::assertSame($environmentInformation['warningMessage'], 'No warning.');

        static::assertSame($environmentInformation['errorCode'], -1);
        static::assertSame($environmentInformation['errorMessage'], 'No error.');

        $request = new Request();
        try {
            $this->controller->checkConnection($request, $context);
        } catch (\Exception $e) {
            /* @var MigrationContextPropertyMissingException $e */
            static::assertInstanceOf(MigrationContextPropertyMissingException::class, $e);
            static::assertSame(Response::HTTP_BAD_REQUEST, $e->getStatusCode());
            static::assertSame('Required property "connectionId" for migration context is missing', $e->getMessage());
        }
    }
}
