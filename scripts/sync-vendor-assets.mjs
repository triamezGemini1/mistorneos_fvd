/**
 * Copia dependencias npm a public/assets/vendor/ (sin CDN en runtime).
 * Uso: npm run build:vendor
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const vendorRoot = path.join(root, 'public', 'assets', 'vendor');

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function copyFile(src, dest) {
  if (!fs.existsSync(src)) {
    throw new Error(`No encontrado: ${src} (ejecuta npm install)`);
  }
  ensureDir(path.dirname(dest));
  fs.copyFileSync(src, dest);
}

function copyDir(src, dest) {
  if (!fs.existsSync(src)) {
    throw new Error(`No encontrado: ${src} (ejecuta npm install)`);
  }
  ensureDir(dest);
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      copyDir(from, to);
    } else {
      copyFile(from, to);
    }
  }
}

const fileCopies = [
  ['node_modules/bootstrap/dist/css/bootstrap.min.css', 'bootstrap/css/bootstrap.min.css'],
  ['node_modules/bootstrap/dist/css/bootstrap.min.css.map', 'bootstrap/css/bootstrap.min.css.map'],
  ['node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', 'bootstrap/js/bootstrap.bundle.min.js'],
  ['node_modules/bootstrap/dist/js/bootstrap.bundle.min.js.map', 'bootstrap/js/bootstrap.bundle.min.js.map'],
  ['node_modules/@fortawesome/fontawesome-free/css/all.min.css', 'fontawesome/css/all.min.css'],
  ['node_modules/sweetalert2/dist/sweetalert2.min.css', 'sweetalert2/sweetalert2.min.css'],
  ['node_modules/sweetalert2/dist/sweetalert2.min.js', 'sweetalert2/sweetalert2.min.js'],
  ['node_modules/vue/dist/vue.global.prod.js', 'vue/vue.global.prod.js'],
];

ensureDir(vendorRoot);

for (const [fromRel, toRel] of fileCopies) {
  copyFile(path.join(root, fromRel), path.join(vendorRoot, toRel));
}

copyDir(
  path.join(root, 'node_modules/@fortawesome/fontawesome-free/webfonts'),
  path.join(vendorRoot, 'fontawesome/webfonts')
);

console.log('Vendor assets sincronizados en public/assets/vendor/');
