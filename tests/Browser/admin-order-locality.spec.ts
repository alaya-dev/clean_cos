import { expect, test } from '@playwright/test';

test('la localité First Delivery garde un seul chevron dans Ajouter une commande', async ({ page }) => {
    const email = process.env.PLAYWRIGHT_ADMIN_EMAIL;
    const password = process.env.PLAYWRIGHT_ADMIN_PASSWORD;
    test.skip(!email || !password, 'Set PLAYWRIGHT_ADMIN_EMAIL and PLAYWRIGHT_ADMIN_PASSWORD for the authenticated admin visual check.');

    await page.route('**/api/v1/admin/me', (route) => route.fulfill({ json: { data: { role: 'super_admin' } } }));
    await page.route('**/api/v1/public/checkout-fields', (route) => route.fulfill({
        json: {
            data: [{ key: 'governorate', label: 'Gouvernorat', type: 'select', options: [{ value: 'Tunis', label: 'Tunis' }], is_required: true }],
            meta: { schema_version: 'test' },
        },
    }));
    await page.route('**/api/v1/admin/first-delivery/localities**', (route) => route.fulfill({
        json: { data: [{ locality_id: 1, locality_name: 'Centre', delegation_name: 'Tunis', governorate_name: 'Tunis' }] },
    }));

    await page.goto('/admin/login');
    await page.locator('input[name="email"]').fill(email as string);
    await page.locator('input[name="password"]').fill(password as string);
    await Promise.all([
        page.waitForURL('**/admin**'),
        page.getByRole('button', { name: 'Se connecter' }).click(),
    ]);

    await page.goto('/admin/orders/new');
    const governorate = page.getByRole('combobox', { name: 'Gouvernorat' });
    await governorate.fill('Tunis');
    await page.getByRole('option', { name: 'Tunis' }).click();

    const locality = page.getByLabel('Localité First Delivery');
    await expect(locality).toHaveCount(1);
    await expect(locality).toBeEnabled();
    const visualState = await locality.evaluate((element) => {
        const style = getComputedStyle(element);
        return {
            appearance: style.appearance,
            backgroundImage: style.backgroundImage,
            backgroundRepeat: style.backgroundRepeat,
            borderRadius: style.borderRadius,
            height: Math.round(element.getBoundingClientRect().height),
        };
    });

    expect(visualState.appearance).toBe('none');
    expect(visualState.backgroundImage).not.toBe('none');
    expect(visualState.backgroundRepeat).toBe('no-repeat');
    expect(visualState.borderRadius).toBe('8.8px');
    expect(visualState.height).toBeGreaterThanOrEqual(44);

    await locality.focus();
    await expect(locality).toBeFocused();
});
