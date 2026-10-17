import { execFileSync } from 'node:child_process';
import { browserTestEnvironment, projectRoot } from './support/environment';

export default function globalSetup(): void {
    execFileSync('php', ['artisan', 'browser-test:seed'], {
        cwd: projectRoot,
        env: { ...process.env, ...browserTestEnvironment },
        stdio: 'inherit',
    });
}
