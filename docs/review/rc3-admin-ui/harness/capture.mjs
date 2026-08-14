import pkg from '../../../../training/playwright-videos/node_modules/playwright-core/index.js';
const { chromium } = pkg;
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const htmlDir = path.join(here, 'html');
const shotDir = path.join(here, '..', 'screenshots');
fs.mkdirSync(shotDir, { recursive: true });

const allScreens = [
	'01-wizard-store-setup',
	'02-wizard-fulfilment-types',
	'03-wizard-warehouse-defaults',
	'04-wizard-in-store-defaults',
	'05-wizard-international-defaults',
	'06-wizard-areas-charges',
	'07-wizard-apply-products',
	'08-wizard-finish',
	'09-overview',
	'10-site-wide-defaults',
	'11-delivery-options',
	'12-delivery-option-editor',
	'13-delivery-areas',
	'14-delivery-area-editor',
	'15-delivery-charges',
	'16-delivery-charge-editor',
	'17-pickup-locations',
	'18-product-exceptions',
	'19-needs-attention',
	'20-settings',
	'21-legacy-delivery-rules',
	'22-technical-diagnostics',
	'23-product-using-defaults',
	'24-product-customized',
	'25-variation-inherited',
	'26-variation-customized',
	'27-delivery-preview-ready',
	'28-delivery-preview-needs-attention',
];

const requested = process.argv.slice(2).map((name) => name.replace(/\.(png|html)$/i, ''));
const screens = requested.length
	? allScreens.filter((name) => requested.includes(name))
	: allScreens;
if (requested.length && screens.length !== requested.length) {
	const unknown = requested.filter((name) => !allScreens.includes(name));
	console.error(`Unknown screen id(s): ${unknown.join(', ')}`);
	process.exit(1);
}

const openDetails = {};

async function capture(page, name, viewport, outName) {
	const file = path.join(htmlDir, `${name}.html`);
	if (!fs.existsSync(file)) {
		console.error(`Missing HTML: ${name}.html`);
		return false;
	}
	await page.setViewportSize(viewport);
	await page.goto(pathToFileURL(file).href, { waitUntil: 'domcontentloaded' });
	for (const selector of openDetails[name] || []) {
		await page.locator(selector).evaluate((el) => {
			if (el instanceof HTMLDetailsElement) {
				el.open = true;
			}
		}).catch(() => {});
	}
	await page.screenshot({
		path: path.join(shotDir, outName),
		fullPage: true,
	});
	console.log(`Captured ${outName}`);
	return true;
}

const browser = await chromium.launch({ channel: 'chrome' });
const page = await browser.newPage();

for (const name of screens) {
	await capture(page, name, { width: 1440, height: 1000 }, `${name}.png`);
}

if (!requested.length || requested.includes('01-wizard-store-setup') || requested.includes('29-wizard-tablet')) {
	await capture(page, '01-wizard-store-setup', { width: 768, height: 1024 }, '29-wizard-tablet.png');
}
if (!requested.length || requested.includes('09-overview') || requested.includes('30-overview-tablet')) {
	await capture(page, '09-overview', { width: 768, height: 1024 }, '30-overview-tablet.png');
}
if (!requested.length || requested.includes('23-product-using-defaults') || requested.includes('31-product-panel-mobile-admin')) {
	await capture(page, '23-product-using-defaults', { width: 390, height: 844 }, '31-product-panel-mobile-admin.png');
}

await browser.close();
