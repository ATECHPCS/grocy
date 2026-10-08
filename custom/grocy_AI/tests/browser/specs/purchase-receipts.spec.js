const
{
	test,
	expect
} = require('@playwright/test');
const trip = {
	id: 7,
	created_at: '',
	created_by: 1,
	status: 'reviewing',
	default_location_id: null,
	default_shopping_location_id: null,
	transaction_id: null,
	committed_at: null,
	checksum: null,
	module_version: '2.5.4'
};
async function setup(page)
{
	const state = {
		receipts: [],
		lines: [],
		writes: []
	};
	await page.route('**/api/objects/**', r => r.fulfill(
	{
		json: []
	}));
	await page.route('**/api/grocy-ai/capture/**', r =>
	{
		const req = r.request(),
			p = new URL(req.url()).pathname,
			m = req.method();
		const b = m === 'GET' || /multipart/.test(req.headers()['content-type'] || '') ?
		{} : req.postDataJSON();
		if (m !== 'GET') state.writes.push(
		{
			path: p,
			body: b
		});
		if (p.endsWith('/trips')) return r.fulfill(
		{
			json:
			{
				trips: [trip]
			}
		});
		if (p.endsWith('/trips/7')) return r.fulfill(
		{
			json:
			{
				trip,
				lines: state.lines,
				checksum: 'a'.repeat(64)
			}
		});
		if (p.endsWith('/lines/1/receipt-evidence') && m === 'PUT') return r.fulfill({ json: { receipt_line_id: b.receipt_line_id } });
		if (p.endsWith('/receipt-readiness'))
		{
			const reasons = state.receipts.length ? state.receipts.filter(v => v.receipt.status !== 'finished').map(v => 'receipt_' + v.receipt.id + '_unfinished') : ['no_receipts'];
			return r.fulfill(
			{
				json:
				{
					ready: !reasons.length,
					reasons,
					receipts: state.receipts
				}
			});
		}
		if (p.endsWith('/receipts'))
		{
			if (m === 'POST') state.receipts.push(
			{
				receipt:
				{
					id: state.receipts.length + 1,
					status: 'needs_review',
					merchant: '',
					printed_total: null,
					difference_accepted_amount: null
				},
				lines: [],
				totals:
				{
					entered_total: 0,
					printed_total: null,
					difference: null
				},
				issues: ['no_lines', 'printed_total_missing']
			});
			return r.fulfill(
			{
				json: m === 'GET' ?
				{
					receipts: state.receipts
				} : state.receipts.at(-1)
			});
		}
		const match = p.match(/receipts\/(\d+)(.*)/),
			v = state.receipts[Number(match?.[1]) - 1],
			tail = match?.[2];
		if (!v) return r.fulfill(
		{
			status: 404,
			json:
			{
				error_message: 'Missing'
			}
		});
		if (tail.endsWith('/matches')) return r.fulfill(
		{
			json:
			{
				capture_lines: [],
				products: [
				{
					id: 101,
					name: 'Milk'
				}]
			}
		});
		if (tail.endsWith('/allocation') && m === 'PUT')
		{
			const line = v.lines[Number(tail.split('/')[2]) - 1];
			if (b.id) Object.assign(line.allocations.find(a => a.id === b.id), b.delete ?
			{
				active: 0
			} : b);
			else line.allocations.push(
			{
				id: 1,
				active: 1,
				...b
			});
			return r.fulfill(
			{
				json: v
			});
		}
		if (tail === '/image') return r.fulfill(
		{
			status: 204
		});
		if (tail === '/extract' || tail === '/retry')
		{
			v.receipt.extraction_status = 'manual_required';
			return r.fulfill(
			{
				json:
				{
					outcome: 'manual_required'
				}
			});
		}
		if (m !== 'GET')
		{
			if (tail === '/finish') v.receipt.status = 'finished';
			else if (tail === '/reopen') v.receipt.status = 'needs_review';
			else if (b.accept_difference) v.receipt.difference_accepted_amount = v.totals.difference;
			else
			{
				v.receipt.status = 'needs_review';
				v.receipt.difference_accepted_amount = null;
				if (tail === '/lines') v.lines.push(
				{
					id: v.lines.length + 1,
					description: '',
					quantity: 1,
					line_total: null,
					decision: 'needs_review',
					allocations: [],
					...b
				});
				else if (tail.startsWith('/lines/')) Object.assign(v.lines[Number(tail.split('/')[2]) - 1], b);
				else Object.assign(v.receipt, b);
			}
		}
		const total = v.lines.reduce((n, l) => n + Number(l.line_total || 0), 0);
		v.totals = {
			entered_total: total,
			printed_total: v.receipt.printed_total,
			difference: v.receipt.printed_total === null ? null : v.receipt.printed_total - total
		};
		v.issues = [];
		if (!v.lines.length) v.issues.push('no_lines');
		if (v.lines.some(l => l.decision === 'needs_review')) v.issues.push('line_1_needs_review');
		if (v.totals.difference === null) v.issues.push('printed_total_missing');
		else if (v.totals.difference && v.receipt.difference_accepted_amount !== v.totals.difference) v.issues.push('total_difference');
		return r.fulfill(
		{
			json: v
		});
	});
	await page.goto('/fixtures/capture-review.html');
	await page.locator('#grocyai-capture-review-trips button').click();
	return state;
}
async function openReceiptDetails(page)
{
	await expect(page.locator('.grocy-ai-receipt')).not.toHaveCount(0);
	while (await page.getByRole('button', { name: 'View receipt' }).count()) await page.getByRole('button', { name: 'View receipt' }).first().click();
}
const photo = {
	name: 'receipt.png',
	mimeType: 'image/png',
	buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64')
};
test('multiple photos and blocked commit', async (
{
	page
}) =>
{
	await setup(page);
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await page.getByLabel('Add receipt photos').setInputFiles([photo,
	{
		...photo,
		name: 'second.png'
	}]);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(2);
	await expect(page.locator('#grocyai-receipt-readiness')).toContainText('Finish receipt');
});
test('@flowreview unknown scanned UPC can be paired with a receipt line before product approval without allocating stock', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.lines = [{ id: 100, trip_id: 7, seq: 1, scanned_barcode: '012345678905', canonical_gtin: '012345678905', resolved_product_id: null, status: 'unknown', quantity: 1, price: null, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }];
	state.receipts[0].lines = [{ id: 1, description: 'Sample oats', quantity: 1, line_total: 2, decision: 'needs_review', allocations: [], capture_match_status: 'unique', capture_candidates: [{ capture_line_id: 100, seq: 1, scanned_barcode: '012345678905', display_name: 'Sample oats', source: 'Open Food Facts', score: 100, reason: 'name match' }] }];
	await page.locator('#grocyai-capture-review-trips button').click();
	const line = page.locator('.grocy-ai-receipt-line');
	await expect(line).toContainText('Scanned #1');
	await expect(line).toContainText('suggested');
	await page.locator('#grocyai-review-next').click();
	await expect(line.getByRole('button', { name: 'Pair scanned item' })).toBeVisible();
	expect(state.writes.filter(write => write.path.endsWith('/receipt-evidence'))).toHaveLength(0);
	await line.getByRole('button', { name: 'Pair scanned item' }).click();
	expect(state.writes.filter(write => write.path.endsWith('/receipt-evidence')).map(write => write.body)).toEqual([{ receipt_line_id: 1 }]);
	expect(state.writes.filter(write => write.path.endsWith('/allocation'))).toHaveLength(0);
});
test('approved paired scan preselects receipt quantity and derived price for explicit allocation', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.lines = [{ id: 100, trip_id: 7, seq: 1, scanned_barcode: '012345678905', canonical_gtin: '012345678905', resolved_product_id: 101, status: 'known', quantity: 2, price: null, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }];
	state.receipts[0].lines = [{ id: 1, description: 'Sample oats', quantity: 2, line_total: 5, decision: 'include', allocations: [], paired_capture_line_id: 100, capture_match_status: 'none', capture_candidates: [] }];
	await page.locator('#grocyai-capture-review-trips button').click();
	const allocation = page.locator('.grocy-ai-receipt-allocation').last();
	await expect(allocation.getByLabel('Purchase match')).toHaveValue('capture:100:101');
	await expect(allocation.getByLabel('Purchase quantity')).toHaveValue('2');
	await expect(allocation.getByLabel('Confirmed unit price')).toHaveValue('2.5');
	expect(state.writes.filter(write => write.path.endsWith('/allocation'))).toHaveLength(0);
	await allocation.getByRole('button', { name: 'Add allocation' }).click();
	expect(state.writes.filter(write => write.path.endsWith('/allocation')).map(write => write.body)).toEqual([{ capture_line_id: 100, product_id: 101, quantity: 2, unit_price: 2.5 }]);
});
test('receipt pairing can be cleared and scans claimed by another line are unavailable', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.lines = [1, 2].map(seq => ({ id: 100 + seq, trip_id: 7, seq, scanned_barcode: '01234567890' + seq, canonical_gtin: '01234567890' + seq, resolved_product_id: null, status: 'unknown', quantity: 1, price: null, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }));
	const options = [1, 2].map(seq => ({ capture_line_id: 100 + seq, seq, scanned_barcode: '01234567890' + seq, display_name: 'Sample ' + seq, source: 'research_provider', paired_receipt_line_id: seq === 1 ? 1 : null }));
	state.receipts[0].lines = [1, 2].map(id => ({ id, description: 'Sample ' + id, quantity: 1, line_total: 2, decision: 'needs_review', allocations: [], paired_capture_line_id: id === 1 ? 101 : null, capture_candidates: [], capture_match_status: 'none', capture_options: options }));
	await page.locator('#grocyai-capture-review-trips button').click();
	const first = page.locator('.grocy-ai-receipt-line').first();
	const second = page.locator('.grocy-ai-receipt-line').last();
	await expect(first).toContainText('Sample 1');
	await expect(second.getByLabel('Scanned item for this receipt line').locator('option[value="101"]')).toHaveCount(0);
	await first.getByRole('button', { name: 'Clear scan pairing' }).click();
	expect(state.writes.filter(write => write.path.endsWith('/receipt-evidence')).map(write => write.body)).toEqual([{ receipt_line_id: null }]);
});
test('OCR fallback, manual Ignore, correction invalidates acceptance, finish and reopen', async (
{
	page
}) =>
{
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	const c = page.locator('.grocy-ai-receipt');
	await c.getByRole('button',
	{
		name: 'Read receipt',
		exact: true
	}).click();
	await expect(c).toContainText('Enter the receipt manually');
	await c.getByLabel('Printed total').fill('5');
	await c.getByRole('button',
	{
		name: 'Save receipt details'
	}).click();
	await c.getByRole('button',
	{
		name: 'Add manual line'
	}).click();
	const l = page.locator('.grocy-ai-receipt-line');
	await l.getByLabel('Description').fill('Snack');
	await l.getByLabel('Line total').fill('4');
	await l.getByLabel('Decision').selectOption('ignore');
	await l.getByRole('button',
	{
		name: 'Save line',
		exact: true
	}).click();
	await c.getByRole('button',
	{
		name: 'Accept difference and leave as is'
	}).click();
	await expect(c).toContainText('Difference accepted');
	await l.getByLabel('Line total').fill('3');
	await l.getByRole('button',
	{
		name: 'Save line',
		exact: true
	}).click();
	await expect(c).not.toContainText('Difference accepted');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await c.getByRole('button',
	{
		name: 'Accept difference and leave as is'
	}).click();
	await c.getByRole('button',
	{
		name: 'Finish receipt',
		exact: true
	}).click();
	await expect(page.locator('#grocyai-capture-review-commit')).toBeEnabled();
	await c.getByRole('button',
	{
		name: 'Reopen receipt'
	}).click();
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
});
test('receipt controls fit phone widths and saved receipt edits must be saved before commit', async (
{
	page
}) =>
{
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	for (const width of [320, 375, 390])
	{
		await page.setViewportSize(
		{
			width,
			height: 844
		});
		await openReceiptDetails(page);
		const button = page.getByRole('button',
		{
			name: 'Save receipt details'
		});
		expect((await button.boundingBox()).height).toBeGreaterThanOrEqual(44);
		expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
	}
	await page.getByLabel('Merchant',
	{
		exact: true
	}).fill('Changed');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await expect(page.getByText('Unsaved changes — save this section before committing.')).toBeVisible();
});
test('@flowreview save rejection retains typed correction and reports an error', async (
{
	page
}) =>
{
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await page.route('**/receipts/1', r => r.request().method() === 'PUT' ? r.fulfill(
	{
		status: 400,
		json:
		{
			error_message: 'Invalid receipt total'
		}
	}) : r.fallback());
	await page.getByLabel('Printed total').fill('5');
	await page.getByRole('button',
	{
		name: 'Save receipt details'
	}).click();
	await expect(page.getByText('Invalid receipt total')).toBeVisible();
	await expect(page.locator('#grocyai-receipt-details-1 .grocy-ai-receipt-status')).toBeFocused();
	await expect(page.getByLabel('Printed total')).toHaveValue('5');
});
test('explicit Include and product match confirm quantity and price, then permit correction', async (
{
	page
}) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await page.getByRole('button',
	{
		name: 'Add manual line'
	}).click();
	const line = page.locator('.grocy-ai-receipt-line');
	await line.getByLabel('Description').fill('Milk');
	await line.getByLabel('Decision').selectOption('include');
	await line.getByRole('button',
	{
		name: 'Save line',
		exact: true
	}).click();
	await line.getByRole('button',
	{
		name: 'Find product suggestions'
	}).click();
	await line.getByLabel('Purchase match').selectOption('product:101');
	await line.getByLabel('Confirmed unit price').fill('2.50');
	await line.getByRole('button',
	{
		name: 'Add allocation'
	}).click();
	const allocation = line.locator('.grocy-ai-receipt-allocation').first();
	await expect(allocation.getByLabel('Confirmed unit price')).toHaveValue('2.5');
	await allocation.getByLabel('Confirmed unit price').fill('2.25');
	await allocation.getByRole('button',
	{
		name: 'Save allocation'
	}).click();
	await expect(allocation.getByLabel('Confirmed unit price')).toHaveValue('2.25');
	expect(state.writes.at(-1).body).toMatchObject(
	{
		product_id: 101,
		quantity: 1,
		unit_price: 2.25
	});
	await allocation.getByRole('button',
	{
		name: 'Remove allocation'
	}).click();
	await expect(line.getByRole('button',
	{
		name: 'Save allocation'
	})).toHaveCount(0);
	expect(await page.evaluate(() => window.__captureFixture.forbiddenWrite)).toBeNull();
});
test('photo upload works on LAN browsers without randomUUID', async (
{
	page
}) =>
{
	await page.addInitScript(() => Object.defineProperty(window.crypto, 'randomUUID',
	{
		value: undefined
	}));
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
});
test('unsaved drafts survive another section save and block finish or acceptance', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.receipts[0].lines = [{ id: 1, description: 'Milk', quantity: 1, line_total: 2, decision: 'include', allocations: [{ id: 1, active: 1, product_id: 101, capture_line_id: null, quantity: 1, unit_price: 2 }] }];
	state.receipts[0].totals = { entered_total: 2, printed_total: 3, difference: 1 };
	state.receipts[0].receipt.printed_total = 3;
	await page.locator('#grocyai-capture-review-trips button').click();
	const price = page.getByLabel('Confirmed unit price').first();
	await price.fill('1.50');
	await page.getByLabel('Merchant', { exact: true }).fill('Corrected store');
	const writesBefore = state.writes.length;
	await page.getByRole('button', { name: 'Finish receipt', exact: true }).click();
	await expect(page.getByText('Save all edited sections before this action.')).toBeVisible();
	await page.getByRole('button', { name: 'Accept difference and leave as is' }).click();
	expect(state.writes.length).toBe(writesBefore);
	await expect(price).toHaveValue('1.50');
	await page.getByRole('button', { name: 'Save receipt details' }).click();
	await expect(page.getByText('Saved.')).toBeVisible();
	await expect(price).toHaveValue('1.50');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await page.getByRole('button', { name: 'Finish receipt', exact: true }).click();
	expect(state.receipts[0].receipt.status).toBe('needs_review');
	await page.getByRole('button', { name: 'Save allocation', exact: true }).click();
	await expect(price).toHaveValue('1.5');
	await page.getByRole('button', { name: 'Accept difference and leave as is' }).click();
	await page.getByRole('button', { name: 'Finish receipt', exact: true }).click();
	await expect(page.getByRole('button', { name: 'Reopen receipt' })).toBeVisible();
	expect(state.receipts[0].lines[0].allocations[0].unit_price).toBe(1.5);
});

