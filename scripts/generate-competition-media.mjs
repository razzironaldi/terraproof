import { chromium } from '@playwright/test';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT_DIR = path.resolve(__dirname, '..');
const DELIVERABLES_DIR = path.join(ROOT_DIR, 'competition-deliverables');
const SCREENSHOTS_DIR = path.join(DELIVERABLES_DIR, 'screenshots');
const TEMP_VIDEO_DIR = path.join(DELIVERABLES_DIR, '.tmp_video');

fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
fs.mkdirSync(TEMP_VIDEO_DIR, { recursive: true });

const PORT = 8765;
const BASE_URL = `http://127.0.0.1:${PORT}`;

async function waitForServer(url, timeoutMs = 20000) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        try {
            const res = await fetch(url);
            if (res.ok) return true;
        } catch {}
        await new Promise(r => setTimeout(r, 400));
    }
    throw new Error(`Server at ${url} did not become ready within ${timeoutMs}ms`);
}

async function launchBrowserWithFallback() {
    const strategies = [
        { name: 'Playwright Bundled Chromium', opts: { headless: true } },
        { name: 'System Google Chrome', opts: { headless: true, channel: 'chrome' } },
        { name: 'System Microsoft Edge', opts: { headless: true, channel: 'msedge' } },
    ];

    for (const strat of strategies) {
        try {
            const browser = await chromium.launch(strat.opts);
            console.log(`[OK] Launched headless browser via: ${strat.name}`);
            return browser;
        } catch (err) {
            console.log(`[INFO] Strategy (${strat.name}) unavailable, trying next...`);
        }
    }
    throw new Error('Could not launch any Chromium/Chrome/Edge browser instance.');
}

