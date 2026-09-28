/**
 * FocusStack Camera Controller v3.1
 * + Pinch-to-zoom (native veya dijital fallback)
 * + EXACT output resolution (cover-crop + high-quality resample)
 * + ImageCapture resolution attempt
 */
(function(global){
'use strict';

class CameraController {
    constructor() {
        this.stream = null;
        this.videoEl = null;
        this.track = null;
        this.capabilities = {};
        this.supported = {};
        this.settings = {};
        this.imageCapture = null;
        this.state = 'idle';
        this.lastError = null;
        this._canvas = document.createElement('canvas');
        this._abortStack = false;

        // Dijital zoom (native zoom yoksa)
        this._digitalZoom = 1.0;
        this._nativeZoom = null;   // number veya null
    }

    // ============================================================
    // INIT
    // ============================================================
    async init(videoEl, opts = {}) {
        if (!videoEl) throw new Error('videoEl zorunlu');
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Bu tarayıcı kamera API desteklemiyor.');
        }
        if (!window.isSecureContext) {
            throw new Error('Kamera için HTTPS gerekir.');
        }

        this.videoEl = videoEl;
        this.state = 'initializing';

        const facingMode = opts.facingMode || 'environment';
        const width  = opts.width  || 4032;
        const height = opts.height || 3024;

        const attempts = [
            { facingMode: { ideal: facingMode }, width: { ideal: width }, height: { ideal: height } },
            { facingMode: { ideal: facingMode }, width: { ideal: 3840 },  height: { ideal: 2160 } },
            { facingMode: { ideal: facingMode }, width: { ideal: 1920 },  height: { ideal: 1080 } },
            { facingMode: { ideal: facingMode }, width: { ideal: 1280 },  height: { ideal: 720 } },
            { facingMode: { ideal: facingMode } },
            { facingMode: 'environment' },
        ];

        let lastErr = null;
        this.stream = null;
        for (const video of attempts) {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: false, video });
                break;
            } catch (e) {
                lastErr = e;
                console.warn('[camera] gUM attempt failed', video, e.name || e.message);
            }
        }
        if (!this.stream) {
            const name = lastErr ? (lastErr.name || '') : '';
            let msg = 'Kamera açılamadı.';
            if (name === 'NotAllowedError') {
                msg = 'Kamera izni verilmedi. Tarayıcı adres çubuğundaki kilit simgesinden kamera iznini açın ve sayfayı yenileyin.';
            } else if (name === 'NotFoundError') {
                msg = 'Kamera bulunamadı.';
            } else if (name === 'NotReadableError') {
                msg = 'Kamera başka bir uygulama tarafından kullanılıyor.';
            } else if (name === 'OverconstrainedError') {
                msg = 'Bu cihaz istenen kamera özelliklerini desteklemiyor.';
            }
            const err = new Error(msg);
            err.name = name;
            throw err;
        }

        this.videoEl.srcObject = this.stream;
        this.videoEl.setAttribute('playsinline', '');
        this.videoEl.setAttribute('autoplay', '');
        this.videoEl.muted = true;
        await this.videoEl.play().catch(() => {});
        await this._waitForVideo();

        this.track = this.stream.getVideoTracks()[0];
        this.supported = navigator.mediaDevices.getSupportedConstraints
            ? navigator.mediaDevices.getSupportedConstraints() : {};

        try {
            this.capabilities = this.track.getCapabilities ? this.track.getCapabilities() : {};
        } catch (e) {
            this.capabilities = {};
        }
        try {
            this.settings = this.track.getSettings ? this.track.getSettings() : {};
        } catch (_) { this.settings = {}; }

        await this._tryBumpResolution();

        if ('ImageCapture' in window && this.track) {
            try { this.imageCapture = new ImageCapture(this.track); }
            catch (_) { this.imageCapture = null; }
        }

        this.state = 'ready';
        console.log('[camera] capabilities', this.capabilities);
        console.log('[camera] settings', this.settings);
        return this.getCapabilityReport();
    }

    async _tryBumpResolution() {
        if (!this.track || !this.capabilities.width) return;
        const curW = this.videoEl.videoWidth;
        const maxW = this.capabilities.width.max;
        if (maxW && maxW > curW) {
            try {
                await this.track.applyConstraints({
                    width: { ideal: maxW },
                    height: { ideal: Math.round(maxW * this.videoEl.videoHeight / this.videoEl.videoWidth) }
                });
                await this.sleep(200);
                console.log('[camera] resolution bumped to', this.videoEl.videoWidth, '×', this.videoEl.videoHeight);
            } catch (e) {
                console.warn('[camera] resolution bump failed', e);
            }
        }
    }

    async _waitForVideo(timeout = 6000) {
        const start = performance.now();
        while (!this.videoEl.videoWidth && (performance.now() - start) < timeout) {
            await this.sleep(50);
        }
        if (!this.videoEl.videoWidth) throw new Error('Kamera görüntüsü hazırlanamadı.');
    }

    // ============================================================
    // CAPABILITIES
    // ============================================================
    getCapabilityReport() {
        const caps = this.capabilities || {};
        const sup = this.supported || {};
        const fd = caps.focusDistance;
        const fdValid = !!sup.focusDistance && fd != null &&
                        Number.isFinite(fd.min) && Number.isFinite(fd.max) && fd.max > fd.min;

        const z = caps.zoom;
        const zValid = !!sup.zoom && z != null && Number.isFinite(z.min) && Number.isFinite(z.max) && z.max > z.min;

        return {
            focusMode: { supported: !!sup.focusMode, available: Array.isArray(caps.focusMode) ? caps.focusMode : [] },
            focusDistance: { supported: fdValid, min: fdValid ? fd.min : null, max: fdValid ? fd.max : null, step: fdValid && Number.isFinite(fd.step) ? fd.step : null },
            pointsOfInterest: { supported: !!sup.pointsOfInterest },
            zoom: {
                supported: zValid,
                native: zValid,
                min: zValid ? z.min : 1,
                max: zValid ? z.max : 5,
                step: zValid && Number.isFinite(z.step) ? z.step : 0.1,
                digitalZoomSupported: true,
                currentDigital: this._digitalZoom,
                currentNative: this._nativeZoom
            },
            torch: { supported: !!sup.torch, available: !!caps.torch },
            exposureMode: { supported: !!sup.exposureMode, available: Array.isArray(caps.exposureMode) ? caps.exposureMode : [] },
            whiteBalanceMode: { supported: !!sup.whiteBalanceMode, available: Array.isArray(caps.whiteBalanceMode) ? caps.whiteBalanceMode : [] },
            iso: { supported: !!sup.iso, min: caps.iso ? caps.iso.min : null, max: caps.iso ? caps.iso.max : null },
            width: caps.width || null,
            height: caps.height || null,
            deviceLabel: this.track ? this.track.label : null,
            currentSettings: this.track && this.track.getSettings ? this.track.getSettings() : {},
            actualResolution: {
                width: this.videoEl ? this.videoEl.videoWidth : 0,
                height: this.videoEl ? this.videoEl.videoHeight : 0,
            },
        };
    }

    detectBestMode() {
        const caps = this.capabilities || {};
        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('manual') && caps.focusDistance) return 'MANUAL_SWEEP';
        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('single-shot')) return 'AUTO_SINGLE';
        if (Array.isArray(caps.focusMode) && caps.focusMode.includes('continuous')) return 'AUTO_CONTINUOUS';
        return 'FALLBACK';
    }

    // ============================================================
    // CONSTRAINTS
    // ============================================================
    async applyConstraints(constraints) {
        if (!this.track) return false;
        try {
            await this.track.applyConstraints(constraints);
            await this.sleep(80);
            this._refreshSettings();
            return true;
        } catch (e) {
            console.warn('[camera] applyConstraints failed', e.name || '', e.message || '', constraints);
            if (e.name === 'OverconstrainedError') {
                const advanced = constraints && constraints.advanced;
                if (Array.isArray(advanced)) {
                    for (const c of advanced) {
                        if (c.focusDistance != null) this.capabilities.focusDistance = null;
                        if (c.focusMode != null) this.capabilities.focusMode = null;
                        if (c.zoom != null) this.capabilities.zoom = null;
                        if (c.torch != null) this.capabilities.torch = false;
                        if (c.pointsOfInterest != null) this.supported.pointsOfInterest = false;
                    }
                }
            }
            return false;
        }
    }

    _refreshSettings() {
        try { this.settings = this.track && this.track.getSettings ? this.track.getSettings() : {}; } catch (_) {}
    }

    async setFocusMode(mode) {
        if (!this.supported.focusMode) return false;
        return this.applyConstraints({ advanced: [{ focusMode: mode }] });
    }

    async setFocusDistance(value) {
        const fd = this.capabilities.focusDistance;
        if (!fd) return false;
        value = Number(value);
        if (!Number.isFinite(value)) return false;
        value = Math.max(fd.min, Math.min(fd.max, value));
        return this.applyConstraints({ advanced: [{ focusDistance: value }] });
    }

    async setPointsOfInterest(points) {
        if (!this.supported.pointsOfInterest) return false;
        return this.applyConstraints({ advanced: [{ pointsOfInterest: points }] });
    }

    async focusAt(x, y, opts = {}) {
        x = Math.max(0, Math.min(1, Number(x)));
        y = Math.max(0, Math.min(1, Number(y)));
        let result = false;

        if (opts.distance != null && this.capabilities.focusDistance) {
            const ok = await this.setFocusDistance(opts.distance).catch(() => false);
            if (ok) result = true;
        }
        if (this.supported.pointsOfInterest) {
            try {
                const ok = await this.setPointsOfInterest([{ x, y }]);
                if (ok) result = true;
            } catch (_) {}
        }
        if (this.capabilities.focusMode && Array.isArray(this.capabilities.focusMode)) {
            if (this.capabilities.focusMode.includes('single-shot')) {
                const ok = await this.setFocusMode('single-shot').catch(() => false);
                if (ok) result = true;
            } else if (this.capabilities.focusMode.includes('continuous')) {
                const ok = await this.setFocusMode('continuous').catch(() => false);
                if (ok) result = true;
            }
        }
        const settleMs = opts.settleMs == null ? 350 : Number(opts.settleMs);
        if (settleMs > 0) await this.sleep(settleMs);
        return result;
    }

    // ============================================================
    // ZOOM — native + dijital
    // ============================================================
    async setZoom(value) {
        value = Number(value);
        if (!Number.isFinite(value) || value <= 0) return false;
        value = Math.max(1, Math.min(8, value));

        const z = this.capabilities.zoom;
        const nativeOk = !!this.supported.zoom && z != null && z.max > z.min;

        if (nativeOk) {
            const nativeVal = Math.max(z.min, Math.min(z.max, value));
            const ok = await this.applyConstraints({ advanced: [{ zoom: nativeVal }] });
            if (ok) {
                this._nativeZoom = nativeVal;
                this._digitalZoom = 1.0;
                this._applyDigitalZoomCss();
                return true;
            }
        }

        this._digitalZoom = value;
        this._nativeZoom = null;
        this._applyDigitalZoomCss();
        return true;
    }

    getZoom() {
        return {
            digital: this._digitalZoom,
            native: this._nativeZoom,
            effective: this._digitalZoom * (this._nativeZoom || 1),
        };
    }

    _applyDigitalZoomCss() {
        if (!this.videoEl) return;
        const z = this._digitalZoom;
        if (z <= 1.001) {
            this.videoEl.style.transform = '';
            this.videoEl.style.transformOrigin = '';
        } else {
            this.videoEl.style.transform = `scale(${z})`;
            this.videoEl.style.transformOrigin = 'center center';
        }
    }

    async setTorch(on) {
        if (!this.supported.torch) return false;
        return this.applyConstraints({ advanced: [{ torch: !!on }] });
    }

    // ============================================================
    // PHOTO — EXACT output size destekli
    // ============================================================
    /**
     * @param {Object} opts
     * @param {number} [opts.outputWidth]   - Zorunlu tam genişlik (px)
     * @param {number} [opts.outputHeight]  - Zorunlu tam yükseklik (px)
     * @param {number} [opts.maxDim]        - outputWidth/Height verilmezse max boyut
     * @param {string} [opts.type='image/jpeg']
     * @param {number} [opts.quality=0.95]
     */
    async takePhoto(opts = {}) {
        if (this.state !== 'ready' || !this.track) {
            throw new Error('Kamera hazır değil.');
        }

        const vw = this.videoEl ? this.videoEl.videoWidth  : 0;
        const vh = this.videoEl ? this.videoEl.videoHeight : 0;
        if (!vw || !vh) throw new Error('Video hazır değil.');

        const hasExactSize = !!(opts.outputWidth && opts.outputHeight);
        const needResize = !!(opts.maxDim) && Math.max(vw, vh) > opts.maxDim;
        const hasDigitalZoom = this._digitalZoom > 1.001;

        // ImageCapture — sadece yeniden boyutlandırma / özel boyut GEREKMİYORSA
        if (!hasExactSize && !needResize && !hasDigitalZoom &&
            this.imageCapture && typeof this.imageCapture.takePhoto === 'function') {
            try {
                const settings = {};
                if (opts.width)  settings.imageWidth  = opts.width;
                if (opts.height) settings.imageHeight = opts.height;
                const blob = await this.imageCapture.takePhoto(settings);
                if (blob && blob.size > 0) {
                    if (!opts.type || blob.type === opts.type) return blob;
                }
            } catch (e) {
                console.warn('[camera] ImageCapture failed, canvas fallback', e);
            }
        }
        return this.captureCanvas(opts);
    }

    captureCanvas(opts = {}) {
        return new Promise((resolve, reject) => {
            const v = this.videoEl;
            if (!v || !v.videoWidth) {
                reject(new Error('Video hazır değil.'));
                return;
            }
            const type = opts.type || 'image/jpeg';
            const quality = typeof opts.quality === 'number' ? opts.quality : 0.95;

            const vw = v.videoWidth;
            const vh = v.videoHeight;

            // 1) Dijital zoom varsa kaynak merkezden kırpılır
            const dz = this._digitalZoom > 1.001 ? this._digitalZoom : 1.0;
            let srcW = Math.round(vw / dz);
            let srcH = Math.round(vh / dz);
            let srcX = Math.round((vw - srcW) / 2);
            let srcY = Math.round((vh - srcH) / 2);

            // 2) Hedef boyut
            let outW, outH;
            if (opts.outputWidth && opts.outputHeight) {
                outW = Math.round(opts.outputWidth);
                outH = Math.round(opts.outputHeight);
            } else {
                outW = srcW;
                outH = srcH;
                const maxDim = opts.maxDim || 6000;
                if (Math.max(outW, outH) > maxDim) {
                    const scale = maxDim / Math.max(outW, outH);
                    outW = Math.round(outW * scale);
                    outH = Math.round(outH * scale);
                }
            }

            // 3) Aspect-ratio farklıysa "cover" mantığıyla kaynak alanı kırp
            const srcAR = srcW / srcH;
            const dstAR = outW / outH;
            if (Math.abs(srcAR - dstAR) > 0.001) {
                if (srcAR > dstAR) {
                    // kaynak daha geniş → yatay (sol/sağ) kırp
                    const newW = Math.round(srcH * dstAR);
                    srcX += Math.round((srcW - newW) / 2);
                    srcW = newW;
                } else {
                    // kaynak daha uzun → dikey (üst/alt) kırp
                    const newH = Math.round(srcW / dstAR);
                    srcY += Math.round((srcH - newH) / 2);
                    srcH = newH;
                }
            }

            // 4) Canvas'a yüksek kaliteli resample ile çiz
            this._canvas.width = outW;
            this._canvas.height = outH;
            const ctx = this._canvas.getContext('2d', { alpha: false });
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(v, srcX, srcY, srcW, srcH, 0, 0, outW, outH);

            this._canvas.toBlob((blob) => {
                if (!blob) { reject(new Error('Fotoğraf oluşturulamadı.')); return; }
                resolve(blob);
            }, type, quality);
        });
    }

    capture(opts = {}) { return this.takePhoto(opts); }

    // ============================================================
    // UTIL
    // ============================================================
    sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
    getVideoSize() {
        if (!this.videoEl) return { width: 0, height: 0 };
        return { width: this.videoEl.videoWidth, height: this.videoEl.videoHeight };
    }
    stopFocusStack() { this._abortStack = true; }

    stop() {
        this._abortStack = true;
        if (this.stream) {
            this.stream.getTracks().forEach(t => { try { t.stop(); } catch (_) {} });
        }
        this.stream = null;
        if (this.videoEl) {
            this.videoEl.srcObject = null;
            this.videoEl.style.transform = '';
        }
        this.track = null;
        this.imageCapture = null;
        this.capabilities = {};
        this.supported = {};
        this.settings = {};
        this._digitalZoom = 1.0;
        this._nativeZoom = null;
        this.state = 'idle';
    }
}

