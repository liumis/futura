<div
    wire:ignore
    x-data="{
        documentId: {{ (int) $documentId }},
        booted: false,
        async init() {
            const loadScript = (src) => new Promise((resolve, reject) => {
                if ([...document.scripts].some((script) => script.src === src || script.getAttribute('src') === src)) {
                    resolve();
                    return;
                }
                const el = document.createElement('script');
                el.src = src;
                el.async = true;
                el.onload = () => resolve();
                el.onerror = () => reject(new Error('Failed to load ' + src));
                document.head.appendChild(el);
            });

            try {
                if (! window.jQuery) {
                    await loadScript('https://code.jquery.com/jquery-3.7.1.min.js');
                }
                await loadScript(@js(rtrim($scriptBase, '/').'/js/isign.frame.js'));

                window.Isign = window.Isign || {};
                window.Isign.adjustHeight = true;
                window.Isign.onSignSuccess = () => {
                    $wire.syncDokobitDocument(this.documentId);
                };
                window.Isign.onSignError = (errors) => {
                    const message = Array.isArray(errors)
                        ? errors.join(', ')
                        : (errors ? String(errors) : 'Unable to sign document.');
                    $wire.reportDokobitError(message);
                };

                this.$nextTick(() => {
                    if (window.Isign.PostMessage && typeof window.Isign.PostMessage.init === 'function') {
                        window.Isign.PostMessage.init();
                    }
                    this.booted = true;
                });
            } catch (error) {
                console.error(error);
                $wire.reportDokobitError(error?.message || 'Could not load Dokobit signing frame.');
            }
        }
    }"
    class="space-y-3"
>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Sandbox rejects real Mobile-ID credentials — use Dokobit test identities if you are on Sandbox.
        After a successful signature, the signed PDF is downloaded into this system automatically.
    </p>

    @if (filled($url))
        <iframe
            id="isign-gateway"
            src="{{ $url }}"
            class="w-full rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
            style="min-height: 70vh; height: 70vh;"
            title="Dokobit signing"
            allow="camera; microphone; clipboard-write"
        ></iframe>
    @else
        <p class="text-sm text-danger-600">Signing URL is missing.</p>
    @endif
</div>
