<?php

namespace Tests\Feature;

use App\Enums\BankStatementDirection;
use App\Filament\Admin\Pages\BankStatements;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementsDefaultMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_latest_month_with_statements_when_current_month_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28'));

        $this->actingAsAdmin();

        $import = BankStatementImport::query()->create([
            'original_filename' => 'sep.xml',
            'content_hash' => 'hash-sep-default',
            'uploaded_by' => auth()->id(),
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->id,
            'fingerprint' => 'sep-default-1',
            'month_key' => '2026-09',
            'booking_date' => '2026-09-15',
            'direction' => BankStatementDirection::Debit,
            'amount' => 12.34,
            'currency' => 'EUR',
            'account_iban' => 'LT121000011101001000',
            'counterpart_name' => 'Test Merchant',
            'description' => 'September payment',
        ]);

        $page = Livewire::test(BankStatements::class)
            ->assertOk()
            ->assertSee('September 2026')
            ->assertSee('12.34');

        $this->assertSame('2026-09', $page->instance()->defaultMonthKey());

        Carbon::setTestNow();
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $user = User::factory()->create(['email' => 'admin-bank-default-month@example.com']);
        $user->assignRole(User::ROLE_ADMIN);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        return $user;
    }
}
