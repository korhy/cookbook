<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Category;
use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Drafts review queue is the only way a recipe submitted over MCP ever becomes public, so the
 * status filter is load-bearing rather than cosmetic.
 *
 * It shipped broken once: the filter's choices were enum instances, which a plain Symfony
 * ChoiceType cannot turn back into a value on submit ("The selected choice is invalid"), and the
 * labels were looked up in EasyAdmin's translation domain instead of ours. Neither was caught
 * because nothing rendered the page.
 */
final class RecipeStatusFilterTest extends WebTestCase
{
    private const INDEX_URL = '/admin/recipe';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = substr(uniqid(), -6);

        $category = new Category();
        $category->setName('Filter test category '.$this->suffix);
        $this->em->persist($category);

        $draft = new Recipe();
        $draft->setTitle('Filter draft recipe '.$this->suffix)
            ->setDescription('Submitted over MCP, awaiting review.')
            ->setCategory($category)
            ->setStatus(RecipeStatus::Draft);
        $this->em->persist($draft);

        $published = new Recipe();
        $published->setTitle('Filter published recipe '.$this->suffix)
            ->setDescription('Authored in the back-office.')
            ->setCategory($category);
        $this->em->persist($published);

        $this->em->flush();

        // 'admin' is the firewall name in security.yaml; loginUser() defaults to 'main'.
        $this->client->loginUser($this->admin(), 'admin');
    }

    public function testTheIndexRendersWithTheStatusColumn(): void
    {
        $this->client->request('GET', self::INDEX_URL);

        $this->assertResponseIsSuccessful();
    }

    public function testFilteringOnDraftKeepsDraftsAndDropsPublished(): void
    {
        $crawler = $this->client->request('GET', self::INDEX_URL.'?filters[status][comparison]==&filters[status][value]=draft');

        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        $this->assertStringNotContainsString('The selected choice is invalid', $html);
        $this->assertStringContainsString('Filter draft recipe '.$this->suffix, $html);
        $this->assertStringNotContainsString('Filter published recipe '.$this->suffix, $html);
    }

    public function testFilteringOnPublishedDropsDrafts(): void
    {
        $crawler = $this->client->request('GET', self::INDEX_URL.'?filters[status][comparison]==&filters[status][value]=published');

        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        $this->assertStringNotContainsString('The selected choice is invalid', $html);
        $this->assertStringContainsString('Filter published recipe '.$this->suffix, $html);
        $this->assertStringNotContainsString('Filter draft recipe '.$this->suffix, $html);
    }

    /**
     * The "Drafts" sidebar entry is the review queue's entry point: a link that silently returns
     * everything would defeat the whole quarantine.
     */
    public function testTheDraftsMenuLinkShowsOnlyDrafts(): void
    {
        $crawler = $this->client->request('GET', self::INDEX_URL);
        $this->assertResponseIsSuccessful();

        $link = $crawler->filter('a')->reduce(
            static fn ($node): bool => 'Drafts' === trim($node->text())
        );

        $this->assertGreaterThan(0, $link->count(), 'No "Drafts" entry in the admin menu.');

        $followed = $this->client->click($link->first()->link());
        $this->assertResponseIsSuccessful();

        $html = $followed->html();
        $this->assertStringNotContainsString('The selected choice is invalid', $html);
        $this->assertStringContainsString('Filter draft recipe '.$this->suffix, $html);
        $this->assertStringNotContainsString('Filter published recipe '.$this->suffix, $html);
    }

    /**
     * The filter form is rendered through its own endpoint. The labels must come from our
     * `messages` catalogue, not EasyAdmin's domain, or the raw key is displayed.
     */
    public function testTheFilterLabelsAreTranslatedNotRawKeys(): void
    {
        $crawler = $this->client->request('GET', '/admin/recipe/render-filters?filters[status][comparison]==&filters[status][value]=draft');

        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        $this->assertStringNotContainsString('recipe_status.draft', $html);
        $this->assertStringNotContainsString('recipe_status.published', $html);
    }

    private function admin(): Admin
    {
        $admin = $this->em->getRepository(Admin::class)->findOneBy(['username' => 'filter_test_admin']);

        if (null === $admin) {
            $admin = new Admin();
            $admin->setUsername('filter_test_admin');
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
        $this->em->createQuery('DELETE FROM App\Entity\Recipe r WHERE r.title LIKE :p')
            ->setParameter('p', '%'.$this->suffix)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category c WHERE c.name LIKE :p')
            ->setParameter('p', '%'.$this->suffix)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Admin a WHERE a.username = :u')
            ->setParameter('u', 'filter_test_admin')->execute();
        parent::tearDown();
        $this->em->close();
    }
}
