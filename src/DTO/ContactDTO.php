<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;
class ContactDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 255)]
    public string $message = '';
}
