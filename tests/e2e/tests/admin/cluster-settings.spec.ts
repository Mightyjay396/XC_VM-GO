import { test, expect, type Page } from '@playwright/test';
import { postAnswer } from './support';

/**
 * The Settings page's cluster API settings (MAIN <-> LB plan, Phase 1: the
 * Cluster tab, ClusterSettings::normalize, the extension probe) and the Info
 * tab's Cluster block. A refused cluster value fails the whole save
 * (SettingsService::edit), so the refusal checks change nothing on the panel;
 * should the panel ever accept one, the test puts the old value back.
 */

const save = (page: Page) => page.locator('#settings-form button[type="submit"]').first();

/** Open the Cluster tab and return its pane. */
async function clusterTab(page: Page) {
  const resp = await page.goto('./settings');
  expect(resp?.status(), 'settings').toBe(200);
  await page.locator('button[data-bs-target="#cluster"]').click();
  const pane = page.locator('#cluster');
  await expect(pane).toBeVisible();
  return pane;
}

/** Submit the whole form with cluster_api_port set to `port`; the panel must refuse it with `message`. */
async function refusedPort(page: Page, port: string, message: RegExp): Promise<void> {
  const pane = await clusterTab(page);
  const field = pane.locator('#cluster_api_port');
  const before = await field.inputValue();
  await field.fill(port);
  const { body, text } = await postAnswer(page, page, 'settings', save(page));
  try {
    expect(body, `settings answered non-JSON: ${text.slice(0, 300)}`).not.toBeNull();
    expect(body!.result, `port ${port} was accepted`).toBe(false);
    expect(String(body!.data?.message ?? '')).toMatch(message);
  } finally {
    if (body?.result !== false) {
      // A regression saved it: put the panel's value back.
      const again = await clusterTab(page);
      await again.locator('#cluster_api_port').fill(before);
      await postAnswer(page, page, 'settings', save(page));
    }
  }
  // Nothing was saved: the page still shows the old value.
  await expect((await clusterTab(page)).locator('#cluster_api_port')).toHaveValue(before);
}

test.describe('cluster settings', () => {
  test('the Cluster tab says which extension it has and shows every setting', async ({ page }) => {
    const pane = await clusterTab(page);
    // Either "xcvm_core <version> (API n)" or why the cluster crypto is unavailable.
    await expect(pane.locator('.alert').first()).toContainText(/xcvm_core \S+ \(API \d+\)|\((NO_SODIUM_OR_GCM|NO_EXTENSION|API_OUT_OF_RANGE)\)/);
    for (const id of ['cluster_api_enabled', 'cluster_api_port', 'cluster_transport', 'lb_token_rotation_min', 'lb_lease_fence', 'lb_fence_drain_min', 'lb_scan_roots']) {
      await expect(pane.locator(`#${id}`), id).toBeAttached();
    }
    await expect(page.locator('body')).not.toContainText(/Fatal error|Uncaught|Stack trace/);
  });

  test('a reserved port for the cluster API is refused, and nothing is saved', async ({ page }) => {
    // 6379 (Redis) is reserved on every panel, whatever its own ports.
    await refusedPort(page, '6379', /already used by MAIN .* or reserved/);
  });

  test('a privileged port for the cluster API is refused, and nothing is saved', async ({ page }) => {
    await refusedPort(page, '1023', /must be 0 or between 1024 and 65535/);
  });

  test('the Info tab has the Cluster block exactly when the extension is usable', async ({ page }) => {
    const pane = await clusterTab(page);
    const usable = (await pane.locator('.alert').first().getAttribute('class'))?.includes('alert-info') ?? false;
    await page.locator('button[data-bs-target="#info"]').click();
    const info = page.locator('#info');
    await expect(info).toBeVisible();
    for (const label of ['Licence gate', 'Panel key', 'Next token refresh', 'Stream secret']) {
      await expect(info.getByText(label, { exact: true }), label).toHaveCount(usable ? 1 : 0);
    }
  });
});
