<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ContactsBoard;
use App\Filament\Admin\Resources\DirectoryContacts\Pages\CreateDirectoryContact;
use App\Filament\Admin\Resources\DirectoryContactStages\DirectoryContactStageResource;
use App\Filament\Admin\Resources\DirectoryContactStages\Pages\CreateDirectoryContactStage;
use App\Filament\Admin\Resources\DirectoryContactStages\Pages\ListDirectoryContactStages;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactStage;
use App\Models\OriginCountry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DirectoryContactStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_stages_list(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListDirectoryContactStages::class)
            ->assertOk()
            ->assertSee('Stages');

        $this->assertNotNull(DirectoryContactStageResource::getUrl('index'));
    }

    public function test_admin_can_create_and_reorder_stages(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateDirectoryContactStage::class)
            ->fillForm(['name' => 'Lead'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateDirectoryContactStage::class)
            ->fillForm(['name' => 'Negotiation'])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = DirectoryContactStage::query()->where('name', 'Lead')->first();
        $negotiation = DirectoryContactStage::query()->where('name', 'Negotiation')->first();

        $this->assertNotNull($lead);
        $this->assertNotNull($negotiation);
        $this->assertGreaterThan($lead->sort_order, $negotiation->sort_order);

        Livewire::test(ListDirectoryContactStages::class)
            ->call('reorderTable', [(string) $negotiation->id, (string) $lead->id])
            ->assertOk();

        $this->assertSame(1, (int) $negotiation->fresh()->sort_order);
        $this->assertSame(2, (int) $lead->fresh()->sort_order);
    }

    public function test_admin_can_assign_a_stage_to_a_contact_and_move_it_on_the_board(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;
        $stage = DirectoryContactStage::query()->create(['name' => 'Lead']);

        Livewire::test(CreateDirectoryContact::class)
            ->fillForm([
                'company_name' => 'Nordic Fabrics',
                'country' => $country,
                'directory_contact_stage_id' => $stage->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contact = DirectoryContact::query()->where('company_name', 'Nordic Fabrics')->first();
        $nextStage = DirectoryContactStage::query()->create(['name' => 'Won']);

        $this->assertNotNull($contact);
        $this->assertSame($stage->id, $contact->directory_contact_stage_id);

        Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertDontSee('Unassigned')
            ->assertSee('Lead')
            ->assertSee('Won')
            ->assertSee('New')
            ->call('moveContactStage', $contact->id, (string) $nextStage->id)
            ->assertOk();

        $this->assertSame($nextStage->id, $contact->fresh()->directory_contact_stage_id);
    }

    public function test_board_shows_contact_dates_and_saves_a_comment_from_quick_view(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;

        $contact = DirectoryContact::query()->create([
            'company_name' => 'Baltic Yarns',
            'type' => 'Manufacturer',
            'country' => $country,
            'phone' => '+37060000000',
            'email' => 'info@balticyarns.test',
            'last_contact' => '2026-07-01',
            'next_contact' => '2026-08-01',
        ]);

        $page = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->assertSee('New contact')
            ->assertSee('Last contact')
            ->assertSee('Next contact')
            ->assertSee('Comment (for myself)')
            ->assertSee('Summary')
            ->assertSee('Contacts')
            ->assertSee('Type')
            ->assertDontSee('Comment date');

        $items = $page->instance()->getKanbanItems();
        $item = collect($items)->firstWhere('id', $contact->id);

        $this->assertNotNull($item);
        $this->assertSame('2026-07-01', $item['last_contact']);
        $this->assertSame('2026-08-01', $item['next_contact']);

        $quickView = $page->instance()->getContactQuickView($contact->id);
        $this->assertSame('Baltic Yarns', $quickView['title']);
        $this->assertSame('Manufacturer', $quickView['type']);
        $this->assertSame('+37060000000', $quickView['phone']);
        $this->assertSame('info@balticyarns.test', $quickView['email']);
        $this->assertSame('2026-07-01', $quickView['last_contact']);
        $this->assertSame('2026-08-01', $quickView['next_contact']);
        $this->assertSame([], $quickView['persons']);

        $result = $page->instance()->saveContactQuickView($contact->id, [
            'type' => 'Distributor',
            'phone' => '+37061111111',
            'email' => 'sales@balticyarns.test',
            'last_contact' => '2026-09-03',
            'next_contact' => '2026-09-20',
            'comment' => 'Called about samples',
        ]);

        $this->assertTrue($result['ok']);
        $contact->refresh();
        $this->assertSame('Distributor', $contact->type);
        $this->assertSame('+37061111111', $contact->phone);
        $this->assertSame('sales@balticyarns.test', $contact->email);
        $this->assertSame('2026-09-03', $contact->last_contact?->toDateString());
        $this->assertSame('2026-09-20', $contact->next_contact?->toDateString());
        $this->assertSame('Called about samples', $contact->latestComment?->comment);
        $this->assertNotNull($contact->latestComment?->commented_at);

        $updated = collect($result['items'])->firstWhere('id', $contact->id);
        $this->assertSame('2026-09-03', $updated['last_contact']);
        $this->assertSame('2026-09-20', $updated['next_contact']);
        $this->assertSame(1, $updated['comments_count']);
        $this->assertSame('Distributor', $updated['type']);
    }

    public function test_adding_a_board_comment_sets_last_contact_when_empty(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;

        $contact = DirectoryContact::query()->create([
            'company_name' => 'Empty Dates Co',
            'country' => $country,
        ]);

        $items = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->instance()
            ->getKanbanItems();
        $item = collect($items)->firstWhere('id', $contact->id);

        $this->assertSame('', $item['last_contact']);
        $this->assertSame('', $item['next_contact']);

        $result = Livewire::test(ContactsBoard::class)
            ->instance()
            ->saveContactQuickView($contact->id, [
                'last_contact' => '',
                'next_contact' => '2026-09-25',
                'comment' => 'First call logged from the board',
            ]);

        $this->assertTrue($result['ok']);
        $contact->refresh();
        $this->assertSame(now()->toDateString(), $contact->last_contact?->toDateString());
        $this->assertSame('2026-09-25', $contact->next_contact?->toDateString());
        $this->assertSame('First call logged from the board', $contact->latestComment?->comment);
        $this->assertTrue(
            $contact->latestComment?->commented_at?->diffInSeconds(now()) < 5,
        );
    }

    public function test_board_lists_last_contacted_and_upcoming_including_late(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;

        DirectoryContact::query()->create([
            'company_name' => 'Older Call Co',
            'country' => $country,
            'last_contact' => now()->subDays(10)->toDateString(),
            'next_contact' => now()->addDays(10)->toDateString(),
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Recent Call Co',
            'country' => $country,
            'last_contact' => now()->subDay()->toDateString(),
            'next_contact' => now()->addDays(3)->toDateString(),
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Late Followup Co',
            'country' => $country,
            'last_contact' => now()->subDays(20)->toDateString(),
            'next_contact' => now()->subDays(2)->toDateString(),
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'No Dates Co',
            'country' => $country,
        ]);

        $page = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->assertSee('Last contacted')
            ->assertSee('Upcoming contact')
            ->assertSee('Show all');

        $lastContacted = $page->instance()->getLastContactedList(0);
        $this->assertSame(
            ['Recent Call Co', 'Older Call Co', 'Late Followup Co'],
            collect($lastContacted)->pluck('title')->all(),
        );

        $upcoming = $page->instance()->getUpcomingContactList(0);
        $this->assertSame(
            ['Late Followup Co', 'Recent Call Co', 'Older Call Co'],
            collect($upcoming)->pluck('title')->all(),
        );
        $this->assertTrue(
            ($upcoming[0]['next_contact'] ?? '') < now()->toDateString(),
            'Late contacts should appear first in the upcoming list.',
        );
    }

    public function test_board_date_lists_return_top_20_by_default(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;

        foreach (range(1, 21) as $index) {
            DirectoryContact::query()->create([
                'company_name' => sprintf('Dated Co %02d', $index),
                'country' => $country,
                'last_contact' => now()->subDays(22 - $index)->toDateString(),
                'next_contact' => now()->addDays($index)->toDateString(),
            ]);
        }

        $page = Livewire::test(ContactsBoard::class)->assertOk()->instance();

        $this->assertCount(20, $page->getLastContactedList());
        $this->assertCount(21, $page->getLastContactedList(0));
        $this->assertSame('Dated Co 21', $page->getLastContactedList()[0]['title']);
        $this->assertCount(20, $page->getUpcomingContactList());
        $this->assertSame('Dated Co 01', $page->getUpcomingContactList()[0]['title']);
    }

    public function test_board_filters_by_country_and_company_name(): void
    {
        $this->actingAsAdmin();

        DirectoryContact::query()->create([
            'company_name' => 'Italian Sofas',
            'country' => 'Italy',
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Swedish Fabrics',
            'country' => 'Sweden',
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Belgian Leather',
            'country' => 'Belgium',
        ]);

        $page = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->set('filters.country', 'Italy')
            ->assertDispatched('contacts-board-filtered');

        $items = $page->instance()->getKanbanItems();
        $this->assertCount(1, $items);
        $this->assertSame('Italian Sofas', $items[0]['title']);

        $page->set('filters.country', null)
            ->set('filters.company_name', 'Swedish Fabrics');

        $items = $page->instance()->getKanbanItems();
        $this->assertCount(1, $items);
        $this->assertSame('Swedish Fabrics', $items[0]['title']);

        $this->assertArrayHasKey('Italy', $page->instance()->countryOptions('Ital'));
        $this->assertSame('Italy (1)', $page->instance()->countryOptions('Ital')['Italy']);
        $this->assertSame('Italy (1)', $page->instance()->countryOptionLabel('Italy'));
        $this->assertArrayNotHasKey('France', $page->instance()->countryOptions());
        $this->assertArrayHasKey('Swedish Fabrics', $page->instance()->companyOptions('Swed'));
    }

    public function test_board_filters_by_type(): void
    {
        $this->actingAsAdmin();

        DirectoryContact::query()->create([
            'company_name' => 'Dist Co',
            'type' => 'Distributor',
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Mfg Co',
            'type' => 'Manufacturer',
        ]);

        $page = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->set('filters.type', 'Distributor');

        $titles = collect($page->instance()->getKanbanItems())->pluck('title')->all();
        $this->assertSame(['Dist Co'], $titles);
        $this->assertArrayHasKey('Manufacturer', $page->instance()->typeOptions());
        $this->assertSame('Manufacturer (1)', $page->instance()->typeOptions()['Manufacturer']);
        $this->assertSame('Distributor (1)', $page->instance()->typeOptionLabel('Distributor'));
    }

    public function test_board_filters_by_last_and_next_contact_date_ranges(): void
    {
        $this->actingAsAdmin();

        DirectoryContact::query()->create([
            'company_name' => 'Early Contact Co',
            'last_contact' => '2026-01-10',
            'next_contact' => '2026-02-10',
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Mid Contact Co',
            'last_contact' => '2026-03-15',
            'next_contact' => '2026-04-15',
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Late Contact Co',
            'last_contact' => '2026-06-20',
            'next_contact' => '2026-07-20',
        ]);

        $page = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->set('filters.last_contact_from', '2026-03-01')
            ->set('filters.last_contact_until', '2026-03-31');

        $titles = collect($page->instance()->getKanbanItems())->pluck('title')->all();
        $this->assertSame(['Mid Contact Co'], $titles);

        $page->set('filters.last_contact_from', null)
            ->set('filters.last_contact_until', null)
            ->set('filters.next_contact_from', '2026-07-01')
            ->set('filters.next_contact_until', '2026-07-31');

        $titles = collect($page->instance()->getKanbanItems())->pluck('title')->all();
        $this->assertSame(['Late Contact Co'], $titles);
    }

    public function test_board_summary_counts_total_and_needs_contact(): void
    {
        $this->actingAsAdmin();
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        DirectoryContact::query()->create([
            'company_name' => 'Overdue Co',
            'last_contact' => $yesterday,
            'next_contact' => $yesterday,
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Due Today Co',
            'last_contact' => $yesterday,
            'next_contact' => $today,
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Upcoming Co',
            'last_contact' => $yesterday,
            'next_contact' => $tomorrow,
        ]);
        DirectoryContact::query()->create([
            'company_name' => 'Never Co',
            'last_contact' => null,
            'next_contact' => null,
        ]);

        $summary = Livewire::test(ContactsBoard::class)
            ->assertOk()
            ->assertSee('Total contacts')
            ->assertSee('Needs contact')
            ->instance()
            ->getSummaryStats();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['needs_contact']);
        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(1, $summary['due_today']);
        $this->assertSame(1, $summary['upcoming']);
        $this->assertSame(1, $summary['never_contacted']);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-contact-stages@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
