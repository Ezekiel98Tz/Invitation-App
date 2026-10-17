import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import {
    testCelebrationName,
    testEventTitle,
    testStandaloneEventTitle,
} from './support/environment';
import {
    expectNoHorizontalOverflow,
    signInAsEmptyUser,
    signInAsTestUser,
} from './support/helpers';

const reviewScreenshots = path.resolve(process.cwd(), 'test-results', 'phase-four-visual');

async function navigateWorkspace(page: import('@playwright/test').Page, label: string): Promise<void> {
    const mobileToggle = page.getByRole('button', { name: 'Open navigation' });
    if (await mobileToggle.isVisible()) {
        await mobileToggle.click();
    }
    await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: label, exact: true }).click();
}

test.describe('Phase 4 workspace workflows', () => {
    test('event discovery searches the existing list and opens the selected event workspace', async ({ page }) => {
        await signInAsTestUser(page);

        await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: testStandaloneEventTitle, exact: true })).toBeVisible();

        await page.getByLabel('Search events').fill(testStandaloneEventTitle);
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page.getByRole('link', { name: testStandaloneEventTitle, exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toHaveCount(0);

        await page.getByLabel('Search events').fill('no-event-with-this-title');
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'No events match that search' })).toBeVisible();
        await page.getByRole('search').getByRole('button', { name: 'Clear search' }).click();

        await page.getByRole('link', { name: testStandaloneEventTitle, exact: true }).click();
        await expect(page).toHaveURL(/\/events\/\d+$/);
        await expect(page.getByRole('heading', { name: testStandaloneEventTitle, level: 1 })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'Guests' })).toHaveAttribute('aria-selected', 'true');

        const eventNavigation = page.getByRole('navigation', { name: `${testStandaloneEventTitle} operations` });
        await expect(eventNavigation).toBeVisible();
        await expect(eventNavigation.getByRole('link', { name: 'Check-in', exact: true })).toHaveCount(0);
        await expect(eventNavigation.getByRole('link', { name: 'Pledges', exact: true })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Check-in off' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Pledges off' })).toBeVisible();
    });

    test('empty dashboard offers a working create-event path without creating fixture data', async ({ page }) => {
        await signInAsEmptyUser(page);

        await expect(page.getByRole('heading', { name: 'Your workspace is ready for its first event' })).toBeVisible();
        await page.getByRole('button', { name: 'Create your first event' }).click();

        const dialog = page.getByRole('dialog', { name: 'Create event' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByLabel('Title')).toBeVisible();
        await expect(dialog.getByLabel('Venue')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Save' })).toBeEnabled();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(page.getByRole('heading', { name: 'Your workspace is ready for its first event' })).toBeVisible();
    });

    test('dashboard recovers from an event-list error without presenting it as an empty account', async ({ page }) => {
        await signInAsTestUser(page);
        await page.route('**/api/v1/events**', async (route) => {
            const url = new URL(route.request().url());
            if (url.pathname === '/api/v1/events') {
                await route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Temporary test outage.' }),
                });
                return;
            }
            await route.continue();
        });

        await page.reload();
        await expect(page.getByRole('heading', { name: 'We couldn’t load your events' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Your workspace is ready for its first event' })).toHaveCount(0);

        await page.unroute('**/api/v1/events**');
        await page.getByRole('button', { name: 'Try again' }).click();
        await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
    });

    test('guest details, invitation preview, and delivery states use the existing event workflows', async ({ page }) => {
        await signInAsTestUser(page);
        await page.getByRole('link', { name: testEventTitle, exact: true }).click();

        await expect(page.getByRole('heading', { name: testEventTitle, level: 1 })).toBeVisible();
        await expect(page.getByText('Avery Browser', { exact: true })).toBeVisible();
        const guestMenu = page.getByRole('button', { name: 'Actions for Avery Browser' });
        await expect(guestMenu).toBeVisible();

        await guestMenu.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'View details' })).toBeFocused();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByRole('menuitem', { name: 'Edit guest' })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(guestMenu).toBeFocused();

        await guestMenu.click();
        await page.getByRole('menuitem', { name: 'View details' }).click();
        const guestDialog = page.getByRole('dialog', { name: 'Guest details' });
        await expect(guestDialog).toBeVisible();
        await expect(guestDialog.getByText('Avery Browser')).toBeVisible();
        await expect(guestDialog.getByText('accepted', { exact: true })).toBeVisible();
        await expect(guestDialog.getByText('2', { exact: true })).toBeVisible();
        await guestDialog.getByRole('button', { name: 'Close', exact: true }).click();

        await page.getByRole('tab', { name: 'Invitations' }).click();
        await expect(page.getByRole('heading', { name: 'Queue invitations' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Invitation preview' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Email', exact: true })).toBeVisible();

        await page.getByRole('tab', { name: 'Deliveries' }).click();
        await expect(page.getByRole('heading', { name: 'Delivery history' })).toBeVisible();
        await expect(page.getByText('Queued', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Provider accepted', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Delivered', { exact: true })).toHaveCount(4);
        await expect(page.getByText('Failed', { exact: true }).first()).toBeVisible();
        await expect(page.getByText(/only Delivered is confirmed delivery/i)).toBeVisible();

        await page.getByLabel('Show').selectOption('failed');
        await expect(page.getByRole('button', { name: 'Retry failed deliveries' })).toBeVisible();
        await expect(page.getByText('The delivery could not be sent.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
    });

    test('contacts search, details, and event relationships remain clear and navigable', async ({ page }) => {
        await signInAsTestUser(page);
        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Contacts' }).click();

        await expect(page.getByRole('heading', { name: 'Contacts', level: 1 })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Avery Browser', exact: true })).toBeVisible();

        await page.getByLabel('Find a contact').fill('nothing-matches-this');
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'No contacts match this search' })).toBeVisible();
        await page.getByRole('search').getByRole('button', { name: 'Clear search' }).click();

        await page.getByRole('link', { name: 'Avery Browser', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Event guest records' })).toBeVisible();
        await expect(page.getByText('2', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: testStandaloneEventTitle, exact: true })).toBeVisible();

        await page.getByRole('link', { name: testEventTitle, exact: true }).click();
        await expect(page.getByRole('heading', { name: testEventTitle, level: 1 })).toBeVisible();
    });

    test('celebrations distinguish grouped events and link into each event workspace', async ({ page }) => {
        await signInAsTestUser(page);
        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Celebrations' }).click();

        await expect(page.getByRole('heading', { name: 'Celebrations', level: 1 })).toBeVisible();
        await page.getByRole('link', { name: testCelebrationName, exact: true }).click();

        await expect(page.getByRole('heading', { name: 'Events in this celebration' })).toBeVisible();
        await expect(page.getByText(/Events remain independent: each has its own date, venue, guests, invitation, and RSVP/i)).toBeVisible();
        await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: testStandaloneEventTitle, exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Add to celebration' }).first()).toBeVisible();

        await page.getByRole('link', { name: testEventTitle, exact: true }).click();
        await expect(page.getByRole('heading', { name: testEventTitle, level: 1 })).toBeVisible();
    });

    test('empty contacts and celebrations explain the existing guest and grouping workflows', async ({ page }) => {
        await signInAsEmptyUser(page);

        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Contacts' }).click();
        await expect(page.getByRole('heading', { name: 'Contacts appear as you manage guests' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'View events' })).toBeVisible();
        await expect(page.getByRole('button', { name: /create contact/i })).toHaveCount(0);

        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Celebrations' }).click();
        await expect(page.getByRole('heading', { name: 'Group the occasions that belong together' })).toBeVisible();
        await page.getByRole('button', { name: 'Create your first celebration' }).click();
        await expect(page.getByRole('dialog', { name: 'New celebration' })).toBeVisible();
        await expect(page.getByRole('dialog').getByLabel('Name')).toBeVisible();
    });

    test('desktop and mobile workspace layouts stay within the viewport', async ({ page }) => {
        test.setTimeout(60_000);
        fs.mkdirSync(reviewScreenshots, { recursive: true });
        await signInAsTestUser(page);

        const eventHref = await page.getByRole('link', { name: testEventTitle, exact: true }).getAttribute('href');
        expect(eventHref).toBeTruthy();

        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Contacts' }).click();
        const contactHref = await page.getByRole('link', { name: 'Avery Browser', exact: true }).getAttribute('href');
        expect(contactHref).toBeTruthy();

        await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Celebrations' }).click();
        const celebrationHref = await page.getByRole('link', { name: testCelebrationName, exact: true }).getAttribute('href');
        expect(celebrationHref).toBeTruthy();

        for (const viewport of [
            { width: 1440, height: 1000, suffix: 'desktop' },
            { width: 390, height: 844, suffix: 'mobile' },
        ]) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });

            await navigateWorkspace(page, 'Events');
            await expect(page.getByRole('heading', { name: 'Events', level: 1 })).toBeVisible();
            await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
            await expectNoHorizontalOverflow(page, `events at ${viewport.width}px`);
            await page.screenshot({ path: path.join(reviewScreenshots, `events-${viewport.suffix}.png`), fullPage: true });

            if (viewport.suffix === 'mobile') {
                await page.getByRole('link', { name: testStandaloneEventTitle, exact: true }).click();
                await expect(page.getByRole('link', { name: 'Check-in off' })).toBeVisible();
                await expect(page.getByRole('link', { name: 'Pledges off' })).toBeVisible();
                await expect(page.getByText('Avery Browser', { exact: true })).toBeVisible();
                await expectNoHorizontalOverflow(page, 'event without optional capabilities at 390px');
                await page.screenshot({ path: path.join(reviewScreenshots, 'event-disabled-capabilities-mobile.png'), fullPage: true });
                await navigateWorkspace(page, 'Events');
            }

            await page.getByRole('link', { name: testEventTitle, exact: true }).click();
            await expect(page.getByRole('heading', { name: testEventTitle, level: 1 })).toBeVisible();
            await expect(page.getByText('Avery Browser', { exact: true })).toBeVisible();
            await expect(page).toHaveURL(new RegExp(`${eventHref}$`));
            await expectNoHorizontalOverflow(page, `event guests at ${viewport.width}px`);
            if (viewport.suffix === 'mobile') {
                await page.screenshot({ path: path.join(reviewScreenshots, 'event-guests-mobile.png'), fullPage: true });
            } else {
                await page.screenshot({ path: path.join(reviewScreenshots, 'event-guests-desktop.png'), fullPage: true });
            }

            await page.getByRole('tab', { name: 'Deliveries' }).click();
            await expect(page.getByText('Delivered', { exact: true })).toHaveCount(4);
            await expectNoHorizontalOverflow(page, `event deliveries at ${viewport.width}px`);
            if (viewport.suffix === 'mobile') {
                await page.screenshot({ path: path.join(reviewScreenshots, 'event-deliveries-mobile.png'), fullPage: true });
            } else {
                await page.screenshot({ path: path.join(reviewScreenshots, 'event-deliveries-desktop.png'), fullPage: true });
            }

            await navigateWorkspace(page, 'Contacts');
            await expect(page.getByRole('heading', { name: 'Contacts', level: 1 })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Avery Browser', exact: true })).toBeVisible();
            await expectNoHorizontalOverflow(page, `contacts at ${viewport.width}px`);
            if (viewport.suffix === 'desktop') {
                await page.screenshot({ path: path.join(reviewScreenshots, 'contacts-desktop.png'), fullPage: true });
            }

            await page.getByRole('link', { name: 'Avery Browser', exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Event guest records' })).toBeVisible();
            await expect(page.getByRole('link', { name: testEventTitle, exact: true })).toBeVisible();
            await expect(page).toHaveURL(new RegExp(`${contactHref}$`));
            await expectNoHorizontalOverflow(page, `contact details at ${viewport.width}px`);
            if (viewport.suffix === 'mobile') {
                await page.screenshot({ path: path.join(reviewScreenshots, 'contact-detail-mobile.png'), fullPage: true });
            } else {
                await page.screenshot({ path: path.join(reviewScreenshots, 'contact-detail-desktop.png'), fullPage: true });
            }

            await navigateWorkspace(page, 'Celebrations');
            await expect(page.getByRole('heading', { name: 'Celebrations', level: 1 })).toBeVisible();
            await expect(page.getByRole('link', { name: testCelebrationName, exact: true })).toBeVisible();
            await expectNoHorizontalOverflow(page, `celebrations at ${viewport.width}px`);
            if (viewport.suffix === 'desktop') {
                await page.screenshot({ path: path.join(reviewScreenshots, 'celebrations-desktop.png'), fullPage: true });
            }

            await page.getByRole('link', { name: testCelebrationName, exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Events in this celebration' })).toBeVisible();
            await expect(page.getByRole('link', { name: testStandaloneEventTitle, exact: true })).toBeVisible();
            await expect(page.getByRole('region', { name: 'Pledge roll-up' })).toBeVisible();
            await expect(page).toHaveURL(new RegExp(`${celebrationHref}$`));
            await expectNoHorizontalOverflow(page, `celebration details at ${viewport.width}px`);
            if (viewport.suffix === 'mobile') {
                await page.screenshot({ path: path.join(reviewScreenshots, 'celebration-detail-mobile.png'), fullPage: true });
            } else {
                await page.screenshot({ path: path.join(reviewScreenshots, 'celebration-detail-desktop.png'), fullPage: true });
            }
        }
    });
});
