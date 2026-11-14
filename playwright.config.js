import { defineConfig, devices } from '@playwright/test';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

process.env.FURNICRAFT_BROWSER_DB ??= join(mkdtempSync(join(tmpdir(), 'furnicraft-browser-smoke-')), 'database.sqlite');

export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/global-setup.js',
    fullyParallel: true,
    reporter: 'list',
    use: {
        ...devices['Desktop Chrome'],
        channel: 'msedge',
        baseURL: 'http://127.0.0.1:8001',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'desktop-edge', use: { ...devices['Desktop Chrome'], channel: 'msedge' } },
        { name: 'mobile-edge', use: { ...devices['Desktop Chrome'], channel: 'msedge', viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true } },
    ],
});
