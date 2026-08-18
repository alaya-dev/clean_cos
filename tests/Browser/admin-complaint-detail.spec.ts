import { expect, test } from '@playwright/test';

test('admin complaint details have a readable desktop workspace', async ({ page }) => {
    const email = process.env.PLAYWRIGHT_ADMIN_EMAIL;
    const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD;
    test.skip(!email || !password, 'Set PLAYWRIGHT_ADMIN_EMAIL and PLAYWRIGHT_ADMIN_PASSWORD for the authenticated admin visual check.');

    await page.goto('/admin/login');
    await page.locator('input[name="email"]').fill(email as string);
    await page.locator('input[name="password"]').fill(password as string);
    await Promise.all([
        page.waitForURL('**/admin**'),
        page.getByRole('button', { name: 'Se connecter' }).click(),
    ]);

    const complaint = {
        public_reference: '01JCOMPTESTDETAIL000000000000',
        subject: 'Produit reçu endommagé',
        description: 'Le colis est arrivé avec une boîte abîmée. Merci de vérifier la commande.',
        customer_name: 'Client de démonstration',
        customer_phone: '+216 22 123 456',
        created_at: '2026-08-03T10:00:00Z',
        status: 'nouvelle',
        has_attachment: false,
        order: { public_reference: '01JORDERTESTDETAIL00000000000' },
        notes: [],
        status_history: [],
    };
    await page.route('**/api/v1/admin/complaints/01JCOMPTESTDETAIL000000000000', async (route) => route.fulfill({ json: { data: complaint } }));
    await page.goto('/admin/complaints/01JCOMPTESTDETAIL000000000000');
    await expect(page.getByRole('heading', { name: 'Réclamation' })).toBeVisible();
    await expect(page.getByText('Coordonnées')).toBeVisible();
    await page.screenshot({ path: 'test-results/admin-complaint-detail-desktop.png', fullPage: true });
});
