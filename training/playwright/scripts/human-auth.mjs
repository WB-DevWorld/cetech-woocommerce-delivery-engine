/**
 * Human-assisted WordPress admin auth for training screenshot capture.
 *
 * Opens installed Chrome (headed). You complete Cloudflare + login normally.
 * Script requires a real #wpadminbar, then saves gitignored auth/storage-state.json.
 * Never prints credentials.
 *
 * Usage:
 *   npm run training:auth
 */
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config as loadEnv } from 'dotenv';
import readline from 'node:readline';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '../../..');
loadEnv({ path: path.join(root, '.env.local') });

const adminUrl = (
	process.env.FLAIROC_WP_ADMIN_URL ||
	(process.env.FLAIROC_BASE_URL || 'https://flairoc.com/intl/').replace(/\/?$/, '/') + 'wp-admin/'
).replace(/\/?$/, '/');

const outPath = path.resolve(__dirname, '../auth/storage-state.json');
const MAX_WAIT_MS = Number(process.env.CETECH_DE_AUTH_WAIT_MS || 10 * 60 * 1000);
const POLL_MS = 3000;

async function isAuthenticatedWpAdmin(page) {
	// Strict: WordPress admin bar only — never treat Cloudflare/login pages as success.
	const bar = page.locator('#wpadminbar');
	if (!(await bar.isVisible().catch(() => false))) {
		return false;
	}
	const title = await page.title().catch(() => '');
	if (/just a moment|attention required|cloudflare|sorry, you have been blocked/i.test(title)) {
		return false;
	}
	return true;
}

function storageLooksAuthenticated(filePath) {
	try {
		const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
		const cookies = Array.isArray(data.cookies) ? data.cookies : [];
		// WordPress logged-in cookie name pattern.
		return cookies.some((c) => /wordpress_logged_in_/i.test(c.name || ''));
	} catch {
		return false;
	}
}

async function main() {
	console.log('');
	console.log('CETECH training — human-assisted auth');
	console.log('--------------------------------------');
	console.log('1. A Chrome window will open to WordPress admin.');
	console.log('2. Complete any Cloudflare challenge yourself.');
	console.log('3. Log in with your normal admin account if asked.');
	console.log('4. Wait until the black WordPress admin bar is visible at the top.');
	console.log('5. Optional: press Enter here after the admin bar appears.');
	console.log('');
	console.log('Session file (gitignored): auth/storage-state.json');
	console.log('Target:', adminUrl);
	console.log('');

	const browser = await chromium.launch({
		channel: 'chrome',
		headless: false,
		args: [
			'--start-maximized',
			'--new-window',
			'--disable-infobars',
		],
	});

	const context = await browser.newContext({
		viewport: null,
		locale: 'en-US',
		colorScheme: 'light',
	});
	const page = await context.newPage();

	// Make the window obvious for the human.
	try {
		const session = await context.newCDPSession(page);
		const { windowId } = await session.send('Browser.getWindowForTarget');
		await session.send('Browser.setWindowBounds', {
			windowId,
			bounds: { windowState: 'normal', width: 1440, height: 1000, left: 80, top: 40 },
		});
	} catch {
		/* CDP focus best-effort */
	}

	async function showOnScreenInstructions() {
		await page.evaluate(() => {
			const id = 'cetech-de-training-auth-banner';
			if (document.getElementById(id)) return;
			const el = document.createElement('div');
			el.id = id;
			el.setAttribute('role', 'status');
			el.style.cssText =
				'position:fixed;z-index:2147483647;left:12px;right:12px;top:12px;padding:14px 18px;' +
				'background:#111;color:#fff;font:600 16px/1.4 system-ui,sans-serif;border-radius:8px;' +
				'box-shadow:0 8px 24px rgba(0,0,0,.35)';
			el.innerHTML =
				'<div>CETECH training capture</div>' +
				'<div style="font-weight:400;margin-top:6px">1) Complete Cloudflare if shown &nbsp; 2) Log in to WordPress admin &nbsp; 3) Wait for the black admin bar — this window will save and close automatically. Do not close Chrome yourself.</div>';
			document.documentElement.appendChild(el);
		}).catch(() => undefined);
	}

	try {
		await page.goto(adminUrl, { waitUntil: 'domcontentloaded', timeout: 90_000 });
	} catch {
		console.log('Initial navigation timed out — continue Cloudflare/login in the open window.');
	}
	await showOnScreenInstructions();

	let enterPressed = false;
	const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
	rl.on('line', () => {
		enterPressed = true;
	});
	console.log('');
	console.log('>>> ACTION REQUIRED (Jane): complete Cloudflare + WordPress login in the Chrome window NOW.');
	console.log('>>> Leave the window open until it closes itself after the admin bar appears.');
	console.log(`Polling for #wpadminbar (up to ${Math.round(MAX_WAIT_MS / 60000)} min). Press Enter after login to finish early.`);

	const started = Date.now();
	let ready = false;
	while (Date.now() - started < MAX_WAIT_MS) {
		if (page.isClosed()) {
			rl.close();
			console.error('ERROR: Chrome window was closed before login finished. Auth state was NOT saved.');
			console.error('Re-run: npm run training:auth — keep the window open until it closes itself.');
			process.exit(1);
		}
		try {
			if (await isAuthenticatedWpAdmin(page)) {
				ready = true;
				break;
			}
		} catch (err) {
			if (/closed|Target page/i.test(String(err?.message || err))) {
				rl.close();
				console.error('ERROR: Browser closed during wait. Auth state was NOT saved.');
				process.exit(1);
			}
			throw err;
		}
		if (enterPressed) {
			ready = await isAuthenticatedWpAdmin(page);
			if (!ready) {
				console.log('Enter received but #wpadminbar not visible yet — keep logging in…');
				enterPressed = false;
			} else {
				break;
			}
		}
		await showOnScreenInstructions();
		await page.waitForTimeout(POLL_MS);
	}
	rl.close();

	if (!ready) {
		await browser.close();
		console.error('ERROR: Authenticated wp-admin (#wpadminbar) was not detected. Auth state was NOT saved.');
		console.error('Retry after a successful login, or use MANUAL SCREENSHOT MODE (see training/playwright/README.md).');
		process.exit(1);
	}

	try {
		await page.goto(adminUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 });
		await page.waitForSelector('#wpadminbar', { timeout: 30_000 });
	} catch {
		/* keep current page if already on admin */
	}

	if (!(await isAuthenticatedWpAdmin(page))) {
		await browser.close();
		console.error('ERROR: Lost wp-admin session before save. Auth state was NOT saved.');
		process.exit(1);
	}

	await context.storageState({ path: outPath });
	await browser.close();

	if (!fs.existsSync(outPath) || !storageLooksAuthenticated(outPath)) {
		console.error('ERROR: storage-state.json missing WordPress login cookies. Not usable.');
		try {
			fs.unlinkSync(outPath);
		} catch {
			/* ignore */
		}
		process.exit(1);
	}

	console.log('');
	console.log('Saved authenticated local auth state (do not commit).');
	console.log('Next: npm run test:capture   (from training/playwright)');
	console.log('');
}

main().catch((err) => {
	console.error('Auth bootstrap failed:', err?.message || err);
	process.exit(1);
});
