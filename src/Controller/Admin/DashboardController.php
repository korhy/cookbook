<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\RecipeStatus;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'app_dashboard')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        $routeBuilder = $this->container->get(AdminUrlGenerator::class);
        $url = $routeBuilder->setController(RecipeController::class)->generateUrl();

        return $this->redirect($url);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Cookbook');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addAssetMapperEntry('admin');
    }

    /**
     * MenuItem::linkTo() takes the CRUD controller, not the entity: linkToCrud() is deprecated
     * since easyadmin 4.29 and the argument order differs. setQueryParameter() still applies —
     * it comes from MenuItemTrait, which ControllerMenuItem uses too.
     */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkTo(RecipeController::class, 'Recipe', 'fas fa-utensils');
        // Review queue for recipes created through the MCP write tools: they land as drafts and
        // are invisible to /api/v1 until published from here.
        yield MenuItem::linkTo(RecipeController::class, 'Drafts', 'fas fa-inbox')
            ->setQueryParameter('filters', [
                'status' => ['comparison' => '=', 'value' => RecipeStatus::Draft->value],
            ]);
        yield MenuItem::linkTo(CategoryController::class, 'Category', 'fas fa-list');
        yield MenuItem::linkTo(IngredientController::class, 'Ingredient', 'fas fa-carrot');
        yield MenuItem::linkTo(InstructionController::class, 'Instruction', 'fas fa-clipboard-list');
    }
}
