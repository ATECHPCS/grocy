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
				lines: [],
				checksum: 'a'.repeat(64)
			}
		});
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
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(2);
	await expect(page.locator('#grocyai-receipt-readiness')).toContainText('Finish receipt');
});
test('OCR fallback, manual Ignore, correction invalidates acceptance, finish and reopen', async (
{
	page
}) =>
{
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
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
	const l = c.locator('.grocy-ai-receipt-line');
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
	for (const width of [320, 375, 390])
	{
		await page.setViewportSize(
		{
			width,
			height: 844
		});
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
test('save rejection retains typed correction and reports an error', async (
{
	page
}) =>
{
	await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
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
	await expect(page.getByLabel('Printed total')).toHaveValue('5');
});
test('explicit Include and product match confirm quantity and price, then permit correction', async (
{
	page
}) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
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
	await expect(page.locator('.grocy-ai-receipt')).toHaveCount(1);
});
test('unsaved drafts survive another section save and block finish or acceptance', async ({ page }) =>
{
	const state = await setup(page);
	await page.getByLabel('Add receipt photos').setInputFiles(photo);
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
