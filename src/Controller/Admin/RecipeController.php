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
            ->setChoices(self::statusFieldChoices())
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
            ->add(
                ChoiceFilter::new('status')
                    ->setChoices(self::statusFilterChoices())
                    // ChoiceFilter::new() pins the domain to EasyAdminBundle, where our keys do
                    // not live, so the raw `recipe_status.draft` would be rendered instead of the
                    // label. The choice labels come from the inner ChoiceType.
                    ->setFormTypeOption('value_type_options.choice_translation_domain', 'messages')
            );
    }

    /**
     * Choices for the *field*: EasyAdmin's ChoiceConfigurator understands backed enums and
     * normalises them to their value before rendering.
     *
     * @return array<string, RecipeStatus> label => enum case
     */
    private static function statusFieldChoices(): array
    {
        $choices = [];
        foreach (RecipeStatus::cases() as $case) {
            $choices[$case->label()] = $case;
        }

        return $choices;
    }

    /**
     * Choices for the *filter*, which are scalar and not enum cases.
     *
     * The filter's values go through a plain Symfony ChoiceType, which has no way to turn the
     * submitted string back into an enum instance without a choice_value callback — so enum cases
     * here render as "The selected choice is invalid" on submit. The backed values round-trip
     * natively, and DQL compares them against the enumType column without conversion.
     *
     * @return array<string, string> label => backed value
     */
    private static function statusFilterChoices(): array
    {
        $choices = [];
        foreach (RecipeStatus::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
