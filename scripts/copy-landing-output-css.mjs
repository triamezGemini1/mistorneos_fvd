/**
 * Publica Tailwind compilado en assets/css/ (ruta fija para producción, sin depender de dist/).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const src = path.join(root, 'public', 'assets', 'dist', 'output.css');
const destDir = path.join(root, 'public', 'assets', 'css');
const dest = path.join(destDir, 'landing-precompiled.css');

if (!fs.existsSync(src)) {
    console.error('copy-landing-output-css: no existe', src);
    process.exit(1);
}
fs.mkdirSync(destDir, { recursive: true });
fs.copyFileSync(src, dest);
console.log('CSS precompilado landing → public/assets/css/landing-precompiled.css');
