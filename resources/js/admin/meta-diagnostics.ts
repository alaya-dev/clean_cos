import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
    type Component,
} from 'vue';
import '../../css/admin-meta.css';
import { adminApi } from './api';
import { showError, showToast } from './feedback';
import SelectControl, { type SelectOption } from './select-control';

type Attempt = {
    channel: string;
    attempt_number: number;
    outcome: string;
    request_sent: boolean;
    http_status: number | null;
    events_received: number | null;
    error_classification: string | null;
    meta_error_code: string | null;
    meta_error_subcode: string | null;
    safe_message: string | null;
    fbtrace_id: string | null;
    graph_api_version: string | null;
    attempted_at: string;
};
type EventRow = {
    public_id: string;
    event_id: string;
    event_name: string;
    event_time: string;
    is_synthetic: boolean;
    configuration_version: number | null;
    mode: 'test' | 'live';
    pixel_id: string | null;
    source_url: string | null;
    browser_state: string;
    capi_state: string;
    last_error_classification: string | null;
    deduplication_status: string;
    global_status: string;
    attempts?: Attempt[];
    retry_eligible?: boolean;
};
type CatalogueMetrics = {
    products_configured: number;
    products_unconfigured: number;
    events_complete: number;
    events_partial: number;
    events_missing: number;
};
type PageResponse = {
    data: {
        data: EventRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            catalogue: CatalogueMetrics;
        };
    };
};

const eventLabels: Record<string, string> = {
    PageView: 'Page vue',
    ViewContent: 'Produit vu',
    Search: 'Recherche',
    AddToCart: 'Ajout au panier',
    InitiateCheckout: 'Début de commande',
    Purchase: 'Achat',
};
const browserLabels: Record<string, string> = {
    eligible: 'Bloqué / non observé',
    rendered: 'Tentative préparée',
    attempted: 'Tentative envoyée',
    blocked_or_unknown: 'Bloqué / non observé',
};
const serverLabels: Record<string, string> = {
    pending: 'En attente',
    sending: 'En cours d’envoi',
    succeeded: 'Accepté par Meta',
    temporary_failure: 'Nouvelle tentative prévue',
    permanent_failure: 'Refusé par Meta',
    skipped_no_consent: 'Non envoyé',
    skipped_tracking_disabled: 'Non envoyé',
    skipped_no_active_configuration: 'Erreur de configuration',
};
const globalLabels: Record<string, string> = {
    pair_dispatched: 'Livraison complète à confirmer',
    server_only: 'Serveur uniquement',
    browser_only: 'Navigateur uniquement',
    pending: 'En attente',
    action_required: 'Action requise',
};
const dedupLabels: Record<string, string> = {
    pending_confirmation: 'En attente de confirmation',
    pending: 'En attente',
    unavailable: 'Indisponible',
    mismatched: 'Incohérente',
    confirmed: 'Confirmée',
};

