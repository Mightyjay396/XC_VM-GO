import { test, expect, type Page } from '@playwright/test';

/**
 * Date pickers. The Bootstrap 5 rewrite of the line, MAG and Enigma2 forms
 * dropped the expiry picker and left a bare text box, and nothing noticed: each
 * page here must open a flatpickr calendar from its date field.
 */

async function opensCalendar(page: Page, field: string): Promise<void> {
  await page.locator(field).click();
  await expect(page.locator('.flatpickr-calendar.open')).toBeVisible();
}

for (const path of ['line', 'mag', 'enigma']) {
  test(`${path}: the expiry field opens a calendar and takes the picked day`, async ({ page }) => {
    await page.goto('./' + path);
    await opensCalendar(page, '#exp_date');
    await page.locator('.flatpickr-calendar.open .flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)', { hasText: /^15$/ }).first().click();
    await expect(page.locator('#exp_date')).toHaveValue(/^\d{4}-\d{2}-15 \d{2}:\d{2}:\d{2}$/);

    // A typed date still counts (allowInput), and "never expire" disables the
    // field. The open calendar can cover the checkbox: tab out to close it (with
    // allowInput, Escape in the field leaves it open).
    // The picker works to the minute, as the old one did.
    await page.locator('#exp_date').fill('2031-02-03 04:05');
    await page.keyboard.press('Tab');
    await expect(page.locator('.flatpickr-calendar.open')).toHaveCount(0);
    await expect(page.locator('#exp_date')).toHaveValue('2031-02-03 04:05:00');
    await page.locator('#no_expire').check();
    await expect(page.locator('#exp_date')).toBeDisabled();
  });
}

for (const path of ['line_mass', 'mag_mass', 'enigma_mass']) {
  test(`${path}: the expiry field opens a calendar once enabled`, async ({ page }) => {
    await page.goto('./' + path);
    await page.locator('[data-bs-target="#user-details"]').click();
    await page.locator('input.activate[data-name="exp_date"]').check();
    await opensCalendar(page, '#exp_date');
  });
}

for (const path of ['user_logs', 'client_logs', 'credit_logs', 'line_activity', 'stream_errors']) {
  test(`${path}: the date range filter opens a calendar`, async ({ page }) => {
    await page.goto('./' + path);
    await opensCalendar(page, '#filter-range');
  });
}
