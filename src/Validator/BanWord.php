<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class BanWord extends Constraint
{
    public string $message = 'This contains an illegal word: "{{ value }}".';
    public array $banWords = [];

    // You can use #[HasNamedArguments] to make some constraint options required.
    // All configurable options must be passed to the constructor.
    public function __construct(
        public string $mode = 'strict',
        ?array $groups = null,
        mixed $payload = null,
        ?array $banWords = null,
        ?string $message = null
    ) {
        $this->banWords = $banWords ?? $this->banWords;
        $this->message = $message ?? $this->message;
        parent::__construct($groups, $payload);
    }
}
