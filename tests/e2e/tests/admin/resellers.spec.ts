import { test, expect, type Page } from '@playwright/test';
import { TAG, ident, openEditModal, openRowMenu, rowAction, rowWith, searchTable, submitForm, tableRows } from './support';

/**
 * A reseller account as an administrator manages it: created in the Resellers
 * group with starting credits, topped up from the list, its notes edited in the
 * modal, disabled / enabled, and deleted.
 */

const username = ident('reseller');
const password = ident('resellerpass');
// group_id 2 is the seeded Resellers group (bin/install/database.sql).
const RESELLERS = '2';

test.use({ viewport: { width: 1920, height: 1080 } });

/** The reg_users table row JSON for our reseller. */
async function reseller(page: Page) {
  const rows = await tableRows(page.request, 'reg_users', username);
  return rows.filter((r) => r.username === username);
}

/** The Users list, filtered down to our reseller. */
async function findRow(page: Page) {
  await page.goto('./users');
  await searchTable(page, username, '#users-table_wrapper .dt-search input, .dt-search input');
  return rowWith(page.locator('#users-table'), username);
}

test.describe.serial('resellers', () => {
  test('create a reseller with starting credits', async ({ page }) => {
    await page.goto('./user');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#member_group_id').selectOption(RESELLERS, { force: true });
    await page.locator('#credits').fill('10');
    await page.locator('#notes').fill(TAG);
    await submitForm(page, page, 'user', page.locator('#user-submit'));
    await page.waitForURL(/users/);

    const [row] = await reseller(page);
    expect(row, 'the reseller is listed').toBeTruthy();
    expect(row.member_group_id).toBe(Number(RESELLERS));
    expect(row.is_reseller).toBe(true);
    expect(row.credits).toBe(10);
    expect(row.status).toBe(1);
  });

  test('top up the credits from the list', async ({ page }) => {
    const menu = await openRowMenu(await findRow(page));
    await menu.locator('.js-credits').click();
    const modal = page.locator('#creditsModal');
    await expect(modal).toBeVisible();
    await modal.locator('#credits-amount').fill('5');
    await modal.locator('#credits-reason').fill(`${TAG} top-up`);

    const adjusted = page.waitForResponse((r) => /\/api\?action=adjust_credits/.test(r.url()));
    await modal.locator('#credits-submit').click();
    expect((await (await adjusted).json())?.result).toBe(true);
    await expect(modal).toBeHidden();
    await expect.poll(async () => (await reseller(page))[0].credits).toBe(15);
  });

  test('edit the notes in the modal', async ({ page }) => {
    const frame = await openEditModal(page, await findRow(page));
    await expect(frame.locator('#username')).toHaveValue(username);
    await frame.locator('#notes').fill(`${TAG} edited`);
    await submitForm(page, frame, 'user', frame.locator('#user-submit'));
    await expect(page.locator('.modal.show')).toHaveCount(0);
    await expect.poll(async () => (await reseller(page))[0].notes).toBe(`${TAG} edited`);
  });

  test('disable and re-enable the reseller', async ({ page }) => {
    expect((await rowAction(page, await findRow(page), 'disable'))?.result).toBe(true);
    await expect.poll(async () => (await reseller(page))[0].status).toBe(0);

    expect((await rowAction(page, await findRow(page), 'enable'))?.result).toBe(true);
    await expect.poll(async () => (await reseller(page))[0].status).toBe(1);
  });

  test('delete the reseller', async ({ page }) => {
    expect((await rowAction(page, await findRow(page), 'delete', { confirm: true }))?.result).toBe(true);
    await expect.poll(async () => (await reseller(page)).length).toBe(0);
  });
});
