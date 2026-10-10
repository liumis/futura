<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">i.SAF 1.2 XML for upload to i.MAS — issued sales invoices and received purchase invoices for the tax period.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0">
                {{ $this->downloadXmlAction }}
            </div>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @php
        $preview = $this->preview();
        $filters = $this->filterContext();
        $exported = $preview['sales']->count() + $preview['purchases']->count();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'File contents',
        'description' => $filters['period_label'].' · '.$filters['data_type_label'].' · Part '.$filters['part_number'],
        'footer' => $exported === 0
            ? 'A header-only file is still valid if there were no VAT invoices in the period.'
            : 'Unclassified invoices are listed below and are not written to the XML. Upload the file at imas.vmi.lt.',
        'stats' => [
            ['label' => 'Sales invoices', 'value' => (string) $preview['sales']->count(), 'hint' => 'Issued (output VAT)'],
            ['label' => 'Purchase invoices', 'value' => (string) $preview['purchases']->count(), 'hint' => 'Received (input VAT)'],
            ['label' => 'Unclassified', 'value' => (string) $preview['unclassified']->count(), 'hint' => 'Excluded from XML'],
            [
                'label' => 'In XML',
                'value' => (string) $exported,
                'hint' => 'iSAF1.2',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    @if ($preview['warnings'] !== [])
        <section class="fi-report-stat-summary mt-6">
            <div class="fi-report-stat-summary__header">
                <h3 class="fi-report-stat-summary__title">Before you upload</h3>
                <p class="fi-report-stat-summary__description">i.SAF will accept ND where a VAT number is unknown. Missing PVM codes should be fixed first.</p>
            </div>
            <ul class="list-disc space-y-1 px-6 py-4 text-sm text-gray-700 dark:text-gray-300">
                @foreach (array_slice($preview['warnings'], 0, 20) as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
                @if (count($preview['warnings']) > 20)
                    <li>… and {{ count($preview['warnings']) - 20 }} more.</li>
                @endif
            </ul>
        </section>
    @endif

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
