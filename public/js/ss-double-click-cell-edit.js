(() => {
    if (window.__ssDoubleClickCellEditBound) {
        return;
    }

    window.__ssDoubleClickCellEditBound = true;

    const findLivewire = (el) => {
        const root = el.closest('[wire\\:id]');
        const id = root?.getAttribute('wire:id');
        if (! id || ! window.Livewire?.find) {
            return null;
        }

        try {
            return window.Livewire.find(id);
        } catch (error) {
            return null;
        }
    };

    document.addEventListener('dblclick', (event) => {
        const cell = event.target?.closest?.('[data-ss-cell-edit="1"]');
        if (! cell) {
            return;
        }

        // Ignore interactive controls, but allow dblclick on links inside editable cells
        // (e.g. website) to open the editor instead of navigating twice.
        if (event.target?.closest?.('input, textarea, select, button, [contenteditable="true"]')) {
            return;
        }

        const recordKey = cell.getAttribute('data-ss-record-key') || '';
        const field = cell.getAttribute('data-ss-cell-field') || '';
        if (! recordKey || ! field) {
            return;
        }

        const wire = findLivewire(cell);
        if (! wire || typeof wire.openDoubleClickCellEdit !== 'function') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        wire.openDoubleClickCellEdit(recordKey, field);
    }, true);
})();
