<?php

namespace App\Livewire;

use App\Models\Todo;
use App\Services\SharepointGraphClient;
use App\Services\SharepointTaskUploader;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use RuntimeException;

class ImageDrawAnnotator extends Component
{
    /**
     * @var list<array{
     *     key: string,
     *     label: string,
     *     kind: string,
     *     path?: string|null,
     *     item_id?: string|null,
     *     todo_id?: int|null,
     *     name?: string|null
     * }>
     */
    public array $images = [];

    public string $disk = 'public';

    /** Injected by Filament schema Livewire wrapper (unused). */
    public mixed $record = null;

    public ?string $activeKey = null;

    public ?string $workingPath = null;

    public ?string $previewUrl = null;

    public bool $open = false;

    public string $penColor = '#ff0000';

    public int $penThickness = 4;

    /**
     * @param  list<array<string, mixed>>|list<string>  $images
     */
    public function mount(array $images = [], string $disk = 'public', array $imagePaths = []): void
    {
        $this->disk = $disk;

        // Backward compatible: plain local paths from older call sites.
        if ($images === [] && $imagePaths !== []) {
            $images = $imagePaths;
        }

        $this->images = $this->normalizeImages($images);
    }

    public function openFor(string $key): void
    {
        $image = $this->findImage($key);
        if ($image === null) {
            return;
        }

        $ext = strtolower(pathinfo((string) ($image['label'] ?? $image['path'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === '' && filled($image['path'] ?? null)) {
            $ext = strtolower(pathinfo((string) $image['path'], PATHINFO_EXTENSION));
        }

        if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            Notification::make()
                ->title('Only JPG/JPEG/PNG can be annotated')
                ->warning()
                ->send();

            return;
        }

        try {
            if (($image['kind'] ?? '') === 'sharepoint') {
                $todoId = (int) ($image['todo_id'] ?? 0);
                $itemId = (string) ($image['item_id'] ?? '');
                if ($todoId <= 0 || $itemId === '') {
                    Notification::make()
                        ->title('Cannot open SharePoint image')
                        ->danger()
                        ->send();

                    return;
                }

                $binary = SharepointGraphClient::make()->downloadItemContent($itemId);
                $working = 'todos/annotate-tmp/'.$todoId.'-'.preg_replace('/[^a-zA-Z0-9_-]+/', '', $itemId).'.'.$ext;
                Storage::disk($this->disk)->put($working, $binary);
                $this->workingPath = $working;
            } else {
                $path = (string) ($image['path'] ?? '');
                if ($path === '' || ! Storage::disk($this->disk)->exists($path)) {
                    Notification::make()
                        ->title('Image not found')
                        ->danger()
                        ->send();

                    return;
                }
                $this->workingPath = $path;
            }
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not open image')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->activeKey = $key;
        $this->previewUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'annotate.preview',
            now()->addMinutes(30),
            ['path' => $this->workingPath],
        );
        $this->open = true;
    }

    /**
     * @param  array<int, array<int, array{0:float,1:float}>>  $strokes
     */
    public function apply(array $strokes, ?string $penColor = null, int|string|null $penThickness = null): void
    {
        if (filled($penColor)) {
            $this->penColor = (string) $penColor;
        }
        if ($penThickness !== null && $penThickness !== '') {
            $this->penThickness = max(1, (int) $penThickness);
        }

        if (! $this->activeKey || ! $this->workingPath) {
            $this->open = false;

            return;
        }

        $imageMeta = $this->findImage($this->activeKey);
        if ($imageMeta === null) {
            $this->open = false;

            return;
        }

        if (! Storage::disk($this->disk)->exists($this->workingPath)) {
            throw new RuntimeException('Image not found on disk.');
        }

        $ext = strtolower(pathinfo($this->workingPath, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $this->open = false;

            return;
        }

        $fullPath = Storage::disk($this->disk)->path($this->workingPath);

        $gd = match ($ext) {
            'png' => imagecreatefrompng($fullPath),
            default => imagecreatefromjpeg($fullPath),
        };

        if ($gd === false) {
            throw new RuntimeException('Could not open image for drawing.');
        }

        $width = imagesx($gd);
        $height = imagesy($gd);
        $pen = $this->allocatePenColor($gd, $this->penColor);
        imagesetthickness($gd, max(1, (int) $this->penThickness));

        foreach ($strokes as $stroke) {
            $prev = null;
            foreach ($stroke as $point) {
                $x = (int) round(((float) ($point[0] ?? 0)) * ($width - 1));
                $y = (int) round(((float) ($point[1] ?? 0)) * ($height - 1));

                if ($prev !== null) {
                    imageline($gd, (int) $prev[0], (int) $prev[1], $x, $y, $pen);
                }

                $prev = [$x, $y];
            }
        }

        match ($ext) {
            'png' => imagepng($gd, $fullPath),
            default => imagejpeg($gd, $fullPath, 90),
        };
        imagedestroy($gd);

        if (($imageMeta['kind'] ?? '') === 'sharepoint') {
            $todo = Todo::query()->find((int) ($imageMeta['todo_id'] ?? 0));
            $itemId = (string) ($imageMeta['item_id'] ?? '');
            $binary = Storage::disk($this->disk)->get($this->workingPath);

            if (! $todo instanceof Todo || $itemId === '' || ! is_string($binary) || $binary === '') {
                throw new RuntimeException('Could not save annotated image to SharePoint.');
            }

            SharepointTaskUploader::replaceFileBinary($todo, $itemId, $binary);
            Storage::disk($this->disk)->delete($this->workingPath);

            Notification::make()
                ->title('Photo updated on SharePoint')
                ->success()
                ->send();
        }

        $this->open = false;
        $this->activeKey = null;
        $this->workingPath = null;
        $this->previewUrl = null;
        $this->dispatch('image-drawn');
    }

    public function render()
    {
        return view('livewire.image-draw-annotator');
    }

    /**
     * @param  list<array<string, mixed>|string>  $images
     * @return list<array{key: string, label: string, kind: string, path?: string|null, item_id?: string|null, todo_id?: int|null, name?: string|null}>
     */
    protected function normalizeImages(array $images): array
    {
        $out = [];

        foreach ($images as $row) {
            if (is_string($row) && $row !== '') {
                $ext = strtolower(pathinfo($row, PATHINFO_EXTENSION));
                if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    continue;
                }
                $out[] = [
                    'key' => 'local:'.$row,
                    'label' => basename(str_replace('\\', '/', $row)),
                    'kind' => 'local',
                    'path' => $row,
                    'view_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'annotate.preview',
                        now()->addMinutes(60),
                        ['path' => $row],
                    ),
                ];

                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $kind = (string) ($row['kind'] ?? 'local');
            $label = (string) ($row['label'] ?? $row['name'] ?? basename((string) ($row['path'] ?? 'image')));
            $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));
            if ($ext === '' && filled($row['path'] ?? null)) {
                $ext = strtolower(pathinfo((string) $row['path'], PATHINFO_EXTENSION));
            }
            if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            if ($kind === 'sharepoint') {
                $itemId = (string) ($row['item_id'] ?? '');
                if ($itemId === '') {
                    continue;
                }
                $out[] = [
                    'key' => (string) ($row['key'] ?? 'sp:'.$itemId),
                    'label' => $label,
                    'kind' => 'sharepoint',
                    'item_id' => $itemId,
                    'todo_id' => isset($row['todo_id']) ? (int) $row['todo_id'] : null,
                    'name' => $label,
                    'path' => filled($row['path'] ?? null) ? (string) $row['path'] : null,
                    'view_url' => filled($row['view_url'] ?? null) ? (string) $row['view_url'] : null,
                    'uploaded_at' => filled($row['uploaded_at'] ?? null) ? (string) $row['uploaded_at'] : null,
                ];

                continue;
            }

            $path = (string) ($row['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $out[] = [
                'key' => (string) ($row['key'] ?? 'local:'.$path),
                'label' => $label !== '' ? $label : basename(str_replace('\\', '/', $path)),
                'kind' => 'local',
                'path' => $path,
                'view_url' => filled($row['view_url'] ?? null)
                    ? (string) $row['view_url']
                    : \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'annotate.preview',
                        now()->addMinutes(60),
                        ['path' => $path],
                    ),
                'uploaded_at' => filled($row['uploaded_at'] ?? null) ? (string) $row['uploaded_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{key: string, label: string, kind: string, path?: string|null, item_id?: string|null, todo_id?: int|null, name?: string|null}|null
     */
    protected function findImage(string $key): ?array
    {
        foreach ($this->images as $image) {
            if (($image['key'] ?? null) === $key) {
                return $image;
            }
        }

        return null;
    }

    /**
     * @return int GD color identifier
     */
    protected function allocatePenColor($image, string $hex): int
    {
        $hex = trim($hex);
        if ($hex === '') {
            $hex = '#ff0000';
        }
        if (! str_starts_with($hex, '#')) {
            $hex = '#'.$hex;
        }

        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));

        return imagecolorallocate($image, $r, $g, $b);
    }
}
