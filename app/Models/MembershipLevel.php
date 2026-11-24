<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\MembershipLevelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Membership level: Basic, Silver, Gold, Platinum, ... with fee and validity.
 */
class MembershipLevel extends Model
{
    /** @use HasFactory<MembershipLevelFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'fee',
        'validity_months',
        'benefits',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'validity_months' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function feeCents(): int
    {
        return Money::fromDecimal($this->fee);
    }

    public function formattedFee(): string
    {
        return Money::format($this->feeCents());
    }

    /** "12 months" / "1 month" */
    public function validityLabel(): string
    {
        return $this->validity_months.' '.str('month')->plural($this->validity_months);
    }

    /** @return list<string> benefits split into lines */
    public function benefitList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', (string) $this->benefits) ?: [])));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MembershipTransaction::class);
    }
}
