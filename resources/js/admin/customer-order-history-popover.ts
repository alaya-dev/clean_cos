import { defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { adminApi } from './api';

type PreviousOrder = {
    number: number;
    public_reference: string;
    status: string;
    created_at: string | null;
    items: Array<{ name: string | null; quantity: number }>;
};

type CustomerHistory = { orders: PreviousOrder[]; has_more: boolean };

const statusLabel = (status: string): string => ({
    nouvelle: 'Nouvelle',
    tentative_1: 'Tentative 1',
    tentative_2: 'Tentative 2',
    tentative_3: 'Tentative 3',
    confirmee: 'Confirmée',
    annulee: 'Annulée',
}[status] || status);

const statusTone = (status: string): string => ({
    nouvelle: 'new',
    confirmee: 'confirmed',
    tentative_1: 'incident',
    tentative_2: 'incident',
    tentative_3: 'incident',
    annulee: 'cancelled',
}[status] || 'muted');

export default defineComponent({
    name: 'CustomerOrderHistoryPopover',
    props: {
        orderReference: { type: String, required: true },
    },
    setup(props) {
        const open = ref(false);
        const loading = ref(false);
        const history = ref<CustomerHistory | null>(null);
        const loadError = ref(false);
        let controller: AbortController | null = null;

        const close = () => {
            open.value = false;
        };
        const load = async () => {
            if (loading.value || history.value !== null) return;

            loading.value = true;
            loadError.value = false;
            controller?.abort();
            controller = new AbortController();

            try {
                const response = await adminApi<{ data: CustomerHistory }>(`orders/${props.orderReference}/customer-history`, 'GET', undefined, controller.signal);
                history.value = response.data;
            } catch (error) {
                if (!(error instanceof DOMException && error.name === 'AbortError')) loadError.value = true;
            } finally {
                loading.value = false;
            }
        };
        const reveal = () => {
            if (!open.value) window.dispatchEvent(new CustomEvent('customer-history:open', { detail: props.orderReference }));

            open.value = true;
            void load();
        };
        const toggle = () => {
            if (open.value) close();
            else reveal();
        };
        const retry = () => {
            history.value = null;
            void load();
        };
        const itemSummary = (items: PreviousOrder['items']): string => items
            .filter((item) => item.name)
            .map((item) => `${item.quantity} × ${item.name}`)
            .join(' · ');
        const formattedDate = (value: string | null): string => value
            ? new Date(value).toLocaleDateString('fr-TN', { day: '2-digit', month: 'short', year: 'numeric' })
            : 'Date indisponible';
        const closeWhenAnotherHistoryOpens = (event: Event) => {
            if ((event as CustomEvent<string>).detail !== props.orderReference) close();
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && open.value) close();
        };

        onMounted(() => {
            window.addEventListener('customer-history:open', closeWhenAnotherHistoryOpens);
            window.addEventListener('keydown', closeOnEscape);
        });
        onBeforeUnmount(() => {
            window.removeEventListener('customer-history:open', closeWhenAnotherHistoryOpens);
            window.removeEventListener('keydown', closeOnEscape);
            controller?.abort();
        });

        return { open, loading, history, loadError, reveal, toggle, close, retry, itemSummary, formattedDate, statusLabel, statusTone };
    },
    template: `<span class="customer-history">
      <button class="customer-history-trigger" type="button" :aria-expanded="open" aria-haspopup="dialog" @mouseenter="reveal" @focus="reveal" @click.stop="toggle">
        <span aria-hidden="true">◌</span><span>Client récurrent</span>
      </button>
      <Teleport to="body">
        <div v-if="open" class="customer-history-overlay" @click.self="close">
          <section class="customer-history-panel" role="dialog" aria-modal="false" aria-label="Historique des commandes du client" @click.stop>
            <header>
              <div><span class="customer-history-kicker">Client récurrent</span><strong>Commandes précédentes</strong><small>Les 5 dernières avant cette commande</small></div>
              <button type="button" aria-label="Fermer l’historique" @click="close">×</button>
            </header>
            <p v-if="loading" class="customer-history-loading">Chargement de l’historique…</p>
            <p v-else-if="loadError" class="customer-history-error">Impossible de charger l’historique.<button type="button" @click="retry">Réessayer</button></p>
            <template v-else-if="history">
              <ol v-if="history.orders.length" class="customer-history-list">
                <li v-for="previous in history.orders" :key="previous.public_reference">
                  <span class="customer-history-number">{{ previous.number }}</span>
                  <div><strong :title="itemSummary(previous.items)">{{ itemSummary(previous.items) || 'Articles non disponibles' }}</strong><small>{{ formattedDate(previous.created_at) }}</small></div>
                  <span class="order-status" :class="'is-' + statusTone(previous.status)">{{ statusLabel(previous.status) }}</span>
                </li>
              </ol>
              <p v-else class="customer-history-empty">Aucune commande antérieure disponible.</p>
              <small v-if="history.has_more" class="customer-history-more">Les 5 commandes les plus récentes sont affichées.</small>
            </template>
          </section>
        </div>
      </Teleport>
    </span>`,
});