async function main() {
    console.log('================================================================================');
    console.log(' TERRAPROOF AUTOMATED MEDIA, SCREENSHOT, PDF & 1080P VIDEO FACTORY');
    console.log('================================================================================');

    // 1. Ensure fresh seeded database before recording
    console.log('[1/5] Booting Laravel server on ' + BASE_URL + '...');
    const serverProc = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${PORT}`], {
        cwd: ROOT_DIR,
        stdio: 'ignore',
        windowsHide: true,
    });

    let browser = null;
    try {
        await waitForServer(BASE_URL);
        console.log('[OK] Laravel server is live at ' + BASE_URL);

        browser = await launchBrowserWithFallback();

        // ---------------------------------------------------------------------
        // 2. CAPTURE 6 HIGH-RES 1920x1080 SCREENSHOTS & RECORD 1080P DEMO VIDEO
        // ---------------------------------------------------------------------
        console.log('[2/5] Recording 1080p interactive walkthrough & capturing 6 screenshots...');
        const consoleErrors = [];
        const pageErrors = [];

        const context = await browser.newContext({
            viewport: { width: 1920, height: 1080 },
            deviceScaleFactor: 1,
            recordVideo: {
                dir: TEMP_VIDEO_DIR,
                size: { width: 1920, height: 1080 },
            },
        });

        const page = await context.newPage();
        page.on('console', msg => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });
        page.on('pageerror', err => {
            pageErrors.push(err.message);
        });

        await page.goto(BASE_URL, { waitUntil: 'networkidle', timeout: 30000 });
        await page.waitForTimeout(1800);

        // Screenshot 1: Tactical Satellite Auditor Overview
        const shot1 = path.join(SCREENSHOTS_DIR, '01-tactical-satellite-auditor-overview.png');
        await page.screenshot({ path: shot1, fullPage: false });
        console.log('  -> Saved ' + path.basename(shot1));
        await page.waitForTimeout(700);

        //Briefly open & close the Why TerraProof Wins / SDG Matrix modal for the 1080p video walkthrough
        await page.click('#btn-open-novelty-modal');
        await page.waitForTimeout(900);
        await page.click('#btn-close-novelty-modal');
        await page.waitForTimeout(400);

        // Screenshot 2: Canopy GPS Multipath Spike Sanitizer Live
        await page.click('#btn-simulate-spike');
        await page.waitForTimeout(2400);
        const shot2 = path.join(SCREENSHOTS_DIR, '02-gps-multipath-spike-sanitizer-live.png');
        await page.screenshot({ path: shot2, fullPage: false });
        console.log('  -> Saved ' + path.basename(shot2));
        await page.waitForTimeout(800);

        // Screenshot 3: TNBBS Forest Encroachment Intersection Lock
        await page.click('#btn-simulate-encroachment');
        await page.waitForTimeout(2400);
        const shot3 = path.join(SCREENSHOTS_DIR, '03-tnbbs-deforestation-intersection-lock.png');
        await page.screenshot({ path: shot3, fullPage: false });
        console.log('  -> Saved ' + path.basename(shot3));
        await page.waitForTimeout(800);

        // Screenshot 4: Biological Yield-Ceiling ($Y_max$) & Anti-Laundering Breach
        await page.click('#tab-yield-gatekeeper');
        await page.waitForTimeout(700);
        await page.click('#btn-preset-legit');
        await page.waitForTimeout(800);
        await page.click('#btn-preset-laundering');
        await page.waitForTimeout(1100);
        const shot4 = path.join(SCREENSHOTS_DIR, '04-biological-yield-ceiling-anti-laundering-breach.png');
        await page.screenshot({ path: shot4, fullPage: false });
        console.log('  -> Saved ' + path.basename(shot4));
        await page.waitForTimeout(700);

        // Exercise SHA-256 Tamper Simulation & Restoration + Verify Ledger Chain
        await page.click('#btn-simulate-tamper');
        await page.waitForTimeout(900);
        await page.click('#btn-restore-tamper');
        await page.waitForTimeout(700);
        await page.click('#btn-verify-chain-inline');
        await page.waitForTimeout(700);

        // Screenshot 5: EUDR Traces-NT GeoJSON & Binary Merkle Root
        await page.click('#tab-traces-compiler');
        await page.waitForTimeout(1000);
        const shot5 = path.join(SCREENSHOTS_DIR, '05-eudr-traces-nt-geojson-and-merkle-passport.png');
        await page.screenshot({ path: shot5, fullPage: false });
        console.log('  -> Saved ' + path.basename(shot5));
        await page.waitForTimeout(800);

        // Activate Offline Blank-Spot Queue Mode, sync queue, then open Printable DDS Certificate for Screenshot 6
        await page.click('#btn-toggle-offline-mode');
        await page.waitForTimeout(600);
        await page.click('#btn-sync-offline-queue');
        await page.waitForTimeout(800);
        await page.goto(`${BASE_URL}/containers/EUDR-DDS-2026-001/passport`, { waitUntil: 'networkidle', timeout: 30000 });
        await page.waitForTimeout(1200);
        const shot6 = path.join(SCREENSHOTS_DIR, '06-offline-canopy-queue-and-dds-certificate.png');
        await page.screenshot({ path: shot6, fullPage: false });
        console.log('  -> Saved ' + path.basename(shot6));
        await page.waitForTimeout(800);

        // Verify 1366x768 laptop viewport renders with 0 errors
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto(BASE_URL, { waitUntil: 'networkidle', timeout: 30000 });
        await page.waitForTimeout(600);

        const videoObj = page.video();
        await page.close();
        await context.close();

        console.log(`  -> Browser Console Errors: ${consoleErrors.length} | Page Runtime Errors: ${pageErrors.length}`);
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.error('Console/Page Errors detected:', { consoleErrors, pageErrors });
            process.exit(1);
        }

        if (videoObj) {
            const recordedPath = await videoObj.path();
            const targetVideoPath = path.join(DELIVERABLES_DIR, 'demo-walkthrough-1080p.webm');
            if (fs.existsSync(recordedPath)) {
                fs.copyFileSync(recordedPath, targetVideoPath);
                console.log('[OK] Saved 1080p walkthrough video: competition-deliverables/demo-walkthrough-1080p.webm');
            }
        }
        fs.rmSync(TEMP_VIDEO_DIR, { recursive: true, force: true });

        // ---------------------------------------------------------------------
        // 3. RENDER A3 PORTRAIT INNOVATION POSTER (.PDF & .PNG)
        // ---------------------------------------------------------------------
        console.log('[3/5] Rendering A3 Portrait Innovation Poster (PDF + PNG)...');
        const posterContext = await browser.newContext({
            viewport: { width: 1122, height: 1587 },
            deviceScaleFactor: 2,
        });
        const posterPage = await posterContext.newPage();
        const posterHtmlUrl = pathToFileURL(path.join(DELIVERABLES_DIR, '02-INNOVATION-POSTER-A3.html')).href;
        await posterPage.goto(posterHtmlUrl, { waitUntil: 'networkidle', timeout: 30000 });
        await posterPage.waitForTimeout(800);

        const posterPngPath = path.join(DELIVERABLES_DIR, '02-INNOVATION-POSTER-A3.png');
        const posterPdfPath = path.join(DELIVERABLES_DIR, '02-INNOVATION-POSTER-A3.pdf');
        await posterPage.screenshot({ path: posterPngPath, fullPage: true });
        await posterPage.pdf({
            path: posterPdfPath,
            width: '297mm',
            height: '420mm',
            printBackground: true,
            margin: { top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' },
        });
        await posterContext.close();
        console.log('  -> Saved 02-INNOVATION-POSTER-A3.png & 02-INNOVATION-POSTER-A3.pdf');

        // ---------------------------------------------------------------------
        // 4. RENDER 15-SLIDE 16:9 PITCH DECK (.PDF)
        // ---------------------------------------------------------------------
        console.log('[4/5] Rendering 15-Slide 16:9 Pitch Deck PDF...');
        const deckContext = await browser.newContext({
            viewport: { width: 1280, height: 720 },
            deviceScaleFactor: 2,
        });
        const deckPage = await deckContext.newPage();
        const deckHtmlUrl = pathToFileURL(path.join(DELIVERABLES_DIR, '04-PITCH-DECK-SLIDES.html')).href;
        await deckPage.goto(deckHtmlUrl, { waitUntil: 'networkidle', timeout: 30000 });
        await deckPage.waitForTimeout(800);

        const deckPdfPath = path.join(DELIVERABLES_DIR, '04-PITCH-DECK-SLIDES.pdf');
        await deckPage.pdf({
            path: deckPdfPath,
            width: '1280px',
            height: '720px',
            printBackground: true,
            margin: { top: '0px', right: '0px', bottom: '0px', left: '0px' },
        });
        await deckContext.close();
        console.log('  -> Saved 04-PITCH-DECK-SLIDES.pdf');

        console.log('[5/5] All competition visual, PDF, and video assets generated successfully!');
    } finally {
        if (browser) {
            await browser.close();
        }
        serverProc.kill();
    }
}

main().catch(err => {
    console.error('[ERROR] Media generation failed:', err);
    process.exit(1);
});
