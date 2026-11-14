import { expect, test } from '@playwright/test';
import { join, resolve } from 'node:path';
import { tmpdir } from 'node:os';

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

for (const url of ['/room/1/visualize?product=1', '/recommendations/1']) {
    test(`automatic 2D preview is ready without provider requests at ${url}`, async ({ page }, testInfo) => {
        const providerRequests = [];
        const errors = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && /\/preview-(?:v2|verify)(?:\/|$)/.test(new URL(request.url()).pathname)) providerRequests.push(request.url());
        });
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(url);
        const canvas = page.locator('[data-room-preview-canvas]');
        await expect(canvas).toBeVisible();
        await expect.poll(() => page.locator('[data-room-preview]').evaluate((frame) => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
        await expect(page.locator('[data-preview-download]')).toBeEnabled();
        const savedAnalysis = await page.locator('[data-room-preview]').evaluate((frame) => ({
            stored: JSON.parse(frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent),
            loaded: frame.roomPreviewEngine.analysis,
        }));
        expect(savedAnalysis.loaded).toEqual(savedAnalysis.stored);
        // Exercise a consented rerender without contacting the provider or changing stored consent.
        await page.locator('[data-room-preview]').evaluate(async (frame) => {
            frame.dataset.consented = 'true';
            frame.dataset.previewV2Enabled = 'true';
            await frame.roomPreviewEngine.start();
        });
        await expect(page.locator('[data-preview-download]')).toBeEnabled();
        expect(providerRequests).toEqual([]);
        expect(errors).toEqual([]);
        const layout = await page.evaluate(() => ({width: document.documentElement.scrollWidth, viewport: document.documentElement.clientWidth}));
        expect(layout.width).toBeLessThanOrEqual(layout.viewport + 1);
        const measurePixels = (frame) => {
            const engine = frame.roomPreviewEngine;
            const { width, height } = engine.canvas;
            const baseline = document.createElement('canvas'); baseline.width = width; baseline.height = height;
            baseline.getContext('2d').drawImage(engine.room, 0, 0, width, height);
            const before = baseline.getContext('2d').getImageData(0, 0, width, height).data;
            const after = engine.context.getImageData(0, 0, width, height).data;
            const p = engine.result;
            const mask = document.createElement('canvas'); mask.width = width; mask.height = height;
            const ctx = mask.getContext('2d');
            const crop = engine.productImageBounds;
            ctx.translate(p.x * width, p.y * height - p.height * height / 2);
            ctx.rotate((Number(p.rotationDeg) || 0) * Math.PI / 180);
            ctx.transform(1, 0, Math.tan((Number(p.perspectiveDeg) || 0) * Math.PI / 180) * 0.35, 1, 0, 0);
            ctx.scale(p.flip === true ? -1 : 1, 1);
            ctx.drawImage(engine.productRenderImage || engine.item, crop.x, crop.y, crop.width, crop.height, -p.width * width / 2, -p.height * height / 2, p.width * width, p.height * height);
            const source = ctx.getImageData(0, 0, width, height).data;
            const frameBounds = frame.getBoundingClientRect();
            const canvasBounds = engine.canvas.getBoundingClientRect();
            const displayScale = Math.min(canvasBounds.width / width, canvasBounds.height / height);
            const imageLeft = canvasBounds.left + (canvasBounds.width - width * displayScale) / 2;
            const imageTop = canvasBounds.top + (canvasBounds.height - height * displayScale) / 2;
            const split = parseFloat(getComputedStyle(frame).getPropertyValue('--split')) || 50;
            const visibleLeft = frame.classList.contains('room-comparison') ? frameBounds.left + frameBounds.width * split / 100 : frameBounds.left;
            let productPixels = 0, visibleProductPixels = 0, maskPixels = 0, allChanged = 0;
            for (let y = 0; y < Math.floor((p.y - p.height * 0.1) * height); y++) {
                for (let x = 0; x < width; x++) {
                    const i = (y * width + x) * 4;
                    if (Math.abs(after[i] - before[i]) + Math.abs(after[i + 1] - before[i + 1]) + Math.abs(after[i + 2] - before[i + 2]) > 45) allChanged++;
                    if (source[i + 3] < 200) continue;
                    maskPixels++;
                    const delta = Math.abs(after[i] - before[i]) + Math.abs(after[i + 1] - before[i + 1]) + Math.abs(after[i + 2] - before[i + 2]);
                    if (delta > 45) {
                        productPixels++;
                        const screenX = imageLeft + x * displayScale;
                        const screenY = imageTop + y * displayScale;
                        if (screenX >= visibleLeft && screenX <= frameBounds.right && screenY >= frameBounds.top && screenY <= frameBounds.bottom) visibleProductPixels++;
                    }
                }
            }
            return {productPixels, visibleProductPixels, visibleRatio: visibleProductPixels / Math.max(1, productPixels), maskPixels, allChanged, crop, item: [engine.item.naturalWidth, engine.item.naturalHeight], frame: [frameBounds.width, frameBounds.height], canvas: [canvasBounds.width, canvasBounds.height], split, placement: p};
        };
        const pixelProof = await page.locator('[data-room-preview]').evaluate(measurePixels);
        const suffix = url.startsWith('/recommendations') ? 'comparison' : 'dedicated';
        await page.screenshot({ path: join(tmpdir(), `furnicraft-auto-fit-${testInfo.project.name}-${suffix}.png`), fullPage: true });
        expect(pixelProof.productPixels, JSON.stringify(pixelProof)).toBeGreaterThan(200);
        expect(pixelProof.visibleProductPixels, JSON.stringify(pixelProof)).toBeGreaterThan(200);
        expect(pixelProof.visibleRatio, JSON.stringify(pixelProof)).toBeGreaterThan(suffix === 'dedicated' ? 0.9 : 0.2);
        // A portrait phone photo must also fit completely inside the frame.
        await page.locator('[data-room-preview]').evaluate(async (frame) => {
            const scene = document.createElement('canvas'); scene.width = 600; scene.height = 800;
            const ctx = scene.getContext('2d');
            ctx.fillStyle = '#dbcebe'; ctx.fillRect(0, 0, 600, 800);
            ctx.fillStyle = '#936f4e'; ctx.fillRect(0, 480, 600, 320);
            const uri = scene.toDataURL('image/png');
            const engine = frame.roomPreviewEngine;
            engine.room.src = uri;
            const before = frame.querySelector('.comparison-room-base');
            if (before) before.src = uri;
            await engine.room.decode();
            await engine.start();
        });
        const portraitProof = await page.locator('[data-room-preview]').evaluate(measurePixels);
        expect(portraitProof.productPixels, JSON.stringify(portraitProof)).toBeGreaterThan(200);
        expect(portraitProof.visibleRatio, JSON.stringify(portraitProof)).toBeGreaterThan(suffix === 'dedicated' ? 0.9 : 0.2);
        expect(providerRequests).toEqual([]);
        expect(errors).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
        await page.screenshot({ path: join(tmpdir(), `furnicraft-auto-fit-${testInfo.project.name}-${suffix}-portrait.png`), fullPage: true });
    });
}

