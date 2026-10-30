// Builds public/assets/icons.svg (an SVG sprite of only the icons we use) and
// public/assets/ui-map.json (semantic icon + colour maps for PHP views).
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const out = join(root, 'public', 'assets');
const iconsDir = join(root, 'node_modules', '@tabler', 'icons', 'icons', 'outline');

const map = JSON.parse(readFileSync(join(here, 'icons.json'), 'utf8'));
const semantic = JSON.parse(readFileSync(join(here, 'semantic.json'), 'utf8'));
delete map._comment;
delete semantic._comment;
const extra = map.extra || [];
delete map.extra;

const resolve = (name) => map[name] || name;
const ids = new Set([...Object.values(map), ...extra]);
for (const group of Object.values(semantic)) for (const v of Object.values(group)) ids.add(resolve(v.icon));

const symbols = [];
for (const id of [...ids].sort()) {
  const file = join(iconsDir, `${id}.svg`);
  if (!existsSync(file)) {
    console.error(`Missing Tabler icon: ${id}`);
    process.exit(1);
  }
  const svg = readFileSync(file, 'utf8');
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').replace(/<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*\/>/, '').trim();
  symbols.push(`<symbol id="${id}" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</g></symbol>`);
}
mkdirSync(out, { recursive: true });
writeFileSync(join(out, 'icons.svg'), `<svg xmlns="http://www.w3.org/2000/svg" style="display:none">${symbols.join('')}</svg>\n`);
// Semantic entries reference icon names; resolve them to sprite ids for PHP.
const resolvedSemantic = Object.fromEntries(Object.entries(semantic).map(([g, vals]) => [g, Object.fromEntries(Object.entries(vals).map(([k, v]) => [k, { ...v, icon: resolve(v.icon) }]))]));
writeFileSync(join(out, 'ui-map.json'), JSON.stringify({ icons: map, semantic: resolvedSemantic }));
console.log(`icons.svg: ${symbols.length} icons; ui-map.json written`);
