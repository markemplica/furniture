import { expect, test } from '@playwright/test';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { resolve } from 'node:path';

test.describe.configure({ mode: 'serial' });
test.skip(({ isMobile }) => !isMobile, 'These checks exercise the phone viewport and touch input.');

test.beforeEach(async ({ page }) => {
    await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css', (route) => route.fulfill({
        path: resolve('node_modules/bootstrap/dist/css/bootstrap.min.css'), contentType: 'text/css',
    }));
    await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js', (route) => route.fulfill({
        path: resolve('node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'), contentType: 'text/javascript',
    }));
    await page.goto('/login');
    await page.getByLabel('Email address').fill('browser-customer@example.test');
    await page.getByLabel('Password', { exact: true }).fill('BrowserTest-12345');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/');
});

async function checkPhoneLayout(page) {
    await page.waitForLoadState('load');
    const audit = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        width: document.documentElement.scrollWidth,
        overflow: [...document.querySelectorAll('body *')]
            .filter((element) => element.scrollWidth > element.clientWidth + 2 && element.scrollWidth > 390)
            .slice(0, 12).map((element) => ({ tag: element.tagName, className: typeof element.className === 'string' ? element.className : '', scroll: element.scrollWidth, client: element.clientWidth })),
        compare: [...document.querySelectorAll('.room-compare-layout, .room-compare-layout > *, .comparison-panel-head')]
            .map((element) => ({ className: element.className, width: Math.round(element.getBoundingClientRect().width), scroll: element.scrollWidth, client: element.clientWidth })),
        tinyControls: [...document.querySelectorAll('main button, main input[type="file"], main select, main textarea')]
            .filter((element) => {
                const rect = element.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0 && getComputedStyle(element).visibility !== 'hidden' && !element.disabled && rect.height < 40;
            })
            .map((element) => ({ tag: element.tagName.toLowerCase(), name: element.getAttribute('name') || element.textContent.trim().slice(0, 30), height: Math.round(element.getBoundingClientRect().height) })),
    }));
    expect(audit.width, JSON.stringify(audit)).toBeLessThanOrEqual(audit.viewport + 1);
    expect(audit.tinyControls, JSON.stringify(audit)).toEqual([]);
}

test('preference form saves without overflow or tiny controls', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/preferences');
    await expect(page.getByRole('heading', { name: 'Plan your room.' })).toBeVisible();
    await checkPhoneLayout(page);
    await page.locator('[name="notes"]').fill('Mobile browser smoke note.');
    await page.getByRole('button', { name: 'Update preferences' }).click();
    await expect(page.getByRole('button', { name: 'Updated successfully' })).toBeDisabled();
    expect(errors).toEqual([]);
    await page.screenshot({ path: join(tmpdir(), 'furnicraft-preferences-mobile.png'), fullPage: true });
});

test('room upload works without AI consent at phone width', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/room');
    await expect(page).toHaveURL(/\/preferences#room-preview$/);
    await checkPhoneLayout(page);
    await page.locator('[data-room-photo-input]').setInputFiles(join(process.env.FURNICRAFT_BROWSER_DB.replace(/[\\/]database\.sqlite$/, ''), 'room-upload.png'));
    await expect(page.locator('[data-room-photo-quality]')).toContainText('Photo checked and prepared');
    await page.getByRole('button', { name: 'Upload room photo' }).click();
    await expect(page.getByRole('dialog', { name: 'Enable AI analysis?' })).toBeVisible();
    await page.getByRole('button', { name: 'Continue without AI' }).click();
    await expect(page).toHaveURL(/\/preferences\?room=\d+#room-preview$/);
    await expect(page.locator('[data-room-option]')).toHaveCount(2);
    await checkPhoneLayout(page);
    expect(errors).toEqual([]);
    await page.screenshot({ path: join(tmpdir(), 'furnicraft-upload-mobile.png'), fullPage: true });
});

test('room preview renders and the comparison slider responds to a touch drag', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/room/1/visualize?product=1');
    await expect(page.getByRole('heading', { name: /See Browser Test Dining Table/ })).toBeVisible();
    await expect.poll(() => page.locator('.room-source-card img').evaluate((image) => image.naturalWidth)).toBeGreaterThan(0);
    await expect(page.locator('[data-room-preview-canvas]')).toBeVisible();
    const placementState = await page.locator('[data-room-preview]').evaluate((frame) => ({
        mode: frame.roomPreviewEngine?.placementMode,
        floor: frame.roomPreviewEngine?.floor,
        source: frame.dataset.placementSource,
        result: frame.roomPreviewEngine?.result,
        status: frame.querySelector('[data-placement-status]')?.textContent?.trim(),
    }));
    expect(placementState.result, JSON.stringify(placementState)).not.toBeNull();
    await checkPhoneLayout(page);
    await page.screenshot({ path: join(tmpdir(), 'furnicraft-preview-mobile.png'), fullPage: true });
    await page.goto('/recommendations/1');
    const slider = page.getByRole('slider', { name: /Before and after preview/ });
    await expect(slider).toBeVisible();
    await checkPhoneLayout(page);
    await slider.scrollIntoViewIfNeeded();
    const bounds = await slider.boundingBox();
    expect(bounds).not.toBeNull();
    const y = bounds.y + bounds.height / 2;
    const session = await page.context().newCDPSession(page);
    await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: bounds.x + bounds.width * .25, y }] });
    await session.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: bounds.x + bounds.width * .8, y }] });
    await session.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect(slider).toHaveAttribute('aria-valuenow', /^(7\d|8\d)$/);
    expect(errors).toEqual([]);
});

test('chat sends a question and shows a reply at phone width', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/recommendations/1');
    await expect(page.getByRole('heading', { name: 'Ask about these matches' })).toBeVisible();
    await checkPhoneLayout(page);
    await page.getByLabel('Question').fill('Do you have Browser Test Dining Table?');
    await page.getByRole('button', { name: 'Ask FurniCraft' }).click();
    await expect(page.locator('.chat-message.user')).toContainText('Do you have Browser Test Dining Table?');
    await expect(page.locator('.chat-message.assistant')).toContainText('Browser Test Dining Table');
    await checkPhoneLayout(page);
    expect(errors).toEqual([]);
    await page.screenshot({ path: join(tmpdir(), 'furnicraft-chat-mobile.png'), fullPage: true });
});
