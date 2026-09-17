import { test, expect } from '@playwright/test';

/**
 * Report pages the cache engine computes per time range. Choosing a range
 * reloads the page with ?range=, and the page must come back showing that
 * range's rows — theft detection lost both the choice and its rows to a
 * controller that handed the view differently named variables.
 */

const pages: [string, string][] = [
  ['theft_detection', '#filter-range'],
  ['line_ips', '#range'],
];

for (const [path, select] of pages) {
  test(`${path} keeps the chosen range`, async ({ page }) => {
    const resp = await page.goto(`./${path}?range=3600`);
    expect(resp?.status()).toBe(200);
    await expect(page.locator('#layout-menu')).toBeVisible();
    await expect(page.locator(select)).toHaveValue('3600');
  });
}
