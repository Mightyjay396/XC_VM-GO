import { test, expect, type Page } from '@playwright/test';
import { TAG, confirmNext, ident, searchTable, submitForm, tableRows } from './support';

/**
 * Bulk actions on the Lines list: two lines selected with the header checkbox,
 * disabled together, then deleted together.
 */

const group = ident('bulk');
const usernames = [ident('bulka'), ident('bulkb')];

test.use({ viewport: { width: 1920, height: 1080 } });

async function ours(page: Page) {
  return (await tableRows(page.request, 'lines', group)).filter((r) => usernames.includes(r.username));
}

/** Click a bulk-bar button and return the multi API's JSON. */
async function bulk(page: Page, sub: string, confirm = false) {
  const called = page.waitForResponse((r) => new RegExp(`/api\\?action=multi&type=line&sub=${sub}&`).test(r.url()));
  await confirmNext(page, confirm, () => page.locator(`#bulk-bar [data-bulk="${sub}"]`).click());
  return (await called).json();
}

test.describe.serial('bulk actions on lines', () => {
  test('create two lines', async ({ page }) => {
    for (const username of usernames) {
      await page.goto('./line');
      await page.locator('#username').fill(username);
      await page.locator('#admin_notes').fill(TAG);
      await submitForm(page, page, 'line', page.locator('#line-submit'));
      await page.waitForURL(/lines/);
    }
    expect((await ours(page)).map((r) => r.username).sort()).toEqual([...usernames].sort());
  });

  test('select both and disable them together', async ({ page }) => {
    await page.goto('./lines');
    await searchTable(page, group);
    await expect(page.locator('#lines-table tbody .row-check')).toHaveCount(2);

    await expect(page.locator('#bulk-bar')).toHaveClass(/d-none/);
    await page.locator('#check-all').check();
    await expect(page.locator('#bulk-count')).toContainText('2');

    expect((await bulk(page, 'disable'))?.result).toBe(true);
    await expect.poll(async () => (await ours(page)).map((r) => r.enabled)).toEqual([false, false]);
  });

  test('select both and delete them together', async ({ page }) => {
    await page.goto('./lines');
    await searchTable(page, group);
    await page.locator('#check-all').check();
    expect((await bulk(page, 'delete', true))?.result).toBe(true);
    await expect.poll(async () => (await ours(page)).length).toBe(0);
  });
});
