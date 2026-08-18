import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { adminApi } from './api';
import SelectControl from './select-control';
import { confirmAction, showError, showToast } from './feedback';
import { normalizeComplaint, normalizeComplaintMeta, normalizeComplaintRows, type Complaint, type ComplaintMeta, type ComplaintPagePayload } from './complaint-adapters';
import '../../css/admin-list-pages.css';

const statusLabel = (status: string) => status === 'en_cours' ? 'En cours' : status === 'resolue' ? 'Résolue' : 'Nouvelle';

export const ComplaintsView: Component = {
    components: { RouterLink, SelectControl },
    setup() {
        const rows = ref<Complaint[]>([]);
        const meta = ref<ComplaintMeta>({ current_page: 1, last_page: 1, total: 0 });
        const search = ref('');
        const status = ref('');
        const dateFrom = ref('');
        const dateTo = ref('');
        const loading = ref(true);
        const deleting = ref<string | null>(null);
        const summary = computed(() => [
            { label: 'Résultats', value: meta.value.total },
            { label: 'Nouvelles sur cette page', value: rows.value.filter((complaint) => complaint.status === 'nouvelle').length },
            { label: 'En cours sur cette page', value: rows.value.filter((complaint) => complaint.status === 'en_cours').length },
            { label: 'Résolues sur cette page', value: rows.value.filter((complaint) => complaint.status === 'resolue').length },
        ]);
        let requestId = 0;
        const options = [{ value: '', label: 'Tous les états' }, { value: 'nouvelle', label: 'Nouvelle' }, { value: 'en_cours', label: 'En cours' }, { value: 'resolue', label: 'Résolue' }];

        const load = async (page = 1) => {
            const currentRequest = ++requestId;
            loading.value = true;
            try {
                const query = new URLSearchParams({ per_page: '25', page: String(page) });
                if (search.value) query.set('search', search.value);
                if (status.value) query.set('status', status.value);
                if (dateFrom.value) query.set('date_from', dateFrom.value);
                if (dateTo.value) query.set('date_to', dateTo.value);
                const payload = (await adminApi<{ data?: ComplaintPagePayload }>(`complaints?${query}`)).data;
                if (currentRequest !== requestId) return;
                rows.value = normalizeComplaintRows(payload?.data);
                meta.value = normalizeComplaintMeta(payload);
            } catch (cause) {
                if (currentRequest === requestId) {
                    rows.value = [];
                    meta.value = normalizeComplaintMeta(null);
                    showError(cause instanceof Error ? cause.message : 'Chargement impossible.');
                }
            } finally {
                if (currentRequest === requestId) loading.value = false;
            }
        };
        let timer: number | undefined;
        const queueSearch = () => { window.clearTimeout(timer); timer = window.setTimeout(() => void load(), 280); };
        const reset = () => { search.value = ''; status.value = ''; dateFrom.value = ''; dateTo.value = ''; void load(1); };
        const remove = async (complaint: Complaint) => {
            if (deleting.value) return;
            const confirmed = await confirmAction('Supprimer la réclamation ?', `${complaint.public_reference} · ${complaint.subject}\nCette action retire la réclamation de la liste. Son historique reste conservé.`, 'Supprimer', 'danger');
            if (!confirmed) return;
            deleting.value = complaint.public_reference;
            try {
                await adminApi(`complaints/${complaint.public_reference}`, 'DELETE');
                rows.value = rows.value.filter((row) => row.public_reference !== complaint.public_reference);
                meta.value = { ...meta.value, total: Math.max(0, meta.value.total - 1) };
                showToast('success', 'Réclamation supprimée de la liste.');
                if (!rows.value.length && meta.value.current_page > 1) await load(meta.value.current_page - 1);
            } catch (cause) { showError(cause instanceof Error ? cause.message : 'Suppression impossible.'); }
            finally { deleting.value = null; }
        };

        onMounted(load);
        onBeforeUnmount(() => { requestId++; window.clearTimeout(timer); });
        return { rows, meta, search, status, dateFrom, dateTo, options, loading, deleting, load, queueSearch, reset, remove, statusLabel, summary };
    },
    template: `<section class="admin-page admin-list-page complaints-list-page">
      <header><div><p class="admin-eyebrow">Service client</p><h1>Réclamations</h1><p class="admin-subtitle">Suivi privé des demandes et de leur chronologie.</p></div></header>
      <section class="list-summary-strip" aria-label="Résumé des réclamations"><article v-for="item in summary" :key="item.label"><small>{{ item.label }}</small><strong>{{ item.value }}</strong></article></section>
      <div class="admin-filter-bar list-filter-toolbar"><label class="admin-search"><span class="sr-only">Rechercher</span><input v-model="search" @input="queueSearch" placeholder="Référence, client ou sujet…"></label><SelectControl v-model="status" :options="options" @change="load()"/><label>Du<input v-model="dateFrom" type="date" @change="load()"></label><label>Au<input v-model="dateTo" type="date" @change="load()"></label><button class="text-link" type="button" @click="reset">Réinitialiser</button></div>
      <p class="list-instruction" role="note">Ouvrez une réclamation pour consulter son contexte, son historique et les actions disponibles.</p>
      <p v-if="loading" class="admin-loading">Chargement…</p><p v-else-if="!rows.length" class="admin-empty">Aucune réclamation ne correspond aux filtres.</p>
      <div v-else class="admin-table complaints-table admin-entity-list"><div class="admin-table-head"><span>Référence / client</span><span>Réclamation</span><span>État</span><span>Action</span></div><article v-for="complaint in rows" :key="complaint.public_reference"><div><strong>{{ complaint.public_reference }}</strong><small>{{ complaint.customer_name }}</small></div><span><strong>{{ complaint.subject }}</strong><small>Demande client</small></span><span class="admin-badge" :class="{ 'is-published': complaint.status === 'resolue', warning: complaint.status === 'nouvelle' }">{{ statusLabel(complaint.status) }}</span><span class="admin-row-actions"><RouterLink class="admin-icon-action" :to="'/complaints/'+complaint.public_reference" title="Ouvrir la réclamation" :aria-label="'Ouvrir la réclamation ' + complaint.public_reference">↗</RouterLink><button class="admin-icon-action is-danger" type="button" title="Supprimer la réclamation" :aria-label="'Supprimer la réclamation ' + complaint.public_reference" :disabled="deleting === complaint.public_reference" @click="remove(complaint)">×</button></span></article></div>
      <nav v-if="meta.last_page > 1" class="admin-pagination" aria-label="Pagination"><button class="admin-outline" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Précédent</button><span>Page {{ meta.current_page }} sur {{ meta.last_page }}</span><button class="admin-outline" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Suivant</button></nav>
    </section>`,
};

