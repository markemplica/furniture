import { expect, test } from '@playwright/test';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { execFileSync } from 'node:child_process';

async function signIn(page, email, password = 'OriginalPassword123') {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('checkbox', { name: 'Keep me signed in' }).check();
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL('/');
}

test('profile password change preserves this session and rejects another session and remembered login', async ({ page, browser, isMobile }, testInfo) => {
    const email = `security-change-${isMobile ? 'mobile' : 'desktop'}@example.test`;
    const other = await browser.newContext({ baseURL: 'http://127.0.0.1:8001' });
    try {
        const otherPage = await other.newPage();
        await signIn(page, email);
        await signIn(otherPage, email);
        const before = await other.cookies();
        const remembered = before.filter((cookie) => cookie.name.startsWith('remember_'));
        expect(remembered.length).toBeGreaterThan(0);
        await page.goto('/profile');
        await page.locator('[data-profile-dialog-open="change-password-dialog"]').click();
        const dialog = page.locator('#change-password-dialog');
        await dialog.getByLabel('Current password', { exact: true }).fill('OriginalPassword123');
        await dialog.getByLabel('New password', { exact: true }).fill('ReplacementPassword123');
        await dialog.getByLabel('Confirm new password', { exact: true }).fill('ReplacementPassword123');
        await dialog.getByRole('button', { name: 'Change password', exact: true }).click();
        await expect(page).toHaveURL('/profile');
        await page.reload();
        await expect(page).toHaveURL('/profile');
        await otherPage.goto('/profile');
        await expect(otherPage).toHaveURL(/\/login(?:\?|$)/);
        await other.clearCookies();
        await other.addCookies(remembered);
        await otherPage.goto('/profile');
        await expect(otherPage).toHaveURL(/\/login(?:\?|$)/);
        await page.screenshot({ path: join(tmpdir(), `furnicraft-password-change-${testInfo.project.name}.png`), fullPage: false });
    } finally { await other.close(); }
});

test('password recovery invalidates both independent authenticated sessions', async ({ page, browser, isMobile }) => {
    const email = `security-reset-${isMobile ? 'mobile' : 'desktop'}@example.test`;
    const other = await browser.newContext({ baseURL: 'http://127.0.0.1:8001' });
    const recovery = await browser.newContext({ baseURL: 'http://127.0.0.1:8001' });
    try {
        const otherPage = await other.newPage();
        const resetPage = await recovery.newPage();
        await signIn(page, email);
        await signIn(otherPage, email);
        execFileSync('php', ['tests/Browser/issue-reset-fixture.php', email], { env: {
            ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: process.env.FURNICRAFT_BROWSER_DB,
            APP_CONFIG_CACHE: join(tmpdir(), 'furnicraft-no-cached-config.php'),
        } });
        await resetPage.goto('/forgot-password');
        await resetPage.getByLabel('Email address').fill(email);
        // A recently seeded code exercises the cooldown branch without sending mail.
        await resetPage.getByRole('button', { name: 'Send code', exact: true }).click();
        await expect(resetPage).toHaveURL('/reset-password');
        await resetPage.getByLabel('6-digit code').fill('123456');
        await resetPage.getByRole('button', { name: 'Verify code', exact: true }).click();
        await resetPage.getByLabel('New password', { exact: true }).fill('ReplacementPassword123');
        await resetPage.getByLabel('Confirm new password', { exact: true }).fill('ReplacementPassword123');
        await resetPage.getByRole('button', { name: 'Change password', exact: true }).click();
        await expect(resetPage).toHaveURL('/login');
        for (const existing of [page, otherPage]) {
            await existing.goto('/profile');
            await expect(existing).toHaveURL(/\/login(?:\?|$)/);
        }
        await signIn(resetPage, email, 'ReplacementPassword123');
    } finally { await other.close(); await recovery.close(); }
});
