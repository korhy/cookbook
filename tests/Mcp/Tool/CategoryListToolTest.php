<?php

namespace App\Tests\Mcp\Tool;

use App\Entity\Category;
use App\Mcp\Tool\CategoryListTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CategoryListToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CategoryListTool $tool;
    private Category $category;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->tool = self::getContainer()->get(CategoryListTool::class);

        $this->category = new Category();
        $this->category->setName('Test category '.uniqid());
        $this->em->persist($this->category);
        $this->em->flush();
    }

    public function testListReturnsCategories(): void
    {
        $result = ($this->tool)();

        $this->assertNotEmpty($result['categories']);
        foreach ($result['categories'] as $category) {
            $this->assertArrayHasKey('id', $category);
            $this->assertArrayHasKey('name', $category);
            $this->assertArrayHasKey('slug', $category);
        }
    }

    protected function tearDown(): void
    {
        $this->em->remove($this->category);
        $this->em->flush();
        parent::tearDown();
        $this->em->close();
    }
}