export const ComplaintDetailView: Component = {
    components: { RouterLink },
    setup() {
        const route = useRoute();
        const complaint = ref<Complaint | null>(null);
        const note = ref('');
        const loading = ref(true);
        const deleting = ref(false);
        let requestId = 0;
        const load = async () => {
            const currentRequest = ++requestId;
            loading.value = true;
            try {
                const result = await adminApi<{ data?: unknown }>(`complaints/${route.params.reference}`);
                const next = normalizeComplaint(result.data);
                if (!next) throw new Error('La réclamation reçue est invalide.');
                if (currentRequest !== requestId) return;
                complaint.value = next;
            } catch (cause) {
                if (currentRequest === requestId) {
                    complaint.value = null;
                    showError(cause instanceof Error ? cause.message : 'Chargement impossible.');
                }
            } finally {
                if (currentRequest === requestId) loading.value = false;
            }
        };
        const transition = async () => {
            if (!complaint.value) return;
            const to = complaint.value.status === 'nouvelle' ? 'en_cours' : 'resolue';
            try { await adminApi(`complaints/${complaint.value.public_reference}/transitions`, 'POST', { to_status: to }); showToast('success', 'Statut mis à jour.'); await load(); }
            catch (cause) { showError(cause instanceof Error ? cause.message : 'Transition impossible.'); }
        };
        const addNote = async () => {
            if (!complaint.value || !note.value.trim()) return;
            try { await adminApi(`complaints/${complaint.value.public_reference}/notes`, 'POST', { body: note.value }); note.value = ''; showToast('success', 'Note ajoutée.'); await load(); }
            catch (cause) { showError(cause instanceof Error ? cause.message : 'Ajout impossible.'); }
        };
        const remove = async () => {
            if (!complaint.value || deleting.value) return;
            const current = complaint.value;
            if (!await confirmAction('Supprimer la réclamation ?', `${current.public_reference} · ${current.subject}\nCette action retire la réclamation de la liste. Son historique reste conservé.`, 'Supprimer', 'danger')) return;
            deleting.value = true;
            try { await adminApi(`complaints/${current.public_reference}`, 'DELETE'); showToast('success', 'Réclamation supprimée de la liste.'); window.location.assign('/admin/complaints'); }
            catch (cause) { showError(cause instanceof Error ? cause.message : 'Suppression impossible.'); deleting.value = false; }
        };
        onMounted(load);
        watch(() => route.params.reference, () => void load());
        onBeforeUnmount(() => { requestId++; });
        return { complaint, note, loading, deleting, transition, addNote, remove, statusLabel };
    },
    template: `<section v-if="complaint" class="admin-page complaint-detail-page">
      <RouterLink class="back-link" to="/complaints">← Retour aux réclamations</RouterLink><header class="complaint-detail-header"><div><p class="admin-eyebrow">Réclamation {{ complaint.public_reference }}</p><h1>{{ complaint.subject }}</h1><div class="complaint-detail-meta"><span class="admin-badge" :class="{ 'is-published': complaint.status === 'resolue', warning: complaint.status === 'nouvelle' }">{{ statusLabel(complaint.status) }}</span><span>Reçue le {{ new Date(complaint.created_at).toLocaleString('fr-TN') }}</span></div></div><div class="complaint-detail-actions"><button v-if="complaint.status !== 'resolue'" class="admin-action" type="button" @click="transition">{{ complaint.status === 'nouvelle' ? 'Prendre en charge' : 'Marquer résolue' }}</button><button class="admin-icon-action is-danger" type="button" title="Supprimer la réclamation" aria-label="Supprimer la réclamation" :disabled="deleting" @click="remove">×</button></div></header>
      <div class="complaint-detail-layout"><main><section class="order-panel complaint-message-panel"><div class="panel-heading"><div><p class="admin-eyebrow">Message client</p><h2>Réclamation</h2></div></div><p class="complaint-body">{{ complaint.description }}</p><a v-if="complaint.has_attachment" class="admin-outline" :href="'/api/v1/admin/complaints/'+complaint.public_reference+'/attachment'">Télécharger la pièce jointe privée</a><p v-else class="admin-empty-inline">Aucune pièce jointe.</p></section><section class="order-panel"><div class="panel-heading"><div><p class="admin-eyebrow">Suivi interne</p><h2>Notes de l’équipe</h2></div></div><form @submit.prevent="addNote"><label class="admin-form-label" for="complaint-note">Ajouter une note interne</label><textarea id="complaint-note" v-model="note" maxlength="5000" required></textarea><button class="admin-action" type="submit">Ajouter la note</button></form><ul v-if="complaint.notes?.length" class="complaint-note-list"><li v-for="entry in complaint.notes || []" :key="entry.id"><div><strong>{{ entry.user?.name || 'Utilisateur supprimé' }}</strong><small>{{ new Date(entry.created_at).toLocaleString('fr-TN') }}</small></div><p>{{ entry.body }}</p></li></ul><p v-else class="admin-empty-inline">Aucune note interne pour le moment.</p></section></main><aside><section class="order-panel complaint-facts-panel"><div class="panel-heading"><div><p class="admin-eyebrow">Coordonnées</p><h2>Client</h2></div></div><dl><dt>Nom</dt><dd>{{ complaint.customer_name }}</dd><dt>Téléphone</dt><dd>{{ complaint.customer_phone }}</dd><dt>Commande liée</dt><dd>{{ complaint.order?.public_reference || 'Aucune commande associée' }}</dd></dl></section><section class="order-panel"><div class="panel-heading"><div><p class="admin-eyebrow">Historique</p><h2>Chronologie</h2></div></div><ol class="complaint-timeline"><li v-for="entry in complaint.status_history || []" :key="entry.id"><strong>{{ statusLabel(entry.to_status) }}</strong><small>{{ new Date(entry.created_at).toLocaleString('fr-TN') }} · {{ entry.actor?.name || 'Système' }}</small></li><li v-if="!(complaint.status_history || []).length" class="admin-empty-inline">Aucun changement enregistré.</li></ol></section></aside></div>
    </section><p v-else-if="loading" class="admin-loading">Chargement de la réclamation…</p><p v-else class="admin-empty">Cette réclamation est indisponible.</p>`,
};