test('a fully blocked floor shows the original room and disables preview export', async ({ page }) => {
    await page.goto('/room/1/visualize?product=1');
    await expect.poll(() => page.locator('[data-room-preview]').evaluate((frame) => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
    await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const engine = frame.roomPreviewEngine;
        engine.floorDetected = true;
        engine.blocked = [[[0, 0], [1, 0], [1, 1], [0, 1]]];
        await engine.start();
    });

    await expect(page.locator('strong[data-placement-status]')).toHaveText('No validated placement available');
    await expect(page.locator('[data-placement-guidance]')).toContainText('No clear area passed the placement checks.');
    await expect(page.locator('[data-preview-download]')).toBeDisabled();
    expect(await page.locator('[data-room-preview]').evaluate((frame) => ({result: frame.roomPreviewEngine.result, finalPlacement: frame.dataset.finalPlacement}))).toEqual({result: null, finalPlacement: ''});
});

test('the sala-set fallback stays modest in size without AI floor geometry', async ({ page }, testInfo) => {
    await page.goto('/room/1/visualize?product=1');
    await expect.poll(() => page.locator('[data-room-preview]').evaluate((frame) => Boolean(frame.roomPreviewEngine?.result))).toBe(true);
    const placement = await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const engine = frame.roomPreviewEngine;
        engine.item.src = '/images/catalog/drive-02.png';
        Object.assign(frame.dataset, {
            initialX: '64', initialY: '88', initialWidth: '68',
            productWidth: '220', productDepth: '88', productHeight: '92',
            productType: 'Sala Set', productCategory: 'Sala Set',
        });
        engine.itemType = 'sala set';
        await engine.start();
        return engine.result;
    });
    expect(placement.width).toBeLessThanOrEqual(0.5);
    expect(placement.height).toBeLessThanOrEqual(0.450001);
    expect(placement.y).toBeGreaterThanOrEqual(0.8);
    await expect(page.locator('[data-preview-download]')).toBeEnabled();
    await page.screenshot({path: join(tmpdir(), `furnicraft-local-sala-${testInfo.project.name}.png`), fullPage: true});
});

