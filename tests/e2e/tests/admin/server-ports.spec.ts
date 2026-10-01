import { test, expect } from '@playwright/test';
import { postAnswer } from './support';

/**
 * MAIN's server page refuses an HTTP port another program already holds on
 * MAIN (ServerService::portsTaken): nginx could not bind it, and the nodes
 * would be told a port nothing of the panel's answers on. Nothing is stored.
 *
 * XC_E2E_TAKEN_PORT names such a port; the default is the panel's own Redis.
 */

const TAKEN = process.env.XC_E2E_TAKEN_PORT || '6379';

test('MAIN refuses an HTTP port another program holds, and stores nothing', async ({ page }) => {
  await page.goto('./server?id=1');
  await page.locator('#http_broadcast_ports').evaluate((select: HTMLSelectElement, port) => {
    select.add(new Option(port, port, true, true));
  }, TAKEN);
  const { body } = await postAnswer(page, page, 'server', page.locator('#submit_button'));
  expect(body?.result).toBe(false);
  expect(body?.status, 'STATUS_PORT_IN_USE (ConstantsInitializer)').toBe(49);

  await page.goto('./server?id=1');
  await expect(page.locator(`#http_broadcast_ports option[value="${TAKEN}"]`)).toHaveCount(0);
});
