<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Tool;

use App\Entity\Ingredient;
use App\Mcp\Tool\IngredientSearchTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IngredientSearchToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IngredientSearchTool $tool;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tool = self::getContainer()->get(IngredientSearchTool::class);
        $this->suffix = substr(uniqid(), -6);

        // 12 rows, so the 10-result cap is actually exercised.
        for ($i = 0; $i < 12; ++$i) {
            $ingredient = new Ingredient();
            $ingredient->setName(\sprintf('Searchable spice %02d %s', $i, $this->suffix));
            $this->em->persist($ingredient);
        }

        $this->em->flush();
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $names = array_column(($this->tool)('SEARCHABLE SPICE 00 '.strtoupper($this->suffix))['ingredients'], 'name');

        $this->assertContains('Searchable spice 00 '.$this->suffix, $names);
    }

    public function testSearchReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->assertSame(['ingredients' => []], ($this->tool)('zzz_no_such_ingredient_zzz'));
    }

    public function testResultsAreCappedAtTen(): void
    {
        $this->assertCount(10, ($this->tool)('Searchable spice')['ingredients']);
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Ingredient i WHERE i.name LIKE :p')
            ->setParameter('p', '%'.$this->suffix)->execute();
        parent::tearDown();
        $this->em->close();
    }
}
