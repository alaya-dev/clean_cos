import { onMounted, ref, type Component } from 'vue';
import { adminApi, dinarsToMillimes, millimesToDinars } from './api';
import SelectControl from './select-control';
import { confirmAction, showError, showToast } from './feedback';

type Promo = { public_id: string; code: string; discount_percentage: number; usage_limit: number; usage_count: number; minimum_subtotal_millimes: number | null; starts_at: string | null; ends_at: string | null; is_active: boolean; is_exhausted: boolean };
type Meta = { current_page: number; last_page: number; total: number };
const blank = () => ({ code: '', discount_percentage: 10, usage_limit: 100, minimum_subtotal_dt: '', starts_at: '', ends_at: '', is_active: true });

const PromotionsView: Component = {
    components: { SelectControl },
    setup() {
        const rows = ref<Promo[]>([]);
        const meta = ref<Meta>({ current_page: 1, last_page: 1, total: 0 });
        const loading = ref(true);
        const saving = ref(false);
        const search = ref('');
        const status = ref('');
        const usage = ref('');
        const editing = ref<Promo | 'new' | null>(null);
        const form = ref(blank());
        const statusOptions = [{ value: '', label: 'Tous les états' }, { value: '1', label: 'Actifs' }, { value: '0', label: 'Inactifs' }];
        const usageOptions = [{ value: '', label: 'Toutes les utilisations' }, { value: 'available', label: 'Disponibles' }, { value: 'exhausted', label: 'Épuisés' }];

        const load = async (page = 1) => {
            loading.value = true;
            try {
                const query = new URLSearchParams({ per_page: '25', page: String(page) });
                if (search.value) query.set('search', search.value);
                if (status.value) query.set('is_active', status.value);
                if (usage.value) query.set('usage_state', usage.value);
                const response = await adminApi<{ data: { data: Promo[]; meta: Meta } }>(`promo-codes?${query}`);
                rows.value = response.data.data;
                meta.value = response.data.meta;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement impossible.');
            } finally {
                loading.value = false;
            }
        };
        let timer: number | undefined;
        const queueSearch = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => void load(), 280);
        };
        const open = (promo?: Promo) => {
            editing.value = promo || 'new';
            form.value = promo
                ? { code: promo.code, discount_percentage: promo.discount_percentage, usage_limit: promo.usage_limit, minimum_subtotal_dt: millimesToDinars(promo.minimum_subtotal_millimes), starts_at: promo.starts_at?.slice(0, 16) || '', ends_at: promo.ends_at?.slice(0, 16) || '', is_active: promo.is_active }
                : blank();
        };
        const save = async () => {
            const minimum = form.value.minimum_subtotal_dt ? dinarsToMillimes(form.value.minimum_subtotal_dt) : null;
            if (form.value.minimum_subtotal_dt && minimum === null) return showError('Le sous-total minimum doit être un montant en DT avec trois décimales au maximum.');
            const existing = typeof editing.value === 'object' ? editing.value : null;
            saving.value = true;
            try {
                await adminApi(existing ? `promo-codes/${existing.public_id}` : 'promo-codes', existing ? 'PATCH' : 'POST', { code: form.value.code, discount_percentage: form.value.discount_percentage, usage_limit: form.value.usage_limit, minimum_subtotal_millimes: minimum, starts_at: form.value.starts_at || null, ends_at: form.value.ends_at || null, is_active: form.value.is_active });
                showToast('success', existing ? 'Code promo mis à jour.' : 'Code promo créé.');
                editing.value = null;
                await load(meta.value.current_page);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            } finally {
                saving.value = false;
            }
        };
        const toggle = async (promo: Promo) => {
            try {
                const response = await adminApi<{ data: Promo }>(`promo-codes/${promo.public_id}/status`, 'POST', { is_active: !promo.is_active });
                Object.assign(promo, response.data);
                showToast('success', promo.is_active ? 'Code activé.' : 'Code désactivé.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Action impossible.');
            }
        };
        const removePromo = async (promo: Promo) => {
            const confirmed = await confirmAction('Supprimer ce code promo ?', `« ${promo.code} » sera supprimé. Les commandes déjà créées conserveront leur remise enregistrée.`, 'Supprimer', 'danger');
            if (!confirmed) return;
            try {
                await adminApi(`promo-codes/${promo.public_id}`, 'DELETE');
                rows.value = rows.value.filter((row) => row.public_id !== promo.public_id);
                meta.value.total = Math.max(0, meta.value.total - 1);
                showToast('success', 'Code promo supprimé.');
                if (!rows.value.length && meta.value.current_page > 1) await load(meta.value.current_page - 1);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Suppression impossible.');
            }
        };

        onMounted(load);
        return { rows, meta, loading, saving, search, status, usage, statusOptions, usageOptions, editing, form, load, queueSearch, open, save, toggle, removePromo };
    },
    template: `<section class="admin-page"><header><div><p class="admin-eyebrow">Boutique / Promotions</p><h1>Codes promo</h1><p class="admin-subtitle">Réductions appliquées aux marchandises uniquement.</p></div><button class="admin-action" @click="open()">Nouveau code</button></header><div class="admin-filter-bar"><label class="admin-search"><span class="sr-only">Rechercher</span><input v-model.trim="search" @input="queueSearch" placeholder="Rechercher un code…"></label><SelectControl v-model="status" :options="statusOptions" @change="load()"/><SelectControl v-model="usage" :options="usageOptions" @change="load()"/></div>
      <form v-if="editing" class="category-form" @submit.prevent="save"><header><h2>{{ editing.public_id ? 'Modifier le code' : 'Créer un code' }}</h2><button class="text-link" type="button" @click="editing = null">Fermer</button></header><div class="form-grid"><label>Code<input v-model.trim="form.code" maxlength="80" required></label><label>Pourcentage<input v-model.number="form.discount_percentage" type="number" min="1" max="100" required></label><label>Limite globale<input v-model.number="form.usage_limit" type="number" min="1" required></label><label>Sous-total minimum (DT)<input v-model="form.minimum_subtotal_dt" inputmode="decimal"></label><label>Début<input v-model="form.starts_at" type="datetime-local"></label><label>Fin<input v-model="form.ends_at" type="datetime-local"></label><label class="inline-check">Actif <input v-model="form.is_active" type="checkbox"></label></div><footer><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></footer></form>
      <p v-if="loading" class="admin-loading">Chargement…</p><p v-else-if="!rows.length" class="admin-empty">Aucun code promo.</p><div v-else class="admin-table"><div class="admin-table-head"><span>Code</span><span>Réduction</span><span>Utilisation</span><span>Actions</span></div><article v-for="promo in rows" :key="promo.public_id"><div><strong>{{ promo.code }}</strong><small v-if="promo.is_exhausted">Limite d’utilisation atteinte</small></div><span>{{ promo.discount_percentage }} %</span><span>{{ promo.usage_count }} sur {{ promo.usage_limit }}</span><span class="admin-row-actions"><button class="text-link" @click="open(promo)">Modifier</button><button class="text-link" @click="toggle(promo)">{{ promo.is_active ? 'Désactiver' : 'Activer' }}</button><button class="text-link danger" @click="removePromo(promo)">Supprimer</button></span></article></div><nav v-if="meta.last_page > 1" class="admin-pagination" aria-label="Pagination"><button class="admin-outline" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Précédent</button><span>Page {{ meta.current_page }} sur {{ meta.last_page }}</span><button class="admin-outline" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Suivant</button></nav></section>`,
};

export default PromotionsView;
