import { test, expect, type Page } from '@playwright/test';
import { TAG, openEditModal, revealInRow, rowAction, rowWith, searchTable, submitForm, tableRows, uniq } from './support';

/**
 * A live channel from creation to deletion: added with a source and a server,
 * started, watched until it runs — with its codecs and the Resources column
 * (producer, CPU, RAM) filled in by cron:streams — stopped, renamed and deleted.
 *
 * The source must be a live stream the panel's server can reach. The default is
 * Unified Streaming's public 24/7 demo channel; XC_E2E_STREAM_SOURCE overrides
 * it, and XC_E2E_SERVER names the server to run it on (default: Main Server).
 */

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';
const SERVER = process.env.XC_E2E_SERVER || 'Main Server';

const name = uniq('stream');
const renamed = uniq('stream-renamed');

/** TableController's live-stream statuses (admin/streams.php). */
const RUNNING = 1;
const STOPPED = 0;

test.use({ viewport: { width: 1920, height: 1080 } });

/** The streams-table row JSON for a stream title. */
async function streamRow(page: Page, title: string) {
  const rows = await tableRows(page.request, 'streams', title);
  return rows.find((r) => r.title === title);
}

/** The Streams list, filtered down to one stream. */
async function findRow(page: Page, title: string) {
  await page.goto('./streams');
  await searchTable(page, title, '#streams-table_wrapper .dt-search input, .dt-search input');
  return rowWith(page.locator('#streams-table'), title);
}

test.describe.serial('live stream lifecycle', () => {
  test('add a live stream with a source and a server', async ({ page }) => {
    await page.goto('./stream');
    await page.locator('#stream_display_name').fill(name);
    await page.locator('#notes').fill(TAG);

    await page.getByRole('tab', { name: /sources/i }).click();
    await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);

    // The server tree: clicking a server moves it from Offline to Online.
    await page.getByRole('tab', { name: /^servers$/i }).click();
    const tree = page.locator('#server_tree');
    await tree.locator('.jstree-anchor', { hasText: SERVER }).click();
    await expect(tree.locator('li#source .jstree-anchor', { hasText: SERVER })).toBeVisible();
    await expect(page.locator('#restart_on_edit')).not.toBeChecked();

    await submitForm(page, page, 'stream', page.locator('#stream-submit'));
    // A new stream opens on its own page; the list is the fallback.
    await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });

    const row = await streamRow(page, name);
    expect(row, 'the stream is listed').toBeTruthy();
    expect(row.server_name).toBe(SERVER);
    expect(row.status).not.toBe(RUNNING);
  });

  test('start it and see it run, with codecs and resources', async ({ page }) => {
    // Starting, probing and one cron:streams pass (once a minute) to sample it.
    test.setTimeout(240_000);

    const started = await rowAction(page, await findRow(page, name), 'start');
    expect(started?.result, `start answered ${JSON.stringify(started)}`).toBe(true);

    await expect
      .poll(async () => (await streamRow(page, name))?.status, {
        timeout: 90_000,
        intervals: [2_000],
        message: `${name} never reached "running" — is ${SOURCE} live and reachable from ${SERVER}? (stream_errors has the producer's reason)`,
      })
      .toBe(RUNNING);
    await expect
      .poll(async () => (await streamRow(page, name))?.info?.video, { timeout: 150_000, intervals: [5_000] })
      .toMatch(/^(h264|hevc|h265)$/i);

    await expect
      .poll(async () => (await streamRow(page, name))?.usage?.producer ?? '', { timeout: 150_000, intervals: [5_000] })
      .toMatch(/^(ffmpeg|fanout)$/);
    const { usage } = await streamRow(page, name);
    expect(usage.mem, 'the producer holds memory').toBeGreaterThan(0);
    expect(typeof usage.cpu, 'a CPU reading').toBe('number');

    // And the list shows it: the producer badge and the RAM figure.
    const row = await findRow(page, name);
    const ram = await revealInRow(row, '[title="RAM"]');
    await expect(ram).toHaveText(/\d+(\.\d)? (MB|GB)/);
    const cell = ram.locator('xpath=..');
    await expect(cell).toContainText(usage.producer);
  });

  test('stop it', async ({ page }) => {
    const stopped = await rowAction(page, await findRow(page, name), 'stop');
    expect(stopped?.result, `stop answered ${JSON.stringify(stopped)}`).toBe(true);
    await expect
      .poll(async () => (await streamRow(page, name))?.status, { timeout: 60_000, intervals: [2_000] })
      .toBe(STOPPED);
    // A stopped stream shows no stale resource reading.
    expect((await streamRow(page, name))?.usage ?? null).toBeNull();
  });

  test('rename it in the edit modal', async ({ page }) => {
    const frame = await openEditModal(page, await findRow(page, name));
    await expect(frame.locator('#stream_display_name')).toHaveValue(name);
    await frame.locator('#stream_display_name').fill(renamed);
    await submitForm(page, frame, 'stream', frame.locator('#stream-submit'));
    await expect(page.locator('.modal.show')).toHaveCount(0);

    await expect.poll(async () => (await streamRow(page, renamed))?.title).toBe(renamed);
    expect(await streamRow(page, name)).toBeUndefined();
  });

  test('delete it', async ({ page }) => {
    const deleted = await rowAction(page, await findRow(page, renamed), 'delete', { confirm: true });
    expect(deleted?.result, `delete answered ${JSON.stringify(deleted)}`).toBe(true);
    await expect.poll(async () => await streamRow(page, renamed)).toBeUndefined();
  });
});
