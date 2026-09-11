<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class BanWord extends Constraint
{
    public string $message = 'This contains an illegal word: "{{ value }}".';

    /**
     * @var string[]
     */
    public array $banWords = [];

    /**
     * @param string[]|null $banWords
     * @param string[]|null $groups
     */
    public function __construct(
        ?array $groups = null,
        mixed $payload = null,
        ?array $banWords = null,
        ?string $message = null,
    ) {
        $this->banWords = $banWords ?? $this->banWords;
        $this->message = $message ?? $this->message;
        parent::__construct($groups, $payload);
    }
}