test('partial photo upload shows success and retries only failed photo with the same request id', async ({ page }) =>
{
	const state = await setup(page);
	const uploads = [];
	await page.route('**/trips/7/receipts', route =>
	{
		if (route.request().method() !== 'POST') return route.fallback();
		const body = route.request().postDataBuffer().toString();
		const id = body.match(/name="request_id"\r\n\r\n([^\r]+)/)[1];
		uploads.push(id);
		if (uploads.length === 2) return route.fulfill({ status: 503, json: { error_message: 'Temporary upload failure' } });
		return route.fallback();
	});
	await page.getByLabel('Add receipt photos').setInputFiles([photo, { ...photo, name: 'second.png' }]);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	await expect(page.getByRole('button', { name: 'Retry failed photos' })).toBeVisible();
	await expect(page.getByText('Photo 1: uploaded', { exact: true })).toBeVisible();
	await expect(page.getByText('Photo 2: failed — Temporary upload failure', { exact: true })).toBeVisible();
	await page.getByRole('button', { name: 'Retry failed photos' }).click();
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(2);
	expect(uploads).toHaveLength(3);
	expect(uploads[2]).toBe(uploads[1]);
	expect(state.receipts).toHaveLength(2);
});

async function seedEditableReceipt(page, allocated)
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.receipts[0].lines = [{ id: 1, description: 'Milk', quantity: 1, line_total: 2, decision: 'include', allocations: allocated ? [{ id: 1, active: 1, product_id: 101, capture_line_id: null, quantity: 1, unit_price: 2 }] : [] }];
	state.receipts[0].receipt.printed_total = 2;
	state.receipts[0].totals = { entered_total: 2, printed_total: 2, difference: 0 };
	state.receipts[0].issues = [];
	await page.locator('#grocyai-capture-review-trips button').click();
	return state;
}

