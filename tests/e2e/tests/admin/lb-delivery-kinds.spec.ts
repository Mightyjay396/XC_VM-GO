import { test, expect, type Page } from '@playwright/test';
import { TAG, adminApi, ident, listRow, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';
import { lb, openViewer, served, serverView, type Viewer } from './cluster-support';

/**
 * ADR 0003 on a load balancer (C4, redirect-to-LB parity, and the kinds no
 * canary had): each kind of live channel MAIN sends its viewers to the load
 * balancer for is served there by the xc_fanout daemon. A plain channel, a
 * direct proxy, the plain one played by a restreamer line, an on-demand
 * channel (LLOD v3) and a loopback (the load balancer's copy pulled from
 * MAIN's). Each viewer comes through MAIN; its stream must keep flowing, and
 * the load balancer's daemon must count it (its watchdog data's `fanout`,
 * from the agent's telemetry).
 *
 * Needs XC_E2E_LB_SERVER with the node's TELEMETRY flow on, and a live source
 * both servers can reach (XC_E2E_STREAM_SOURCE). MAIN's server id is
 * XC_E2E_MAIN_SERVER (1). MAIN's caches take the new lines and channels at
 * their next passes, which can take minutes.
 */

test.skip(!lb, 'XC_E2E_LB_SERVER (a load balancer enrolled in the cluster API) not set');

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';
const MAIN = Number(process.env.XC_E2E_MAIN_SERVER || 1);
const origin = new URL(process.env.XC_E2E_BASE_URL || 'http://localhost/').origin;

const bouquet = uniq('lb-kinds-bouquet');
const viewer = { username: ident('lbkinds'), password: ident('lbkindspass') };
const restreamer = { username: ident('lbkindsrs'), password: ident('lbkindsrspass') };

type Kind = { name: string; proxy?: boolean; onDemand?: boolean; loopback?: boolean; id: number };
const kinds: Record<'plain' | 'proxy' | 'llod' | 'loopback', Kind> = {
  plain: { name: uniq('lb-kind-plain'), id: 0 },
  proxy: { name: uniq('lb-kind-proxy'), proxy: true, id: 0 },
  llod: { name: uniq('lb-kind-llod'), onDemand: true, id: 0 },
  loopback: { name: uniq('lb-kind-loopback'), loopback: true, id: 0 },
};

/**
 * TableController's live-stream statuses (admin/streams.php). A direct proxy
 * the daemon pulls from its first viewer on has no producer pid on its row:
 * with fanout on, the list shows it as On Demand. Its viewer is what tells.
 */
const RUNNING = 1;
const ON_DEMAND = 4;

async function findRow(page: Page, name: string) {
  await page.goto('./streams');
  await searchTable(page, name, '#streams-table_wrapper .dt-search input, .dt-search input');
  return rowWith(page.locator('#streams-table'), name);
}

const status = async (page: Page, k: Kind) => (await tableRows(page.request, 'streams', k.name)).find((r) => r.title === k.name)?.status;

/** The load balancer's daemon's viewers, as its agent last reported them; null without a report. */
async function daemonViewers(page: Page): Promise<number | null> {
  const c = (await serverView(page.request, lb)).watchdog?.fanout?.connections;
  return typeof c === 'number' ? c : null;
}

async function addChannel(page: Page, k: Kind): Promise<void> {
  await page.goto('./stream');
  await page.locator('#stream_display_name').fill(k.name);
  await page.locator('#notes').fill(TAG);
  await page.locator('#bouquets').selectOption({ label: bouquet }, { force: true });
  await page.getByRole('tab', { name: /sources/i }).click();
  await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);
  if (k.proxy) {
    await page.getByRole('tab', { name: /advanced/i }).click();
    await page.locator('#direct_source').check({ force: true });
    await page.locator('#direct_proxy').check({ force: true });
  }
  await page.getByRole('tab', { name: /^servers$/i }).click();
  // The load balancer alone, or (loopback) under MAIN, which then runs the source.
  await page.evaluate(([node, parent]) => {
    const tree = (window as any).$('#server_tree');
    if (parent) {
      tree.jstree('move_node', String(parent), 'source', 'last');
      tree.jstree('move_node', String(node), String(parent), 'last');
    } else {
      tree.jstree('move_node', String(node), 'source', 'last');
    }
  }, [lb, k.loopback ? MAIN : 0]);
  if (k.onDemand) {
    await page.locator('#on_demand').selectOption(String(lb), { force: true });
    await page.locator('#llod').selectOption('2', { force: true });
  }
  await submitForm(page, page, 'stream', page.locator('#stream-submit'));
  await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
  const r = (await tableRows(page.request, 'streams', k.name)).find((x) => x.title === k.name);
  expect(r, `${k.name} is listed`).toBeTruthy();
  k.id = Number(r.id);
}

async function addLine(page: Page, line: { username: string; password: string }, isRestreamer: boolean): Promise<void> {
  await page.goto('./line');
  await page.locator('#username').fill(line.username);
  await page.locator('#password').fill(line.password);
  await page.locator('#max_connections').fill('4');
  await page.locator('#admin_notes').fill(TAG);
  if (isRestreamer) {
    await page.getByRole('tab', { name: /advanced/i }).click();
    await page.locator('#is_restreamer').check({ force: true });
  }
  await page.getByRole('tab', { name: /bouquets/i }).click();
  await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
  await submitForm(page, page, 'line', page.locator('#line-submit'));
  await page.waitForURL(/lines/);
}

