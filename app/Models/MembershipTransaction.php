<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Support\Money;
use Database\Factories\MembershipTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A membership application or renewal. Amount and validity are snapshots of
 * the level at creation time; amount_paid is maintained by PaymentService.
 *
 * Status, totals, approval and reference fields are intentionally NOT mass
 * assignable: they change only through the service layer.
 */
class MembershipTransaction extends Model
{
    /** @use HasFactory<MembershipTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'validity_months' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ---- Money helpers (integer cents, never floats) --------------------

    public function amountCents(): int
    {
        return Money::fromDecimal($this->amount);
    }

    public function paidCents(): int
    {
        return Money::fromDecimal($this->amount_paid);
    }

    public function balanceCents(): int
    {
        return $this->amountCents() - $this->paidCents();
    }

    public function formattedBalance(): string
    {
        return Money::format($this->balanceCents());
    }

    // ---- Relationships ------------------------------------------------

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(MembershipLevel::class, 'membership_level_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function validPayments(): HasMany
    {
        return $this->payments()->whereNull('voided_at');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
