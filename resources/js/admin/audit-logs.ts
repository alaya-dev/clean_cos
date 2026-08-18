import { onMounted, ref, type Component } from 'vue';
import { adminApi } from './api';
import SelectControl from './select-control';

type AuditChange = { field: string; from: unknown; to: unknown };
type AuditLog = {
    public_id: string;
    action: string;
    actor_role_snapshot?: string | null;
    actor?: { public_id: string; name: string; role: string } | null;
    auditable_id?: string | null;
    order_reference?: string | null;
    target_type?: 'order' | 'user' | null;
    target_reference?: string | null;
    created_at: string;
    changes?: AuditChange[];
    before?: Record<string, unknown> | null;
    after?: Record<string, unknown> | null;
    request_id?: string | null;
};
type Page<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number; retention?: { label: string } } };

const auditActionLabels: Record<string, string> = {
    'user.created': 'Utilisateur créé', 'user.updated': 'Utilisateur modifié', 'user.deleted': 'Administrateur supprimé', 'user.password_changed': 'Mot de passe modifié', 'user.password_reset': 'Mot de passe réinitialisé',
    'order.manually_created': 'Commande créée manuellement', 'order.customer_updated': 'Informations client modifiées', 'order.items_updated': 'Articles modifiés', 'order.total_updated': 'Total modifié',
    'order.status_changed': 'Statut modifié', 'order.note_added': 'Note interne ajoutée', 'order.archived': 'Commande archivée', 'order.restored': 'Commande restaurée', 'order.bulk_archived': 'Commandes archivées', 'order.bulk_restored': 'Commandes restaurées',
    'order.bulk_transitioned': 'Statuts modifiés en masse', 'order.bulk_permanently_deleted': 'Commandes supprimées définitivement', 'order.permanently_deleted': 'Commande supprimée définitivement',
};
const statusLabels: Record<string, string> = { nouvelle: 'Nouvelle', tentative_1: 'Tentative 1', tentative_2: 'Tentative 2', tentative_3: 'Tentative 3', confirmee: 'Confirmée', annulee: 'Annulée' };
const fieldLabels: Record<string, string> = {
    status: 'Statut', total_millimes: 'Total', manual_total_millimes: 'Total manuel', item_count: 'Nombre d’articles', items: 'Articles',
    archived: 'Archivage', archived_at: 'Date d’archivage', is_active: 'Activation', role: 'Rôle', count: 'Nombre', reason: 'Motif',
    is_exchange: 'Échange', exchange_article_count: 'Articles échangés', exchange_article_designation: 'Désignation échange',
    references: 'Commandes concernées', deleted: 'Suppression', marketing_consent: 'Consentement marketing', operation: 'Opération',
};
const actionLabel = (action: string) => auditActionLabels[action] ?? action.replaceAll(/[._]/g, ' ').replace(/^./, char => char.toUpperCase());
const roleLabel = (role?: string | null) => role === 'super_admin' ? 'Super Admin' : role === 'admin' ? 'Administrateur' : 'Système';
const actorLabel = (log: AuditLog) => log.actor?.name || roleLabel(log.actor_role_snapshot);
const targetLabel = (log: AuditLog) => log.target_type === 'user' ? 'Utilisateur' : log.target_type === 'order' || log.order_reference ? 'Commande' : 'Cible';
const targetReference = (log: AuditLog) => log.target_reference || log.order_reference || log.auditable_id || '—';
const fieldLabel = (key: string) => fieldLabels[key] ?? key.replaceAll('_', ' ');

const formatValue = (value: unknown, field: string): string => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Oui' : 'Non';
    if (field.includes('millimes') && typeof value === 'number') return `${(value / 1000).toFixed(3).replace('.', ',')} DT`;
    if ((field === 'status' || field.endsWith('_status')) && typeof value === 'string') return statusLabels[value] || value;
    if (Array.isArray(value)) {
        const items = value.map((item) => {
            if (item && typeof item === 'object' && 'product' in item) {
                const row = item as { product?: unknown; quantity?: unknown; variant?: unknown };
                const variant = Array.isArray(row.variant) && row.variant.length ? ` (${row.variant.join(', ')})` : '';
                return `${String(row.quantity ?? 1)} × ${String(row.product ?? 'Article')}${variant}`;
            }
            return typeof item === 'string' ? item : JSON.stringify(item);
        });
        return items.join(' · ') || '—';
    }
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
};

