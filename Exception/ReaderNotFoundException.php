<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class ReaderNotFoundException extends ShopwareHttpException
{
    public function __construct(string $entityName)
    {
        parent::__construct(
            'Data reader for "{{ entityName }}" not found.',
            ['entityName' => $entityName]
        );
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }

    public function getErrorCode(): string
    {
        return 'SWAG_MIGRATION__READER_NOT_FOUND';
    }
}