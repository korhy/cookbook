<?php

namespace App\Controller\Admin;

use App\Entity\Instruction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class InstructionController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Instruction::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('id')->onlyOnIndex();
        yield TextField::new('recipe');
        yield TextField::new('content');
        yield IntegerField::new('position');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('id')
            ->add('content')
            ->add('position')
            ->add('recipe');
    }
}