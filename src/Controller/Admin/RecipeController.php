<?php

namespace App\Controller\Admin;

use App\Entity\Recipe;
use App\Form\RecipeIngredientType;
use App\Form\RecipeInstructionType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

final class RecipeController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Recipe::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('id')->onlyOnIndex();
        yield TextField::new('title');
        yield AssociationField::new('category')->autocomplete();
        yield TextareaField::new('description');
        yield IntegerField::new('duration');
        yield TextField::new('thumbnailFile')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();
        yield CollectionField::new('recipeIngredients', 'Ingredients')
            ->setEntryType(RecipeIngredientType::class)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex()
            ->setFormTypeOptions([
                'by_reference' => false,
            ])
            ->onlyOnForms();
        yield CollectionField::new('instructions')
            ->setEntryType(RecipeInstructionType::class)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex()
            ->setFormTypeOptions([
                'by_reference' => false,
            ])
            ->onlyOnForms();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('id')
            ->add('category');
    }
}