const MetaDiagnosticsView: Component = {
    components: { SelectControl },
    setup() {
        const events = ref<EventRow[]>([]);
        const detail = ref<EventRow | null>(null);
        const detailDialog = ref<HTMLElement | null>(null);
        let detailTrigger: HTMLElement | null = null;
        const loading = ref(true);
        const retrying = ref(false);
        const retryPassword = ref('');
        const importFile = ref<File | null>(null);
        const importReport = ref<{
            rows: Array<Record<string, unknown>>;
            summary: { total: number; conflicts: number };
        } | null>(null);
        const importBusy = ref(false);
        const meta = reactive({
            current_page: 1,
            last_page: 1,
            total: 0,
            catalogue: {
                products_configured: 0,
                products_unconfigured: 0,
                events_complete: 0,
                events_partial: 0,
                events_missing: 0,
            },
        });
        const filters = reactive({
            event_name: '',
            browser_state: '',
            capi_state: '',
            global_status: '',
            mode: '',
            synthetic: '',
            date_from: '',
            date_to: '',
            page: 1,
        });
        const option = (value: string, label: string): SelectOption => ({
            value,
            label,
        });
        const eventOptions = [
            option('', 'Tous les événements'),
            ...Object.entries(eventLabels).map(([value, label]) =>
                option(value, label),
            ),
        ];
        const browserOptions = [
            option('', 'Tous les états navigateur'),
            option('attempted', 'Tentative envoyée'),
            option('eligible', 'Bloqué / non observé'),
        ];
        const serverOptions = [
            option('', 'Tous les états serveur'),
            ...Object.entries(serverLabels).map(([value, label]) =>
                option(value, label),
            ),
        ];
        const globalOptions = [
            option('', 'Tous les statuts'),
            ...Object.entries(globalLabels).map(([value, label]) =>
                option(value, label),
            ),
        ];
        const modeOptions = [
            option('', 'Test et production'),
            option('test', 'Test'),
            option('live', 'Production'),
        ];
        const typeOptions = [
            option('', 'Réels et tests'),
            option('false', 'Événements réels'),
            option('true', 'Tests synthétiques'),
        ];
        const query = computed(() => {
            const params = new URLSearchParams({ page: String(filters.page) });
            Object.entries(filters).forEach(([key, value]) => {
                if (key !== 'page' && value) params.set(key, String(value));
            });
            return params.toString();
        });
        const load = async (): Promise<void> => {
            loading.value = true;
            try {
                const response = await adminApi<PageResponse>(
                    `meta/diagnostics?${query.value}`,
                );
                events.value = response.data.data;
                Object.assign(meta, response.data.meta);
                filters.page = response.data.meta.current_page;
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Chargement des diagnostics impossible.',
                );
            } finally {
                loading.value = false;
            }
        };
        const open = async (event: EventRow): Promise<void> => {
            detailTrigger =
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null;
            try {
                detail.value = (
                    await adminApi<{ data: EventRow }>(
                        `meta/diagnostics/${event.public_id}`,
                    )
                ).data;
                retryPassword.value = '';
                await nextTick();
                detailDialog.value?.focus();
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Détail indisponible.',
                );
            }
        };
        const closeDetail = (): void => {
            detail.value = null;
            void nextTick(() => detailTrigger?.focus());
        };
        const retry = async (): Promise<void> => {
            if (!detail.value) return;
            retrying.value = true;
            try {
                await adminApi(
                    `meta/diagnostics/${detail.value.public_id}/retry`,
                    'POST',
                    { current_password: retryPassword.value },
                );
                retryPassword.value = '';
                closeDetail();
                showToast(
                    'success',
                    'La relance a été mise en file d’attente.',
                );
                await load();
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Relance impossible.',
                );
            } finally {
                retrying.value = false;
            }
        };
        const apply = (): void => {
            filters.page = 1;
            void load();
        };
        const reset = (): void => {
            Object.assign(filters, {
                event_name: '',
                browser_state: '',
                capi_state: '',
                global_status: '',
                mode: '',
                synthetic: '',
                date_from: '',
                date_to: '',
                page: 1,
            });
            void load();
        };
        const previous = (): void => {
            if (filters.page > 1 && !loading.value) {
                filters.page--;
                void load();
            }
        };
        const next = (): void => {
            if (filters.page < meta.last_page && !loading.value) {
                filters.page++;
                void load();
            }
        };
        const explanation = (event: EventRow): string => {
            if (
                event.capi_state === 'succeeded' &&
                event.browser_state !== 'attempted'
            )
                return 'Le Pixel navigateur n’a pas été observé, probablement à cause d’un bloqueur. L’événement serveur a néanmoins été accepté par Meta.';
            if (
                ['configuration_invalid', 'token_decryption_failed'].includes(
                    event.last_error_classification ?? '',
                )
            )
                return 'L’événement n’a pas été envoyé à Meta, car la configuration enregistrée est incomplète ou indisponible.';
            if (event.capi_state === 'permanent_failure')
                return 'Meta a reçu la requête mais l’a refusée. Consultez la tentative serveur pour connaître la raison.';
            return 'Le navigateur et le serveur sont suivis séparément. Une tentative navigateur ne prouve pas sa réception par Meta.';
        };
        const latestAttempt = (event: EventRow): Attempt | null =>
            event.attempts?.at(-1) ?? null;
        const serverStatus = (event: EventRow): string =>
            ['configuration_invalid', 'token_decryption_failed'].includes(
                event.last_error_classification ?? '',
            )
                ? 'Erreur de configuration'
                : serverLabels[event.capi_state] || event.capi_state;
        const chooseImport = (event: Event): void => {
            importFile.value =
                (event.target as HTMLInputElement).files?.[0] ?? null;
            importReport.value = null;
        };
        const dryRunImport = async (): Promise<void> => {
            if (!importFile.value || importBusy.value) return;
            importBusy.value = true;
            try {
                const body = new FormData();
                body.append('file', importFile.value);
                importReport.value = (
                    await adminApi<{
                        data: NonNullable<typeof importReport.value>;
                    }>('meta/catalogue/import/dry-run', 'POST', body)
                ).data;
                showToast('success', 'Simulation terminée.');
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Simulation impossible.',
                );
            } finally {
                importBusy.value = false;
            }
        };
        const commitImport = async (): Promise<void> => {
            if (!importReport.value || importBusy.value) return;
            importBusy.value = true;
            try {
                await adminApi('meta/catalogue/import/commit', 'POST', {
                    rows: importReport.value.rows,
                });
                importReport.value = null;
                importFile.value = null;
                showToast('success', 'Import catalogue enregistré.');
                await load();
            } catch (cause) {
                showError(
                    cause instanceof Error
                        ? cause.message
                        : 'Import impossible.',
                );
            } finally {
                importBusy.value = false;
            }
        };
        watch(detail, (event) =>
            document.body.classList.toggle('admin-modal-open', event !== null),
        );
        onMounted(load);
        onBeforeUnmount(() =>
            document.body.classList.remove('admin-modal-open'),
        );
        return {
            events,
            detail,
            detailDialog,
            loading,
            retrying,
            retryPassword,
            meta,
            filters,
            apply,
            reset,
            open,
            closeDetail,
            retry,
            previous,
            next,
            eventLabels,
            browserLabels,
            serverLabels,
            serverStatus,
            globalLabels,
            dedupLabels,
            eventOptions,
            browserOptions,
            serverOptions,
            globalOptions,
            modeOptions,
            typeOptions,
            explanation,
            latestAttempt,
            importFile,
            importReport,
            importBusy,
            chooseImport,
            dryRunImport,
            commitImport,
        };
    },
    template: `<section class="admin-page meta-diagnostics-page">
      <header class="admin-page-header"><div><p class="admin-eyebrow">Suivi Meta</p><h1>Historique des événements</h1><p class="admin-subtitle">Livraison navigateur et serveur, sans exposer de données client.</p></div><RouterLink class="admin-outline" to="/meta">Configuration Meta</RouterLink></header>
      <section class="meta-section"><header><div><p class="admin-eyebrow">Diagnostic de livraison</p><h2>Événements enregistrés</h2></div></header>
        <div class="admin-filter-bar meta-filter-bar"><label class="toolbar-select"><span>Événement</span><SelectControl v-model="filters.event_name" :options="eventOptions" @change="apply" /></label><label class="toolbar-select"><span>Navigateur</span><SelectControl v-model="filters.browser_state" :options="browserOptions" @change="apply" /></label><label class="toolbar-select"><span>Serveur</span><SelectControl v-model="filters.capi_state" :options="serverOptions" @change="apply" /></label><label class="toolbar-select"><span>Statut</span><SelectControl v-model="filters.global_status" :options="globalOptions" @change="apply" /></label><label class="toolbar-select"><span>Mode</span><SelectControl v-model="filters.mode" :options="modeOptions" @change="apply" /></label><label class="toolbar-select"><span>Type</span><SelectControl v-model="filters.synthetic" :options="typeOptions" @change="apply" /></label><label>Du<input v-model="filters.date_from" type="date" @change="apply"></label><label>Au<input v-model="filters.date_to" type="date" @change="apply"></label><button class="text-link" type="button" @click="reset">Réinitialiser</button></div>
        <p v-if="loading" class="admin-loading">Chargement des événements…</p>
        <div v-else-if="!events.length" class="admin-empty"><strong>Aucun événement Meta n’a encore été enregistré.</strong><span>Effectuez un test ou naviguez sur la boutique pour commencer.</span></div>
        <div v-else class="admin-table-wrap meta-event-table"><table><thead><tr><th>Événement</th><th>Date et heure</th><th>Navigateur</th><th>Serveur</th><th>Déduplication</th><th>Statut global</th><th>Action</th></tr></thead><tbody><tr v-for="event in events" :key="event.public_id"><td data-label="Événement"><strong>{{ eventLabels[event.event_name] }}</strong><small v-if="event.is_synthetic">Test</small></td><td data-label="Date et heure">{{ new Date(event.event_time).toLocaleString('fr-TN') }}</td><td data-label="Navigateur"><span class="admin-badge">{{ event.is_synthetic ? 'Non applicable' : browserLabels[event.browser_state] || 'Non observé' }}</span></td><td data-label="Serveur"><span class="admin-badge" :class="{ 'is-published': event.capi_state === 'succeeded', 'is-danger': event.capi_state === 'permanent_failure' }">{{ serverStatus(event) }}</span></td><td data-label="Déduplication">{{ event.is_synthetic ? 'Non applicable' : dedupLabels[event.deduplication_status] || 'Indisponible' }}</td><td data-label="Statut global"><strong>{{ globalLabels[event.global_status] || 'Action requise' }}</strong></td><td><button class="text-link" type="button" @click="open(event)">Détail</button></td></tr></tbody></table></div>
        <footer class="admin-pagination"><span>{{ meta.total }} événement(s) · page {{ meta.current_page }} sur {{ meta.last_page }}</span><div><button class="admin-outline" type="button" :disabled="filters.page <= 1 || loading" @click="previous">Précédent</button><button class="admin-outline" type="button" :disabled="filters.page >= meta.last_page || loading" @click="next">Suivant</button></div></footer>
      </section>

      <details class="meta-section meta-catalogue-import"><summary>Compatibilité catalogue Meta</summary><p>Produits configurés : {{ meta.catalogue.products_configured }} · Non configurés : {{ meta.catalogue.products_unconfigured }}</p><p>Événements complets : {{ meta.catalogue.events_complete }} · Partiels : {{ meta.catalogue.events_partial }} · Sans mapping : {{ meta.catalogue.events_missing }}</p><input type="file" accept=".csv,.txt,.xlsx" @change="chooseImport"><button class="admin-outline" type="button" :disabled="!importFile || importBusy" @click="dryRunImport">{{ importBusy ? 'Simulation…' : 'Simuler l’import' }}</button><div v-if="importReport"><p>{{ importReport.summary.total }} ligne(s) · {{ importReport.summary.conflicts }} conflit(s)</p><button class="admin-action" type="button" :disabled="importBusy || importReport.summary.conflicts > 0" @click="commitImport">Enregistrer le rapport</button></div></details>

      <Transition name="admin-overlay"><div v-if="detail" class="admin-overlay meta-detail-overlay" role="presentation" @click.self="closeDetail"><section ref="detailDialog" class="meta-detail" role="dialog" aria-modal="true" aria-labelledby="meta-event-title" tabindex="-1" @keydown.esc="closeDetail"><header class="meta-detail__header"><div><p class="admin-eyebrow">{{ eventLabels[detail.event_name] }}</p><h2 id="meta-event-title">Détail de l’événement</h2></div><button class="admin-dialog-close" type="button" aria-label="Fermer le détail" @click="closeDetail">×</button></header>
        <div class="meta-detail__body"><p class="meta-event-explanation">{{ explanation(detail) }}</p><div class="meta-detail__status"><span class="admin-badge">{{ detail.is_synthetic ? 'Test' : 'Événement réel' }}</span><span class="admin-badge" :class="{ 'is-published': detail.capi_state === 'succeeded', 'is-danger': detail.capi_state === 'permanent_failure' }">{{ serverStatus(detail) }}</span></div>
        <div class="meta-detail__grid"><section class="meta-detail__section"><h3>Résumé</h3><dl class="meta-detail__facts"><dt>Événement</dt><dd>{{ detail.event_name }}</dd><dt>Date</dt><dd>{{ new Date(detail.event_time).toLocaleString('fr-TN') }}</dd><dt>Mode</dt><dd>{{ detail.mode === 'test' ? 'Test' : 'Production' }}</dd><dt>Configuration</dt><dd>Version {{ detail.configuration_version || '—' }}</dd><dt>URL source</dt><dd class="meta-detail__wrap">{{ detail.source_url || '—' }}</dd></dl></section>
        <section class="meta-detail__section"><h3>Navigateur</h3><dl class="meta-detail__facts"><dt>Statut</dt><dd>{{ detail.is_synthetic ? 'Non applicable' : browserLabels[detail.browser_state] }}</dd><dt>Pixel</dt><dd>{{ detail.pixel_id || 'Non configuré' }}</dd><dt>Event ID</dt><dd class="meta-detail__wrap">{{ detail.event_id }}</dd></dl></section></div>
        <section class="meta-detail__section"><h3>Serveur</h3><p v-if="!detail.attempts?.length" class="admin-empty-copy">Aucune tentative serveur enregistrée.</p><article v-for="attempt in detail.attempts" :key="attempt.attempt_number" class="meta-attempt"><header><strong>Tentative {{ attempt.attempt_number }}</strong><time>{{ new Date(attempt.attempted_at).toLocaleString('fr-TN') }}</time></header><dl class="meta-detail__facts"><dt>Requête envoyée</dt><dd>{{ attempt.request_sent ? 'Oui' : 'Non' }}</dd><dt>HTTP</dt><dd>{{ attempt.http_status || '—' }}</dd><dt>Événements reçus</dt><dd>{{ attempt.events_received ?? '—' }}</dd><dt>État</dt><dd>{{ attempt.error_classification || 'Accepté' }}</dd><dt v-if="attempt.meta_error_code">Code Meta</dt><dd v-if="attempt.meta_error_code">{{ attempt.meta_error_code }}<template v-if="attempt.meta_error_subcode"> / {{ attempt.meta_error_subcode }}</template></dd><dt v-if="attempt.safe_message">Message</dt><dd v-if="attempt.safe_message" class="meta-detail__wrap">{{ attempt.safe_message }}</dd><dt v-if="attempt.fbtrace_id">fbtrace_id</dt><dd v-if="attempt.fbtrace_id" class="meta-detail__wrap">{{ attempt.fbtrace_id }}</dd><dt>API</dt><dd>{{ attempt.graph_api_version || '—' }}</dd></dl></article></section>
        <section class="meta-detail__section"><h3>Déduplication</h3><dl class="meta-detail__facts"><dt>Même événement</dt><dd>Oui, {{ detail.event_name }}</dd><dt>Même Event ID</dt><dd>Oui, {{ detail.event_id }}</dd><dt>Résultat</dt><dd>{{ dedupLabels[detail.deduplication_status] || 'Indisponible' }}</dd></dl></section>
        <form v-if="detail.retry_eligible" class="meta-retry" @submit.prevent="retry"><label>Mot de passe actuel<input v-model="retryPassword" type="password" autocomplete="current-password" required></label><button class="admin-action" :disabled="retrying">{{ retrying ? 'Relance…' : 'Relancer la livraison' }}</button></form></div>
      </section></div></Transition>
    </section>`,
};

export default MetaDiagnosticsView;