test('discarding an abandoned allocation draft preserves other sections and unblocks finish', async ({ page }) =>
{
	const state = await seedEditableReceipt(page, true);
	const allocation = page.locator('.grocy-ai-receipt-allocation').last();
	await allocation.getByLabel('Confirmed unit price').fill('3');
	await allocation.getByLabel('Confirmed unit price').fill('');
	await page.getByLabel('Description', { exact: true }).fill('Draft milk');
	await page.getByLabel('Merchant', { exact: true }).fill('Draft store');
	const writes = state.writes.length;
	await allocation.getByRole('button', { name: 'Discard allocation edits' }).click();
	await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Draft milk');
	await expect(page.getByLabel('Merchant', { exact: true })).toHaveValue('Draft store');
	await page.getByRole('button', { name: 'Discard line edits' }).click();
	await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Milk');
	await expect(page.getByLabel('Merchant', { exact: true })).toHaveValue('Draft store');
	await page.getByRole('button', { name: 'Discard receipt edits' }).click();
	await expect(page.getByLabel('Merchant', { exact: true })).toHaveValue('');
	expect(state.writes.length).toBe(writes);
	await page.getByRole('button', { name: 'Finish receipt', exact: true }).click();
	await expect(page.getByRole('button', { name: 'Reopen receipt' })).toBeVisible();
});

