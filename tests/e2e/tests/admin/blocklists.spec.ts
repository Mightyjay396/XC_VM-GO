import { test, expect } from '@playwright/test';
import { TAG, deleteListRow, docIP, listRow, submitForm, uniq } from './support';

/**
 * The block lists an administrator keeps: an IP address, a user agent and an
 * ISP — each blocked from its form, found in its list, and removed again.
 *
 * Blocking an IP makes the panel add an iptables DROP rule for it, so the
 * address comes from 198.51.100.0/24 (RFC 5737), which no real client uses;
 * removing the block drops the rule.
 */

const ip = docIP('blocked');
const userAgent = `${uniq('agent')}/1.0`;
const isp = uniq('isp');

test.describe.serial('block lists', () => {
  test('block an IP address, then lift the block', async ({ page }) => {
    await page.goto('./ip');
    await page.locator('#ip').fill(ip);
    await page.locator('#notes').fill(`${TAG} blocked address`);
    await submitForm(page, page, 'ip', page.locator('#ip-submit'));
    await page.waitForURL(/ips/);

    const row = listRow(page, ip);
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(TAG);

    await deleteListRow(page, row, /api\?action=ip&sub=delete/);
    await page.reload();
    await expect(listRow(page, ip)).toHaveCount(0);
  });

  test('block a user agent, then lift the block', async ({ page }) => {
    await page.goto('./useragent');
    await page.locator('#user_agent').fill(userAgent);
    await page.locator('#exact_match').check();
    await submitForm(page, page, 'useragent', page.locator('#ua-submit'));
    await page.waitForURL(/useragents/);
    await expect(listRow(page, userAgent)).toHaveCount(1);

    // Reopen: the exact-match flag was stored.
    await listRow(page, userAgent).locator('a[href*="useragent?id="]').click();
    await expect(page.locator('#user_agent')).toHaveValue(userAgent);
    await expect(page.locator('#exact_match')).toBeChecked();

    await page.goto('./useragents');
    await deleteListRow(page, listRow(page, userAgent), /api\?action=useragent&sub=delete/);
    await page.reload();
    await expect(listRow(page, userAgent)).toHaveCount(0);
  });

  test('block an ISP, then lift the block', async ({ page }) => {
    await page.goto('./isp');
    await page.locator('#isp').fill(isp);
    await page.locator('#blocked').check();
    await submitForm(page, page, 'isp', page.locator('#isp-submit'));
    await page.waitForURL(/isps/);
    await expect(listRow(page, isp)).toHaveCount(1);

    await deleteListRow(page, listRow(page, isp), /api\?action=isp&sub=delete/);
    await page.reload();
    await expect(listRow(page, isp)).toHaveCount(0);
  });
});
