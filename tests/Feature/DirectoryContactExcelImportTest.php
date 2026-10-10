<?php

namespace Tests\Feature;

use App\Enums\DirectoryContactPriority;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactStage;
use App\Models\OriginCountry;
use App\Services\DirectoryContactExcelImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DirectoryContactExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_contacts_persons_and_comments_from_excel(): void
    {
        OriginCountry::query()->firstOrCreate(['name' => 'Italy']);
        DirectoryContactStage::query()->firstOrCreate(
            ['name' => 'New'],
            ['sort_order' => 1],
        );

        $path = storage_path('framework/testing/directory-contacts-import.xlsx');
        @mkdir(dirname($path), 0777, true);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [
            'Company name', 'Type', 'Country', 'Segment', 'Priority', 'Website',
            'Email', 'Phone', 'Stage', 'Last contact', 'Next contact', 'Comment',
            'Contact name', 'Contact phone', 'Contact email', 'Contact note',
        ];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        $sheet->fromArray([
            'Natuzzi',
            'Manufacturer',
            'Italy',
            'Sofas',
            'High',
            'https://www.natuzzi.com/',
            'info@natuzzi.test',
            '+390123',
            'New',
            '2026-08-01',
            '',
            'Large sofa maker',
            'Mario Rossi',
            '+390999',
            'mario@natuzzi.test',
            'Sales',
        ], null, 'A2');

        (new Xlsx($spreadsheet))->save($path);

        $result = app(DirectoryContactExcelImporter::class)->import($path);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['persons']);
        $this->assertSame(1, $result['comments']);

        $contact = DirectoryContact::query()->where('company_name', 'Natuzzi')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Manufacturer', $contact->type);
        $this->assertSame('Italy', $contact->country);
        $this->assertSame(DirectoryContactPriority::High, $contact->priority);
        $this->assertSame('www.natuzzi.com', $contact->website);
        $this->assertSame('2026-08-01', $contact->last_contact?->toDateString());
        $this->assertSame('New', $contact->stage?->name);
        $this->assertCount(1, $contact->contactPersons);
        $this->assertSame('Mario', $contact->contactPersons->first()->name);
        $this->assertSame('Rossi', $contact->contactPersons->first()->surname);
        $this->assertDatabaseHas('directory_contact_comments', [
            'directory_contact_id' => $contact->id,
            'comment' => 'Large sofa maker',
        ]);

        @unlink($path);
    }

    public function test_does_not_duplicate_when_email_or_phone_matches(): void
    {
        OriginCountry::query()->firstOrCreate(['name' => 'Italy']);
        DirectoryContactStage::query()->firstOrCreate(
            ['name' => 'New'],
            ['sort_order' => 1],
        );

        $existing = DirectoryContact::query()->create([
            'company_name' => 'Existing Sofa Co',
            'country' => 'Italy',
            'email' => 'shared@example.com',
            'phone' => '+390123456789',
        ]);

        $path = storage_path('framework/testing/directory-contacts-dedupe.xlsx');
        @mkdir(dirname($path), 0777, true);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [
            'Company name', 'Type', 'Country', 'Segment', 'Priority', 'Website',
            'Email', 'Phone', 'Stage', 'Last contact', 'Next contact', 'Comment',
            'Contact name', 'Contact phone', 'Contact email', 'Contact note',
        ];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        $sheet->fromArray([
            'Brand New Name SA',
            'Manufacturer',
            'Italy',
            'Sofas',
            'Medium',
            '',
            'shared@example.com',
            '',
            'New',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ], null, 'A2');
        $sheet->fromArray([
            'Phone Match Only',
            'Distributor',
            'Italy',
            'Fabrics',
            'Low',
            '',
            '',
            '0039 012 345 6789',
            'New',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ], null, 'A3');

        (new Xlsx($spreadsheet))->save($path);

        $result = app(DirectoryContactExcelImporter::class)->import($path);

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, DirectoryContact::query()->count());
        $this->assertDatabaseHas('directory_contacts', [
            'id' => $existing->id,
            'company_name' => 'Existing Sofa Co',
            'email' => 'shared@example.com',
        ]);

        @unlink($path);
    }
}