test('saving Ignore removes hidden allocation drafts and preserves unrelated receipt edits', async ({ page }) =>
{
	await seedEditableReceipt(page, false);
	await page.getByLabel('Confirmed unit price').fill('3');
	await page.getByLabel('Merchant', { exact: true }).fill('Draft store');
	await page.getByLabel('Decision').selectOption('ignore');
	await page.getByRole('button', { name: 'Save line', exact: true }).click();
	await expect(page.locator('.grocy-ai-receipt-allocation')).toHaveCount(0);
	await expect(page.getByLabel('Merchant', { exact: true })).toHaveValue('Draft store');
	await page.getByRole('button', { name: 'Save receipt details' }).click();
	await page.getByRole('button', { name: 'Finish receipt', exact: true }).click();
	await expect(page.getByRole('button', { name: 'Reopen receipt' })).toBeVisible();
});


test('finished receipts own price and store corrections while capture retains inventory location', async ({ page }) =>
{
	const state = await seedEditableReceipt(page, true);
	state.lines = [{ id: 100, trip_id: 7, seq: 1, scanned_barcode: '', canonical_gtin: null, resolved_product_id: 101, status: 'known', quantity: 1, price: 9, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }];
	state.receipts[0].receipt.status = 'finished';
	await page.route('**/api/objects/shopping_locations', r => r.fulfill({ json: [{ id: 99, name: 'Corrected store' }] }));
	await page.reload();
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.locator('#grocyai-capture-review-commit')).toBeEnabled();
	await expect(page.locator('#grocyai-capture-review-store')).toHaveCount(0);
	await expect(page.getByText('Price (optional)', { exact: true })).toHaveCount(0);
	await expect(page.locator('#grocyai-capture-review-location')).toBeVisible();
	await expect(page.getByText('Correct purchase prices and stores in the receipts below.')).toBeVisible();
	await openReceiptDetails(page);
	await page.getByRole('button', { name: 'Reopen receipt' }).click();
	await page.getByRole('combobox', { name: 'Receipt store', exact: true }).selectOption('99');
	await page.getByRole('button', { name: 'Save receipt details' }).click();
	await expect(page.getByRole('combobox', { name: 'Receipt store', exact: true })).toHaveValue('99');
	await page.locator('#grocyai-review-next').click();
	const allocation = page.locator('.grocy-ai-receipt-allocation').first();
	await allocation.getByLabel('Confirmed unit price').fill('9');
	await allocation.getByRole('button', { name: 'Save allocation', exact: true }).click();
	await expect(allocation.getByLabel('Confirmed unit price')).toHaveValue('9');
	expect(state.writes.at(-1)).toMatchObject({ path: '/api/grocy-ai/capture/trips/7/receipts/1/lines/1/allocation', body: { unit_price: 9 } });
	expect(state.writes.some(w => w.path.endsWith('/receipts/1') && w.body.shopping_location_id === 99)).toBe(true);
	expect(state.writes.every(w => w.path.includes('/receipts'))).toBe(true);
});

