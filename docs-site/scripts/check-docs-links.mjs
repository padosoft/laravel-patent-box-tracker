import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const docsRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'content');
const files = [];
const failures = [];

function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const stat = statSync(full);
    if (stat.isDirectory()) {
      walk(full);
    } else if (entry.endsWith('.md')) {
      files.push(full);
    }
  }
}

function pageForPath(link) {
  const clean = link.split('#')[0].replace(/\/$/, '') || '/';
  if (clean === '/') {
    return join(docsRoot, 'index.md');
  }
  return join(docsRoot, `${clean.replace(/^\//, '')}.md`);
}

walk(docsRoot);

for (const file of files) {
  const content = readFileSync(file, 'utf8');
  const rel = relative(docsRoot, file).replaceAll('\\', '/');
  const matches = content.matchAll(/\[[^\]]+\]\(([^)]+)\)/g);
  for (const match of matches) {
    const link = match[1];
    if (/^(https?:|mailto:|#)/.test(link)) {
      continue;
    }
    if (link.startsWith('/')) {
      const target = pageForPath(link);
      try {
        statSync(target);
      } catch {
        failures.push(`${rel}: missing ${link}`);
      }
    }
  }
}

if (failures.length > 0) {
  console.error('Broken documentation links:');
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Documentation links are valid.');
