<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Recipe;
use App\Enum\RecipeStatus;
use App\Form\RecipeIngredientType;
use App\Form\RecipeInstructionType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
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
        yield ChoiceField::new('status')
            ->setChoices(self::statusChoices())
            ->renderAsBadges([
                RecipeStatus::Draft->value => 'warning',
                RecipeStatus::Published->value => 'success',
            ])
            ->setHelp('Recipes created through the MCP write tools arrive as drafts and stay out of the public API until published here.');
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
            ->add('category')
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()));
    }

    /**
     * @return array<string, RecipeStatus> label => enum case, the shape EasyAdmin expects
     */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (RecipeStatus::cases() as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }
}