// Saved pairing wins; shared allocations must never duplicate receipt editors.
test('review queue assigns one receipt owner and references shared allocations', async ({ page }) =>
{
	await page.addScriptTag({ path: require('path').resolve(__dirname, '../../../../../public/custom/grocy_AI/capture-review-queue.js') });
	const result = await page.evaluate(() => window.GrocyAICaptureReviewQueue.build([{ id: 10, seq: 1 }, { id: 20, seq: 2 }], [
		{ receipt: { id: 7 }, lines: [
			{ id: 1, kind: 'item', paired_capture_line_id: 10, allocations: [{ active: 1, capture_line_id: 20 }] },
			{ id: 2, kind: 'item', allocations: [{ active: 1, capture_line_id: 10 }, { active: 1, capture_line_id: '10' }, { active: 1, capture_line_id: 20 }] },
			{ id: 3, kind: 'item', capture_match_status: 'unique', capture_candidates: [{ capture_line_id: 10 }], allocations: [{ active: 0, capture_line_id: 10 }] },
			{ id: 4, kind: 'tax', decision: 'needs_review' },
			{ id: 5, allocations: [{ active: 1, capture_line_id: 20 }, { active: 1, capture_line_id: '20' }, { active: 0, capture_line_id: 10 }] },
			{ id: 6, kind: 'item', paired_capture_line_id: 999 },
			{ id: 7, kind: 'savings', decision: 'ignore' }
		] }, { receipt: { id: 8 }, lines: [{ id: 1, kind: 'item' }] }
	]));
	expect(result).toEqual({ cards: [
		{ key: 'scan:10', kind: 'scan', scanLineId: 10, receiptKeys: ['receipt:7:1', 'receipt:7:2'] },
		{ key: 'scan:20', kind: 'scan', scanLineId: 20, receiptKeys: ['receipt:7:2', 'receipt:7:5'] },
		{ key: 'receipt:7:2', kind: 'receipt', receiptId: 7, receiptLineId: 2, receiptKeys: ['receipt:7:2'] },
		{ key: 'receipt:7:3', kind: 'receipt', receiptId: 7, receiptLineId: 3, receiptKeys: ['receipt:7:3'] },
		{ key: 'receipt:7:4', kind: 'adjustment', receiptId: 7, receiptLineId: 4, receiptKeys: ['receipt:7:4'] },
		{ key: 'receipt:7:6', kind: 'receipt', receiptId: 7, receiptLineId: 6, receiptKeys: ['receipt:7:6'] },
		{ key: 'receipt:7:7', kind: 'adjustment', receiptId: 7, receiptLineId: 7, receiptKeys: ['receipt:7:7'] },
		{ key: 'receipt:8:1', kind: 'receipt', receiptId: 8, receiptLineId: 1, receiptKeys: ['receipt:8:1'] }
	], receiptOwnerByKey: { 'receipt:7:1': 'scan:10', 'receipt:7:2': 'receipt:7:2', 'receipt:7:3': 'receipt:7:3', 'receipt:7:4': 'receipt:7:4', 'receipt:7:5': 'scan:20', 'receipt:7:6': 'receipt:7:6', 'receipt:7:7': 'receipt:7:7', 'receipt:8:1': 'receipt:8:1' } });
});

async function mountReceiptCards(page, desktop = false)
{
	await page.goto('/health');
	await page.setContent('<main id="input-root"><div id="summaries"></div><section id="scan-10"></section><section id="receipt-7-2"></section><section id="receipt-8-1"></section><section id="receipt-7-3"></section><input id="research"></main>');
	await page.addScriptTag({ path: require('path').resolve(__dirname, '../../../../../public/custom/grocy_AI/capture-receipts.js') });
	await page.evaluate(desktop =>
	{
		window.receiptState = { drafts: {}, uploads: [] };
		window.busyCalls = [];
		window.receiptOptions = {
			url: location.origin + '/api/grocy-ai/capture/trips/7', state: window.receiptState,
			lines: [{ id: 10, seq: 1, selected: 1, resolved_product_id: 101 }], names: { 101: 'Milk' }, stores: [{ id: 9, name: 'Market' }], productNew: '/product/new',
			onBusy: value => window.busyCalls.push(value), reload: () => window.remountReceipts(),
			receipts: [
				{ receipt: { id: 7, status: 'needs_review', merchant: 'Market', printed_total: 8, shopping_location_id: 9 }, totals: { entered_total: 7, difference: 1 }, lines: [
					{ id: 1, description: 'Milk', quantity: 1, line_total: 2, decision: 'include', paired_capture_line_id: 10, allocations: [] },
					{ id: 2, description: 'Shared milk', quantity: 2, line_total: 4, decision: 'include', allocations: [{ id: 1, active: 1, capture_line_id: 10, product_id: 101, quantity: 1, unit_price: 2 }, { id: 2, active: 1, capture_line_id: 20, product_id: 101, quantity: 1, unit_price: 2 }] },
					{ id: 3, description: 'Unmounted adjustment', quantity: 1, line_total: 1, decision: 'needs_review', kind: 'tax' }
				] },
				{ receipt: { id: 8, status: 'finished', merchant: 'Other', printed_total: 3, difference_accepted_amount: 0 }, totals: { entered_total: 3, difference: 0 }, lines: [{ id: 1, description: 'Receipt only', quantity: 1, line_total: 3, decision: 'needs_review' }] }
			]
		};
		if (!desktop)
		{
			window.receiptOptions.inputRoot = document.querySelector('#input-root');
			window.receiptOptions.lineHostFor = (receipt, line) => document.getElementById(receipt.id === 7 && line.id === 1 ? 'scan-10' : 'receipt-' + receipt.id + '-' + line.id);
		}
		window.remountReceipts = () =>
		{
			if (window.receiptEditor) window.receiptEditor.dispose();
			['scan-10', 'receipt-7-2', 'receipt-8-1', 'receipt-7-3'].forEach(id => document.getElementById(id).textContent = '');
			window.receiptEditor = window.GrocyAIReceipts(document.getElementById('summaries'), window.receiptOptions);
		};
		window.remountReceipts();
	}, desktop);
}

