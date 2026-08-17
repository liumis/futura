<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\ShippingSetting;
use App\Services\ActivityLogger;

class ShippingSettingObserver
{
    public function created(ShippingSetting $setting): void
    {
        ActivityLogger::log(
            ActivityLogEvent::ShippingSettingsUpdated,
            'Shipping provider created: '.$setting->name,
            $setting,
            ['values' => $setting->only([
                'name',
                'is_default',
                'items_on_euroaluse',
                'euroaluse_price',
                'default_buffer',
                'fulfillment_warehouse_email',
                'fulfillment_mail_template_id',
            ])],
        );
    }

    public function updated(ShippingSetting $setting): void
    {
        ActivityLogger::log(
            ActivityLogEvent::ShippingSettingsUpdated,
            'Shipping provider updated: '.$setting->name,
            $setting,
            ['changes' => $setting->getChanges()],
        );
    }

    public function deleted(ShippingSetting $setting): void
    {
        ActivityLogger::log(
            ActivityLogEvent::ShippingSettingsUpdated,
            'Shipping provider deleted: '.$setting->name,
            null,
            ['deleted_shipping_setting_id' => $setting->getKey()],
        );
    }
}
