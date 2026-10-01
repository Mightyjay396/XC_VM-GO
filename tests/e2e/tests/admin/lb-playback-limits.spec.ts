import { test, expect, type Page } from '@playwright/test';
import { TAG, adminApi, ident, listRow, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';
import { lb, lbName } from './cluster-support';

/**
 * Phase 6: a line's connection limit holds for viewers on a load balancer. A
 * line with max_connections = 1 opens a second viewer, and the first one's
 * stream is cut; an HLS viewer refreshing its playlist is the same viewer and
 * is never cut by its own refreshes.
 *
 * The channel runs on the load balancer alone, so MAIN sends each viewer
 * there. The source must be a live stream the load balancer can reach
 * (XC_E2E_STREAM_SOURCE overrides the public demo channel). The line and
 * stream caches pick the new records up on their next passes, which can take
 * minutes. Without XC_E2E_LB_SERVER the file is skipped.
 */

test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';
const origin = new URL(process.env.XC_E2E_BASE_URL || 'http://localhost/').origin;

const bouquet = uniq('lb-limit-bouquet');
const channel = uniq('lb-limit-channel');
const line = { username: ident('lblimit'), password: ident('lblimitpass') };

let channelID = 0;

const RUNNING = 1;

type Viewer = { status: number; reader: ReadableStreamDefaultReader<Uint8Array> | null; abort: AbortController };

/** Open a viewer of the channel: its first bytes read, the stream left open. */
async function open(path: string): Promise<Viewer> {
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), 30_000);
  try {
    const resp = await fetch(`${origin}/live/${line.username}/${line.password}/${channelID}.${path}`, { redirect: 'follow', signal: abort.signal });
    const reader = resp.ok && resp.body ? resp.body.getReader() : null;
    if (reader) {
      await reader.read();
    }
    return { status: resp.status, reader, abort };
  } finally {
    clearTimeout(timer);
  }
}

/** Does the viewer's stream end within `ms` (cut by the server)? False while it still flows. */
async function endsWithin(v: Viewer, ms: number): Promise<boolean> {
  if (!v.reader) {
    return true;
  }
  const deadline = Date.now() + ms;
  try {
    for (;;) {
      const left = deadline - Date.now();
      if (left <= 0) {
        return false;
      }
      const r = await Promise.race([v.reader.read(), new Promise<null>((res) => setTimeout(() => res(null), left))]);
      if (r === null) {
        return false;
      }
      if (r.done) {
        return true;
      }
    }
  } catch {
    return true; // the connection was cut
  }
}

async function findRow(page: Page) {
  await page.goto('./streams');
  await searchTable(page, channel, '#streams-table_wrapper .dt-search input, .dt-search input');
  return rowWith(page.locator('#streams-table'), channel);
}

test.describe.serial('a line\'s connection limit on the load balancer', () => {
  test('a channel on the load balancer, in a bouquet, and a line limited to one viewer', async ({ page }) => {
    test.setTimeout(240_000);
    const server = await lbName(page);
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);

    await page.goto('./stream');
    await page.locator('#stream_display_name').fill(channel);
    await page.locator('#notes').fill(TAG);
    await page.locator('#bouquets').selectOption({ label: bouquet }, { force: true });
    await page.getByRole('tab', { name: /sources/i }).click();
    await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);
    await page.getByRole('tab', { name: /^servers$/i }).click();
    await page.locator('#server_tree .jstree-anchor', { hasText: server }).click();
    await submitForm(page, page, 'stream', page.locator('#stream-submit'));
    await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
    const row = (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel);
    expect(row, 'the channel is listed').toBeTruthy();
    channelID = Number(row.id);

    await page.goto('./line');
    await page.locator('#username').fill(line.username);
    await page.locator('#password').fill(line.password);
    await page.locator('#max_connections').fill('1');
    await page.locator('#admin_notes').fill(TAG);
    await page.getByRole('tab', { name: /bouquets/i }).click();
    await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
    await submitForm(page, page, 'line', page.locator('#line-submit'));
    await page.waitForURL(/lines/);

    const started = await rowAction(page, await findRow(page), 'start');
    expect(started?.result, `start answered ${JSON.stringify(started)}`).toBe(true);
    await expect
      .poll(async () => (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel)?.status, { timeout: 120_000, intervals: [2_000] })
      .toBe(RUNNING);
  });

  test('a second viewer on the line cuts the first', async () => {
    test.setTimeout(600_000);
    // The caches take the new line and channel at their next passes.
    let first: Viewer | null = null;
    await expect
      .poll(async () => {
        first?.abort.abort();
        first = await open('ts').catch(() => null);
        return first?.status ?? 0;
      }, { timeout: 480_000, intervals: [15_000] })
      .toBe(200);
    const second = await open('ts');
    try {
      expect(second.status, 'the second viewer is served').toBe(200);
      expect(await endsWithin(first!, 20_000), 'the first viewer is cut once the second opens').toBe(true);
      expect(await endsWithin(second, 5_000), 'the second keeps playing').toBe(false);
    } finally {
      first!.abort.abort();
      second.abort.abort();
    }
  });

  test('an HLS viewer\'s own playlist refreshes never cut it', async () => {
    test.setTimeout(120_000);
    const resp = await fetch(`${origin}/live/${line.username}/${line.password}/${channelID}.m3u8`, { redirect: 'follow' });
    expect(resp.status, 'the playlist').toBe(200);
    expect(await resp.text()).toContain('#EXTM3U');
    // The same viewer asks for its playlist again, as a player does.
    for (let i = 0; i < 3; i++) {
      await new Promise((res) => setTimeout(res, 3_000));
      const again = await fetch(resp.url, { redirect: 'follow' });
      expect(again.status, `refresh ${i + 1}`).toBe(200);
      expect(await again.text(), `refresh ${i + 1}`).toContain('#EXTINF');
    }
  });

  test('delete the line, channel and bouquet', async ({ page }) => {
    await rowAction(page, await findRow(page), 'stop');
    const [l] = (await tableRows(page.request, 'lines', line.username)).filter((r) => r.username === line.username);
    expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: l.id }))?.result).toBe(true);
    const deleted = await rowAction(page, await findRow(page), 'delete', { confirm: true });
    expect(deleted?.result, `delete answered ${JSON.stringify(deleted)}`).toBe(true);
    await page.goto('./bouquets');
    const id = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
  });
});