test('receipt card mounting keeps one authoritative editor and full receipt summaries', async ({ page }) =>
{
	await mountReceiptCards(page);
	for (const id of ['scan-10', 'receipt-7-2', 'receipt-8-1', 'receipt-7-3']) await expect(page.locator('#' + id + ' .grocy-ai-receipt-line')).toHaveCount(1);
	await expect(page.locator('#summaries .grocy-ai-receipt-line')).toHaveCount(0);
	await expect(page.locator('.grocy-ai-receipt-line')).toHaveCount(4);
	await expect(page.locator('#scan-10').getByLabel('Description', { exact: true })).toHaveValue('Milk');
	await expect(page.locator('#receipt-7-2 .grocy-ai-receipt-allocation')).toHaveCount(3);
	const summary = page.locator('#summaries .grocy-ai-receipt').first();
	await expect(summary.getByLabel('Merchant', { exact: true })).toHaveValue('Market');
	await expect(summary.getByRole('combobox', { name: /^Receipt store/ })).toHaveValue('9');
	await expect(summary.getByLabel('Printed total', { exact: true })).toHaveValue('8');
	await expect(summary.getByRole('img')).toHaveAttribute('src', /receipts\/7\/image$/);
	await expect(summary.getByText('Entered total: 7 · Difference: 1', { exact: true })).toBeVisible();
	await expect(summary.getByRole('button', { name: 'Accept difference and leave as is' })).toBeVisible();
	await expect(summary.getByRole('button', { name: 'Finish receipt', exact: true })).toBeVisible();
	await expect(page.getByRole('button', { name: 'Reopen receipt', exact: true })).toBeVisible();
});

test('receipt card mounting preserves drafts across card changes and rejected saves', async ({ page }) =>
{
	await mountReceiptCards(page);
	await page.route('**/receipts/7/lines/1', route => route.fulfill({ status: 409, json: { error_message: 'Receipt changed; review again.' } }));
	const scan = page.locator('#scan-10');
	await scan.getByLabel('Description', { exact: true }).fill('Typed milk');
	await scan.getByLabel('Confirmed unit price').fill('2.50');
	await page.evaluate(() => document.querySelector('#scan-10').hidden = true);
	await page.locator('#receipt-8-1').getByLabel('Description', { exact: true }).fill('Typed receipt only');
	await page.evaluate(() => { window.remountReceipts(); document.querySelector('#scan-10').hidden = false; });
	await expect(scan.getByLabel('Description', { exact: true })).toHaveValue('Typed milk');
	await expect(scan.getByLabel('Confirmed unit price')).toHaveValue('2.50');
	await expect(page.locator('#receipt-8-1').getByLabel('Description', { exact: true })).toHaveValue('Typed receipt only');
	expect(await page.evaluate(() => window.receiptEditor.hasUnsavedEdits())).toBe(true);
	await scan.getByRole('button', { name: 'Save line', exact: true }).click();
	await expect(page.getByRole('status')).toHaveText('Receipt changed; review again.');
	await expect(scan.getByLabel('Description', { exact: true })).toHaveValue('Typed milk');
	expect(await page.evaluate(() => window.receiptEditor.hasUnsavedEdits())).toBe(true);
});

test('receipt card mounting disposes input tracking and locks only receipt controls', async ({ page }) =>
{
	await mountReceiptCards(page);
	let release;
	const gate = new Promise(resolve => release = resolve);
	await page.route('**/receipts/7/lines/1', async route => { await gate; await route.fulfill({ status: 409, json: { error_message: 'Rejected' } }); });
	await page.evaluate(() => { window.remountReceipts(); window.busyCalls = []; });
	await page.locator('#scan-10').getByLabel('Description', { exact: true }).fill('Single draft event');
	expect(await page.evaluate(() => window.busyCalls)).toEqual([true]);
	await page.locator('#scan-10').getByRole('button', { name: 'Save line', exact: true }).click();
	await expect(page.locator('#scan-10').getByLabel('Description', { exact: true })).toBeDisabled();
	await expect(page.locator('#receipt-8-1').getByLabel('Description', { exact: true })).toBeDisabled();
	await expect(page.locator('#research')).toBeEnabled();
	release();
	await expect(page.getByRole('status')).toHaveText('Rejected');
	await expect(page.locator('#scan-10').getByLabel('Description', { exact: true })).toBeEnabled();
	await page.evaluate(() => { window.receiptEditor.dispose(); window.busyCalls = []; });
	await page.locator('#scan-10').getByLabel('Description', { exact: true }).fill('After disposal');
	expect(await page.evaluate(() => window.busyCalls)).toEqual([]);
	expect(await page.evaluate(() => window.receiptState.drafts['/receipts/7/lines/1'].Description.value)).toBe('Single draft event');
});

test('receipt card mounting keeps desktop receipt editing without a card callback', async ({ page }) =>
{
	await mountReceiptCards(page, true);
	await expect(page.locator('#summaries .grocy-ai-receipt-line')).toHaveCount(4);
	await page.locator('.grocy-ai-receipt-line').first().getByLabel('Description', { exact: true }).fill('Desktop milk');
	await page.evaluate(() => window.remountReceipts());
	await expect(page.locator('.grocy-ai-receipt-line').first().getByLabel('Description', { exact: true })).toHaveValue('Desktop milk');
	expect(await page.evaluate(() => window.receiptEditor.hasUnsavedEdits())).toBe(true);
});


