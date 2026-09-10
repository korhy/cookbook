<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\Recipe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * `BanWord` is the only custom constraint in the project, and it is the one thing standing between
 * an MCP-authored draft and a title nobody wants published — `RecipeDraftFactory` runs the entity
 * through the validator precisely so this applies on that path too.
 *
 * Exercised through the real validator rather than in isolation, because the attribute wiring on
 * `Recipe::$title` is half of what can break.
 */
class BanWordValidatorTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testACleanTitlePasses(): void
    {
        $this->assertCount(0, $this->violationsFor('Chocolate cake'));
    }

    public function testABannedWordIsRejected(): void
    {
        $violations = $this->violationsFor('Totally not spam at all');

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('illegal word', (string) $violations[0]->getMessage());
    }

    public function testTheMatchIsCaseInsensitive(): void
    {
        $this->assertCount(1, $this->violationsFor('SPAM sandwich recipe'));
    }

    public function testTheMatchIsOnSubstringsNotWholeWords(): void
    {
        // Documents the current behaviour: str_contains, not a word-boundary match. "Spambourg"
        // trips it. Worth knowing before someone bans a short word.
        $this->assertCount(1, $this->violationsFor('Spamburger deluxe'));
    }

    public function testEveryBannedWordPresentRaisesItsOwnViolation(): void
    {
        $this->assertCount(2, $this->violationsFor('spam and viagra casserole'));
    }

    /**
     * @return \Symfony\Component\Validator\ConstraintViolationListInterface<\Symfony\Component\Validator\ConstraintViolationInterface>
     */
    private function violationsFor(string $title): object
    {
        $recipe = new Recipe();
        $recipe->setTitle($title);

        // validateProperty: the other constraints on the entity are not what this test is about.
        return $this->validator->validateProperty($recipe, 'title');
    }
}
