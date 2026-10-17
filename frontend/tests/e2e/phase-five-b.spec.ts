import { expect, test, type Page } from '@playwright/test';
import {
    expectNoHorizontalOverflow,
    signInAsTestUser,
} from './support/helpers';
import {
    testEventTitle,
    testPastEventTitle,
    testUpcomingEventTitle,
} from './support/environment';

interface WorkspaceEvent {
    id: number;
    title: string;
}

interface WorkspaceGuest {
    id: number;
    name: string;
    email: string | null;
    status: string;
    invite_token: string;
}

async function workspaceEvent(page: Page, title: string): Promise<WorkspaceEvent> {
    const response = await page.request.get('/api/v1/events?per_page=100');
    expect(response.ok()).toBeTruthy();
    const payload = await response.json();
    const event = (payload.data as WorkspaceEvent[]).find((item) => item.title === title);
    expect(event, `fixture event ${title}`).toBeTruthy();

    return event as WorkspaceEvent;
}

async function workspaceGuests(page: Page, eventId: number): Promise<WorkspaceGuest[]> {
    const response = await page.request.get(`/api/v1/events/${eventId}/guests?per_page=100`);
    expect(response.ok()).toBeTruthy();
    const payload = await response.json();

    return payload.data as WorkspaceGuest[];
}

async function guestByEmail(page: Page, eventId: number, email: string): Promise<WorkspaceGuest> {
    const guest = (await workspaceGuests(page, eventId)).find((item) => item.email === email);
    expect(guest, `fixture guest ${email}`).toBeTruthy();

    return guest as WorkspaceGuest;
}

async function csrfHeader(page: Page): Promise<Record<string, string>> {
    await page.request.get('/api/v1/csrf');
    const cookie = (await page.context().cookies()).find((item) => item.name === 'XSRF-TOKEN');
    expect(cookie, 'XSRF cookie').toBeTruthy();

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? ''),
    };
}

async function capturePassQr(page: Page, passUrl: string, guestName: string): Promise<Buffer> {
    await page.goto(passUrl);

    const qrImage = page.getByRole('img', { name: `Entry QR code for ${guestName}` });
    await expect(qrImage).toBeVisible();

    // Rasterize the same QR the guest sees onto an opaque white canvas. This
    // keeps the test image in memory only and avoids transparent-SVG decoding
    // variability in the browser scanner.
    const base64 = await qrImage.evaluate(async (element) => {
        if (!(element instanceof HTMLImageElement)) return '';
        await element.decode();

        const size = 520;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;

        const context = canvas.getContext('2d');
        if (!context) return '';

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, size, size);
        context.drawImage(element, 0, 0, size, size);

        return canvas.toDataURL('image/png').split(',')[1] ?? '';
    });

    expect(base64).not.toBe('');

    return Buffer.from(base64, 'base64');
}

async function uploadScannedPass(page: Page, buffer: Buffer): Promise<void> {
    await page.getByRole('button', { name: 'Scan QR', exact: true }).click();
    await page.getByLabel('Scan a QR image file').setInputFiles({
        name: 'scanned-pass.png',
        mimeType: 'image/png',
        buffer,
    });
}

