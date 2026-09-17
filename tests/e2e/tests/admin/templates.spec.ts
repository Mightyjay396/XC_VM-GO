import { test, expect, type Locator, type Page, type Route } from '@playwright/test';
import { confirmNext, uniq } from './support';

/**
 * Category templates and the activation-code pages — both went blank without
 * anyone noticing (views never committed; list helpers lost in a stale-base
 * commit), so each page is opened here and the template lifecycle is walked:
 * the menu's create entry, create, rename and make it a system template, clone,
 * delete.
 */

const template = uniq('template');
const templateRenamed = uniq('template-renamed');
const templateCopy = `${templateRenamed} (Copy)`;

/** The template card whose title is exactly `name`. */
const card = (page: Page, name: string): Locator =>
  page.locator('.template-card-wrapper').filter({ has: page.getByRole('heading', { name, exact: true }) });

/**
 * Icon classes on the page that the generated icon subset does not define —
 * those render as solid squares. Each `tabler-*` rule sets `--svg`.
 */
async function missingIcons(page: Page): Promise<string[]> {
  const missing = await page.locator('i[class*="tabler-"]').evaluateAll((icons) =>
    icons
      .filter((i) => getComputedStyle(i).getPropertyValue('--svg').trim() === '')
      .map((i) => [...i.classList].find((c) => c.startsWith('tabler-')) ?? ''),
  );
  return [...new Set(missing)];
}

/**
 * Click a card's dropdown entry (`.js-btn-<kind>`), confirm, and return the API
 * answer. On success the page reloads itself, which drops the response body, so
 * the call is routed through the test to read it first (as submitForm does);
 * the reload is then awaited so the next action does not click a card whose
 * script has not run yet.
 */
async function cardAction(page: Page, name: string, kind: 'clone' | 'delete', api: RegExp): Promise<any> {
  const host = card(page, name);
  await expect(host, `one card named "${name}"`).toHaveCount(1);
  await host.locator('[data-bs-toggle="dropdown"]').click();

  let captured: (text: string) => void = () => undefined;
  const answered = new Promise<string>((resolve) => (captured = resolve));
  const handler = async (route: Route) => {
    const resp = await route.fetch();
    captured(await resp.text());
    await route.fulfill({ response: resp });
  };
  await page.route(api, handler);
  const reloaded = page.waitForEvent('load', { timeout: 30_000 });
  reloaded.catch(() => undefined);
  let text: string;
  try {
    await confirmNext(page, true, () => host.locator(`.js-btn-${kind}`).click());
    text = await Promise.race([
      answered,
      new Promise<string>((_, reject) => setTimeout(() => reject(new Error(`no ${api} call within 30s`)), 30_000)),
    ]);
  } finally {
    await page.unroute(api, handler);
  }
  let body: any = null;
  try {
    body = JSON.parse(text);
  } catch {
    // the caller's assertion reports the null
  }
  if (body?.result) {
    await reloaded;
  }
  return body;
}

test.describe('activation code pages', () => {
  const pages: [string, string][] = [
    ['active_codes', '#admin-active-codes-table'],
    ['active_code', '#admin-active-code-form'],
    ['active_codes_batch', '#batches-table'],
    ['active_codes_mass', '#mass-edit-form'],
  ];
  for (const [path, marker] of pages) {
    test(`${path} renders`, async ({ page }) => {
      const resp = await page.goto('./' + path);
      expect(resp?.status()).toBe(200);
      await expect(page.locator('#layout-menu')).toBeVisible();
      await expect(page.locator(marker)).toBeAttached();
    });
  }

  test('the codes table answers', async ({ page }) => {
    const resp = await page.request.post('table', {
      form: { id: 'active_codes', draw: '1', start: '0', length: '10' },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(resp.status()).toBe(200);
    const body = await resp.json();
    expect(Array.isArray(body?.data), `table?id=active_codes answered ${JSON.stringify(body).slice(0, 300)}`).toBe(true);
  });
});

test.describe.serial('category templates', () => {
  test('the menu entry "Create Category Template" opens the create dialog', async ({ page }) => {
    await page.goto('./category_template');
    await expect(page).toHaveURL(/category_templates\?create=1/);
    await expect(page.locator('#createTemplateModal')).toBeVisible();
  });

  test('create a template; it opens in the editor', async ({ page }) => {
    await page.goto('./category_templates?create=1');
    await page.locator('#new_template_name').fill(template);
    await page.locator('#btnSubmitCreate').click();

    await page.waitForURL(/category_template\?id=\d+/);
    await expect(page.locator('#templateNameInput')).toHaveValue(template);
    expect(await missingIcons(page)).toEqual([]);
  });

  test('rename it and make it a system template', async ({ page }) => {
    await page.goto('./category_templates');
    await card(page, template).locator('a[href*="category_template?id="]').click();
    await expect(page.locator('#templateNameInput')).toHaveValue(template);

    await page.locator('#templateNameInput').fill(templateRenamed);
    await page.locator('#templateIsSystem').check();
    const saved = page.waitForResponse((r) => /\/api\?action=category_template_save/.test(r.url()));
    await page.locator('#btnSaveTemplate').click();
    const body = await (await saved).json();
    expect(body?.result, `save answered ${JSON.stringify(body)}`).toBe(true);
    // The toast carries the server's message, not the word "success".
    await expect(page.locator('#xc-toast-container .toast-body').last()).toHaveText(body.message);

    await page.reload();
    await expect(page.locator('#templateNameInput')).toHaveValue(templateRenamed);
    await expect(page.locator('#templateIsSystem')).toBeChecked();
  });

  test('the list shows it as a system template', async ({ page }) => {
    await page.goto('./category_templates');
    await expect(card(page, templateRenamed)).toHaveCount(1);
    await expect(card(page, templateRenamed).locator('.badge', { hasText: /system/i })).toHaveCount(1);
    await expect(card(page, template)).toHaveCount(0);
    expect(await missingIcons(page)).toEqual([]);
  });

  test('clone it', async ({ page }) => {
    await page.goto('./category_templates');
    const body = await cardAction(page, templateRenamed, 'clone', /\/api\?action=category_template_clone/);
    expect(body?.result, `clone answered ${JSON.stringify(body)}`).toBe(true);
    await expect(card(page, templateCopy)).toHaveCount(1);
  });

  test('delete the clone and the template', async ({ page }) => {
    await page.goto('./category_templates');
    for (const name of [templateCopy, templateRenamed]) {
      const body = await cardAction(page, name, 'delete', /\/api\?action=category_template_delete/);
      expect(body?.result, `delete "${name}" answered ${JSON.stringify(body)}`).toBe(true);
      await expect(card(page, name)).toHaveCount(0);
    }
  });
});
