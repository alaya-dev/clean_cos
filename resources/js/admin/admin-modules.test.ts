import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import {
    dismissError,
    dismissToast,
    feedbackState,
    resolveConfirmation,
    showError,
    showToast,
    confirmAction,
} from './feedback';

describe('admin operational modules', () => {
    it('exports the catalogue, inventory, order list, and order detail views', () => {
        for (const file of ['products', 'inventory', 'orders', 'order-detail', 'select-control', 'first-delivery', 'users', 'audit-logs']) {
            const source = readFileSync(`resources/js/admin/${file}.ts`, 'utf8');
            expect(source).toMatch(/export (default|const)/);
        }
    });

    it('keeps order operations visually product-first and guards permanent deletion', () => {
        const list = readFileSync('resources/js/admin/orders.ts', 'utf8');
        const detail = readFileSync('resources/js/admin/order-detail.ts', 'utf8');

        expect(list).toContain('product_thumbnail_url');
        expect(list).toContain('Supprimer définitivement');
        expect(list).not.toContain('order-row-icon');
        expect(detail).toContain('order-item-image');
        expect(detail).toContain('Mettre à jour le statut');
        expect(detail).not.toContain('manual_override');
        expect(list).toContain('bulkManualStatus');
        expect(list).toContain('orderLink(order)');
        expect(detail).toContain('backToOrders');
        expect(detail).toContain('Retour à la liste des commandes');
    });

    it('exposes an optional manual total when creating an operator order', () => {
        const create = readFileSync('resources/js/admin/order-create.ts', 'utf8');

        expect(create).toContain('manualTotalInput');
        expect(create).toContain('manual_total_millimes');
        expect(create).toContain('Total facturé personnalisé');
    });

    it('surfaces new orders without changing the current list ordering', () => {
        const list = readFileSync('resources/js/admin/orders.ts', 'utf8');
        const shell = readFileSync('resources/js/admin/main.ts', 'utf8');
        const dashboard = readFileSync('resources/js/admin/dashboard.ts', 'utf8');
        const styles = readFileSync('resources/css/app.css', 'utf8');

        expect(list).toContain('orders-attention-banner');
        expect(list).toContain('Voir les nouvelles commandes');
        expect(list).toContain('applySummaryFilter');
        expect(list).toContain('filters.value.status === \'nouvelle\'');
        expect(list).toContain('pulseAdminOrderAttention');
        expect(shell).toContain('admin-nav-count');
        expect(shell).toContain('new_orders_count');
        expect(dashboard).toContain('setAdminNewOrderCount(payload.counts.new || 0)');
        expect(dashboard).toContain('statusDonutStyle');
        expect(dashboard).toContain('dashboard-compare-metrics');
        expect(dashboard).toContain('Trafic des commandes');
        expect(dashboard).toContain('Produits les plus commandés');
        expect(styles).toContain('.orders-page .order-row:has(.order-status.is-new)');
        expect(styles).toContain('@keyframes admin-order-attention-pulse');
    });

    it('shows abandoned checkout drafts in the default orders list without a type filter', () => {
        const list = readFileSync('resources/js/admin/orders.ts', 'utf8');
        const styles = readFileSync('resources/css/app.css', 'utf8');

        expect(list).toContain('checkout-drafts?per_page=100&page=1');
        expect(list).toContain('showInlineDrafts');
        expect(list).toContain('value: \'drafts\', label: \'Paniers abandonnés\'');
        expect(list).toContain('label: \'Archives\'');
        expect(list).toContain('const draftOnly = computed');
        expect(list).toContain('checkout-drafts?per_page=25&page=');
        expect(list).toContain('orders-drafts-heading');
        expect(list).toContain('path: \'/orders/drafts/\' + draft.token');
        expect(list).toContain('deleteDraft(draft)');
        expect(list).not.toContain('changeRecordType');
        const draftDetail = readFileSync('resources/js/admin/order-draft.ts', 'utf8');
        const adminApi = readFileSync('resources/js/admin/api.ts', 'utf8');
        expect(list).toContain('import { adminApi } from \'./api\';');
        expect(list).toContain('adminApi(`checkout-drafts/${draft.token}`, \'DELETE\')');
        expect(adminApi).toContain('path.startsWith(\'/\') ? path : `/api/v1/admin/${path}`');
        expect(adminApi).toContain('window.location.reload()');
        expect(draftDetail).toContain('adminApi');
        expect(draftDetail).toContain('checkout-drafts/${draft.value.public_token}');
        expect(draftDetail).toContain('filteredGovernorates');
        expect(draftDetail).toContain('admin-governorate-options');
        expect(draftDetail).toContain('line.image_url');
        expect(list).toContain('draft.items[0]?.image_url');
        expect(draftDetail).toContain('Supprimer ce panier abandonné');
        expect(styles).toContain('.orders-drafts-heading');
        expect(styles).toContain('.checkout-draft-row');
    });

    it('loads prior customer orders only from the returning-customer marker and keeps the order list usable on tablets', () => {
        const list = readFileSync('resources/js/admin/orders.ts', 'utf8');
        const popover = readFileSync('resources/js/admin/customer-order-history-popover.ts', 'utf8');
        const styles = readFileSync('resources/css/app.css', 'utf8');

        expect(list).toContain('CustomerOrderHistoryPopover');
        expect(list).toContain('order.is_returning_customer');
        expect(popover).toContain('orders/${props.orderReference}/customer-history');
        expect(popover).toContain('Les 5 dernières avant cette commande');
        expect(popover).toContain('controller?.abort()');
        expect(popover).toContain('<Teleport to="body">');
        expect(styles).toContain('.customer-history-overlay');
        expect(styles).toContain('.customer-history-panel');
        expect(styles).toContain('@media(min-width:640px) and (max-width:1179px)');
    });

    it('keeps dependent variants explicit in the product editor without auto-save', () => {
        const editor = readFileSync('resources/js/admin/product-editor.ts', 'utf8');

        expect(editor).toContain('parent_client_key');
        expect(editor).toContain('Prix de la combinaison');
        expect(editor).toContain('Variante par défaut');
        expect(editor).toContain('Rouge @ 100 ml');
        expect(editor).toContain('has_variants: variantMode.value');
    });

    it('shows the backend-provided Navex display label for an unmapped provider status', () => {
        const navex = readFileSync('resources/js/admin/navex.ts', 'utf8');
        const detail = readFileSync('resources/js/admin/order-detail.ts', 'utf8');

        expect(navex).toContain('display_status_label');
        expect(detail).toContain('display_status_label');
    });

    it('exposes the secure First Delivery token, locality, shipment, and provider-selection flow', () => {
        const delivery = readFileSync('resources/js/admin/first-delivery.ts', 'utf8');
        const detail = readFileSync('resources/js/admin/order-detail.ts', 'utf8');
        const list = readFileSync('resources/js/admin/orders.ts', 'utf8');
        const shell = readFileSync('resources/js/admin/main.ts', 'utf8');

        expect(delivery).toContain('type="password"');
        expect(delivery).toContain('token_masked');
        expect(delivery).toContain('Tester et synchroniser les localités');
        expect(delivery).toContain('<SelectControl');
        expect(detail).toContain('first_delivery_locality_id');
        expect(detail).toContain('Imprimer le bordereau');
        expect(detail).toContain('cancelFirstDelivery');
        expect(list).toContain('delivery_provider');
        expect(list).toContain('provider_label');
        expect(shell).toContain('path: \'/first-delivery\'');
    });

    it('manages feedback state through shared dialogs and toasts', async () => {
        showToast('success', 'Produit enregistré.');
        expect(feedbackState.toasts.value).toHaveLength(1);
        dismissToast(feedbackState.toasts.value[0].id);
        expect(feedbackState.toasts.value).toHaveLength(0);

        showError('Le produit doit avoir un nom.');
        expect(feedbackState.errorDialog.value?.message).toBe('Le produit doit avoir un nom.');
        dismissError();
        expect(feedbackState.errorDialog.value).toBeNull();

        const confirmation = confirmAction('Supprimer ?', 'Cette action est définitive.', 'Supprimer', 'danger');
        resolveConfirmation(true);
        await expect(confirmation).resolves.toBe(true);
    });
});