test.describe('Phase 5B digital pass and entry validation', () => {
    test('valid pass QR validates, does not check in, and explicit confirmation records attendance', async ({ page }) => {
        await signInAsTestUser(page);

        const event = await workspaceEvent(page, testEventTitle);
        const guest = await guestByEmail(page, event.id, 'avery.browser@example.test');
        const qr = await capturePassQr(
            page,
            `/e/${guest.invite_token}/pass`,
            'Avery Browser',
        );

        await expect(page.getByText(testEventTitle, { exact: true }).first()).toBeVisible();
        await expect(page.getByText('For Avery Browser', { exact: true })).toBeVisible();
        await expect(page.getByText(/Present this pass at the entrance/i)).toBeVisible();
        await expect(page.getByRole('img', { name: 'Entry QR code for Avery Browser' })).toBeVisible();
        await expect(page.getByText(/Showing the pass does not check you in/i)).toBeVisible();
        await expect(page.getByText(/separate entry code sent to you/i)).toBeVisible();

        const before = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        const checkedInBefore = (await before.json()).checked_in;

        await page.goto(`/events/${event.id}/check-in`);
        await uploadScannedPass(page, qr);

        await expect(page.getByText('Valid credential', { exact: true })).toBeVisible();
        await expect(page.getByText('Avery Browser', { exact: true })).toBeVisible();

        const afterLookup = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        expect((await afterLookup.json()).checked_in).toBe(checkedInBefore);

        await page.getByRole('button', { name: 'Confirm check-in' }).click();
        const dialog = page.getByRole('dialog', { name: 'Confirm check-in' });
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Check in', exact: true }).click();

        await expect(page.getByText('Checked in Avery Browser.')).toBeVisible();

        const afterCheckIn = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        const attendancePage = await afterCheckIn.json();
        expect(attendancePage.checked_in).toBe(checkedInBefore + 1);
        expect(attendancePage.attendances[0].method).toBe('qr');

        await uploadScannedPass(page, qr);
        await expect(page.getByText('Already checked in', { exact: true })).toBeVisible();

        const afterDuplicate = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        expect((await afterDuplicate.json()).checked_in).toBe(checkedInBefore + 1);
    });

    test('revoked pass QR is rejected without recording attendance', async ({ page }) => {
        await signInAsTestUser(page);

        const event = await workspaceEvent(page, testEventTitle);
        const guest = await guestByEmail(page, event.id, 'pass.revoke@example.test');
        const qr = await capturePassQr(
            page,
            `/e/${guest.invite_token}/pass`,
            'Pass Revoke Guest',
        );
        const before = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        const checkedInBefore = (await before.json()).checked_in;

        const revoke = await page.request.post(
            `/api/v1/events/${event.id}/guests/${guest.id}/credential/revoke`,
            { headers: await csrfHeader(page) },
        );
        expect(revoke.ok()).toBeTruthy();

        await page.goto(`/events/${event.id}/check-in`);
        await uploadScannedPass(page, qr);

        await expect(page.getByText('Credential revoked', { exact: true })).toBeVisible();

        const attendance = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        expect((await attendance.json()).checked_in).toBe(checkedInBefore);

        await page.goto(`/e/${guest.invite_token}/pass`);
        await expect(page.getByText('Pass revoked', { exact: true })).toBeVisible();
    });

    test('pass states are explicit for pending, declined, expired, upcoming, and unavailable passes', async ({ page }) => {
        await signInAsTestUser(page);

        const event = await workspaceEvent(page, testEventTitle);
        const pending = await guestByEmail(page, event.id, 'briar.example@example.test');
        const declined = await guestByEmail(page, event.id, 'ellis.example@example.test');

        await page.goto(`/e/${pending.invite_token}/pass`);
        await expect(page.getByText('RSVP required', { exact: true })).toBeVisible();
        await expect(page.getByRole('img', { name: /Entry QR code/ })).toHaveCount(0);

        await page.goto(`/e/${declined.invite_token}/pass`);
        await expect(page.getByText('Pass unavailable', { exact: true })).toBeVisible();
        await expect(page.getByRole('img', { name: /Entry QR code/ })).toHaveCount(0);

        const past = await workspaceEvent(page, testPastEventTitle);
        const pastGuest = (await workspaceGuests(page, past.id))[0];
        await page.goto(`/e/${pastGuest.invite_token}/pass`);
        await expect(page.getByText('Pass expired', { exact: true })).toBeVisible();
        await expect(page.getByRole('img', { name: /Entry QR code/ })).toHaveCount(0);

        const upcoming = await workspaceEvent(page, testUpcomingEventTitle);
        const upcomingGuest = (await workspaceGuests(page, upcoming.id))[0];
        await page.goto(`/e/${upcomingGuest.invite_token}/pass`);
        await expect(page.getByText('Entry is not open yet', { exact: true })).toBeVisible();
        await expect(page.getByRole('img', { name: /Entry QR code/ })).toHaveCount(0);

        await page.goto(`/e/${'x'.repeat(32)}/pass`);
        await expect(page.getByRole('heading', { name: 'Event pass not found' })).toBeVisible();
    });

    test('expired credential result is shown from the authoritative manual lookup', async ({ page }) => {
        await signInAsTestUser(page);

        const past = await workspaceEvent(page, testPastEventTitle);
        const guest = (await workspaceGuests(page, past.id))[0];

        const reveal = await page.request.post(
            `/api/v1/events/${past.id}/guests/${guest.id}/credential/reveal`,
            { headers: await csrfHeader(page) },
        );
        expect(reveal.ok()).toBeTruthy();
        const revealed = await reveal.json();

        await page.goto(`/events/${past.id}/check-in`);
        await page.getByLabel('Entry code').fill(String(revealed.code));
        await page.getByRole('button', { name: 'Look up' }).click();

        await expect(page.getByText('Credential expired', { exact: true })).toBeVisible();

        const attendance = await page.request.get(`/api/v1/events/${past.id}/attendances`);
        expect((await attendance.json()).checked_in).toBe(0);
        expect(JSON.stringify(attendance)).not.toContain(String(revealed.code));
    });

    test('camera-unavailable feedback keeps manual lookup usable and never records attendance on invalid input', async ({ page }) => {
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: undefined,
            });
        });

        await signInAsTestUser(page);
        const event = await workspaceEvent(page, testEventTitle);
        const before = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        const checkedInBefore = (await before.json()).checked_in;

        await page.goto(`/events/${event.id}/check-in`);
        await page.getByRole('button', { name: 'Scan QR', exact: true }).click();
        await page.getByRole('button', { name: 'Start camera' }).click();

        await expect(page.getByText(/does not expose a camera scanning API/i)).toBeVisible();
        await expect(page.getByText(/Enter the code manually or scan a QR image/i).first()).toBeVisible();

        await page.getByRole('button', { name: 'Enter code', exact: true }).click();
        await expect(page.getByLabel('Entry code')).toBeVisible();
        await page.getByLabel('Entry code').fill('NOT-A-REAL-CODE');
        await page.getByRole('button', { name: 'Look up' }).click();

        await expect(page.getByText('Not recognised', { exact: true })).toBeVisible();

        const attendance = await page.request.get(`/api/v1/events/${event.id}/attendances`);
        expect((await attendance.json()).checked_in).toBe(checkedInBefore);
    });

    test('pass and scanner remain usable and contained on mobile', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signInAsTestUser(page);

        const event = await workspaceEvent(page, testEventTitle);
        const guest = await guestByEmail(page, event.id, 'avery.browser@example.test');

        await page.goto(`/e/${guest.invite_token}/pass`);
        await expect(page.getByRole('img', { name: 'Entry QR code for Avery Browser' })).toBeVisible();
        await expectNoHorizontalOverflow(page, 'digital pass at 390px');

        await page.goto(`/events/${event.id}/check-in`);
        await page.getByRole('button', { name: 'Scan QR', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Start camera' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Scan QR image' })).toBeVisible();
        await expectNoHorizontalOverflow(page, 'QR check-in at 390px');
    });
});
