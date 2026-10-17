import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { testEventTitle } from './support/environment';
import {
    expectNoHorizontalOverflow,
    signInAsTestUser,
} from './support/helpers';

async function openEvent(page: import('@playwright/test').Page): Promise<void> {
    await signInAsTestUser(page);
    await page.getByRole('link', { name: testEventTitle, exact: true }).click();
    await expect(page.getByRole('heading', { name: testEventTitle, level: 1 })).toBeVisible();
}

async function openGuestAction(
    page: import('@playwright/test').Page,
    guestName: string,
    action: string,
): Promise<void> {
    await page.getByRole('button', { name: `Actions for ${guestName}` }).click();
    await page.getByRole('menuitem', { name: action, exact: true }).click();
}

async function chooseEmail(page: import('@playwright/test').Page): Promise<void> {
    const email = page.getByRole('radio', { name: /Email/ });
    await expect(email).toBeEnabled();
    await email.focus();
    await page.keyboard.press('Space');
    await expect(email).toBeChecked();
}

const reviewScreenshots = path.resolve(process.cwd(), 'test-results', 'phase-five-visual');

test.describe('Phase 5A communication and delivery reliability', () => {
    test('individual invitation resend requires an explicit available channel', async ({ page }) => {
        fs.mkdirSync(reviewScreenshots, { recursive: true });
        await openEvent(page);

        const guestMenu = page.getByRole('button', { name: 'Actions for Avery Browser' });
        await guestMenu.click();
        await page.getByRole('menuitem', { name: 'Resend invitation' }).click();

        const dialog = page.getByRole('dialog', { name: 'Resend invitation' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText('One guest')).toBeVisible();

        const email = dialog.getByRole('radio', { name: /Email/ });
        const sms = dialog.getByRole('radio', { name: /SMS/ });
        const whatsapp = dialog.getByRole('radio', { name: /WhatsApp/ });
        await expect(email).not.toBeChecked();
        await expect(sms).toBeDisabled();
        await expect(whatsapp).toBeDisabled();
        await expect(dialog.getByText(/not enabled for guest messages/i).first()).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Queue 1 invitation' })).toBeDisabled();
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
            path: path.join(reviewScreenshots, 'individual-channel-selection.png'),
            fullPage: false,
        });

        await chooseEmail(page);
        await expect(dialog.getByRole('heading', { name: 'Message preview' })).toBeVisible();
        await expect(dialog.getByText(/Subject/)).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(guestMenu).toBeFocused();

        await guestMenu.click();
        await page.getByRole('menuitem', { name: 'Resend invitation' }).click();
        await chooseEmail(page);
        await page.getByRole('button', { name: 'Queue 1 invitation' }).click();

        await expect(page.getByText('1 invitation queued via Email.')).toBeVisible();
        await expect(page.getByText(/queued attempts, not confirmed deliveries/i)).toBeVisible();
        await page.getByRole('button', { name: 'Done', exact: true }).click();

        await page.getByRole('tab', { name: 'Deliveries' }).click();
        const avery = page.getByRole('region', { name: 'Delivery history for Avery Browser' });
        await expect(avery).toBeVisible();
        await expect(avery.getByText('Queued', { exact: true })).toBeVisible();
        await expect(avery.getByText('Delivered', { exact: true })).toBeVisible();
    });

    test('individual reminder uses the selected channel and records a queued attempt', async ({ page }) => {
        await openEvent(page);
        await openGuestAction(page, 'Devon Example', 'Send reminder');

        const dialog = page.getByRole('dialog', { name: 'Send reminder' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Queue 1 reminder' })).toBeDisabled();

        await chooseEmail(page);
        await expect(dialog.getByRole('heading', { name: 'Message preview' })).toBeVisible();
        await page.getByRole('button', { name: 'Queue 1 reminder' }).click();

        await expect(page.getByText('1 reminder queued via Email.')).toBeVisible();
        await page.getByRole('button', { name: 'Done', exact: true }).click();

        await page.getByRole('tab', { name: 'Deliveries' }).click();
        const devon = page.getByRole('region', { name: 'Delivery history for Devon Example' });
        await expect(devon).toBeVisible();
        await expect(devon.getByText('Queued', { exact: true })).toBeVisible();
    });

    test('selected reminder batch reviews eligible and ineligible recipients and reports both', async ({ page }) => {
        await openEvent(page);

        const selectAll = page.getByRole('checkbox', { name: 'Select all guests on this page' });
        const selectionBar = page.locator('.recipient-selection-bar');
        await selectAll.check();
        await expect(selectionBar.getByText('8 selected')).toBeVisible();
        await expect(selectionBar.getByText(/All 8 guests on this page are selected/i)).toBeVisible();
        await selectionBar.getByRole('button', { name: 'Clear selection' }).click();
        await expect(selectionBar).toHaveCount(0);

        await page.getByRole('checkbox', { name: 'Select Finley Example' }).check();
        await page.getByRole('checkbox', { name: 'Select Briar Example' }).check();

        await expect(selectionBar.getByText('2 selected')).toBeVisible();
        await expect(selectionBar.getByText(/Only guests selected on this page are included/i)).toBeVisible();
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
            path: path.join(reviewScreenshots, 'batch-selection.png'),
            fullPage: false,
        });
        await selectionBar.getByRole('button', { name: 'Send reminders' }).click();

        const dialog = page.getByRole('dialog', { name: 'Send reminders' });
        await expect(dialog).toBeVisible();
        await chooseEmail(page);
        await expect(dialog.getByText(/1 of 2 selected guests can be queued on Email/i)).toBeVisible();
        await expect(dialog.getByText(/No provider-accepted invitation is available to follow up on/i)).toBeVisible();

        await page.getByRole('button', { name: 'Queue 1 reminder' }).click();
        await expect(page.getByText('1 reminder queued via Email.')).toBeVisible();
        await expect(page.getByText(/1 recipient not queued/i)).toBeVisible();
        await page.getByRole('button', { name: 'Done', exact: true }).click();

        await expect(page.locator('.recipient-selection-bar')).toHaveCount(0);

        await page.getByRole('tab', { name: 'Deliveries' }).click();
        const groups = page.locator('.delivery-guest-record');
        await expect(groups.first().getByRole('heading', { name: 'Finley Example' })).toBeVisible();
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
            path: path.join(reviewScreenshots, 'delivery-history-grouped.png'),
            fullPage: false,
        });
    });

    test('selected invitation batch works on mobile without horizontal overflow', async ({ page }) => {
        test.setTimeout(45_000);
        await page.setViewportSize({ width: 390, height: 844 });
        await openEvent(page);

        await page.getByRole('checkbox', { name: 'Select Harper Example' }).check();
        await page.getByRole('checkbox', { name: 'Select Briar Example' }).check();

        const selectionBar = page.locator('.recipient-selection-bar');
        await expect(selectionBar.getByText('2 selected')).toBeVisible();
        await expectNoHorizontalOverflow(page, 'selected guest table at 390px');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
            path: path.join(reviewScreenshots, 'batch-selection-mobile.png'),
            fullPage: false,
        });

        await selectionBar.getByRole('button', { name: 'Send invitations' }).click();
        const dialog = page.getByRole('dialog', { name: 'Send invitations' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('radio', { name: /Email/ })).toBeVisible();
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
            path: path.join(reviewScreenshots, 'communication-channels-mobile.png'),
            fullPage: false,
        });
        await chooseEmail(page);
        await expect(dialog.getByText(/2 of 2 selected guests can be queued on Email/i)).toBeVisible();
        await expectNoHorizontalOverflow(page, 'communication dialog at 390px');

        await page.getByRole('button', { name: 'Queue 2 invitations' }).click();
        await expect(page.getByText('2 invitations queued via Email.')).toBeVisible();
        await page.getByRole('button', { name: 'Done', exact: true }).click();
        await expect(page.locator('.recipient-selection-bar')).toHaveCount(0);
    });

    test('the invitations tab opens the same channel-aware confirmation without sending on open', async ({ page }) => {
        await openEvent(page);
        await page.getByRole('tab', { name: 'Invitations' }).click();
        await page.getByRole('button', { name: 'Review and queue invitations' }).click();

        const dialog = page.getByRole('dialog', { name: 'Send invitations' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText('All guests whose invitation has not yet been provider-accepted')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Queue invitations' })).toBeDisabled();

        await chooseEmail(page);
        await expect(dialog.getByRole('heading', { name: 'Message preview' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();

        await page.getByRole('tab', { name: 'Deliveries' }).click();
        await expect(page.getByRole('heading', { name: 'Delivery history' })).toBeVisible();
    });

    test('submit is disabled while the request is in flight', async ({ page }) => {
        await openEvent(page);
        await page.route('**/api/v1/events/*/guests/*/resend', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 800));
            await route.continue();
        });

        await openGuestAction(page, 'Ellis Example', 'Resend invitation');
        await chooseEmail(page);

        const dialog = page.getByRole('dialog', { name: 'Resend invitation' });
        const submit = dialog.getByRole('button', { name: /Queue/ });
        await submit.click();
        await expect(submit).toBeDisabled();
        await expect(page.getByText('1 invitation queued via Email.')).toBeVisible();
    });
});
