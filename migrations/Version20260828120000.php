<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Recipe.status (the draft/published quarantine).
 *
 * Hand-written in three steps rather than as a single NOT NULL column, because
 * `ADD COLUMN ... NOT NULL` without a default fails on any table that already has rows.
 *
 * Every existing row predates the MCP write path, which is the only thing that creates drafts, so
 * they all backfill to 'published' and the public API behaves exactly as it did before.
 */
final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recipe.status (draft|published), backfilling existing rows to published';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE recipe ADD status VARCHAR(255) DEFAULT NULL");
        $this->addSql("UPDATE recipe SET status = 'published' WHERE status IS NULL");
        $this->addSql("ALTER TABLE recipe ALTER COLUMN status SET DEFAULT 'published'");
        $this->addSql('ALTER TABLE recipe ALTER COLUMN status SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP status');
    }
}