test('receipt card mounting falls back to an editable summary when a card host is missing', async ({ page }) =>
{
	await mountReceiptCards(page);
	await page.evaluate(() =>
	{
		window.receiptOptions.lineHostFor = () => null;
		window.remountReceipts();
	});
	await expect(page.locator('#summaries .grocy-ai-receipt-line')).toHaveCount(4);
	await expect(page.locator('.grocy-ai-receipt-line')).toHaveCount(4);
	const first = page.locator('#summaries .grocy-ai-receipt-line').first();
	await first.getByLabel('Description', { exact: true }).fill('Fallback milk');
	expect(await page.evaluate(() => window.receiptEditor.hasUnsavedEdits())).toBe(true);
	await page.evaluate(() => window.remountReceipts());
	await expect(first.getByLabel('Description', { exact: true })).toHaveValue('Fallback milk');
});

test('@flowreview @mobilequeue combined scan and receipt-only cards retain edits and totals across receipts', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles([photo, { ...photo, name: 'other.png' }]);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(2);
	state.lines = [{ id: 100, trip_id: 7, seq: 1, scanned_barcode: '012345678905', canonical_gtin: '012345678905', resolved_product_id: null, status: 'unknown', quantity: 1, price: null, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }];
	state.receipts[0].lines = [{ id: 1, kind: 'item', description: 'Scan receipt evidence', quantity: 1, line_total: 2, decision: 'needs_review', paired_capture_line_id: 100, allocations: [] }];
	state.receipts[1].lines = [{ id: 1, kind: 'item', description: 'Receipt only', quantity: 1, line_total: 3, decision: 'needs_review', allocations: [] }];
	await page.locator('#grocyai-capture-review-trips button').click();
	const active = page.locator('.grocy-ai-review-card:visible');
	await expect(active).toContainText('UPC 012345678905');
	await expect(active.getByLabel('Line total', { exact: true })).toHaveValue('2');
	await expect(page.locator('.grocy-ai-receipts .grocy-ai-receipt-line')).toHaveCount(0);
	await active.getByLabel('Description', { exact: true }).fill('Typed evidence');
	await page.locator('#grocyai-review-next').click();
	await expect(page.locator('#grocyai-review-progress')).toHaveText('2 of 2');
	await expect(active.getByLabel('Description', { exact: true })).toHaveValue('Receipt only');
	await active.getByRole('combobox', { name: /^Decision/ }).selectOption('ignore');
	await active.getByRole('button', { name: 'Save line', exact: true }).click();
	await expect.poll(() => state.receipts[1].lines[0].decision).toBe('ignore');
	await expect(page.locator('#grocyai-review-progress')).toHaveText('2 of 2');
	await page.locator('#grocyai-review-prev').click();
	await expect(active.getByLabel('Description', { exact: true })).toHaveValue('Typed evidence');
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await expect(page.locator('#grocyai-receipt-readiness')).toContainText('Finish receipt');
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(2);
	await page.setViewportSize({ width: 320, height: 844 });
	expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
	for (const control of await active.locator('input:not([type=checkbox]), select, button').all()) expect((await control.boundingBox()).height).toBeGreaterThanOrEqual(44);
});

test('@flowreview @mobilequeue shared receipt allocations have one editor reachable from either scan', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await openReceiptDetails(page);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.lines = [1, 2].map(seq => ({ id: 100 + seq, trip_id: 7, seq, scanned_barcode: '01234567890' + seq, canonical_gtin: null, resolved_product_id: 101, status: 'known', quantity: 1, price: null, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }));
	state.receipts[0].lines = [{ id: 1, kind: 'item', description: 'Shared milk', quantity: 2, line_total: 4, decision: 'include', allocations: [1, 2].map(id => ({ id, active: 1, capture_line_id: 100 + id, product_id: 101, quantity: 1, unit_price: 2 })) }];
	await page.locator('#grocyai-capture-review-trips button').click();
	await expect(page.locator('.grocy-ai-receipt-line')).toHaveCount(1);
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'scan:101');
	await page.getByRole('button', { name: 'Review shared receipt line: Shared milk' }).click();
	await expect(page.locator('.grocy-ai-review-card:visible')).toHaveAttribute('data-review-key', 'receipt:1:1');
	await page.getByLabel('Description', { exact: true }).fill('Shared draft');
	await page.locator('#grocyai-review-prev').click();
	await page.getByRole('button', { name: 'Review shared receipt line: Shared milk' }).click();
	await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Shared draft');
	await expect(page.locator('.grocy-ai-receipt-line')).toHaveCount(1);
});

test('@flowreview @mobilequeue compact multiple receipt summaries reveal photo and edits explicitly at 320px', async ({ page }) =>
{
	await page.setViewportSize({ width: 320, height: 844 });
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles([photo, { ...photo, name: 'second.png' }]);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(2);
	await expect(page.locator('.grocy-ai-receipt-details:visible')).toHaveCount(0);
	await expect(page.locator('.grocy-ai-receipt-compact-summary:visible')).toHaveCount(2);
	const receipt = page.locator('.grocy-ai-receipt').first();
	await expect(receipt).toContainText('needs review');
	await expect(receipt.locator('.grocy-ai-receipt-compact-summary')).toContainText('Entered total: 0');
	const toggle = receipt.getByRole('button', { name: 'View receipt' });
	expect((await toggle.boundingBox()).height).toBeGreaterThanOrEqual(44);
	await toggle.click();
	await expect(receipt.getByRole('img')).toBeVisible();
	await receipt.getByLabel('Merchant', { exact: true }).fill('Unsaved market');
	await receipt.getByRole('button', { name: 'Hide receipt details' }).click();
	await expect(page.locator('#grocyai-capture-review-commit')).toBeDisabled();
	await receipt.getByRole('button', { name: 'View receipt' }).click();
	await expect(receipt.getByLabel('Merchant', { exact: true })).toHaveValue('Unsaved market');
	await expect(receipt.getByRole('button', { name: 'Finish receipt', exact: true })).toBeVisible();
	expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
	await page.setViewportSize({ width: 1280, height: 800 });
	await expect(page.locator('.grocy-ai-receipt-details:visible')).toHaveCount(2);
});

