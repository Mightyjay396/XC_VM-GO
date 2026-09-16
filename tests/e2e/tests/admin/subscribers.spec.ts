import { test, expect, type Page } from '@playwright/test';
import {
  TAG,
  deleteListRow,
  ident,
  listRow,
  mac,
  openEditModal,
  rowAction,
  rowWith,
  searchTable,
  submitForm,
  tableRows,
  uniq,
} from './support';

/**
 * A subscriber's life as an administrator handles it: a line created with a
 * bouquet, found in the list, edited in the modal, disabled / enabled, banned /
 * unbanned and deleted — then the same for a MAG and an Enigma2 device.
 */

const bouquet = uniq('sub-bouquet');
const username = ident('line');
const password = ident('pass');
const magMac = mac('mag');
const e2Mac = mac('enigma');

test.use({ viewport: { width: 1920, height: 1080 } });

/** The lines-table row JSON for our line (the list's own data source). */
async function lineState(page: Page) {
  const rows = await tableRows(page.request, 'lines', username);
  return rows.filter((r) => r.username === username);
}

test.describe.serial('subscribers: lines, MAG and Enigma2 devices', () => {
  test('create the bouquet the subscribers will get', async ({ page }) => {
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);
  });

  test('create a line with the bouquet', async ({ page }) => {
    await page.goto('./line');
    await page.locator('#username').fill(username);
    await page.locator('#password').fill(password);
    await page.locator('#max_connections').fill('1');
    await page.locator('#admin_notes').fill(TAG);
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
    await submitForm(page, page, 'line', page.locator('#line-submit'));
    await page.waitForURL(/lines/);

    const [line] = await lineState(page);
    expect(line, 'the new line is listed').toBeTruthy();
    expect(line.password).toBe(password);
    expect(line.max_connections).toBe(1);
    expect(line.enabled).toBe(true);
    expect(line.admin_enabled).toBe(true);
  });

  test('find the line and raise its connections in the edit modal', async ({ page }) => {
    await page.goto('./lines');
    await searchTable(page, username);
    const row = await rowWith(page.locator('#lines-table'), username);

    const frame = await openEditModal(page, row);
    await expect(frame.locator('#username')).toHaveValue(username);
    await frame.getByRole('tab', { name: /bouquets/i }).click();
    await expect(frame.locator('#tab-bouquets').getByLabel(bouquet, { exact: true })).toBeChecked();
    await frame.getByRole('tab', { name: /details/i }).click();
    await frame.locator('#max_connections').fill('3');

    await submitForm(page, frame, 'line', frame.locator('#line-submit'));
    // The saved modal closes itself and the list reloads.
    await expect(page.locator('#frameModal')).toBeHidden();
    await expect.poll(async () => (await lineState(page))[0].max_connections).toBe(3);
  });

  test('disable and re-enable the line', async ({ page }) => {
    await page.goto('./lines');
    await searchTable(page, username);
    const table = page.locator('#lines-table');

    expect((await rowAction(page, await rowWith(table, username), 'disable'))?.result).toBe(true);
    await expect.poll(async () => (await lineState(page))[0].enabled).toBe(false);

    await expect((await rowWith(table, username)).locator('[data-sub="enable"]')).toBeAttached();
    expect((await rowAction(page, await rowWith(table, username), 'enable'))?.result).toBe(true);
    await expect.poll(async () => (await lineState(page))[0].enabled).toBe(true);
  });

  test('ban and unban the line', async ({ page }) => {
    await page.goto('./lines');
    await searchTable(page, username);
    const table = page.locator('#lines-table');

    expect((await rowAction(page, await rowWith(table, username), 'ban'))?.result).toBe(true);
    await expect.poll(async () => (await lineState(page))[0].admin_enabled).toBe(false);

    await expect((await rowWith(table, username)).locator('[data-sub="unban"]')).toBeAttached();
    expect((await rowAction(page, await rowWith(table, username), 'unban'))?.result).toBe(true);
    await expect.poll(async () => (await lineState(page))[0].admin_enabled).toBe(true);
  });

  test('delete the line', async ({ page }) => {
    await page.goto('./lines');
    await searchTable(page, username);
    const result = await rowAction(page, await rowWith(page.locator('#lines-table'), username), 'delete', { confirm: true });
    expect(result?.result).toBe(true);
    await expect.poll(async () => (await lineState(page)).length).toBe(0);
  });

  for (const device of [
    { kind: 'MAG', form: 'mag', list: 'mags', table: '#mags-table', mac: magMac },
    { kind: 'Enigma2', form: 'enigma', list: 'enigmas', table: '#e2-table', mac: e2Mac },
  ]) {
    test(`${device.kind} device: create, find and delete`, async ({ page }) => {
      await page.goto('./' + device.form);
      await page.locator('#mac').fill(device.mac);
      await page.locator('#admin_notes').fill(`${TAG} ${device.kind}`);
      await page.getByRole('tab', { name: /bouquets/i }).click();
      await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
      await submitForm(page, page, device.form, page.locator(`#${device.form}-submit`));
      await page.waitForURL(new RegExp(device.list));

      const listed = (await tableRows(page.request, device.list, device.mac)).filter((r) => r.mac === device.mac);
      expect(listed, `${device.kind} ${device.mac} is listed`).toHaveLength(1);

      await page.goto('./' + device.list);
      await searchTable(page, device.mac, `${device.table}_wrapper .dt-search input, .dt-search input`);
      const row = await rowWith(page.locator(device.table), device.mac);
      expect((await rowAction(page, row, 'delete', { confirm: true }))?.result).toBe(true);
      await expect
        .poll(async () => (await tableRows(page.request, device.list, device.mac)).filter((r) => r.mac === device.mac).length)
        .toBe(0);
    });
  }

  test('delete the bouquet', async ({ page }) => {
    await page.goto('./bouquets');
    await deleteListRow(page, listRow(page, bouquet), /api\?action=bouquet&sub=delete/);
  });
});
