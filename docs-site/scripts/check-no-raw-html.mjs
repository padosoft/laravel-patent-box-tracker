import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'content');
const htmlPattern = /<\/?[a-z][\s\S]*?>/i;
const allowed = new Set(['README.md']);
const failures = [];

function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const stat = statSync(full);
    if (stat.isDirectory()) {
      walk(full);
      continue;
    }
    if (!entry.endsWith('.md')) {
      continue;
    }
    const rel = relative(root, full).replaceAll('\\', '/');
    if (allowed.has(rel)) {
      continue;
    }
    const content = readFileSync(full, 'utf8');
    if (htmlPattern.test(content)) {
      failures.push(rel);
    }
  }
}

walk(root);

if (failures.length > 0) {
  console.error('Raw HTML is not allowed in docmd Markdown pages:');
  for (const file of failures) {
    console.error(`- ${file}`);
  }
  process.exit(1);
}

console.log('No raw HTML found in docs-site/content.');