// ================================================================
// SHARPNESS ANALYZER
// ================================================================
class SharpnessAnalyzer {
    static _canvas = null;
    static analyzeVideoRegion(video, options = {}) {
        if (!video || !video.videoWidth) return null;
        const x  = options.x == null ? 0.5 : Number(options.x);
        const y  = options.y == null ? 0.5 : Number(options.y);
        const rw = options.width  || 0.6;
        const rh = options.height || 0.6;
        const gridSize = options.gridSize || 6;
        if (!Number.isFinite(rw) || !Number.isFinite(rh) || rw <= 0 || rh <= 0) return null;
        const W = Math.min(1000, video.videoWidth);
        const H = Math.round(W * video.videoHeight / video.videoWidth);
        if (!this._canvas) this._canvas = document.createElement('canvas');
        const canvas = this._canvas;
        canvas.width = W; canvas.height = H;
        const ctx = canvas.getContext('2d', { alpha: false });
        ctx.drawImage(video, 0, 0, W, H);
        const cx = Math.round(x * W), cy = Math.round(y * H);
        const roiW = Math.round(rw * W), roiH = Math.round(rh * H);
        if (roiW < 1 || roiH < 1) return null;
        const sx = Math.max(0, cx - Math.floor(roiW / 2));
        const sy = Math.max(0, cy - Math.floor(roiH / 2));
        const ex = Math.min(W, sx + roiW), ey = Math.min(H, sy + roiH);
        const cropW = ex - sx, cropH = ey - sy;
        if (cropW < 1 || cropH < 1) return null;
        let image;
        try { image = ctx.getImageData(sx, sy, cropW, cropH); }
        catch (e) { return null; }
        return this.analyzeImageData(image, gridSize);
    }
    static analyzeImageData(imageData, gridSize = 6) {
        const { data, width, height } = imageData;
        const gray = new Float32Array(width * height);
        for (let i = 0, p = 0; i < data.length; i += 4, p++) {
            gray[p] = 0.299 * data[i] + 0.587 * data[i+1] + 0.114 * data[i+2];
        }
        const globalScore = this._lapVar(gray, width, 0, 0, width, height);
        const tiles = [];
        const tw = Math.max(1, Math.floor(width  / gridSize));
        const th = Math.max(1, Math.floor(height / gridSize));
        for (let gy = 0; gy < gridSize; gy++) {
            for (let gx = 0; gx < gridSize; gx++) {
                const x0 = gx * tw, y0 = gy * th;
                const x1 = gx === gridSize - 1 ? width  : x0 + tw;
                const y1 = gy === gridSize - 1 ? height : y0 + th;
                tiles.push(this._lapVar(gray, width, x0, y0, x1, y1));
            }
        }
        const sorted = [...tiles].sort((a, b) => b - a);
        const topN = Math.max(1, Math.floor(sorted.length * 0.35));
        const topMean = sorted.slice(0, topN).reduce((a, b) => a + b, 0) / topN;
        const score = globalScore * 0.45 + topMean * 0.55;
        return { score, global: globalScore,
            mean: tiles.reduce((a, b) => a + b, 0) / tiles.length,
            max: Math.max(...tiles), min: Math.min(...tiles),
            topMean, tiles, tilesPerSide: gridSize };
    }
    static _lapVar(gray, stride, x0, y0, x1, y1) {
        let sum = 0, sum2 = 0, n = 0;
        const yStart = Math.max(y0 + 1, 1), yEnd = y1 - 1;
        const xStart = Math.max(x0 + 1, 1), xEnd = x1 - 1;
        for (let y = yStart; y < yEnd; y++) {
            const row = y * stride;
            for (let x = xStart; x < xEnd; x++) {
                const idx = row + x;
                const lap = gray[idx - stride] + gray[idx + stride] + gray[idx - 1] + gray[idx + 1] - 4 * gray[idx];
                sum += lap; sum2 += lap * lap; n++;
            }
        }
        if (!n) return 0;
        const mean = sum / n;
        return (sum2 / n) - (mean * mean);
    }
}

global.CameraController = CameraController;
global.SharpnessAnalyzer = SharpnessAnalyzer;
})(window);