/**
 * A viewer of `k` on `line`, through MAIN, that the load balancer's daemon
 * counts while its stream flows. Tried again until `waitMs`: the first one
 * waits out MAIN's caches, and a source that ends and restarts (a finite test
 * clip) leaves gaps where MAIN serves its own not-on-air clip, which flows too,
 * or a restreamer gets STREAM_OFFLINE. A kind the daemon never serves fails
 * with its last attempt's reason.
 */
async function servedByTheDaemon(page: Page, k: Kind, line: { username: string; password: string }, waitMs: number): Promise<void> {
  // The daemon idle first, so the count is this viewer's.
  await expect.poll(() => daemonViewers(page), { timeout: 90_000, intervals: [3_000], message: 'the daemon reports no viewer before this one' }).toBe(0);
  let v: Viewer | null = null;
  try {
    await expect
      .poll(async () => {
        v?.abort.abort();
        v = await openViewer(`${origin}/live/${line.username}/${line.password}/${k.id}.ts`).catch(() => null);
        if (!served(v)) {
          return `${v?.status} ${v?.type}`;
        }
        // Keep reading, as a player does, while the agent reports it (every few seconds).
        const viewer = v;
        void (async () => {
          while (viewer.reader) {
            const r = await viewer.reader.read().catch(() => ({ done: true }));
            if (r.done) {
              break;
            }
          }
        })();
        for (const end = Date.now() + 30_000; Date.now() < end; await new Promise((res) => setTimeout(res, 3_000))) {
          if (((await daemonViewers(page)) ?? 0) > 0) {
            return 'served by the daemon';
          }
        }
        return 'served, but not by the load balancer\'s daemon';
      }, { timeout: waitMs, intervals: [10_000], message: `${k.name} is never served by the load balancer's daemon` })
      .toBe('served by the daemon');
  } finally {
    (v as Viewer | null)?.abort.abort();
  }
}

test.describe.serial('each kind of live channel, served by the load balancer\'s daemon', () => {
  test('a bouquet, two lines and a channel of each kind on the load balancer', async ({ page }) => {
    test.setTimeout(420_000);
    await page.goto('./bouquet');
    await page.locator('#bouquet_name').fill(bouquet);
    await submitForm(page, page, 'bouquet');
    await page.waitForURL(/bouquets/);
    for (const k of Object.values(kinds)) {
      await addChannel(page, k);
    }
    await addLine(page, viewer, false);
    await addLine(page, restreamer, true);

    for (const k of [kinds.plain, kinds.proxy, kinds.loopback]) {
      const started = await rowAction(page, await findRow(page, k.name), 'start');
      expect(started?.result, `${k.name}: start answered ${JSON.stringify(started)}`).toBe(true);
    }
    for (const k of [kinds.plain, kinds.loopback]) {
      await expect.poll(() => status(page, k), { timeout: 150_000, intervals: [3_000], message: `${k.name} never ran` }).toBe(RUNNING);
    }
    expect([RUNNING, ON_DEMAND], 'the proxy: listed running, or on demand while the daemon owns its pull').toContain(await status(page, kinds.proxy));
    expect(await status(page, kinds.llod), 'on demand: started by its first viewer').toBe(ON_DEMAND);
  });

  test('C4: a plain channel', async ({ page }) => {
    test.setTimeout(900_000);
    await servedByTheDaemon(page, kinds.plain, viewer, 780_000);
  });

  test('a direct proxy', async ({ page }) => {
    test.setTimeout(420_000);
    await servedByTheDaemon(page, kinds.proxy, viewer, 300_000);
  });

  test('the plain channel, played by a restreamer line', async ({ page }) => {
    test.setTimeout(900_000);
    await servedByTheDaemon(page, kinds.plain, restreamer, 780_000);
  });

  test('an on-demand channel (LLOD v3), started by its viewer', async ({ page }) => {
    test.setTimeout(420_000);
    await servedByTheDaemon(page, kinds.llod, viewer, 300_000);
  });

  test('a loopback: the load balancer\'s copy pulled from MAIN\'s', async ({ page }) => {
    test.setTimeout(420_000);
    await servedByTheDaemon(page, kinds.loopback, viewer, 300_000);
  });

  test('delete the channels, the lines and the bouquet', async ({ page }) => {
    test.setTimeout(300_000);
    // The channels with a producer of their own; the proxy's pull is the
    // daemon's, so its row offers no stop, and deleting stops the rest.
    for (const k of [kinds.plain, kinds.loopback]) {
      await rowAction(page, await findRow(page, k.name), 'stop');
    }
    for (const line of [viewer, restreamer]) {
      const [l] = (await tableRows(page.request, 'lines', line.username)).filter((r) => r.username === line.username);
      expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: l.id }))?.result).toBe(true);
    }
    for (const k of Object.values(kinds)) {
      const deleted = await rowAction(page, await findRow(page, k.name), 'delete', { confirm: true });
      expect(deleted?.result, `${k.name}: delete answered ${JSON.stringify(deleted)}`).toBe(true);
    }
    await page.goto('./bouquets');
    const id = await listRow(page, bouquet).locator('.js-del').getAttribute('data-id');
    expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
  });
});
