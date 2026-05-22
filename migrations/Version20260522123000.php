<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist mobile/API cart lines in MySQL (replaces ephemeral cache cart).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cart_line (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL, INDEX IDX_60C559C0A76ED395 (user_id), INDEX IDX_60C559C04584665A (product_id), UNIQUE INDEX uniq_cart_line_user_product (user_id, product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cart_line ADD CONSTRAINT FK_60C559C0A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cart_line ADD CONSTRAINT FK_60C559C04584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart_line DROP FOREIGN KEY FK_60C559C0A76ED395');
        $this->addSql('ALTER TABLE cart_line DROP FOREIGN KEY FK_60C559C04584665A');
        $this->addSql('DROP TABLE cart_line');
    }
}
