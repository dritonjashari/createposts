// `npm run build` in the repository root: everything a server needs after
// `git clone` or `git pull`.
//   1. Creates backend/config.local.php from the example (first run only).
//   2. Installs the frontend's dependencies (first run, or when
//      frontend/package-lock.json changed).
//   3. Builds the frontend into frontend/dist, which index.php serves.
// Type-checking is left out (it's a development check and roughly doubles
// the memory the build needs on shared hosting); `npm run build` inside
// frontend/ still does both.
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const frontend = join(root, 'frontend');

const [major, minor] = process.versions.node.split('.').map(Number);
if (major < 20 || (major === 20 && minor < 19) || (major === 21) || (major === 22 && minor < 12)) {
  console.error(
    `Node.js ${process.versions.node} is too old: the frontend build needs 20.19+ or 22.12+.\n` +
    'On cPanel, pick a newer version under "Setup Node.js App" and run the "source .../activate" command it shows first.',
  );
  process.exit(1);
}

function run(command, args, cwd, { shell = false } = {}) {
  // Through a shell, pass one command line (args here are fixed strings).
  const result = shell
    ? spawnSync([command, ...args].join(' '), { cwd, stdio: 'inherit', shell: true })
    : spawnSync(command, args, { cwd, stdio: 'inherit' });
  if (result.status !== 0) {
    console.error(`\nFailed: ${command} ${args.join(' ')}`);
    process.exit(result.status ?? 1);
  }
}

// 1. Backend config.
const config = join(root, 'backend', 'config.local.php');
const configExample = join(root, 'backend', 'config.local.example.php');
if (!existsSync(config)) {
  copyFileSync(configExample, config);
}

// 2. Frontend dependencies. `npm ci` installs exactly what package-lock.json
// lists and never rewrites it, so the checkout stays clean for the next pull.
const lockFile = join(frontend, 'package-lock.json');
const installedLock = join(frontend, 'node_modules', '.package-lock.json');
if (!existsSync(installedLock) || statSync(installedLock).mtimeMs < statSync(lockFile).mtimeMs) {
  // npm is a .cmd script on Windows, which only runs through a shell.
  run('npm', ['ci', '--no-audit', '--no-fund'], frontend, { shell: process.platform === 'win32' });
}

// 3. Frontend build.
run(process.execPath, [join(frontend, 'node_modules', 'vite', 'bin', 'vite.js'), 'build'], frontend);

console.log('\nBuild finished.');
if (readFileSync(config, 'utf8') === readFileSync(configExample, 'utf8')) {
  console.log(
    'Next: open backend/config.local.php and fill in your database login (DB_NAME, DB_USER, DB_PASS).\n' +
    'The tables are created automatically on the first visit.',
  );
}
