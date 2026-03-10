<?php

namespace App\Form;

use App\Entity\Instruction;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecipeInstructionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextType::class, [
                'label' => 'Content',
                'attr' => [
                    'placeholder' => 'e.g., 200',
                ],
            ])
            ->add('position', TextType::class, [
                'label' => 'Position',
                'attr' => [
                    'placeholder' => 'e.g., 1',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Instruction::class,
        ]);
    }
}