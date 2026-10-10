<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AbsenceCalendar;
use App\Filament\Admin\Pages\MonthlyPayment;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Admin\Resources\LeaveRequestTypes\LeaveRequestTypeResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-smoke@example.com',
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_login_page_loads(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('fonts.googleapis.com', false)
            ->assertSee('Jost', false);
    }

    public function test_guest_is_redirected_from_admin_pages(): void
    {
        $this->get(EmployeeResource::getUrl('index'))
            ->assertRedirect();
    }

    public function test_admin_can_open_employee_and_leave_pages_without_server_error(): void
    {
        $this->actingAs($this->admin);

        $urls = [
            EmployeeResource::getUrl('index'),
            EmployeeResource::getUrl('create'),
            LeaveRequestResource::getUrl('index'),
            LeaveRequestResource::getUrl('create'),
            LeaveRequestTypeResource::getUrl('index'),
            AbsenceCalendar::getUrl(),
            MonthlyPayment::getUrl(),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);

            $this->assertNotEquals(
                500,
                $response->status(),
                "Expected non-500 for {$url}, got {$response->status()}",
            );
            $this->assertTrue(
                $response->isSuccessful() || $response->isRedirect(),
                "Expected 2xx/3xx for {$url}, got {$response->status()}",
            );
        }
    }
}
