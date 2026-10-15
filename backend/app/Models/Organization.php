<?php

namespace App\Models;

use App\Support\CompanyDomains;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property list<string> $allowed_domains
 */
#[Fillable(['name', 'allowed_domains'])]
class Organization extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['allowed_domains' => 'array'];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allowsEmail(string $email): bool
    {
        $domain = CompanyDomains::domainOf($email);

        return $domain !== null && in_array($domain, array_map('strtolower', $this->allowed_domains), true);
    }
}
