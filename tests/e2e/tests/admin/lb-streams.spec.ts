import { test, expect, type Page } from '@playwright/test';
import { TAG, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';
import { lb, lbName, queued, row, until, withFlow } from './cluster-support';

/**
 * Phase 4: MAIN controls a load balancer's streams through signed commands
 * (the COMMANDS flow): a live channel run on the load balancer alone is
 * started and stopped from the admin, its state comes back to MAIN's list,
 * and the node's command queue drains (its agent ran and acked each one).
 *
 * The source must be a live stream the load balancer can reach (the default is
 * Unified Streaming's public demo channel; XC_E2E_STREAM_SOURCE overrides it).
 * Without XC_E2E_LB_SERVER the file is skipped.
 */

test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';
const name = uniq('lb-stream');

/** TableController's live-stream statuses (admin/streams.php). */
const RUNNING = 1;
const STOPPED = 0;

test.use({ viewport: { width: 1920, height: 1080 } });

async function streamRow(page: Page) {
  return (await tableRows(page.request, 'streams', name)).find((r) => r.title === name);
}

async function findRow(page: Page) {
  await page.goto('./streams');
  await searchTable(page, name, '#streams-table_wrapper .dt-search input, .dt-search input');
  return rowWith(page.locator('#streams-table'), name);
}

/** Every command MAIN queued for the node has been acked. */
const drained = (page: Page) => until(page, 'the node\'s command queue drained', async (tr) => (await queued(tr)) === 0, 90_000);

test.describe.serial('a live channel on the load balancer, through its commands', () => {
  let server = '';

  test('add a live channel run on the load balancer alone', async ({ page }) => {
    server = await lbName(page);
    await page.goto('./stream');
    await page.locator('#stream_display_name').fill(name);
    await page.locator('#notes').fill(TAG);
    await page.getByRole('tab', { name: /sources/i }).click();
    await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);
    await page.getByRole('tab', { name: /^servers$/i }).click();
    const tree = page.locator('#server_tree');
    await tree.locator('.jstree-anchor', { hasText: server }).click();
    await expect(tree.locator('li#source .jstree-anchor', { hasText: server })).toBeVisible();
    await submitForm(page, page, 'stream', page.locator('#stream-submit'));
    await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
    const r = await streamRow(page);
    expect(r, 'the channel is listed').toBeTruthy();
    expect(r.server_name).toBe(server);
  });

  test('start and stop it through the node\'s commands', async ({ page }) => {
    test.setTimeout(420_000);
    await withFlow(page, 'commands', async () => {
      const started = await rowAction(page, await findRow(page), 'start');
      expect(started?.result, `start answered ${JSON.stringify(started)}`).toBe(true);
      await expect
        .poll(async () => (await streamRow(page))?.status, {
          timeout: 120_000,
          intervals: [2_000],
          message: `${name} never ran on ${server}: is ${SOURCE} reachable from it? (stream_errors has the producer's reason)`,
        })
        .toBe(RUNNING);
      await drained(page);

      const stopped = await rowAction(page, await findRow(page), 'stop');
      expect(stopped?.result, `stop answered ${JSON.stringify(stopped)}`).toBe(true);
      await expect.poll(async () => (await streamRow(page))?.status, { timeout: 90_000, intervals: [2_000] }).toBe(STOPPED);
      await drained(page);
      await expect(await row(page)).not.toContainText(/Fatal error|Uncaught/);
    });
  });

  test('delete it', async ({ page }) => {
    const deleted = await rowAction(page, await findRow(page), 'delete', { confirm: true });
    expect(deleted?.result, `delete answered ${JSON.stringify(deleted)}`).toBe(true);
    await expect.poll(async () => await streamRow(page)).toBeUndefined();
  });
});
