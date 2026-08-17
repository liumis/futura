<div>
    @if ($images !== [])
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full min-w-[32rem] table-fixed text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="w-[55%] px-3 py-2">File</th>
                        <th class="w-[25%] px-3 py-2">Uploaded</th>
                        <th class="w-[20%] px-3 py-2 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                    @foreach ($images as $image)
                        @php
                            $uploadedLabel = '—';
                            if (filled($image['uploaded_at'] ?? null)) {
                                try {
                                    $uploadedLabel = \Illuminate\Support\Carbon::parse((string) $image['uploaded_at'])
                                        ->timezone(config('app.timezone'))
                                        ->format('Y-m-d H:i');
                                } catch (\Throwable) {
                                    $uploadedLabel = '—';
                                }
                            }
                        @endphp
                        <tr class="align-middle">
                            <td class="px-3 py-2">
                                <span class="block truncate text-gray-800 dark:text-gray-100" title="{{ $image['label'] }}">
                                    {{ $image['label'] }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $uploadedLabel }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if (filled($image['view_url'] ?? null))
                                        <a
                                            href="{{ $image['view_url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                                        >
                                            View
                                        </a>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="openFor(@js($image['key']))"
                                        wire:loading.attr="disabled"
                                        class="rounded-md border border-gray-200 px-2 py-1 text-xs text-primary-700 hover:bg-gray-50 dark:border-gray-600 dark:text-primary-300 dark:hover:bg-gray-800"
                                    >
                                        Draw
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($open && $previewUrl)
        <template x-teleport="body">
            <div
                class="ss-annotate-overlay"
                style="position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.65);"
                x-data="{
                    componentId: @js($this->getId()),
                    strokes: [],
                    current: [],
                    drawing: false,
                    imgLoaded: false,
                    loadError: null,
                    penColor: @js($penColor),
                    penThickness: {{ (int) $penThickness }},
                    canvas: null,
                    img: null,
                    imgUrl: @js($previewUrl),
                    wire() {
                        return Livewire.find(this.componentId);
                    },
                    close() {
                        this.wire()?.set('open', false);
                    },
                    save() {
                        this.wire()?.call('apply', this.strokes, this.penColor, this.penThickness);
                    },
                    onPointerDown(e) {
                        if (!this.imgLoaded) return;
                        e.preventDefault();
                        this.drawing = true;
                        this.canvas.setPointerCapture?.(e.pointerId);
                        const rect = this.canvas.getBoundingClientRect();
                        const x = (e.clientX - rect.left) / rect.width;
                        const y = (e.clientY - rect.top) / rect.height;
                        this.current = [[x, y]];
                    },
                    onPointerMove(e) {
                        if (!this.drawing) return;
                        e.preventDefault();
                        const rect = this.canvas.getBoundingClientRect();
                        const x = (e.clientX - rect.left) / rect.width;
                        const y = (e.clientY - rect.top) / rect.height;
                        const last = this.current[this.current.length - 1];
                        const dx = x - last[0];
                        const dy = y - last[1];
                        if ((dx * dx) + (dy * dy) < 0.00001) return;
                        this.current.push([x, y]);
                        this.redraw();
                    },
                    onPointerUp(e) {
                        if (!this.drawing) return;
                        this.drawing = false;
                        try { this.canvas.releasePointerCapture?.(e.pointerId); } catch (_) {}
                        if (this.current.length > 1) {
                            this.strokes.push(this.current);
                        }
                        this.current = [];
                        this.redraw();
                    },
                    redraw() {
                        if (!this.img || !this.canvas) return;
                        const ctx = this.canvas.getContext('2d');
                        ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                        ctx.drawImage(this.img, 0, 0, this.canvas.width, this.canvas.height);
                        ctx.strokeStyle = this.penColor;
                        ctx.lineWidth = this.penThickness;
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';

                        const all = this.strokes.concat(this.current.length > 1 ? [this.current] : []);
                        for (const stroke of all) {
                            ctx.beginPath();
                            stroke.forEach((p, i) => {
                                const px = p[0] * this.canvas.width;
                                const py = p[1] * this.canvas.height;
                                if (i === 0) ctx.moveTo(px, py);
                                else ctx.lineTo(px, py);
                            });
                            ctx.stroke();
                        }
                    },
                    loadImage() {
                        this.canvas = this.$refs.drawCanvas;
                        this.img = new Image();
                        this.img.onload = () => {
                            const maxW = Math.min(1200, this.img.naturalWidth);
                            const scale = maxW / this.img.naturalWidth;
                            this.canvas.width = Math.max(1, Math.round(this.img.naturalWidth * scale));
                            this.canvas.height = Math.max(1, Math.round(this.img.naturalHeight * scale));
                            this.redraw();
                            this.imgLoaded = true;
                            this.loadError = null;
                        };
                        this.img.onerror = () => {
                            this.loadError = 'Could not load image preview.';
                            this.imgLoaded = false;
                        };
                        this.img.src = this.imgUrl;
                    },
                }"
                x-init="loadImage()"
                @keydown.escape.window="close()"
                @click.self="close()"
            >
                <div
                    class="ss-annotate-dialog"
                    style="display:flex;max-height:92vh;width:100%;max-width:64rem;flex-direction:column;border-radius:0.75rem;background:#fff;padding:1rem;box-shadow:0 25px 50px -12px rgba(0,0,0,.45);"
                    @click.stop
                >
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            Draw on image
                        </div>
                        <button
                            type="button"
                            class="rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                            @click="close()"
                        >
                            Close
                        </button>
                    </div>

                    <div
                        class="ss-annotate-canvas-wrap"
                        style="min-height:240px;overflow:auto;border:1px solid #e5e7eb;border-radius:0.375rem;background:#f9fafb;padding:0.5rem;"
                    >
                        <div class="flex min-h-[240px] items-center justify-center">
                            <p
                                class="text-sm text-danger-600"
                                x-show="loadError"
                                x-text="loadError"
                                x-cloak
                            ></p>
                            <canvas
                                x-ref="drawCanvas"
                                class="ss-annotate-canvas"
                                style="display:block;max-width:100%;max-height:70vh;touch-action:none;margin:0 auto;"
                                x-show="!loadError"
                                @pointerdown="onPointerDown($event)"
                                @pointermove="onPointerMove($event)"
                                @pointerup="onPointerUp($event)"
                                @pointercancel="onPointerUp($event)"
                            ></canvas>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Pen</div>
                        <input type="color" class="h-8 w-10 rounded" x-model="penColor">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Size</div>
                        <input type="range" min="2" max="12" step="1" x-model.number="penThickness">
                        <div class="ms-auto flex gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-gray-200 px-3 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                                @click="strokes = []; current = []; redraw()"
                            >
                                Clear
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-primary-600 px-3 py-2 text-xs font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                                @click="save()"
                                :disabled="!imgLoaded"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