test('saved floor geometry and low placement confidence reach the renderer', async ({ page }) => {
    await page.goto('/room/1/visualize?product=1');
    const result = await page.locator('[data-room-preview]').evaluate((frame) => {
        const analysis = {
            gemini_used: true,
            vision_geometry: {
                confidence: 0.95,
                floor_polygon: [[0, 0.6], [1, 0.6], [1, 1], [0, 1]],
                blocked_zones: [{polygon: [[0, 0.6], [0.2, 0.6], [0.2, 1], [0, 1]]}],
            },
        };
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        frame.dataset.confidence = 'low';
        const engine = new window.RoomPreviewEngine(frame);
        return {floor: engine.floor, detected: engine.floorDetected, blocked: engine.blocked.length, confidence: engine.initialConfidence};
    });
    expect(result).toEqual({floor: [[0, 0.6], [1, 0.6], [1, 1], [0, 1]], detected: true, blocked: 1, confidence: 0.42});
});

test('AI sofa zone keeps its size in front of the wall and window', async ({ page }, testInfo) => {
    await page.goto('/room/1/visualize?product=1');
    const result = await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const analysis = {gemini_used:true, vision_geometry:{confidence:.9,
            floor_polygon:[[0,.61],[.28,.66],[1,.66],[1,1],[0,1]],
            openings:[{type:'window', bbox:[.59,.23,.98,.55]}],
            blocked_zones:[{reason:'existing_furniture', polygon:[[.58,.55],[1,.55],[1,.66],[.58,.66]]}],
        }};
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        Object.assign(frame.dataset, {initialX:'62', initialY:'80', initialWidth:'42',
            productWidth:'220', productDepth:'88', productHeight:'92',
            productType:'Sala Set', productCategory:'Sala Set', placementSource:'candidate-zone',
            placementRotation:'-2', placementPerspective:'6'});
        frame.querySelector('[data-preview-product]').src = '/images/catalog/drive-02.png';
        const engine = new window.RoomPreviewEngine(frame);
        frame.roomPreviewEngine = engine;
        await engine.start();
        return {placement:engine.result, visibleCells:engine.productCollisionCells.length};
    });
    expect(result.placement.x).toBe(.62);
    expect(result.placement.y).toBe(.8);
    expect(result.placement.width).toBe(.42);
    expect(result.placement.depth).toBe(.126);
    expect(result.visibleCells).toBeGreaterThan(0);
    await expect(page.locator('[data-preview-download]')).toBeEnabled();
    await page.locator('[data-room-preview]').screenshot({path:join(tmpdir(), `furnicraft-sofa-wall-${testInfo.project.name}.png`)});
});

test('transparent product corners do not hide a doorway but visible furniture does', async ({ page }) => {
    await page.goto('/room/1/visualize?product=1');
    const result = await page.locator('[data-room-preview]').evaluate(async (frame) => {
        const analysis = {vision_geometry:{floor_polygon:[[0,.6],[1,.6],[1,1],[0,1]],
            openings:[{type:'door', bbox:[.2,.25,.35,.45]}]}};
        frame.closest('.comparison-panel').querySelector('[data-preview-analysis]').textContent = JSON.stringify(analysis);
        Object.assign(frame.dataset, {initialX:'50', initialY:'90', initialWidth:'60', placementRotation:'0', placementPerspective:'0'});
        const cutout = document.createElement('canvas'); cutout.width = 600; cutout.height = 500;
        const ctx = cutout.getContext('2d'); ctx.fillStyle = '#70402a';
        ctx.fillRect(300,0,300,500); ctx.fillRect(0,450,600,50);
        frame.querySelector('[data-preview-product]').src = cutout.toDataURL();
        const engine = new window.RoomPreviewEngine(frame);
        await engine.start();
        const placement = engine.result;
        const clear = placement && engine.placementFitsPhoto(placement);
        engine.bodyBlocked = [[[.6,.25],[.7,.25],[.7,.45],[.6,.45]]];
        return {width:placement?.width, clear, blocked:placement && !engine.placementFitsPhoto(placement)};
    });
    expect(result).toEqual({width:.6, clear:true, blocked:true});
});
