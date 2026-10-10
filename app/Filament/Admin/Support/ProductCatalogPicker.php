<?php

namespace App\Filament\Admin\Support;

use Illuminate\Support\HtmlString;

class ProductCatalogPicker
{
    /**
     * Picker UI is vanilla JS (public/js/cargo-order-picker.js).
     * Do not attach Alpine x-data to Filament schema components.
     *
     * @return array<string, string>
     */
    public static function chromeAlpineAttributes(): array
    {
        return [];
    }

    public static function collectionHeadingHtml(string $heading): HtmlString
    {
        $chevron = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';

        return new HtmlString(
            '<button type="button" class="fi-cargo-order-collection-title">'
            .'<span class="fi-cargo-order-collection-chevron">'.$chevron.'</span>'
            .'<span class="fi-cargo-order-collection-name">'.e($heading).'</span>'
            .' <span class="fi-order-section-total" wire:ignore>(0)</span>'
            .'</button>'
        );
    }
}
