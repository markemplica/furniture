/*
 * Deterministic automatic 2D room placement and canvas renderer.
 * All formulas below are image-space estimates; they are not construction measurements.
 */
(() => {
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const readJson = (element, fallback = {}) => {
        try { return JSON.parse(element?.textContent || JSON.stringify(fallback)); } catch (_) { return fallback; }
    };
    const normalizePoint = (point) => {
        if (!Array.isArray(point) || point.length < 2) return null;
        let x = Number(point[0]);
        let y = Number(point[1]);
        if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
        if (Math.max(Math.abs(x), Math.abs(y)) > 1.001) { x /= 100; y /= 100; }
        return x >= 0 && x <= 1 && y >= 0 && y <= 1 ? [x, y] : null;
    };
    const imageContentBounds = (pixels, width, height, sourceWidth = width, sourceHeight = height) => {
        let minX = width, minY = height, maxX = -1, maxY = -1;
        for (let y = 0; y < height; y += 1) {
            for (let x = 0; x < width; x += 1) {
                if (pixels[(y * width + x) * 4 + 3] <= 8) continue;
                minX = Math.min(minX, x);
                minY = Math.min(minY, y);
                maxX = Math.max(maxX, x);
                maxY = Math.max(maxY, y);
            }
        }
        if (maxX < minX || maxY < minY) return { x: 0, y: 0, width: sourceWidth, height: sourceHeight };
        return {
            x: minX * sourceWidth / width,
            y: minY * sourceHeight / height,
            width: (maxX - minX + 1) * sourceWidth / width,
            height: (maxY - minY + 1) * sourceHeight / height,
        };
    };
    const polygon = (value) => Array.isArray(value) ? value.map(normalizePoint).filter(Boolean) : [];
    const detectedFloorPolygon = (vision, analysis) => {
        const visionFloor = polygon(vision?.floor_polygon);
        if (visionFloor.length >= 3) return visionFloor;
        return polygon(analysis?.floor_polygon);
    };
    const pointInPolygon = (x, y, points) => {
        if (points.length < 3) return false;
        let inside = false;
        for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
            const [xi, yi] = points[i];
            const [xj, yj] = points[j];
            const crosses = ((yi > y) !== (yj > y)) && x < ((xj - xi) * (y - yi) / ((yj - yi) || 1e-8)) + xi;
            if (crosses) inside = !inside;
        }
        return inside;
    };
    const bounds = (points) => points.length ? {
        minX: Math.min(...points.map(([x]) => x)), maxX: Math.max(...points.map(([x]) => x)),
        minY: Math.min(...points.map(([, y]) => y)), maxY: Math.max(...points.map(([, y]) => y)),
    } : null;
    const polygonArea = (points) => Math.abs(points.reduce((sum, [x, y], i) => {
        const [nx, ny] = points[(i + 1) % points.length];
        return sum + (x * ny) - (nx * y);
    }, 0) / 2);
    const weightedMedian = (items) => {
        const sorted = [...items].filter((item) => Number(item.reliability) > 0).sort((a, b) => a.scale - b.scale);
        const total = sorted.reduce((sum, item) => sum + Number(item.reliability), 0);
        let accumulated = 0;
        return sorted.find((item) => (accumulated += Number(item.reliability)) >= total / 2)?.scale ?? null;
    };
    const confidenceScore = (value, fallback = 0.35) => {
        if (typeof value === 'string') return ({ high: 0.82, medium: 0.62, low: 0.38 }[value.toLowerCase()] ?? fallback);
        const number = Number(value);
        return Number.isFinite(number) ? clamp(number, 0, 1) : fallback;
    };
    const polygonInsidePolygon = (inner, outer) => {
        if (inner.length < 3 || outer.length < 3) return false;
        for (let i = 0; i < inner.length; i += 1) {
            const start = inner[i];
            const end = inner[(i + 1) % inner.length];
            // Sampling each footprint edge catches concave floor notches between its corners.
            for (let step = 0; step <= 8; step += 1) {
                const t = step / 8;
                if (!pointInPolygon(start[0] + (end[0] - start[0]) * t, start[1] + (end[1] - start[1]) * t, outer)) return false;
            }
        }
        return true;
    };
    const rectanglesOverlap = (a, b) => a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
    const polygonIntersectsRect = (points, rect) => {
        if (!Array.isArray(points) || points.length < 3) return false;
        const inRect = ([x, y]) => x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom;
        if (points.some(inRect)) return true;
        const corners = [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]];
        if (corners.some(([x, y]) => pointInPolygon(x, y, points))) return true;
        const orient = (a, b, c) => (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
        const onSegment = (point, a, b) => Math.abs(orient(a, b, point)) < 1e-8
            && point[0] >= Math.min(a[0], b[0]) - 1e-8 && point[0] <= Math.max(a[0], b[0]) + 1e-8
            && point[1] >= Math.min(a[1], b[1]) - 1e-8 && point[1] <= Math.max(a[1], b[1]) + 1e-8;
        const segmentsCross = (a, b, c, d) => {
            const o1 = orient(a, b, c), o2 = orient(a, b, d);
            const o3 = orient(c, d, a), o4 = orient(c, d, b);
            if (((o1 > 0 && o2 < 0) || (o1 < 0 && o2 > 0)) && ((o3 > 0 && o4 < 0) || (o3 < 0 && o4 > 0))) return true;
            return (Math.abs(o1) < 1e-8 && onSegment(c, a, b))
                || (Math.abs(o2) < 1e-8 && onSegment(d, a, b))
                || (Math.abs(o3) < 1e-8 && onSegment(a, c, d))
                || (Math.abs(o4) < 1e-8 && onSegment(b, c, d));
        };
        for (let i = 0; i < points.length; i += 1) {
            const a = points[i], b = points[(i + 1) % points.length];
            for (let j = 0; j < corners.length; j += 1) {
                if (segmentsCross(a, b, corners[j], corners[(j + 1) % corners.length])) return true;
            }
        }
        return false;
    };

    class RoomPreviewEngine {
        constructor(frame) {
            this.frame = frame;
            this.canvas = frame.querySelector('[data-room-preview-canvas]');
            this.context = this.canvas?.getContext('2d');
            this.room = frame.querySelector('[data-preview-room]');
            this.item = frame.querySelector('[data-preview-product]');
            this.productImageBounds = null;
            // Both Blade screens keep the JSON next to the frame inside its panel.
            this.analysis = readJson(frame.querySelector('[data-preview-analysis]')
                || frame.closest('.comparison-panel')?.querySelector('[data-preview-analysis]'));
            this.vision = this.analysis.vision_geometry || null;
            this.status = document.querySelector('[data-placement-status]');
            this.dimensions = document.querySelector('[data-placement-dimensions]');
            this.guidance = document.querySelector('[data-placement-guidance]');
            this.loading = frame.querySelector('[data-preview-loading]');
            this.download = frame.closest('.comparison-panel')?.querySelector('[data-preview-download]');
            this.detections = frame.closest('.comparison-panel')?.querySelector('[data-preview-detections]');
            this.warnings = [];
            const confidenceValue = this.vision?.confidence ?? this.analysis.confidence ?? this.analysis.scene_confidence ?? this.analysis.placement_confidence ?? 0.35;
            this.roomConfidence = confidenceScore(confidenceValue);
            this.initialConfidence = this.placementConfidenceFor(frame.dataset.placementConfidence ?? frame.dataset.confidence);
            this.placementConfidence = this.initialConfidence;
            this.verificationScore = null;
            this.runId = 0;
            this.result = null;
            this.scaleBasis = null;
            this.floor = detectedFloorPolygon(this.vision, this.analysis);
            this.floorDetected = this.floor.length >= 3;
            this.horizon = clamp(Number(this.vision?.horizon_y ?? this.analysis.horizon_y_percent ?? 40) / (Number(this.vision?.horizon_y ?? this.analysis.horizon_y_percent ?? 40) > 1 ? 100 : 1), 0.05, 0.9);
            if (!this.floorDetected) {
                // Conservative trapezoid for local 2D planning when AI floor segmentation is unavailable.
                const floorTop = clamp(this.horizon + 0.18, 0.52, 0.72);
                this.floor = [[0.17, floorTop], [0.83, floorTop], [0.99, 0.99], [0.01, 0.99]];
            }
            const visionOpeningRegions = (this.vision?.openings || []).map((opening) => ({ polygon: this.bboxPolygon(opening.bbox), type: opening.type }));
            // Prefer the validated room-facing legacy door quad when both schemas describe the same opening.
            this.openings = [...(this.analysis.openings || []), ...(this.vision?.openings || [])];
            this.blocked = [...(this.vision?.blocked_zones || []), ...(this.analysis.blocked_zones || []), ...(this.analysis.obstacles || []), ...(this.analysis.openings || []), ...visionOpeningRegions]
                .map((region) => this.regionPolygon(region)).filter((shape) => shape.length >= 3);
            // Windows and wall-adjacent floor restrictions may sit behind a sofa.
            // Doorways must also remain visible above its floor footprint.
            this.bodyBlocked = [...(this.analysis.openings || []), ...visionOpeningRegions]
                .filter((region) => !/window|skylight|transom/i.test(`${region.type || ''} ${region.label || ''}`))
                .map((region) => this.regionPolygon(region)).filter((shape) => shape.length >= 3);
            this.existing = [...(this.vision?.existing_furniture || []), ...(this.analysis.existing_furniture || [])].map((item) => this.rectFromBbox(item.bbox)).filter(Boolean);
            this.itemType = `${frame.dataset.productType || ''} ${frame.dataset.productCategory || ''}`.toLowerCase();
            this.placementMode = this.placementModeFromServer() || this.placementModeFromType(this.itemType);
            this.setupComparisonSlider();
        }

        placementModeFromType(type) {
            if (/door|doorway|entry|entrance/.test(type)) return 'door';
            if (/wall[-\s]*(mounted|mount|hung|shelf|cabinet|unit|rack)|floating[-\s]*(shelf|cabinet)|mounted[-\s]+wall/.test(type)) return 'wall';
            return 'floor';
        }

        placementConfidenceFor(value) {
            const label = String(value || '').toLowerCase();
            const zoneConfidence = ({ high: 0.9, medium: 0.68, low: 0.42 })[label];
            return zoneConfidence === undefined ? this.roomConfidence : Math.min(this.roomConfidence, zoneConfidence);
        }

        placementModeFromServer() {
            const surface = String(this.frame.dataset.placementSurface || '').toLowerCase();
            if (surface === 'opening') return 'door';
            if (surface === 'wall') return 'wall';
            if (surface === 'floor' || surface === 'wall-floor') return 'floor';
            return null;
        }

        serverPlacement(dims, naturalAspect) {
            if (this.frame.dataset.placementRenderable !== 'true') return null;

            const x = Number(this.frame.dataset.initialX) / 100;
            const y = Number(this.frame.dataset.initialY) / 100;
            const maxWidth = Number(this.frame.dataset.initialWidth) / 100;
            if (![x, y, maxWidth].every(Number.isFinite) || maxWidth <= 0) return null;

            const mode = this.placementModeFromServer() || this.placementMode;
            const score = Number(this.frame.dataset.placementScore);
            const placement = {
                mode,
                x,
                y,
                score: Number.isFinite(score) ? clamp(score / 100, 0, 1) : 0,
                source: this.frame.dataset.placementSource || 'server-selected',
                reason: this.frame.dataset.placementReason || '',
                flip: this.frame.dataset.placementFlip === 'true',
                rotationDeg: clamp(Number(this.frame.dataset.placementRotation || 0), -18, 18),
                perspectiveDeg: clamp(Number(this.frame.dataset.placementPerspective || 0), -24, 24),
            };

            if (mode === 'door') {
                const quad = polygon(readJson({ textContent: this.frame.dataset.initialQuad || 'null' }, null));
                if (quad.length !== 4) return null;
                placement.quad = quad;
                placement.width = maxWidth;
                placement.height = bounds(quad).maxY - bounds(quad).minY;
                placement.scale = 1;
                return placement;
            }

            const width = this.boundedVisualWidth(maxWidth, naturalAspect);
            const height = width * (this.room.naturalWidth / this.room.naturalHeight) / naturalAspect;
            const depth = this.floorFootprintDepth(width);
            const rect = { left: x - width / 2, right: x + width / 2, top: y - height, bottom: y };
            if (rect.left < 0.02 || rect.right > 0.98 || rect.top < 0.02 || rect.bottom > 0.98) return null;

            Object.assign(placement, { width, height, depth, scale: 1, scaleBasis: 'server-zone-visual-fit' });
            return placement;
        }

        placementFitsPhoto(placement) {
            const rect = {
                left: placement.x - placement.width / 2,
                right: placement.x + placement.width / 2,
                top: placement.y - placement.height,
                bottom: placement.y,
            };
            if (rect.left < 0.02 || rect.right > 0.98 || rect.top < 0.02 || rect.bottom > 0.98) return false;
            if (placement.mode === 'floor') {
                const foot = [
                    [rect.left, placement.y], [placement.x, placement.y], [rect.right, placement.y],
                    [rect.left, placement.y - placement.depth], [placement.x, placement.y - placement.depth], [rect.right, placement.y - placement.depth],
                ];
                if (!polygonInsidePolygon(foot, this.floor)) return false;
            }
            const restricted = placement.mode === 'floor'
                ? {...rect, top: placement.y - placement.depth}
                : rect;
            if (this.blocked.some((shape) => polygonIntersectsRect(shape, restricted))) return false;
            if (this.existing.some((box) => rectanglesOverlap(restricted, box))) return false;
            const bodyRegions = [...(this.bodyBlocked || []), ...this.existing.map((box) => [
                [box.left, box.top], [box.right, box.top], [box.right, box.bottom], [box.left, box.bottom],
            ])];
            return !this.visibleShapeOverlaps(placement, bodyRegions);
        }

        floorFootprintDepth(width) {
            // Same photo-only footprint as FurniturePlacementService::renderDepth().
            return Math.max(0.08, width * 0.3);
        }

        visibleShapeOverlaps(placement, regions) {
            if (!regions.length) return false;
            const cells = this.productCollisionCells || [{left:0, top:0, right:1, bottom:1}];
            const aspect = this.room.naturalWidth / this.room.naturalHeight;
            const angle = (Number(placement.rotationDeg) || 0) * Math.PI / 180;
            const shear = Math.tan((Number(placement.perspectiveDeg) || 0) * Math.PI / 180) * 0.35;
            const project = (u, v) => {
                const dy = (v - 0.5) * placement.height;
                const dx = (u - 0.5) * placement.width * (placement.flip === true ? -1 : 1) + shear * dy / aspect;
                return [placement.x + dx * Math.cos(angle) - dy * Math.sin(angle) / aspect,
                    placement.y - placement.height / 2 + dx * Math.sin(angle) * aspect + dy * Math.cos(angle)];
            };
            return cells.some((cell) => {
                const box = bounds([project(cell.left, cell.top), project(cell.right, cell.top), project(cell.right, cell.bottom), project(cell.left, cell.bottom)]);
                const rect = {left:box.minX, top:box.minY, right:box.maxX, bottom:box.maxY};
                return regions.some((region) => polygonIntersectsRect(region, rect));
            });
        }


        setupComparisonSlider() {
            if (!this.frame.classList.contains('room-comparison')) return;

            let dragging = false;
            const update = (clientX) => {
                const bounds = this.frame.getBoundingClientRect();
                if (!bounds.width) return;
                const value = clamp(((clientX - bounds.left) / bounds.width) * 100, 2, 98);
                const rounded = Math.round(value);
                this.frame.style.setProperty('--split', `${value}%`);
                this.frame.setAttribute('aria-valuenow', String(rounded));
                this.frame.setAttribute('aria-valuetext', `${rounded}% before, ${100 - rounded}% after`);
            };

            this.frame.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) return;
                dragging = true;
                this.frame.setPointerCapture(event.pointerId);
                update(event.clientX);
                event.preventDefault();
            });
            this.frame.addEventListener('pointermove', (event) => {
                if (dragging) update(event.clientX);
            });
            const stopDragging = (event) => {
                dragging = false;
                if (this.frame.hasPointerCapture?.(event.pointerId)) this.frame.releasePointerCapture(event.pointerId);
            };
            this.frame.addEventListener('pointerup', stopDragging);
            this.frame.addEventListener('pointercancel', stopDragging);
            this.frame.addEventListener('keydown', (event) => {
                const current = Number(this.frame.getAttribute('aria-valuenow')) || 50;
                let next = current;
                if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') next -= 4;
                else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') next += 4;
                else if (event.key === 'Home') next = 2;
                else if (event.key === 'End') next = 98;
                else return;
                event.preventDefault();
                update(this.frame.getBoundingClientRect().left + (clamp(next, 2, 98) / 100) * this.frame.getBoundingClientRect().width);
            });
        }

        bboxPolygon(box) {

            const rect = this.rectFromBbox(box);

            return rect ? [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]] : [];

        }

        regionPolygon(region) {
            if (!region || typeof region !== 'object') return [];
            const shape = polygon(region.polygon);
            return shape.length >= 3 ? shape : this.bboxPolygon(region.bbox);
        }


        rectFromBbox(box) {
            if (!Array.isArray(box) || box.length !== 4) return null;
            let [x1, y1, x2, y2] = box.map(Number);
            if (![x1, y1, x2, y2].every(Number.isFinite)) return null;
            if (Math.max(Math.abs(x1), Math.abs(y1), Math.abs(x2), Math.abs(y2)) > 1.001) [x1, y1, x2, y2] = [x1 / 100, y1 / 100, x2 / 100, y2 / 100];
            return { left: Math.min(x1, x2), right: Math.max(x1, x2), top: Math.min(y1, y2), bottom: Math.max(y1, y2) };
        }

        async selectProduct(button) {
            this.frame.dataset.productId = button.dataset.productId || '';
            this.frame.dataset.productName = button.dataset.productName || '';
            this.frame.dataset.productWidth = button.dataset.productWidth || '';
            this.frame.dataset.productDepth = button.dataset.productDepth || '';
            this.frame.dataset.productHeight = button.dataset.productHeight || '';
            this.frame.dataset.productDimensionsEstimated = button.dataset.productDimensionsEstimated || 'false';
            this.frame.dataset.productType = button.dataset.productType || '';
            this.frame.dataset.productCategory = button.dataset.productCategory || '';
            this.frame.dataset.verifyUrl = button.dataset.verifyUrl || '';
            this.frame.dataset.initialX = button.dataset.placementX || '50';
            this.frame.dataset.initialY = button.dataset.placementY || '86';
            this.frame.dataset.initialWidth = button.dataset.placementWidth || '65';
            this.frame.dataset.initialQuad = button.dataset.placementQuad || 'null';
            this.frame.dataset.placementConfidence = button.dataset.placementConfidence || 'low';
            this.frame.dataset.placementSource = button.dataset.placementSource || 'safe-fallback';
            this.frame.dataset.placementRenderable = button.dataset.placementRenderable || 'false';
            this.frame.dataset.placementSurface = button.dataset.placementSurface || '';
            this.frame.dataset.placementScore = button.dataset.placementScore || '0';
            this.frame.dataset.placementReason = button.dataset.placementReason || '';
            this.frame.dataset.placementFlip = button.dataset.placementFlip || 'false';
            this.frame.dataset.placementRotation = button.dataset.placementRotation || '0';
            this.frame.dataset.placementPerspective = button.dataset.placementPerspective || '0';
            this.item.src = button.dataset.productImage || '';
            this.result = null;
            this.frame.dataset.finalPlacement = '';
            this.itemType = `${this.frame.dataset.productType} ${this.frame.dataset.productCategory}`.toLowerCase();
            this.placementMode = this.placementModeFromServer() || this.placementModeFromType(this.itemType);
            this.warnings = [];
            this.initialConfidence = this.placementConfidenceFor(this.frame.dataset.placementConfidence);
            this.placementConfidence = this.initialConfidence;
            this.verificationScore = null;
            document.querySelectorAll('[data-inline-preview-name]').forEach((node) => { node.textContent = button.dataset.productName || 'Selected furniture'; });
            const image = document.querySelector('[data-inline-preview-card-image]');
            if (image) { image.src = button.dataset.productImage || ''; image.alt = button.dataset.productName || ''; }
            const score = document.querySelector('[data-inline-preview-score]');
            if (score) score.textContent = `${button.dataset.productScore || '0'}% match`;
            const price = document.querySelector('[data-inline-preview-price]');
            if (price) price.textContent = button.dataset.productPrice || '0.00';
            const link = document.querySelector('[data-inline-preview-link]');
            if (link) link.href = button.dataset.productUrl || '#';
            document.querySelectorAll('[data-recommendation-preview]').forEach((control) => {
                const selected = control === button;
                control.setAttribute('aria-pressed', String(selected));
                control.textContent = selected ? 'Selected' : 'Preview in room';
            });
            const runId = ++this.runId;
            await this.start(runId);
        }

        async start(runId = ++this.runId) {
            const enhancementRequested = this.retryAiPreview === true;
            this.retryAiPreview = false;
            this.result = null;
            this.frame.dataset.finalPlacement = '';
            if (this.download) { this.download.disabled = true; this.download.onclick = null; }
            if (this.detections) this.detections.innerHTML = '';
            this.warnings = [];
            this.placementConfidence = this.initialConfidence;
            this.verificationScore = null;
            if (!this.canvas || !this.context || !this.room || !this.item) {
                this.setStatus('Dimensions not available for this item.', '');
                return;
            }
            if (this.room.complete && this.room.naturalWidth > 0) this.drawRoomOnly();
            this.setProgress('Preparing automatic 2D preview');
            try {
                await Promise.all([this.room.decode(), this.item.decode()]);
                if (runId !== this.runId) return;
            } catch (_) {
                if (runId !== this.runId) return;
                this.setProgress('');
                this.setStatus('Room or furniture image could not be loaded. Upload another photo to try again.', '');
                return;
            }
            this.productRenderImage = this.rasterizeProductImage(this.item);
            this.productImageBounds = this.visibleImageBounds(this.productRenderImage);
            this.productCollisionCells = this.visibleProductCells();
            const dims = this.dimensionsFromCatalog();
            if (!dims) {
                this.drawRoomOnly();
                this.setProgress('');
                this.setStatus('Dimensions not available for this item.', '');
                if (this.dimensions) this.dimensions.textContent = 'This furniture item needs width, depth, and height in the catalog before it can be previewed.';
                return;
            }
            this.dimensions && (this.dimensions.textContent = `${dims.width} × ${dims.depth} × ${dims.height} cm ${dims.estimated ? 'standard planning estimate; confirm with shop' : 'catalog dimensions'}`);
            if (this.vision?.image_quality?.usable === false && !(this.placementMode === 'door' && this.openingForDoor(dims) && (this.vision.image_quality.issues || []).includes('no_floor_visible') && (this.vision.image_quality.issues || []).every((issue) => issue === 'no_floor_visible'))) {
                this.drawRoomOnly();
                const issue = (this.vision.image_quality.issues || [])[0] || 'no_floor_visible';
                const tips = { blurry: 'The room photo is blurry. Retake it with the camera held steady.', too_dark: 'The room photo is too dark. Turn on the lights and retake it.', too_close: 'The photo is too close. Step back and include the floor and walls.', no_floor_visible: 'The floor is not visible. Take the photo from the doorway so the floor is visible.', extreme_wide_angle: 'The wide-angle view distorts room proportions. Retake from farther back using the normal camera lens.' };
                this.setStatus('Room photo needs a clearer view', 'low');
                if (this.guidance) this.guidance.textContent = tips[issue] || tips.no_floor_visible;
                this.setProgress('');
                return;
            }
            this.setProgress('Fitting furniture');
            if (this.placementMode !== 'door' && !this.floorDetected) {
                this.placementConfidence = Math.min(this.placementConfidence, 0.42);
                this.warnings.push('No clear floor boundary was detected; an estimated floor area is being used for a rough placement.');
            }
            const analysisPending = ['queued', 'processing', 'retrying'].includes(this.frame.dataset.placementStatus);
            const placement = this.autoFit(dims);
            if (!placement) {
                this.drawRoomOnly();
                this.setProgress('');
                if (analysisPending && this.frame.dataset.consented === 'true') {
                    this.setStatus(this.placementMode === 'door' ? 'Analyzing doorway in your photo' : 'Analyzing room photo', 'pending');
                    if (this.guidance) this.guidance.textContent = this.placementMode === 'door'
                        ? 'The room photo is still being analyzed. A door will appear only if a complete doorway frame is found.'
                        : 'The room photo is still being analyzed. Furniture will appear if a clear placement area is found.';
                } else if (this.placementMode === 'door') {
                    this.setStatus('No confirmed doorway available', 'low');
                    if (this.guidance) this.guidance.textContent = 'A complete doorway placement was not confirmed, so this door has not been placed. Use a photo showing the full frame from top to floor, then re-analyze when AI is available.';
                } else {
                    this.setStatus('No validated placement available', 'low');
                    if (this.guidance) this.guidance.textContent = 'No clear area passed the placement checks. Try a wider photo showing the floor and nearby doors, or re-analyze the room. The product has not been placed over a blocked area.';
                }
                return;
            }
            this.result = placement;
            this.draw(placement, dims);
            this.report(placement);
            this.frame.dataset.finalPlacement = JSON.stringify(placement);
            if (this.download) { this.download.disabled = false; this.download.onclick = () => this.downloadPng(); }
            let aiImageShown = false;
            if (this.frame.dataset.consented === 'true' && analysisPending) {
                this.setProgress('AI room analysis is still running', false);
            } else if (enhancementRequested && this.frame.dataset.consented === 'true' && this.frame.dataset.previewV2Enabled === 'true' && this.frame.dataset.previewV2Url) {
                aiImageShown = await this.generateAiPreview(runId, true);
                if (runId !== this.runId) return;
            } else if (enhancementRequested && !analysisPending && this.frame.dataset.consented === 'true' && this.frame.dataset.verifyUrl) {
                this.setProgress('Checking the visual fit', false);
                await this.verifyLoop(dims, runId);
                if (runId !== this.runId) return;
            } else if (!aiImageShown && (analysisPending || this.frame.dataset.consented !== 'true' || this.analysis.gemini_used !== true)) {
                this.placementConfidence = Math.min(this.placementConfidence, 0.45);
                this.report(this.result);
            }
            this.setProgress('');
            this.frame.dataset.finalPlacement = JSON.stringify(this.result);
        }

        dimensionsFromCatalog() {
            const catalog = {
                width: Number(this.frame.dataset.productWidth),
                depth: Number(this.frame.dataset.productDepth),
                height: Number(this.frame.dataset.productHeight),
            };
            const type = this.itemType || `${this.frame.dataset.productType || ''} ${this.frame.dataset.productCategory || ''}`.toLowerCase();
            const estimate = /door|doorway|entry/.test(type) ? [90, 4, 210]
                : /bed|bunk|double.deck/.test(type) ? [150, 205, 110]
                : /cabinet|wardrobe|dresser|shelf|storage/.test(type) ? [100, 45, 180]
                : /sala|sofa|couch|sectional|living.room/.test(type) ? [225, 90, 92]
                : /dining/.test(type) ? [180, 90, 76]
                : /chair|stool|bench|seating/.test(type) ? [60, 65, 95]
                : /table|desk/.test(type) ? [105, 60, 75]
                : [100, 60, 90];
            const estimated = this.frame.dataset.productDimensionsEstimated === 'true' || !(catalog.width > 0 && catalog.depth > 0 && catalog.height > 0);
            return {
                width: catalog.width > 0 ? catalog.width : estimate[0],
                depth: catalog.depth > 0 ? catalog.depth : estimate[1],
                height: catalog.height > 0 ? catalog.height : estimate[2],
                estimated,
            };
        }

        rasterizeProductImage(image) {
            if (!(image.naturalWidth > 0 && image.naturalHeight > 0)) return image;
            const raster = document.createElement('canvas');
            const ratio = Math.min(1, 1600 / Math.max(image.naturalWidth, image.naturalHeight));
            raster.width = Math.max(1, Math.round(image.naturalWidth * ratio));
            raster.height = Math.max(1, Math.round(image.naturalHeight * ratio));
            // Draw SVGs whole before cropping. Their viewBox coordinates can differ
            // from naturalWidth/naturalHeight in cropped drawImage calls.
            raster.getContext('2d').drawImage(image, 0, 0, raster.width, raster.height);
            return raster;
        }

        visibleImageBounds(image) {
            const fullImage = { x: 0, y: 0, width: image.naturalWidth || image.width, height: image.naturalHeight || image.height };
            try {
                const ratio = Math.min(1, 256 / Math.max(fullImage.width, fullImage.height));
                const width = Math.max(1, Math.round(fullImage.width * ratio));
                const height = Math.max(1, Math.round(fullImage.height * ratio));
                const sample = document.createElement('canvas');
                sample.width = width;
                sample.height = height;
                const context = sample.getContext('2d', { willReadFrequently: true });
                if (!context) return fullImage;
                context.drawImage(image, 0, 0, width, height);
                const pixels = context.getImageData(0, 0, width, height).data;
                return imageContentBounds(pixels, width, height, fullImage.width, fullImage.height);
            } catch (_) {
                // Keep the original image when alpha bounds cannot be inspected.
                return fullImage;
            }
        }

        productImageAspect() {
            const bounds = this.productImageBounds || { width: this.item.naturalWidth, height: this.item.naturalHeight };
            return bounds.width / Math.max(1, bounds.height);
        }

        visibleProductCells() {
            try {
                const sample = document.createElement('canvas');
                sample.width = sample.height = 48;
                const context = sample.getContext('2d', {willReadFrequently:true});
                const crop = this.productImageBounds;
                context.drawImage(this.productRenderImage || this.item, crop.x, crop.y, crop.width, crop.height, 0, 0, 48, 48);
                const pixels = context.getImageData(0, 0, 48, 48).data;
                const cells = [];
                // Merge opaque runs in each row; transparent corners and gaps do not collide.
                for (let y = 0; y < 48; y++) {
                    let start = null;
                    for (let x = 0; x <= 48; x++) {
                        const visible = x < 48 && pixels[(y * 48 + x) * 4 + 3] > 8;
                        if (visible && start === null) start = x;
                        if (!visible && start !== null) {
                            cells.push({left:start/48, top:y/48, right:x/48, bottom:(y+1)/48});
                            start = null;
                        }
                    }
                }
                return cells;
            } catch (_) {
                return [{left:0, top:0, right:1, bottom:1}];
            }
        }

        boundedVisualWidth(width, naturalAspect) {
            if (this.placementMode !== 'floor' || this.floorDetected !== false) return width;
            // No detected floor means the type-based size is only a fallback.
            // Leave room visible and limit height using the actual cutout proportions.
            const frameAspect = this.room.naturalWidth / this.room.naturalHeight;
            return Math.min(width, 0.5, 0.45 * naturalAspect / frameAspect);
        }

        setProgress(step, blocking = true) {
            if (!this.loading) return;
            this.loading.hidden = !step || !blocking;
            if (step && blocking) this.loading.textContent = `Automatic fit · ${step}`;
            if (step && !blocking && this.guidance) this.guidance.textContent = `The automatic placement is ready. ${step} is continuing in the background.`;
        }

        setStatus(text, level = '') {
            if (this.status) this.status.textContent = text;
            this.frame.dataset.resultLevel = level;
        }

        floorWidthAt(y) {
            if (this.floor.length < 3) return 0;
            const intersections = [];
            for (let index = 0; index < this.floor.length; index += 1) {
                const [x1, y1] = this.floor[index];
                const [x2, y2] = this.floor[(index + 1) % this.floor.length];
                if (Math.abs(y2 - y1) < 1e-6) {
                    if (Math.abs(y - y1) < 1e-4) intersections.push(x1, x2);
                    continue;
                }
                const low = Math.min(y1, y2), high = Math.max(y1, y2);
                if (y < low || y > high) continue;
                const t = (y - y1) / (y2 - y1);
                intersections.push(x1 + (x2 - x1) * t);
            }
            if (intersections.length < 2) return 0;
            return Math.max(...intersections) - Math.min(...intersections);
        }

        estimateScale(y, opening = null) {
            this.scaleBasis = null;
            const imageHeight = this.room.naturalHeight;
            if (opening) {
                const box = this.rectFromBbox(opening.bbox);
                const polygonBounds = bounds(polygon(opening.polygon));
                const pixelHeight = box ? (box.bottom - box.top) * imageHeight : polygonBounds ? (polygonBounds.maxY - polygonBounds.minY) * imageHeight : 0;
                const typicalHeight = Number(opening.estimated_height_cm || 210);
                if (pixelHeight > 0 && typicalHeight > 0) return pixelHeight / typicalHeight;
            }
            // Floor width at the target row already includes the photo's perspective.
            // Prefer that width-based scale over unreliable single-object guesses.
            const dimensionConfidence = String(this.analysis.dimension_confidence || '').toLowerCase();
            const estimatedWidthM = Number(this.analysis.estimated_width_m);
            const floorSpan = this.floorWidthAt(y);
            if (this.analysis.gemini_used === true && ['high', 'medium'].includes(dimensionConfidence) && estimatedWidthM >= 1 && estimatedWidthM <= 30 && floorSpan > 0) {
                this.scaleBasis = 'photo-width-estimate';
                return this.room.naturalWidth * floorSpan / (estimatedWidthM * 100);
            }

            const scaleClues = Array.isArray(this.vision?.scale_clues) && this.vision.scale_clues.length
                ? this.vision.scale_clues
                : (this.analysis.scale_clues || []);
            const clueScales = scaleClues.map((clue) => {
                const clueType = String(clue.object || '').toLowerCase();
                const priority = /door/.test(clueType) ? 1 : /ceiling/.test(clueType) ? 0.85 : /window/.test(clueType) ? 0.7 : 0.55;
                const reliability = clamp(Number(clue.reliability || 0) * priority, 0, 1);
                const pixels = Number(clue.pixel_height || 0);
                const realCm = Number(clue.typical_real_cm || 0);
                const baseY = Number(clue.base_y || 0);
                const normalizedBase = baseY > 1 ? baseY / 100 : baseY;
                const deltaPixels = (normalizedBase - this.horizon) * imageHeight;
                if (normalizedBase - this.horizon < 0.1) return null;
                const k = pixels > 0 && realCm > 0 && deltaPixels > 0 ? pixels / (realCm * deltaPixels) : 0;
                const scale = k * Math.max(0, (y - this.horizon) * imageHeight);
                return scale > 0 && reliability > 0 ? { scale, reliability } : null;
            }).filter(Boolean);
            if (clueScales.length) {
                const median = weightedMedian(clueScales);
                const reliable = clueScales.filter((entry) => Math.abs(entry.scale - median) / median <= 0.25);
                // A lone or conflicting scale cue is not enough to claim a reliable physical scale.
                if (reliable.length < 2 && !(clueScales.length === 1 && reliable[0]?.reliability >= 0.9)) return null;
                const total = reliable.reduce((sum, entry) => sum + entry.reliability, 0);
                if (total > 0) {
                    this.scaleBasis = reliable.length >= 2 ? 'reference-clue-consensus' : 'single-high-confidence-reference';
                    return reliable.reduce((sum, entry) => sum + entry.scale * entry.reliability, 0) / total;
                }
            }

            return null;
        }

        openingRect(opening) {
            const box = opening.bbox ? this.rectFromBbox(opening.bbox) : null;
            if (box) return box;
            const shape = polygon(opening.polygon || []);
            const value = bounds(shape);
            return value ? { left: value.minX, right: value.maxX, top: value.minY, bottom: value.maxY } : null;
        }
        openingForDoor(dims = null) {
            const productAspect = dims?.width > 0 && dims?.height > 0 ? dims.width / dims.height : null;
            const candidates = this.openings.map((item, index) => {
                const label = `${item.type || ''} ${item.label || ''}`.toLowerCase();
                const quad = polygon(item.door_fit_quad_percent);
                if (!/door|doorway|entry|entrance|archway|closet|passage/.test(label)
                    || /window|skylight|transom/.test(label) || quad.length !== 4) return null;

                const box = bounds(quad);
                const imageAspect = (box.maxX - box.minX) * (this.room?.naturalWidth || 1)
                    / Math.max(1e-6, (box.maxY - box.minY) * (this.room?.naturalHeight || 1));
                const aspectFit = productAspect && imageAspect > 0
                    ? 1 / (1 + Math.abs(Math.log(imageAspect / productAspect)))
                    : 0.5;
                const confidence = confidenceScore(item.confidence, 0.35);
                const size = clamp(polygonArea(quad) / 0.18, 0, 1);
                const typedDoor = String(item.type || '').toLowerCase() === 'door' ? 1 : 0.6;

                return { item, index, score: 0.45 * aspectFit + 0.35 * confidence + 0.15 * size + 0.05 * typedDoor };
            }).filter(Boolean);

            return candidates.sort((a, b) => b.score - a.score || a.index - b.index)[0]?.item || null;
        }

        autoFit(dims) {
            const naturalAspect = this.productImageAspect();
            if (this.frame.dataset.placementRenderable !== undefined) {
                const serverPlacement = this.serverPlacement(dims, naturalAspect);
                if (serverPlacement && this.placementFitsPhoto(serverPlacement)) return serverPlacement;
                if (this.placementMode === 'door' && this.frame.dataset.placementRenderable === 'false') return null;
                this.warnings.push('The suggested placement did not fit the detected room area, so FurniCraft checked another photo-based position.');
            }
            if (this.placementMode === 'door') {
                const opening = this.openingForDoor(dims);
                if (opening) {
                    const quad = polygon(opening.door_fit_quad_percent);
                    const box = bounds(quad);
                    return { mode: 'door', quad, x: (box.minX + box.maxX) / 2, y: box.maxY, width: box.maxX - box.minX, scale: 1, score: 0.8 };
                }
                this.warnings.push('No reliable doorway was found.');
                return null;
            }

            let best = null;
            const floorBox = bounds(this.floor);
            if (this.placementMode === 'wall') best = this.fitWall(dims, naturalAspect);
            if (!best && floorBox && this.floor.length >= 3) {
                for (let factor = 1; factor >= 0.6 && !best; factor = Math.round((factor - 0.05) * 100) / 100) {
                    for (let y = Math.max(floorBox.minY + 0.04, this.horizon + 0.04, this.floorDetected === false ? 0.82 : 0); y <= floorBox.maxY; y += 0.035) {
                        for (let x = floorBox.minX + 0.04; x <= floorBox.maxX - 0.04; x += 0.04) {
                            const candidate = this.candidateAt(x, y, dims, naturalAspect, factor);
                            if (!candidate) continue;
                            if (!best || candidate.score > best.score) best = candidate;
                        }
                    }
                }
            }
            if (best) {
                if (best.scale < 0.995) this.warnings.push('Furniture was scaled down to fit the visible floor area.');
                return best;
            }
            this.warnings.push('No clear floor area passed the placement checks for this item.');
            return null;
        }

        candidateAt(x, y, dims, naturalAspect, scaleFactor) {
            if (!pointInPolygon(x, y, this.floor)) return null;
            const floorSpan = this.floorWidthAt(y);
            const configuredWidth = Number(this.frame.dataset.initialWidth) / 100;
            const visualWidthCap = Number.isFinite(configuredWidth) && configuredWidth > 0
                ? configuredWidth
                : floorSpan * 0.42;
            const frameAspect = this.room.naturalWidth / this.room.naturalHeight;
            const width = this.boundedVisualWidth(Math.min(visualWidthCap, floorSpan * 0.72, 0.82 * naturalAspect / frameAspect), naturalAspect) * scaleFactor;
            const height = width * frameAspect / naturalAspect;
            if (![width, height].every(Number.isFinite) || width <= 0 || height <= 0) return null;
            const depth = this.floorFootprintDepth(width);
            const rect = { left: x - width / 2, right: x + width / 2, top: y - height, bottom: y };
            if (rect.left < 0.02 || rect.right > 0.98 || rect.top < 0.02 || rect.bottom > 0.98) return null;
            const foot = [[rect.left, y], [x, y], [rect.right, y], [rect.left, y - depth], [x, y - depth], [rect.right, y - depth]];
            if (!polygonInsidePolygon(foot, this.floor)) return null;
            if (!this.placementFitsPhoto({mode:'floor', x, y, width, height, depth})) return null;
            const floor = bounds(this.floor);
            const nearBackWall = floor ? 1 - clamp(Math.abs(y - (floor.minY + (floor.maxY - floor.minY) * 0.48)) / 0.5, 0, 1) : 0.35;
            const centered = 1 - Math.abs(x - 0.5) * 1.2;
            const openArea = 1 - this.existing.length * 0.08;
            const doors = this.openings.filter((opening) => ['door', 'archway'].includes(String(opening.type || '').toLowerCase()) || /door|entry|entrance/i.test(String(opening.label || '')));
            const nearestDoor = doors.reduce((distance, opening) => {
                const openingBox = this.openingRect(opening);
                if (!openingBox) return distance;
                const pixels = Math.hypot((x - (openingBox.left + openingBox.right) / 2) * this.room.naturalWidth, (y - openingBox.bottom) * this.room.naturalHeight);
                return Math.min(distance, pixels);
            }, Infinity);
            // Preserve roughly 60–90 cm of door-side walking space when image scale is usable.
            const clearancePixels = this.estimateScale(y) * 75;
            const doorWalkway = Number.isFinite(nearestDoor) ? clamp(nearestDoor / Math.max(1, clearancePixels), 0, 1) : 0.75;
            return { mode: 'floor', x, y, width, height, depth, scale: scaleFactor, score: 0.36 * nearBackWall + 0.23 * centered + 0.16 * openArea + 0.25 * doorWalkway };
        }

        fitWall(dims, aspect) {
            const walls = [...(this.vision?.walls || []), ...(this.analysis.wall_regions || [])].map((wall) => polygon(wall.polygon)).filter((shape) => shape.length >= 3);
            const wall = walls.sort((a, b) => polygonArea(b) - polygonArea(a))[0];
            if (!wall) return null;
            const box = bounds(wall);
            const x = (box.minX + box.maxX) / 2;
            const y = (box.minY + box.maxY) / 2;
            const width = (box.maxX - box.minX) * 0.7;
            const height = width * (this.room.naturalWidth / this.room.naturalHeight) / aspect;
            const rect = { left: x - width / 2, right: x + width / 2, top: y - height / 2, bottom: y + height / 2 };
            const corners = [[rect.left, rect.top], [rect.right, rect.top], [rect.right, rect.bottom], [rect.left, rect.bottom]];
            if (!corners.every(([cx, cy]) => pointInPolygon(cx, cy, wall))) return null;
            if (this.blocked.some((shape) => polygonIntersectsRect(shape, rect))) return null;
            return { mode: 'wall', x, y: rect.bottom, width, height, scale: 1, score: 0.72 };
        }

        drawRoomOnly() {
            const size = this.canvasSize();
            if (size) this.context.drawImage(this.room, 0, 0, size.width, size.height);
        }

        canvasSize() {
            if (!this.room?.naturalWidth || !this.room?.naturalHeight) return null;
            const ratio = Math.min(1, 1600 / Math.max(this.room.naturalWidth, this.room.naturalHeight));
            const width = Math.max(1, Math.round(this.room.naturalWidth * ratio));
            const height = Math.max(1, Math.round(this.room.naturalHeight * ratio));
            this.canvas.width = width;
            this.canvas.height = height;
            this.canvas.style.aspectRatio = `${width} / ${height}`;
            return { width, height };
        }

        draw(placement, dims) {
            const size = this.canvasSize();
            if (!size) return;
            const ctx = this.context;
            const { width, height } = size;
            ctx.clearRect(0, 0, width, height);
            ctx.drawImage(this.room, 0, 0, width, height);
            if (!this.dimensionsFromCatalog()) return;
            ctx.save();
            const lighting = this.vision?.lighting || (typeof this.analysis.lighting === 'object' ? this.analysis.lighting : {});
            const brightness = clamp(Number(lighting.brightness ?? 0.55), 0, 1);
            const x = placement.x * width;
            const y = placement.y * height;
            const itemWidth = placement.width * width;
            const itemHeight = placement.height * height;
            ctx.globalAlpha = 0.12 + brightness * 0.13;
            ctx.fillStyle = '#171a18';
            ctx.filter = 'blur(12px)';
            ctx.beginPath();
            ctx.ellipse(x, y - Math.max(2, itemHeight * 0.015), Math.max(3, itemWidth * 0.34), Math.max(2, itemHeight * 0.035), 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
            if (placement.mode === 'door' && placement.quad?.length === 4) {
                this.drawQuad(ctx, this.productRenderImage || this.item, placement.quad.map(([qx, qy]) => [qx * width, qy * height]), this.productImageBounds);
            } else {
                const flip = placement.flip === true ? -1 : 1;
                ctx.save();
                ctx.globalAlpha = 0.94;
                ctx.filter = `brightness(${clamp(0.92 + brightness * 0.13, 0.85, 1.08)}) saturate(${clamp(0.88 + Number(lighting.warmth || 0.45) * 0.18, 0.8, 1.1)})`;
                ctx.shadowColor = 'rgba(20, 24, 21, .24)';
                ctx.shadowBlur = Math.max(2, itemWidth * 0.025);
                ctx.shadowOffsetY = Math.max(1, itemHeight * 0.012);
                ctx.translate(x, y - itemHeight / 2);
                ctx.rotate((Number(placement.rotationDeg) || 0) * Math.PI / 180);
                const perspective = Math.tan((Number(placement.perspectiveDeg) || 0) * Math.PI / 180) * 0.35;
                ctx.transform(1, 0, perspective, 1, 0, 0);
                ctx.scale(flip, 1);
                const crop = this.productImageBounds || { x: 0, y: 0, width: this.item.naturalWidth, height: this.item.naturalHeight };
                ctx.drawImage(this.productRenderImage || this.item, crop.x, crop.y, crop.width, crop.height, -itemWidth / 2, -itemHeight / 2, itemWidth, itemHeight);
                ctx.restore();
            }
        }

        drawQuad(ctx, image, quad, crop = null) {
            crop = crop || { x: 0, y: 0, width: image.naturalWidth || image.width, height: image.naturalHeight || image.height };
            const [tl, tr, br, bl] = quad;
            const project = (u, v) => [
                (1-u)*(1-v)*tl[0] + u*(1-v)*tr[0] + u*v*br[0] + (1-u)*v*bl[0],
                (1-u)*(1-v)*tl[1] + u*(1-v)*tr[1] + u*v*br[1] + (1-u)*v*bl[1],
            ];
            const triangle = (source, target) => {
                const [[sx0,sy0],[sx1,sy1],[sx2,sy2]] = source;
                const [[dx0,dy0],[dx1,dy1],[dx2,dy2]] = target;
                const den = sx0*(sy1-sy2)+sx1*(sy2-sy0)+sx2*(sy0-sy1);
                if (Math.abs(den) < 1e-6) return;
                const a=(dx0*(sy1-sy2)+dx1*(sy2-sy0)+dx2*(sy0-sy1))/den;
                const b=(dy0*(sy1-sy2)+dy1*(sy2-sy0)+dy2*(sy0-sy1))/den;
                const c=(dx0*(sx2-sx1)+dx1*(sx0-sx2)+dx2*(sx1-sx0))/den;
                const d=(dy0*(sx2-sx1)+dy1*(sx0-sx2)+dy2*(sx1-sx0))/den;
                const e=(dx0*(sx1*sy2-sx2*sy1)+dx1*(sx2*sy0-sx0*sy2)+dx2*(sx0*sy1-sx1*sy0))/den;
                const f=(dy0*(sx1*sy2-sx2*sy1)+dy1*(sx2*sy0-sx0*sy2)+dy2*(sx0*sy1-sx1*sy0))/den;
                ctx.save(); ctx.beginPath(); ctx.moveTo(dx0,dy0); ctx.lineTo(dx1,dy1); ctx.lineTo(dx2,dy2); ctx.closePath(); ctx.clip();
                ctx.transform(a,b,c,d,e,f); ctx.drawImage(image,0,0); ctx.restore();
            };
            const cols = 10, rows = 18;
            for (let r=0;r<rows;r++) for (let c=0;c<cols;c++) {
                const u0=c/cols,u1=(c+1)/cols,v0=r/rows,v1=(r+1)/rows;
                const src=[[crop.x+u0*crop.width,crop.y+v0*crop.height],[crop.x+u1*crop.width,crop.y+v0*crop.height],[crop.x+u1*crop.width,crop.y+v1*crop.height],[crop.x+u0*crop.width,crop.y+v1*crop.height]];
                const dst=[project(u0,v0),project(u1,v0),project(u1,v1),project(u0,v1)];
                triangle([src[0],src[1],src[2]],[dst[0],dst[1],dst[2]]);
                triangle([src[0],src[2],src[3]],[dst[0],dst[2],dst[3]]);
            }
        }

        async generateAiPreview(runId, retry = false) {
            const endpoint = this.frame.dataset.previewV2Url;
            const productId = Number(this.frame.dataset.productId || 0);
            if (!endpoint || !productId) return false;

            this.setProgress('Preparing the enhanced AI room image', false);
            try {
                const response = await fetch(endpoint, {
                    signal: AbortSignal.timeout(15000), method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.frame.dataset.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ furniture_id: productId, retry }),
                });
                if (runId !== this.runId) return false;
                if (!response.ok) {
                    this.warnings.push('Optional AI enhancement was unavailable; the automatic 2D placement is retained.');
                    this.report(this.result);
                    return false;
                }
                let payload = await response.json();
                const deadline = Date.now() + 500000;
                while (['queued', 'processing', 'retrying'].includes(payload.status) && Date.now() < deadline) {
                    if (runId !== this.runId) return false;
                    this.setProgress(payload.status === 'queued' ? 'Queuing the enhanced room image' : 'Preparing the enhanced room image', false);
                    await new Promise((resolve) => window.setTimeout(resolve, 2500));
                    const statusResponse = await fetch(payload.status_url, { signal: AbortSignal.timeout(10000), credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    if (runId !== this.runId) return false;
                    if (!statusResponse.ok) throw new Error('enhancement status unavailable');
                    payload = await statusResponse.json();
                }
                if (runId !== this.runId) return false;
                // Retain the immediate browser composition when enhancement fails.
                // A fallback artifact can use different placement geometry from the displayed 2D result.
                if (payload.status !== 'completed' || !payload.preview_url) {
                    this.warnings.push(payload.message || 'Optional AI enhancement is still unavailable; the automatic 2D placement is retained.');
                    this.report(this.result);
                    return false;
                }

                const generated = new Image();
                generated.src = payload.preview_url;
                await generated.decode();
                if (runId !== this.runId) return false;
                const { width, height } = this.canvas;
                if (!(width > 0 && height > 0 && generated.naturalWidth > 0 && generated.naturalHeight > 0)) return false;
                if (Math.abs(generated.naturalWidth / generated.naturalHeight - width / height) > 0.01) {
                    this.warnings.push('The enhanced image changed the room proportions; the automatic 2D placement is retained.');
                    this.report(this.result);
                    return false;
                }
                // Decode and prepare offscreen before replacing the usable local preview.
                const buffer = document.createElement('canvas');
                buffer.width = width;
                buffer.height = height;
                const bufferContext = buffer.getContext('2d');
                bufferContext.drawImage(this.room, 0, 0, width, height);
                bufferContext.drawImage(generated, 0, 0, width, height);
                this.context.drawImage(buffer, 0, 0, width, height);
                this.result = {
                    mode: 'generated-image', source: 'ai-generated', productId,
                    aiVisualCheckPassed: true, physicalFitConfirmed: false,
                    // These are instructions supplied to the generator, not measured output coordinates.
                    placementGuidance: payload.placement || null,
                };
                this.verificationScore = null;
                this.frame.dataset.finalPlacement = JSON.stringify(this.result);
                this.report(this.result);
                return true;
            } catch (_) {
                if (runId === this.runId) {
                    this.warnings.push('Optional AI enhancement could not be loaded; the automatic 2D placement is retained.');
                    this.report(this.result);
                }
                return false;
            }
        }
        async verifyLoop(dims, runId = this.runId) {
            let best = { ...this.result };
            let bestScore = -1;
            for (let attempt = 0; attempt < 3; attempt += 1) {
                if (runId !== this.runId) return;
                this.setProgress(`Checking the visual fit (${attempt + 1}/3)`, false);
                try {
                    const preview = this.canvas.toDataURL('image/jpeg', 0.78).split(',')[1];
                    const response = await fetch(this.frame.dataset.verifyUrl, {
                        signal: AbortSignal.timeout(35000), method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.frame.dataset.csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ preview, placement: this.result, product_id: this.frame.dataset.productId }),
                    });
                    if (!response.ok) throw new Error('verification unavailable');
                    const check = await response.json();
                    if (runId !== this.runId) return;
                    if (check.valid !== true) break;
                    const score = clamp(Number(check.realism_score || 0), 0, 1);
                    if (score > bestScore) { best = { ...this.result }; bestScore = score; }
                    if (check.verdict === 'good' && score >= 0.75) break;
                    const correction = check.corrections || {};
                    const correctedScale = clamp(this.result.scale * clamp(Number(correction.scale_factor || 1), 0.6, 1.4), 0.6, 1.3);
                    const candidate = { ...this.result, x: clamp(this.result.x + Number(correction.dx || 0), 0.02, 0.98), y: clamp(this.result.y + Number(correction.dy || 0), 0.02, 0.98), scale: correctedScale, width: this.result.width * correctedScale / Math.max(0.001, this.result.scale), height: this.result.height * correctedScale / Math.max(0.001, this.result.scale), depth: this.result.depth * correctedScale / Math.max(0.001, this.result.scale) };
                    if (typeof correction.flip === 'boolean') candidate.flip = correction.flip;
                    if (candidate.mode === 'door' && candidate.quad?.length === 4) {
                        const center = candidate.quad.reduce((sum, point) => [sum[0] + point[0] / 4, sum[1] + point[1] / 4], [0, 0]);
                        const factor = clamp(Number(correction.scale_factor || 1), 0.6, 1.4);
                        candidate.quad = candidate.quad.map(([qx, qy]) => [center[0] + (qx - center[0]) * factor + Number(correction.dx || 0), center[1] + (qy - center[1]) * factor + Number(correction.dy || 0)]);
                    }
                    if (!this.hardValid(candidate, dims)) break;
                    this.result = candidate;
                    this.draw(candidate, dims);
                } catch (_) {
                    this.warnings.push('Automatic visual checking was unavailable; the safe local fit is shown.');
                    break;
                }
            }
            this.result = best;
            this.draw(best, dims);
            this.verificationScore = bestScore >= 0 ? clamp(bestScore, 0, 1) : null;
            this.report(best);
        }

        hardValid(place, dims) {
            if (place.mode === 'door') return Boolean(place.quad?.length === 4 && place.quad.flat().every((v) => v >= 0.02 && v <= 0.98));
            return Boolean(dims.width && dims.depth && dims.height) && this.placementFitsPhoto(place);
        }

        report(place) {
            if (place?.mode === 'generated-image') {
                this.setStatus('AI visual preview ready', 'low');
                if (this.guidance) this.guidance.textContent = 'The preview was generated from your room photo and the selected catalog item and passed the AI visual check. Its apparent size and placement remain a visual estimate; physical fit is not confirmed.';
                if (this.detections) this.detections.innerHTML = '<span class="preview-badge is-rough">AI visual preview</span><span class="preview-badge">AI visual check passed</span>';
                return;
            }
            const confidence = this.placementConfidence >= 0.75 ? 'High confidence' : this.placementConfidence >= 0.5 ? 'Medium confidence' : 'Rough estimate';
            this.setStatus(confidence, confidence.startsWith('Rough') ? 'low' : 'good');
            const area = place.mode === 'door'
                ? 'the detected doorway'
                : place.mode === 'wall'
                    ? 'the clearest compatible wall area'
                    : `${place.x < 0.38 ? 'the left' : place.x > 0.62 ? 'the right' : 'the center'} clear floor area`;
            if (this.guidance) {
                const reason = this.warnings.length ? `${this.warnings.join(' ')} ` : place.reason ? `${place.reason} ` : '';
                this.guidance.textContent = `${reason}Automatically placed in ${area}; no manual positioning is needed. This is a 2D visual estimate; physical fit is not confirmed.`;
            }
            if (this.detections) {
                const badges = [];
                badges.push(this.frame.dataset.consented === 'true' && this.analysis?.gemini_used === true
                    ? '<span class="preview-badge">AI-assisted 2D placement</span>'
                    : '<span class="preview-badge is-rough">Local 2D estimate</span>');
                if (this.floorDetected) badges.push('<span class="preview-badge">Floor detected</span>');
                const door = this.openingForDoor();
                if (door) badges.push('<span class="preview-badge">Door detected</span>');
                if (this.openings.some((item) => /window/i.test(`${item.type || ''} ${item.label || ''}`))) badges.push('<span class="preview-badge">Window detected</span>');
                badges.push(`<span class="preview-badge ${confidence.startsWith('Rough') ? 'is-rough' : ''}">${confidence}</span>`);
                if (Number.isFinite(this.verificationScore)) badges.push(`<span class="preview-badge">AI visual score · ${Math.round(this.verificationScore * 100)}%</span>`);
                this.detections.innerHTML = badges.join(' ');
            }
        }

        downloadPng() {
            this.canvas.toBlob((blob) => {
                if (!blob) return;
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'furnicraft-room-preview.png';
                link.click();
                URL.revokeObjectURL(url);
            }, 'image/png');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-room-preview]').forEach((frame) => {
            const engine = new RoomPreviewEngine(frame);
            engine.start();
            frame.roomPreviewEngine = engine;
        });
        document.addEventListener('click', async (event) => {
            const retryButton = event.target.closest('[data-preview-retry]');
            if (retryButton) {
                const engine = document.querySelector('[data-room-preview]')?.roomPreviewEngine;
                if (!engine || retryButton.disabled) return;
                retryButton.disabled = true;
                engine.retryAiPreview = true;
                try { await engine.start(); } finally { retryButton.disabled = false; }
                return;
            }
            const button = event.target.closest('[data-recommendation-preview]');
            if (!button) return;
            const frame = document.querySelector('[data-room-preview]');
            if (!frame?.roomPreviewEngine) return;
            const bounds = frame.getBoundingClientRect();
            if (bounds.top < 0 || bounds.bottom > window.innerHeight) frame.scrollIntoView({ behavior: 'smooth', block: 'center' });
            frame.roomPreviewEngine.selectProduct(button);
        });
    });
    window.RoomPreviewEngine = RoomPreviewEngine;
    window.RoomPreviewEngineTest = { pointInPolygon, polygonArea, bounds, rectanglesOverlap, polygonIntersectsRect, polygonInsidePolygon, weightedMedian, imageContentBounds, confidenceScore, detectedFloorPolygon };
})();
