import { copyFileSync, mkdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const manifestPath = join(root, 'public/build/manifest.json');

if (!existsSync(manifestPath)) {
    console.error('public/build/manifest.json missing — run vite build first');
    process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const entry = manifest['resources/css/filament/admin/theme.css'];

if (!entry?.file) {
    console.error('Filament theme entry missing from Vite manifest');
    process.exit(1);
}

const source = join(root, 'public/build', entry.file);
const destDir = join(root, 'public/css');
const dest = join(destDir, 'filament-admin-theme.css');
const css = readFileSync(source, 'utf8');

if (!css.includes('Jost') || !css.includes('23262a')) {
    console.error('Built theme is missing Futura tokens (Jost / #23262a) — refusing to copy');
    process.exit(1);
}

mkdirSync(destDir, { recursive: true });
copyFileSync(source, dest);
console.log(`Copied ${entry.file} → public/css/filament-admin-theme.css`);
