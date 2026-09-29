import { test, expect } from '@playwright/test';
import { TAG, deleteListRow, docIP, listRow, submitForm, uniq } from './support';

/**
 * Access that bypasses a line: an RTMP IP allowed to push, and an HMAC key
 * for signed stream URLs — each created, reopened, changed and deleted.
 *
 * The RTMP address comes from 198.51.100.0/24 (RFC 5737), which no real
 * encoder uses.
 */

const rtmpIP = docIP('rtmp');
const hmac = uniq('hmac');

test.describe.serial('RTMP IPs and HMAC keys', () => {
  test('allow an RTMP IP to push', async ({ page }) => {
    await page.goto('./rtmp_ip');
    await page.locator('#ip').fill(rtmpIP);
    await page.locator('#notes').fill(`${TAG} rtmp`);
    await page.locator('#push').check();
    await submitForm(page, page, 'rtmp_ip', page.locator('#rtmp-submit'));

    await page.waitForURL(/rtmp_ips/);
    await expect(listRow(page, rtmpIP)).toHaveCount(1);
  });

  test('the RTMP IP got a password and keeps push only', async ({ page }) => {
    await page.goto('./rtmp_ips');
    await listRow(page, rtmpIP).locator('a[href*="rtmp_ip?id="]').click();
    await expect(page.locator('#ip')).toHaveValue(rtmpIP);
    // Left blank on create: the panel generates one.
    await expect(page.locator('#password')).not.toHaveValue('');
    await expect(page.locator('#push')).toBeChecked();
    await expect(page.locator('#pull')).not.toBeChecked();

    await page.locator('#pull').check();
    await submitForm(page, page, 'rtmp_ip', page.locator('#rtmp-submit'));
    await page.waitForURL(/rtmp_ips/);
    await listRow(page, rtmpIP).locator('a[href*="rtmp_ip?id="]').click();
    await expect(page.locator('#pull')).toBeChecked();
  });

  test('remove the RTMP IP', async ({ page }) => {
    await page.goto('./rtmp_ips');
    await deleteListRow(page, listRow(page, rtmpIP), /api\?action=rtmp_ip&sub=delete/);
    await page.reload();
    await expect(listRow(page, rtmpIP)).toHaveCount(0);
  });

  test('create an HMAC key', async ({ page }) => {
    await page.goto('./hmac');
    // A new key is generated as the page opens.
    await expect(page.locator('#keygen')).toHaveValue(/^[A-Za-z0-9]{32}$/);
    await expect(page.locator('#enabled')).toBeChecked();
    await page.locator('#notes').fill(hmac);
    await submitForm(page, page, 'hmac', page.locator('#hmac-submit'));

    await page.waitForURL(/hmacs/);
    await expect(listRow(page, hmac)).toHaveCount(1);
  });

  test('disable the HMAC key; its secret is not shown again', async ({ page }) => {
    await page.goto('./hmacs');
    await listRow(page, hmac).locator('a[href*="hmac?id="]').click();
    await expect(page.locator('#notes')).toHaveValue(hmac);
    await expect(page.locator('#keygen')).toHaveValue('HMAC KEY HIDDEN');

    await page.locator('#enabled').uncheck();
    await submitForm(page, page, 'hmac', page.locator('#hmac-submit'));
    await page.waitForURL(/hmacs/);
    await listRow(page, hmac).locator('a[href*="hmac?id="]').click();
    await expect(page.locator('#enabled')).not.toBeChecked();
  });

  test('delete the HMAC key', async ({ page }) => {
    await page.goto('./hmacs');
    await deleteListRow(page, listRow(page, hmac), /api\?action=hmac&sub=delete/);
    await page.reload();
    await expect(listRow(page, hmac)).toHaveCount(0);
  });
});
