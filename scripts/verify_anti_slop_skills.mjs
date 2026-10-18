import { chromium } from 'playwright';
import path from 'path';
import fs from 'fs';

const outDir = 'C:\\Users\\UseR\\.gemini\\antigravity-cli\\brain\\d0200248-cb3c-44c2-a22b-daaa7a054fc3\\scratch';
fs.mkdirSync(outDir, { recursive: true });

(async () => {
    let browser;
    try {
        browser = await chromium.launch({ channel: 'msedge', headless: true });
    } catch {
        browser = await chromium.launch({ channel: 'chrome', headless: true });
    }
    const consoleErrors = [];
    const pageErrors = [];

    // 1. Desktop 1920x1080 Verification
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();
    page.on('console', msg => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', err => pageErrors.push(err.message));

    await page.goto('http://127.0.0.1:8000', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    // Check computed font family & focus-visible rule
    const desktopAudit = await page.evaluate(() => {
        const bodyFont = window.getComputedStyle(document.body).fontFamily;
        const borderL2Count = document.querySelectorAll('.border-l-2, .border-l-4').length;
        const ariaLiveCount = document.querySelectorAll('[aria-live="polite"]').length;
        const labeledInputs = Array.from(document.querySelectorAll('input, select')).every(
            el => el.id && (document.querySelector(`label[for="${el.id}"]`) || el.getAttribute('aria-label') || el.closest('label'))
        );
        return { bodyFont, borderL2Count, ariaLiveCount, labeledInputs };
    });

    await page.screenshot({ path: path.join(outDir, 'verify_desktop_spatial.png') });

    // Click parcel LP-PLT-011 (TNBBS Forest Encroachment)
    await page.click('#dir-btn-LP-PLT-011');
    await page.waitForTimeout(700);
    await page.screenshot({ path: path.join(outDir, 'verify_desktop_parcel_011.png') });

    // Switch to Yield Mass-Balance Workspace
    await page.click('#tab-yield-gatekeeper');
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(outDir, 'verify_desktop_yield.png') });

    // Switch to Traces-NT Compiler Workspace
    await page.click('#tab-traces-compiler');
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(outDir, 'verify_desktop_traces.png') });

    // Visit Passport View
    await page.goto('http://127.0.0.1:8000/containers/EUDR-DDS-2026-001/passport', { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(outDir, 'verify_desktop_passport.png') });

    // 2. Mobile 390x844 Verification
    const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobilePage = await mobileContext.newPage();
    mobilePage.on('console', msg => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    mobilePage.on('pageerror', err => pageErrors.push(err.message));

    await mobilePage.goto('http://127.0.0.1:8000', { waitUntil: 'networkidle' });
    await mobilePage.waitForTimeout(800);

    const mobileAudit = await mobilePage.evaluate(() => {
        return {
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            hasHorizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
        };
    });

    await mobilePage.screenshot({ path: path.join(outDir, 'verify_mobile_390px.png') });

    await browser.close();

    console.log(JSON.stringify({
        desktopAudit,
        mobileAudit,
        consoleErrors,
        pageErrors
    }, null, 2));
})();
