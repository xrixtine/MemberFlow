<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Policies\PaymentPolicy;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class PaymentPagesTest extends PaymentTestCase
{
    public function test_payment_history_lists_all_required_columns(): void
    {
        $transaction = $this->application($this->pendingMember(['first_name' => 'Rhea', 'last_name' => 'Villanueva', 'membership_level_id' => $this->silver->id]));
        $payment = $this->pay($transaction, '400', 'gcash');

        $this->actingAs($this->admin)->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($payment->receipt_no)
            ->assertSee('Rhea')->assertSee('Villanueva')
            ->assertSee($transaction->member->membership_no)
            ->assertSee($transaction->reference_no)
            ->assertSee('GCash')
            ->assertSee('₱400.00')
            ->assertSee($payment->paid_at->format('M j, Y'))
            ->assertSee('Valid')
            ->assertSee($this->admin->name)
            ->assertSee(route('admin.payments.show', $payment));
    }

    public function test_history_search_filters_and_pagination(): void
    {
        $rhea = $this->pay($this->application($this->pendingMember(['last_name' => 'Villanueva', 'membership_level_id' => $this->silver->id])), '100', 'gcash');
        $ben = $this->pay($this->application($this->pendingMember(['last_name' => 'Bautista', 'membership_level_id' => $this->silver->id])), '200', 'bank_transfer');
        $this->payments->void($ben, $this->admin, 'Duplicate');

        $ids = fn (array $q) => $this->actingAs($this->admin)->get(route('admin.payments.index', $q))->viewData('payments')->pluck('id')->all();

        $this->assertSame([$rhea->id], $ids(['q' => 'Villanueva']));
        $this->assertSame([$ben->id], $ids(['q' => $ben->receipt_no]));
        $this->assertSame([$rhea->id], $ids(['q' => $rhea->transaction->reference_no]));
        $this->assertSame([$rhea->id], $ids(['method' => 'gcash']));
        $this->assertSame([$ben->id], $ids(['state' => 'voided']));
        $this->assertSame([$rhea->id], $ids(['state' => 'valid']));
        $this->assertCount(2, $ids(['from' => today()->toDateString(), 'to' => today()->toDateString()]));
        $this->assertCount(0, $ids(['from' => today()->addDay()->toDateString()]));

        $this->actingAs($this->admin)->get(route('admin.payments.index', ['q' => 'nobody']))->assertSee('No payments match your filters');

        foreach (range(1, 15) as $i) {
            $this->pay($this->thousandPesoApplication(), '10');
        }
        $this->assertCount(2, $this->actingAs($this->admin)->get(route('admin.payments.index', ['page' => 2]))->viewData('payments'));
    }

    public function test_empty_payment_history(): void
    {
        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertOk()->assertSee('No payments yet');
    }

    public function test_record_payment_picker_lists_only_transactions_with_a_balance(): void
    {
        $open = $this->thousandPesoApplication();
        $paid = $this->thousandPesoApplication();
        $this->pay($paid, '1000');

        $this->actingAs($this->admin)->get(route('admin.payments.create'))
            ->assertOk()
            ->assertSee($open->reference_no)
            ->assertDontSee($paid->reference_no);
    }

    public function test_payment_form_shows_balance_preview_and_a_fresh_idempotency_key(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->pay($transaction, '250');

        $first = $this->actingAs($this->admin)->get(route('admin.payments.create', ['transaction' => $transaction->id]));
        $first->assertOk()
            ->assertSee('data-balance-cents="75000"', false)
            ->assertSee('value="750.00"', false)
            ->assertSee('Cash')->assertSee('GCash')->assertSee('Bank transfer');

        preg_match('/name="idempotency_key" value="([^"]+)"/', $first->getContent(), $a);
        preg_match('/name="idempotency_key" value="([^"]+)"/', $this->actingAs($this->admin)->get(route('admin.payments.create', ['transaction' => $transaction->id]))->getContent(), $b);
        $this->assertNotSame($a[1], $b[1]);
    }

    public function test_payment_form_explains_when_a_transaction_cannot_be_paid(): void
    {
        $transaction = $this->thousandPesoApplication();
        $this->pay($transaction, '1000');

        $this->actingAs($this->admin)->get(route('admin.payments.create', ['transaction' => $transaction->id]))
            ->assertOk()
            ->assertSee('This transaction is already fully paid.')
            ->assertDontSee('name="idempotency_key"', false);
    }

    public function test_details_page_shows_payment_transaction_and_sibling_payments(): void
    {
        $transaction = $this->thousandPesoApplication();
        $first = $this->pay($transaction, '400', 'gcash');
        $second = $this->pay($transaction, '100');

        $this->actingAs($this->admin)->get(route('admin.payments.show', $second))
            ->assertOk()
            ->assertSee($second->receipt_no)
            ->assertSee($first->receipt_no)
            ->assertSee($transaction->reference_no)
            ->assertSee('₱1,000.00 · ₱500.00 · ', false) // amount · paid · balance
            ->assertSee('Void payment');
    }

    public function test_receipt_renders_and_voided_receipt_says_void_with_reason(): void
    {
        $transaction = $this->thousandPesoApplication();
        $payment = $this->pay($transaction, '400', 'bank_transfer');

        $this->actingAs($this->admin)->get(route('admin.payments.receipt', $payment))
            ->assertOk()
            ->assertSee('Payment acknowledgement receipt')
            ->assertSee($payment->receipt_no)
            ->assertSee($transaction->reference_no)
            ->assertSee($transaction->member->fullName())
            ->assertSee($transaction->member->membership_no)
            ->assertSee('₱400.00')
            ->assertSee('Bank transfer')
            ->assertSee('Silver')
            ->assertSee($this->admin->name)
            ->assertSee('VALID')
            ->assertSee('not an official receipt or invoice for tax purposes')
            ->assertSee('window.print()', false);

        $this->payments->void($payment, $this->admin, 'Customer reversed the transfer');

        $this->actingAs($this->admin)->get(route('admin.payments.receipt', $payment))
            ->assertOk()
            ->assertSee('VOID')
            ->assertSee('VOIDED')
            ->assertSee('Customer reversed the transfer');
    }

    public function test_transaction_and_history_pages_integrate_payments(): void
    {
        $transaction = $this->thousandPesoApplication();
        $payment = $this->pay($transaction, '400');

        $this->actingAs($this->admin)->get(route('admin.transactions.show', $transaction))
            ->assertSee(route('admin.payments.create', ['transaction' => $transaction->id]))
            ->assertSee(route('admin.payments.show', $payment))
            ->assertSee(route('admin.payments.receipt', $payment));

        $this->pay($transaction, '600');
        $this->actingAs($this->admin)->get(route('admin.transactions.show', $transaction))
            ->assertDontSee(route('admin.payments.create', ['transaction' => $transaction->id]));

        $this->actingAs($this->admin)->get(route('admin.members.history', $transaction->member))
            ->assertSee($payment->receipt_no)
            ->assertSee(route('admin.payments.show', $payment));
    }

    public function test_staff_and_guests_cannot_access_payments(): void
    {
        $staff = User::factory()->member()->create();
        $transaction = $this->thousandPesoApplication();
        $payment = $this->pay($transaction, '100');

        $requests = [
            ['get', route('admin.payments.index'), []],
            ['get', route('admin.payments.create'), []],
            ['get', route('admin.payments.create', ['transaction' => $transaction->id]), []],
            ['post', route('admin.payments.store'), ['membership_transaction_id' => $transaction->id, 'amount' => '100', 'method' => 'cash', 'paid_at' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid()]],
            ['get', route('admin.payments.show', $payment), []],
            ['get', route('admin.payments.receipt', $payment), []],
            ['patch', route('admin.payments.void', $payment), ['void_reason' => 'x']],
        ];

        foreach ($requests as [$method, $url, $data]) {
            $this->actingAs($staff)->{$method}($url, $data)->assertForbidden();
        }

        auth()->logout();
        foreach ($requests as [$method, $url, $data]) {
            $this->{$method}($url, $data)->assertRedirect(route('login'));
        }

        $this->assertSame(1, $transaction->payments()->count());
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_policy_allows_only_active_administrators(): void
    {
        $policy = new PaymentPolicy;
        $payment = $this->pay($this->thousandPesoApplication(), '100');

        $this->assertTrue($policy->create($this->admin));
        $this->assertTrue($policy->void($this->admin, $payment));
        $this->assertFalse($policy->create(User::factory()->member()->create()));
        $this->assertFalse($policy->void(User::factory()->admin()->inactive()->create(), $payment));
    }

    public function test_there_are_no_edit_or_delete_routes_for_payments(): void
    {
        $this->assertFalse(Route::has('admin.payments.edit'));
        $this->assertFalse(Route::has('admin.payments.update'));
        $this->assertFalse(Route::has('admin.payments.destroy'));
    }
}
