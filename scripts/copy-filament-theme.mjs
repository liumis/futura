import { copyFileSync, mkdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const manifestPath = join(root, 'public/build/manifest.json');
const destDir = join(root, 'public/css');
const dest = join(destDir, 'filament-admin-theme.css');

if (!existsSync(manifestPath)) {
    if (existsSync(dest)) {
        console.warn('No Vite manifest; keeping existing public/css/filament-admin-theme.css');
        process.exit(0);
    }
    console.error('public/build/manifest.json missing — run vite build first');
    process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const entry = manifest['resources/css/filament/admin/theme.css'];

if (!entry?.file) {
    if (existsSync(dest)) {
        console.warn('Filament theme missing from Vite manifest; keeping committed CSS');
        process.exit(0);
    }
    console.error('Filament theme entry missing from Vite manifest');
    process.exit(1);
}

const source = join(root, 'public/build', entry.file);
const css = readFileSync(source, 'utf8');

// Laravel Cloud's Vite build sometimes emits a default Filament bundle without Futura tokens.
// Never fail the deploy in that case — keep the committed public/css theme instead.
if (!css.includes('Jost') || !css.includes('23262a')) {
    if (existsSync(dest)) {
        console.warn('Vite theme missing Futura tokens (Jost / #23262a); keeping committed public/css/filament-admin-theme.css');
        process.exit(0);
    }
    console.error('Built theme is missing Futura tokens and no committed CSS fallback exists');
    process.exit(1);
}

mkdirSync(destDir, { recursive: true });
copyFileSync(source, dest);
console.log(`Copied ${entry.file} → public/css/filament-admin-theme.css`);
