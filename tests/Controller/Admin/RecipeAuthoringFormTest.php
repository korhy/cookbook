<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Category;
use App\Entity\Ingredient;
use App\Entity\Instruction;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Enum\IngredientUnit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Smoke test for the authoring form, which is where every recipe is actually written.
 *
 * `testing.md` says `configureFields()` needs no test, and that still holds — nothing here asserts
 * a field declaration. What it asserts is that the page *renders its widgets*, because three of
 * them are assembled by dependencies rather than by this repository: the thumbnail input comes from
 * VichUploader, the ingredient rows from EasyAdmin's complex CollectionField, and the category
 * picker from Symfony UX Autocomplete. A dependency refresh moves all three at once, and before
 * this test nothing in the suite opened the page — the same blind spot that let the status filter
 * ship broken (see RecipeStatusFilterTest).
 *
 * Assertions are on form control names and the collection prototype, not on markup or classes, so
 * an upstream restyle does not break the test while a widget that stops rendering does.
 */
final class RecipeAuthoringFormTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;
    private int $recipeId;
    private string $title;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = substr(uniqid(), -6);
        $this->title = 'Authoring recipe '.$this->suffix;

        $category = new Category();
        $category->setName('Authoring category '.$this->suffix);
        $this->em->persist($category);

        $ingredient = new Ingredient();
        $ingredient->setName('Authoring ingredient '.$this->suffix);
        $this->em->persist($ingredient);

        $recipe = new Recipe();
        $recipe->setTitle($this->title)
            ->setDescription('Rendered by the authoring form test.')
            ->setCategory($category)
            ->setDuration(30);
        $this->em->persist($recipe);

        $line = new RecipeIngredient();
        $line->setRecipe($recipe)->setIngredient($ingredient)->setQuantity(200.0)->setUnit(IngredientUnit::Gram);
        $recipe->addRecipeIngredient($line);
        $this->em->persist($line);

        $step = new Instruction();
        $step->setRecipe($recipe)->setContent('Mix everything.')->setPosition(1);
        $recipe->addInstruction($step);
        $this->em->persist($step);

        $this->em->flush();
        $this->recipeId = (int) $recipe->getId();

        // A real request arrives on a cold unit of work. Without this the form reads the
        // collections out of the identity map, where they are still the empty ArrayCollection the
        // constructor made, and both rows go missing from the page for a reason that has nothing
        // to do with the code under test.
        $this->em->clear();

        // 'admin' is the firewall name in security.yaml; loginUser() defaults to 'main'.
        $this->client->loginUser($this->admin(), 'admin');
    }

    public function testTheNewFormRendersEveryAuthoringWidget(): void
    {
        $crawler = $this->client->request('GET', '/admin/recipe/new');

        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('input[name="Recipe[title]"]'));
        self::assertCount(1, $crawler->filter('select[name="Recipe[status]"]'));
        self::assertCount(1, $crawler->filter('textarea[name="Recipe[description]"]'));
        self::assertCount(1, $crawler->filter('input[name="Recipe[duration]"]'));

        // AssociationField::autocomplete() — the picker is a select fed by /admin/recipe/autocomplete.
        self::assertCount(1, $crawler->filter('select[name="Recipe[category][autocomplete]"]'));

        // VichImageType, on the non-persisted thumbnailFile property.
        self::assertCount(1, $crawler->filter('input[type="file"][name="Recipe[thumbnailFile][file]"]'));

        // A complex CollectionField renders no row on an empty form: the add button clones this
        // prototype, so an absent prototype means the editor cannot add a line at all.
        $prototypes = $crawler->filter('.field-collection[data-prototype]');
        self::assertCount(2, $prototypes, 'Expected a prototype for both recipeIngredients and instructions.');

        // Crawler::html() returns the first node only, so read the attribute off each one.
        $markup = implode('', $prototypes->each(static fn (Crawler $node): string => (string) $node->attr('data-prototype')));
        self::assertStringContainsString('Recipe_recipeIngredients_', $markup);
        self::assertStringContainsString('Recipe_instructions_', $markup);
    }

    public function testTheEditFormRendersTheExistingIngredientAndInstructionRows(): void
    {
        $crawler = $this->client->request('GET', '/admin/recipe/'.$this->recipeId.'/edit');

        self::assertResponseIsSuccessful();

        self::assertSame($this->title, $crawler->filter('input[name="Recipe[title]"]')->attr('value'));

        // The join entity carries quantity and unit, so a row is three controls and not one.
        self::assertCount(1, $crawler->filter('select[name="Recipe[recipeIngredients][0][ingredient]"]'));
        self::assertSame('200', $crawler->filter('input[name="Recipe[recipeIngredients][0][quantity]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('select[name="Recipe[recipeIngredients][0][unit]"]'));

        self::assertSame('Mix everything.', $crawler->filter('input[name="Recipe[instructions][0][content]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('input[name="Recipe[instructions][0][position]"]'));

        // The UX Autocomplete Stimulus controller is only attached once a value is selected, which
        // is why this is asserted on edit and not on the empty form.
        self::assertStringContainsString(
            'symfony--ux-autocomplete--autocomplete',
            $crawler->html(),
            'The ingredient/category autocomplete controller is not wired on the edit form.'
        );
    }

    private function admin(): Admin
    {
        $admin = $this->em->getRepository(Admin::class)->findOneBy(['username' => 'authoring_test_admin']);

        if (null === $admin) {
            $admin = new Admin();
            $admin->setUsername('authoring_test_admin');
            $admin->setPassword(
                static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($admin, 'password')
            );
            $admin->setRoles(['ROLE_ADMIN']);
            $this->em->persist($admin);
            $this->em->flush();
        }

        return $admin;
    }

    protected function tearDown(): void
    {
        // Neither foreign key cascades and a DQL delete ignores cascade: remove, so the owned rows
        // go before the recipe.
        $like = '%'.$this->suffix;
        $this->em->createQuery('DELETE FROM App\Entity\Instruction i WHERE i.recipe IN (SELECT r.id FROM App\Entity\Recipe r WHERE r.title LIKE :p)')
            ->setParameter('p', $like)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\RecipeIngredient ri WHERE ri.recipe IN (SELECT r2.id FROM App\Entity\Recipe r2 WHERE r2.title LIKE :p)')
            ->setParameter('p', $like)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Recipe r3 WHERE r3.title LIKE :p')
            ->setParameter('p', $like)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Ingredient g WHERE g.name LIKE :p')
            ->setParameter('p', $like)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :p')
            ->setParameter('p', $like)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :u')
            ->setParameter('u', 'authoring_test_admin')->execute();
        parent::tearDown();
        $this->em->close();
    }
}
