import { test, expect, type APIRequestContext } from '@playwright/test';
import { TAG, adminApi, ident, listRow, submitForm, tableRows, uniq } from './support';

/**
 * Playback is for what a line's bouquets hold. A direct-source channel shows it
 * without running anything: stream auth answers the line whose bouquet holds it
 * with a redirect to the source, and must refuse every other line — before that
 * redirect, or the refused line reads the source URL (often carrying the
 * provider's credentials) from the Location header. The movie route is checked
 * too: it never looked at bouquets at all.
 */

const SOURCE = process.env.XC_E2E_STREAM_SOURCE || 'https://demo.unified-streaming.com/k8s/live/stable/scte35.isml/.m3u8';

const withChannel = uniq('play-bouquet');
const without = uniq('play-other-bouquet');
const channel = uniq('play-channel');
const allowed = { username: ident('playok'), password: ident('playokpass') };
const refused = { username: ident('playno'), password: ident('playnopass') };

const origin = new URL(process.env.XC_E2E_BASE_URL || 'http://localhost/').origin;

let channelID = 0;

/** Request a playback path without following redirects. */
async function play(request: APIRequestContext, kind: 'live' | 'movie', line: { username: string; password: string }) {
  const resp = await request.get(`${origin}/${kind}/${line.username}/${line.password}/${channelID}.ts`, { maxRedirects: 0 });
  const location = resp.headers()['location'] ?? '';
  // A redirect's body is irrelevant; an answer's first bytes name a refusal.
  const body = location ? '' : (await resp.body()).subarray(0, 4096).toString();
  return { status: resp.status(), location, body };
}

test.describe.serial('playback follows the line\'s bouquets', () => {
  test('create a direct-source channel in one bouquet, and a line on each bouquet', async ({ page }) => {
    for (const name of [withChannel, without]) {
      await page.goto('./bouquet');
      await page.locator('#bouquet_name').fill(name);
      await submitForm(page, page, 'bouquet');
      await page.waitForURL(/bouquets/);
    }

    await page.goto('./stream');
    await page.locator('#stream_display_name').fill(channel);
    await page.locator('#notes').fill(TAG);
    await page.locator('#bouquets').selectOption({ label: withChannel }, { force: true });
    await page.getByRole('tab', { name: /sources/i }).click();
    await page.locator('input[name="stream_source[]"]').first().fill(SOURCE);
    await page.locator('[data-bs-target="#tab-advanced"]').click();
    await page.locator('#direct_source').check();
    await submitForm(page, page, 'stream', page.locator('#stream-submit'));
    await page.waitForURL(/\/(stream_view\?id=\d+|streams)/, { waitUntil: 'commit' });
    const row = (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel);
    expect(row, 'the channel is listed').toBeTruthy();
    channelID = Number(row.id);

    for (const [line, bouquet] of [[allowed, withChannel], [refused, without]] as const) {
      await page.goto('./line');
      await page.locator('#username').fill(line.username);
      await page.locator('#password').fill(line.password);
      await page.locator('#admin_notes').fill(TAG);
      await page.getByRole('tab', { name: /bouquets/i }).click();
      await page.locator('#tab-bouquets').getByLabel(bouquet, { exact: true }).check();
      await submitForm(page, page, 'line', page.locator('#line-submit'));
      await page.waitForURL(/lines/);
    }
  });

  test('the line whose bouquet holds the channel is sent to its source', async ({ request }) => {
    // The caches (lines, streams, the bouquet map) pick the new records up on
    // their next passes.
    test.setTimeout(480_000);
    await expect
      .poll(async () => (await play(request, 'live', allowed)).location, { timeout: 420_000, intervals: [15_000] })
      .toBe(SOURCE);
    expect(await play(request, 'movie', allowed)).toMatchObject({ status: 302, location: SOURCE });
  });

  test('a line without it is refused, and never sees the source', async ({ request }) => {
    for (const kind of ['live', 'movie'] as const) {
      const answer = await play(request, kind, refused);
      expect(answer.location, `${kind}: Location`).toBe('');
      // Production answers a bare 404; with Settings → debug_show_errors on,
      // a 200 page naming the error.
      if (answer.status !== 404) {
        expect(answer.body, `${kind}: answered ${answer.status}`).toContain('NOT_IN_BOUQUET');
      }
    }
  });

  test('delete the lines, channel and bouquets', async ({ page }) => {
    for (const line of [allowed, refused]) {
      const [row] = (await tableRows(page.request, 'lines', line.username)).filter((r) => r.username === line.username);
      expect((await adminApi(page.request, 'line', { sub: 'delete', user_id: row.id }))?.result).toBe(true);
    }
    const stream = (await tableRows(page.request, 'streams', channel)).find((r) => r.title === channel);
    expect((await adminApi(page.request, 'stream', { sub: 'delete', stream_id: stream.id, server_id: stream.server_col_id ?? '' }))?.result).toBe(true);
    await page.goto('./bouquets');
    for (const name of [withChannel, without]) {
      const id = await listRow(page, name).locator('.js-del').getAttribute('data-id');
      expect((await adminApi(page.request, 'bouquet', { sub: 'delete', bouquet_id: id! }))?.result).toBe(true);
    }
  });
});
