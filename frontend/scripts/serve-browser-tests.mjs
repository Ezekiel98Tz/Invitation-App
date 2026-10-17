import { spawn, spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..', '..');

const verify = spawnSync('php', ['artisan', 'browser-test:verify'], {
    cwd: projectRoot,
    env: process.env,
    stdio: 'inherit',
});

if (verify.error) {
    console.error('Could not run the browser-test guard:', verify.error.message);
    process.exit(1);
}

if (verify.status !== 0) {
    console.error('Browser-test server was not started because the safety guard failed.');
    process.exit(verify.status ?? 1);
}

const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8001'], {
    cwd: projectRoot,
    env: process.env,
    stdio: 'inherit',
});

const stop = (signal) => {
    if (!server.killed) server.kill(signal);
};

process.on('SIGINT', () => stop('SIGINT'));
process.on('SIGTERM', () => stop('SIGTERM'));
process.on('exit', () => stop('SIGTERM'));

server.on('error', (error) => {
    console.error('Could not start the Laravel browser-test server:', error.message);
    process.exit(1);
});

server.on('exit', (code) => {
    process.exit(code ?? 1);
});
