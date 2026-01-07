<?php

namespace App\Controller\Admin;

use App\Entity\Recipe;
use App\Form\RecipeType;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/recipe', name: 'admin.recipe.')]
#[IsGranted('ROLE_USER')]
final class RecipeController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(
        RecipeRepository $recipeRepository,
        PaginatorInterface $paginator,
        Request $request
    ): Response {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);

        // Get the query from repository
        $query = $recipeRepository->getRecipesWithCategoryQueryBuilder()->getQuery();
        
        // Paginate the results
        $recipes = $paginator->paginate($query, $page, $limit);

        return $this->render('admin/recipe/index.html.twig', [
            'recipes' => $recipes,
            'currentPage' => $page,
            'limit' => $limit,
            'maxPages' => ceil($recipes->getTotalItemCount() / $limit)
        ]);
    }

    #[Route('/recipe/{slug}-{id}', name: 'show', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]+'])]
    public function show(Request $request, string $slug, int $id, RecipeRepository $recipeRepository): Response
    {
        $recipe = $recipeRepository->find($id);

        if($recipe->getSlug() != $slug) {
            return $this->redirectToRoute('admin.recipe.show', ['id' => $id, 'slug' => $recipe->getSlug()]);
        }

        return $this->render('admin/recipe/show.html.twig', [
            'recipe' => $recipe
        ]);
    }

    /*
     * Create a recipe
     */
    #[Route('/create', name: 'create')]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {
        $recipe = new Recipe();
        $form = $this->createForm(RecipeType::class, $recipe);

        $form->handleRequest($request);

        if($form->isSubmitted() && $form->isValid() ) {
            $entityManager->persist($recipe);
            $entityManager->flush();
            $this->addFlash('success', 'New recipe created');

            return $this->redirectToRoute('admin.recipe.index');
        }

        return $this->render('admin/recipe/create.html.twig', [
            'form' => $form
        ]);
    }

    /*
     * Edit a recipe
     * */
    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Recipe $recipe, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(RecipeType::class, $recipe);
        // Handle form submission
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('thumbnailFile')->getData();
            $filename = $recipe->getId() . '.' . $file->getClientOriginalExtension();
            $file->move($this->getParameter('kernel.project_dir' . '/public/recipes/images'), $filename);
            $recipe->setThumbnail($filename);
            // Save modification
            $entityManager->flush();
            $this->addFlash('success', 'Recipe save!');
            return $this->redirectToRoute('admin.recipe.index');
        }

        return $this->render('admin/recipe/edit.html.twig', [
            'recipe' => $recipe,
            'form' => $form
        ]);
    }

   /*
    * Remove a recipe
    */
    #[Route('/{id}', name: 'remove', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function remove(Request $request, Recipe $recipe, EntityManagerInterface $entityManager): RedirectResponse
    {
        $entityManager->remove($recipe);
        $entityManager->flush();
        $this->addFlash('success', 'Recipe remove!');
        return $this->redirectToRoute('admin.recipe.index');
    }
}
