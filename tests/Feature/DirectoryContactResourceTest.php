<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Filament\Admin\Resources\DirectoryContacts\Pages\CreateDirectoryContact;
use App\Filament\Admin\Resources\DirectoryContacts\Pages\ListDirectoryContacts;
use App\Models\DirectoryContact;
use App\Models\OriginCountry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DirectoryContactResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_directory_contacts_list(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListDirectoryContacts::class)
            ->assertOk()
            ->assertSee('Contacts');

        $this->assertNotNull(DirectoryContactResource::getUrl('index'));
    }

    public function test_admin_can_create_a_contact_with_persons(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;

        Livewire::test(CreateDirectoryContact::class)
            ->fillForm([
                'company_name' => 'Acme UAB',
                'country' => $country,
                'email' => 'info@acme.test',
                'phone' => '+37060000000',
                'last_contact' => '2026-08-20',
                'next_contact' => '2026-09-10',
                'contactPersons' => [
                    [
                        'name' => 'Jonas',
                        'surname' => 'Jonaitis',
                        'phone' => '+37061111111',
                        'email' => 'jonas@acme.test',
                        'comment' => 'Sales',
                    ],
                    [
                        'name' => 'Ona',
                        'surname' => 'Onaitė',
                        'phone' => '+37062222222',
                        'email' => 'ona@acme.test',
                        'comment' => 'Accounting',
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contact = DirectoryContact::query()->where('company_name', 'Acme UAB')->first();

        $this->assertNotNull($contact);
        $this->assertSame($country, $contact->country);
        $this->assertSame('info@acme.test', $contact->email);
        $this->assertSame('2026-08-20', $contact->last_contact?->toDateString());
        $this->assertSame('2026-09-10', $contact->next_contact?->toDateString());
        $this->assertCount(2, $contact->contactPersons);
        $this->assertDatabaseHas('directory_contact_persons', [
            'directory_contact_id' => $contact->id,
            'name' => 'Jonas',
            'surname' => 'Jonaitis',
            'email' => 'jonas@acme.test',
        ]);
        $this->assertDatabaseHas('directory_contact_persons', [
            'directory_contact_id' => $contact->id,
            'name' => 'Ona',
            'surname' => 'Onaitė',
        ]);

        Livewire::test(ListDirectoryContacts::class)
            ->assertOk()
            ->assertSee('Acme UAB')
            ->assertSee('Jonas Jonaitis')
            ->assertSee('2026-08-20')
            ->assertSee('2026-09-10');
    }

    public function test_admin_can_add_dated_comments_shown_on_the_list(): void
    {
        $this->actingAsAdmin();
        $country = OriginCountry::query()->first()?->name
            ?? OriginCountry::query()->create(['name' => 'Lithuania'])->name;
        $longComment = 'Called the warehouse about delayed leather shipment and they promised an update next week after checking stock.';

        Livewire::test(CreateDirectoryContact::class)
            ->fillForm([
                'company_name' => 'Baltic Leather',
                'country' => $country,
                'comments' => [
                    [
                        'commented_at' => '2026-08-01',
                        'comment' => 'First call.',
                    ],
                    [
                        'commented_at' => '2026-09-02',
                        'comment' => $longComment,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contact = DirectoryContact::query()->where('company_name', 'Baltic Leather')->first();

        $this->assertNotNull($contact);
        $this->assertCount(2, $contact->comments);
        $this->assertSame('2026-09-02', $contact->latestComment?->commented_at?->toDateString());
        $this->assertSame($longComment, $contact->latestComment?->comment);

        Livewire::test(ListDirectoryContacts::class)
            ->assertOk()
            ->assertSee('2')
            ->assertSee(Str::limit($longComment, 60, '...'))
            ->assertSee($longComment);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-directory-contacts@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