test('@flowreview disregard receipt item requires confirmation, preserves amounts, and allows restoration', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.receipts[0].lines = [{ id: 1, description: 'Unwanted item', quantity: 2, line_total: 5, decision: 'needs_review', allocations: [] }];
	await page.locator('#grocyai-capture-review-trips button').click();
	const line = page.locator('.grocy-ai-receipt-line');
	page.once('dialog', dialog => dialog.dismiss());
	await line.getByRole('button', { name: 'Disregard receipt item', exact: true }).click();
	expect(state.writes.filter(write => write.path.endsWith('/lines/1'))).toHaveLength(0);
	page.once('dialog', dialog => dialog.accept());
	await line.getByRole('button', { name: 'Disregard receipt item', exact: true }).click();
	await expect(line).toContainText('Ignored');
	expect(state.writes.filter(write => write.path.endsWith('/lines/1')).map(write => write.body)).toEqual([{ decision: 'ignore' }]);
	await expect(line.getByLabel('Receipt quantity')).toHaveValue('2');
	await expect(line.getByLabel('Line total')).toHaveValue('5');
	await expect(line.getByRole('button', { name: 'Disregard receipt item', exact: true })).toHaveCount(0);
	await line.getByLabel('Decision').selectOption('needs_review');
	await line.getByRole('button', { name: 'Save line', exact: true }).click();
	await expect(line.getByRole('button', { name: 'Disregard receipt item', exact: true })).toBeVisible();
});

test('@flowreview disregard matched receipt item explains allocation removal without saving', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.receipts[0].lines = [{ id: 1, description: 'Matched item', quantity: 1, line_total: 2, decision: 'include', allocations: [{ id: 1, active: 1, product_id: 101, quantity: 1, unit_price: 2 }] }];
	await page.locator('#grocyai-capture-review-trips button').click();
	await page.locator('.grocy-ai-receipt-line').getByRole('button', { name: 'Disregard receipt item', exact: true }).click();
	const feedback = page.locator('.grocy-ai-receipt-line .grocy-ai-receipt-status');
	await expect(feedback).toContainText('Remove this receipt item’s allocations before disregarding it.');
	await expect(feedback).toBeVisible();
	expect(state.writes.filter(write => write.path.endsWith('/lines/1'))).toHaveLength(0);
});

test('manually choosing a scanned UPC can pair without saving unrelated receipt edits', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
	state.lines = [{ id: 100, trip_id: 7, seq: 1, scanned_barcode: '012345678905', canonical_gtin: '00012345678905', resolved_product_id: null, status: 'unknown', quantity: 1, price: null, best_before_override: null, selected: 1, applied_at: null, outcome: null, created_at: '', updated_at: '' }];
	state.receipts[0].lines = [{ id: 1, description: 'Store item', quantity: 1, line_total: 2, decision: 'needs_review', allocations: [], capture_match_status: 'none', capture_candidates: [] }];
	await page.locator('#grocyai-capture-review-trips button').click();
	await openReceiptDetails(page);
	await page.locator('#grocyai-review-next').click();
	const editor = page.locator('.grocy-ai-receipt-line');
	await editor.getByLabel('Scanned item for this receipt line').selectOption('100');
	await expect(page.locator('#grocyai-review-announcement')).not.toContainText('Unsaved changes');
	await page.getByLabel('Merchant').fill('Unsaved merchant correction');
	await editor.getByLabel('Description', { exact: true }).fill('Unreviewed description correction');
	await editor.getByRole('button', { name: 'Pair scanned item', exact: true }).click();
	await expect(editor).toContainText('Save or discard edits to this receipt line before pairing.');
	expect(state.writes.filter(write => write.path.endsWith('/receipt-evidence'))).toHaveLength(0);
	await expect(editor.getByLabel('Description', { exact: true })).toHaveValue('Unreviewed description correction');
	await editor.getByRole('button', { name: 'Discard line edits', exact: true }).click();
	await expect(editor.getByLabel('Description', { exact: true })).toHaveValue('Store item');
	await expect(editor.getByLabel('Scanned item for this receipt line')).toHaveValue('100');
	await editor.getByRole('button', { name: 'Pair scanned item', exact: true }).click();
	await expect.poll(() => state.writes.filter(write => write.path.endsWith('/receipt-evidence')).map(write => write.body)).toEqual([{ receipt_line_id: 1 }]);
	await openReceiptDetails(page);
	await expect(page.getByLabel('Merchant')).toHaveValue('Unsaved merchant correction');
	expect(state.writes.filter(write => write.path.endsWith('/allocation'))).toHaveLength(0);
	await expect(page.getByRole('button', { name: 'Commit purchase', exact: true })).toBeDisabled();
});

test('@flowreview header blockers open receipt details without writes', async ({ page }) =>
{
 const state = await setup(page);
 state.receipts = [{ receipt: { id: 1, status: 'needs_review', merchant: 'Market' }, lines: [], totals: { entered_total: 2, printed_total: 3, difference: 1 }, issues: ['total_difference'] }];
 await page.locator('#grocyai-capture-review-trips button').click();
 await expect(page.locator('#grocyai-review-overview')).toContainText('1 receipt');
 const receipt = page.locator('.grocy-ai-receipt-compact-summary');
 await expect(receipt).toContainText('Market'); await expect(receipt).toContainText('Difference: 1'); await expect(receipt).toContainText('Needs review');
 await expect(page.locator('#grocyai-receipt-details-1')).toBeHidden();
 await page.locator('#grocyai-review-next-unresolved').click();
 await expect(page.locator('#grocyai-receipt-details-1')).toBeVisible();
 await expect(page.locator('#grocyai-receipt-details-1')).toBeFocused();
 await page.locator('#grocyai-capture-review-trips button').click();
 await expect(page.locator('#grocyai-receipt-details-1')).toBeVisible();
 expect(state.writes).toEqual([]);
});
