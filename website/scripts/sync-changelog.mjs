import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const websiteRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = resolve(websiteRoot, '..');
const sourcePath = resolve(repoRoot, 'CHANGELOG.md');
const targetPath = resolve(websiteRoot, 'src/content/docs/docs/changelog.mdx');

const changelog = await readFile(sourcePath, 'utf8');
const body = changelog
  .replace(/^# Changelog\s*/u, '')
  .replace(
    /^This changelog follows the shape of \[Keep a Changelog\]\([^)]+\): changes are grouped by release and by impact\. The root `CHANGELOG\.md` is the source of truth for GitHub Releases and the website changelog\.\s*/mu,
    '',
  )
  .trim();

const generated = `---
title: "Changelog"
description: "Release history and user-facing changes for KosmoKrator."
sidebar:
  order: 100
---

${body}
`;

await mkdir(dirname(targetPath), { recursive: true });
await writeFile(targetPath, generated);

console.log(`Synced ${sourcePath} -> ${targetPath}`);
