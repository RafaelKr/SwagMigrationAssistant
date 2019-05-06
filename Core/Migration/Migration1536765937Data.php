<?php declare(strict_types=1);

namespace SwagMigrationNext\Core\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1536765937Data extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1536765937;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
CREATE TABLE `swag_migration_data` (
    `id`              BINARY(16)  NOT NULL,
    `run_id`          BINARY(16)  NOT NULL,
    `auto_increment`  BIGINT unsigned NOT NULL AUTO_INCREMENT,
    `entity`          VARCHAR(255),
    `raw`             LONGTEXT,
    `converted`       LONGTEXT,
    `convert_failure` TINYINT(1)  NOT NULL DEFAULT '0',
    `unmapped`        LONGTEXT,
    `written`         TINYINT(1)  NOT NULL DEFAULT '0',
    `write_failure`   TINYINT(1)  NOT NULL DEFAULT '0',
    `created_at`      DATETIME(3) NOT NULL,
    `updated_at`      DATETIME(3),
    KEY `idx.swag_migration_data.auto_increment` (`auto_increment`),
    KEY `idx.swag_migration_data.entity__run_id` (`entity`, `run_id`),
    CONSTRAINT `json.swag_migration_data.raw` CHECK (JSON_VALID(`raw`)),
    CONSTRAINT `json.swag_migration_data.converted` CHECK (JSON_VALID(`converted`)),
    CONSTRAINT `json.swag_migration_data.unmapped` CHECK (JSON_VALID(`unmapped`)),
    PRIMARY KEY (`id`),
    CONSTRAINT `fk.swag_migration_run.run_id` FOREIGN KEY (`run_id`) REFERENCES `swag_migration_run` (`id`)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
)
    ENGINE = InnoDB
    AUTO_INCREMENT=1
    DEFAULT CHARSET = utf8mb4
    COLLATE = utf8mb4_unicode_ci;
SQL;
        $connection->executeQuery($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
