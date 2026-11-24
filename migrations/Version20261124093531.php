<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces three single-column indexes on messenger_messages with one composite index.
 *
 * Not an application schema change: the table belongs to Symfony Messenger's Doctrine transport,
 * and the 7.4 patch line changed the index its own schema declares. Without this migration
 * `doctrine:schema:validate` stays red, which would mask a real mapping drift later.
 *
 * Index-only, and safe at container boot: no table is rewritten and no row is touched. CREATE INDEX
 * does take a write lock on messenger_messages for its duration — acceptable on a transport queue
 * that holds at most a handful of rows. CONCURRENTLY would avoid the lock but cannot run inside the
 * transaction a migration gets, which is not a trade worth making here.
 */
final class Version20261124093531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collapse the three messenger_messages indexes into one composite index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_75ea56e0e3bd61ce');
        $this->addSql('DROP INDEX IF EXISTS idx_75ea56e016ba31db');
        $this->addSql('DROP INDEX IF EXISTS idx_75ea56e0fb7336f0');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750');
        $this->addSql('CREATE INDEX idx_75ea56e0e3bd61ce ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX idx_75ea56e016ba31db ON messenger_messages (delivered_at)');
        $this->addSql('CREATE INDEX idx_75ea56e0fb7336f0 ON messenger_messages (queue_name)');
    }
}
