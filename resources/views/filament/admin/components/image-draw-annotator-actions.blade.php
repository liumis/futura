@php
    /** @var list<array<string, mixed>|string> $images */
    /** @var string $wireKey */
    $images = $images ?? ($paths ?? []);
@endphp

@livewire(
    'image-draw-annotator',
    [
        'images' => $images,
        'disk' => 'public',
    ],
    key($wireKey)
)