const legacyChanges = (log: AuditLog): AuditChange[] => {
    const before = log.before || {};
    const after = log.after || {};
    const changes: AuditChange[] = [];
    if ('from_status' in after || 'to_status' in after) {
        changes.push({ field: 'status', from: after.from_status ?? before.status ?? null, to: after.to_status ?? after.status ?? null });
    }
    const consumed = new Set(['from_status', 'to_status', 'status']);
    [...new Set([...Object.keys(before), ...Object.keys(after)])].forEach((field) => {
        if (consumed.has(field)) return;
        const from = before[field] ?? null;
        const to = after[field] ?? null;
        if (JSON.stringify(from) !== JSON.stringify(to)) changes.push({ field, from, to });
    });

    return changes;
};

const AuditLogsView: Component = {
    components: { SelectControl },
    setup() {
        const logs = ref<AuditLog[]>([]);
        const loading = ref(true);
        const error = ref('');
        const page = ref(1);
        const meta = ref<Page<AuditLog>['meta']>({ current_page: 1, last_page: 1, total: 0 });
        const retentionLabel = ref('Conservation : 730 jours — purge automatique mensuelle.');
        const search = ref('');
        const actorRole = ref('');
        const scope = ref('');
        const dateFrom = ref('');
        const dateTo = ref('');
        const expanded = ref<string | null>(null);
        let debounce: number | undefined;
        const params = () => new URLSearchParams(Object.entries({ search: search.value, actor_role: actorRole.value, scope: scope.value, date_from: dateFrom.value, date_to: dateTo.value, page: String(page.value), per_page: '25' }).filter(([, value]) => value !== ''));
        const load = async () => {
            loading.value = true;
            error.value = '';
            try {
                const response = await adminApi<{ data: Page<AuditLog> }>(`audit-logs?${params().toString()}`);
                logs.value = response.data.data;
                meta.value = response.data.meta;
                retentionLabel.value = response.data.meta.retention?.label || retentionLabel.value;
            } catch (cause) {
                error.value = cause instanceof Error ? cause.message : 'Le chargement du journal a échoué.';
            } finally { loading.value = false; }
        };
        const queueLoad = () => { window.clearTimeout(debounce); debounce = window.setTimeout(() => { page.value = 1; void load(); }, 300); };
        const resetFilters = () => { search.value = ''; actorRole.value = ''; scope.value = ''; dateFrom.value = ''; dateTo.value = ''; page.value = 1; void load(); };
        const changePage = (next: number) => { if (next >= 1 && next <= meta.value.last_page) { page.value = next; void load(); } };
        const changesFor = (log: AuditLog) => log.changes?.length ? log.changes : legacyChanges(log);
        const formatChange = (change: AuditChange) => `${fieldLabel(change.field)} : ${formatValue(change.from, change.field)} → ${formatValue(change.to, change.field)}`;
        const formatDate = (value: string) => new Intl.DateTimeFormat('fr-TN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
        onMounted(load);
        return { logs, loading, error, page, meta, retentionLabel, search, actorRole, scope, dateFrom, dateTo, expanded, actionLabel, actorLabel, targetLabel, targetReference, changesFor, formatChange, formatDate, load, queueLoad, resetFilters, changePage,
            roleOptions: [{ value: '', label: 'Tous les intervenants' }, { value: 'super_admin', label: 'Super Admin' }, { value: 'admin', label: 'Administrateur' }],
            scopeOptions: [{ value: '', label: 'Toutes les actions' }, { value: 'orders', label: 'Commandes' }, { value: 'users', label: 'Utilisateurs' }],
        };
    },
    template: `
      <section class="admin-page audit-page"><header class="admin-page-header"><div><p class="admin-eyebrow">Traçabilité</p><h1>Journal d’audit</h1><p class="admin-subtitle">Chaque ligne indique l’action, l’intervenant, la cible et les valeurs avant → après. Les données personnelles et secrets restent masqués.</p></div></header>
        <p class="audit-retention-note" role="status">{{ retentionLabel }}</p>
        <section class="admin-filter-bar" aria-label="Filtres du journal"><label class="admin-search"><span class="sr-only">Rechercher une action, une commande ou un intervenant</span><input v-model.trim="search" type="search" placeholder="Action, commande ou intervenant…" @input="queueLoad"></label><label class="toolbar-select"><span>Type</span><SelectControl v-model="scope" :options="scopeOptions" @change="page = 1; load()" /></label><label class="toolbar-select"><span>Intervenant</span><SelectControl v-model="actorRole" :options="roleOptions" @change="page = 1; load()" /></label><label>Du<input v-model="dateFrom" type="date" @change="page = 1; load()"></label><label>Au<input v-model="dateTo" type="date" @change="page = 1; load()"></label><button class="text-link" type="button" @click="resetFilters">Réinitialiser</button></section>
        <p v-if="error" class="page-error" role="alert">{{ error }} <button class="text-link" type="button" @click="load">Réessayer</button></p>
        <p v-else-if="loading" class="admin-loading">Chargement du journal…</p>
        <section v-else-if="!logs.length" class="admin-empty" aria-live="polite"><strong>Aucune action ne correspond à ces critères.</strong><span>Les actions des administrateurs apparaîtront ici automatiquement.</span></section>
        <div v-else class="admin-table audit-table"><div class="admin-table-head"><span>Action</span><span>Cible</span><span>Intervenant</span><span>Changements</span><span>Date</span><span></span></div><article v-for="log in logs" :key="log.public_id"><div class="audit-action"><strong>{{ actionLabel(log.action) }}</strong><small>{{ log.action }}</small></div><div class="audit-target"><strong>{{ targetLabel(log) }}</strong><small>{{ targetReference(log) }}</small></div><span class="audit-actor">{{ actorLabel(log) }}</span><ul class="audit-change-list"><li v-for="change in changesFor(log)" :key="change.field + JSON.stringify(change.from) + JSON.stringify(change.to)">{{ formatChange(change) }}</li><li v-if="!changesFor(log).length" class="is-empty">Action enregistrée sans valeur détaillée</li></ul><time :datetime="log.created_at">{{ formatDate(log.created_at) }}</time><button class="text-link audit-detail-toggle" type="button" :aria-expanded="expanded === log.public_id" @click="expanded = expanded === log.public_id ? null : log.public_id">{{ expanded === log.public_id ? 'Masquer' : 'Détails' }}</button><div v-if="expanded === log.public_id" class="audit-details"><p><strong>{{ targetLabel(log) }} :</strong> {{ targetReference(log) }}</p><ul v-if="changesFor(log).length" class="audit-change-list audit-change-list-expanded"><li v-for="change in changesFor(log)" :key="'detail-' + change.field + JSON.stringify(change.from) + JSON.stringify(change.to)">{{ formatChange(change) }}</li></ul><p v-if="log.request_id"><strong>Identifiant de requête :</strong> {{ log.request_id }}</p></div></article></div>
        <nav v-if="meta.last_page > 1" class="admin-pagination" aria-label="Pagination du journal"><button class="admin-outline" type="button" :disabled="page === 1" @click="changePage(page - 1)">Précédent</button><span>Page {{ meta.current_page }} sur {{ meta.last_page }} · {{ meta.total }} actions</span><button class="admin-outline" type="button" :disabled="page === meta.last_page" @click="changePage(page + 1)">Suivant</button></nav>
      </section>`,
};

export default AuditLogsView;
