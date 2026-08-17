<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'company_country',
        'company_id',
        'company_vat',
        'company_address',
        'company_email',
        'company_phone',
        'company_iban',
        'company_bic',
        'vmi_iban',
        'vmi_bic',
        'sodra_iban',
        'sodra_bic',
        'contact_name',
        'contact_email',
        'contact_phone',
    ];

    public static function instance(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function syncContact(): Contact
    {
        if (blank($this->company_name) || blank($this->company_id) || blank($this->company_address)) {
            throw new \RuntimeException('My company details are incomplete. Fill in company name, company id, and address under System → My company.');
        }

        return Contact::query()->updateOrCreate(
            ['company_id' => (string) $this->company_id],
            [
                'company_name' => $this->company_name,
                'company_country' => $this->company_country,
                'company_vat' => $this->company_vat,
                'company_address' => $this->company_address,
                'company_email' => $this->company_email,
                'company_phone' => $this->company_phone,
                'contact_name' => $this->contact_name,
                'contact_email' => $this->contact_email,
                'contact_phone' => $this->contact_phone,
            ],
        );
    }
}
