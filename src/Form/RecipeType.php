<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Recipe;
use App\Service\SluggerService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Sequentially;

class RecipeType extends AbstractType
{
    public function __construct(private SluggerService $sluggerService)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title')
            ->add('slug', TextType::class, /*[
                'required' => false,
                'constraints' => new Sequentially([
                    new Length(min: 10),
                    new Regex('^[a-z0-9]+(?:-[a-z0-9]+)*$')
                ])
            ]*/)
            ->add('thumbnailFile', FileType::class, [
                'mapped' => false,
                'constraints' => [
                    new Image()
                ]
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'label' => 'Category',
                'choice_label' => 'name',
            ])
            ->add('description')
            ->add('duration')
            ->add('save', SubmitType::class, [
                'label' => 'Save'
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, $this->autoSlug(...))
            ->addEventListener(FormEvents::POST_SUBMIT, $this->setAt(...))

        ;
    }

    public function autoSlug(PreSubmitEvent $event): void
    {
        $data = $event->getData();

        if(empty($data['slug'])) {
            $slugger = $this->sluggerService;
            $data['slug'] = $slugger->generateSlug($data['title']);
            $event->setData($data);
        }
    }

    public function setAt(PostSubmitEvent $event)
    {
        $data = $event->getData();

        if (!($data instanceof Recipe)) {
            return;
        }

        $now = new \DateTimeImmutable();

        $data->setUpdatedAt($now);

        if (!$data->getId()) {
            $data->setCreatedAt($now);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Recipe::class,
        ]);
    }
}
