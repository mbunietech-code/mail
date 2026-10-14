<?php

namespace App\Mailbox\Data;

final readonly class Address
{
    public function __construct(public string $email, public ?string $name = null) {}

    /** @return array{email: string, name: string|null} */
    public function toArray(): array
    {
        return ['email' => $this->email, 'name' => $this->name];
    }
}
