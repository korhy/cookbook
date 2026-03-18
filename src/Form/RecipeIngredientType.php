<?php

namespace App\Form;

use App\Entity\RecipeIngredient;
use App\Enum\IngredientUnit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecipeIngredientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredient', IngredientAutocompleteField::class, [
                'label' => 'Ingredient',
            ])
            ->add('quantity', TextType::class, [
                'label' => 'Quantity',
                'attr' => [
                    'placeholder' => 'e.g., 200',
                ],
            ])
            ->add('unit', EnumType::class, [
                'label' => 'Unit',
                'class' => IngredientUnit::class,
                'placeholder' => 'Select a unit',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecipeIngredient::class,
        ]);
    }
}
