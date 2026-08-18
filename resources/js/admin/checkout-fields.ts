import { computed, onMounted, ref, type Component } from 'vue';
import { adminApi } from './api';
import { showError, showToast } from './feedback';
import SelectControl from './select-control';
import '../../css/admin-list-pages.css';

type Field = {
    public_id: string;
    key: string;
    label: string;
    type: string;
    options: string[] | null;
    is_required: boolean;
    is_active: boolean;
    is_system: boolean;
    sort_order: number;
};

const emptyForm = () => ({
    key: '',
    label: '',
    type: 'text',
    optionsText: '',
    is_required: false,
    is_active: true,
});

const CheckoutFieldsView: Component = {
    components: { SelectControl },
    setup() {
        const rows = ref<Field[]>([]);
        const loading = ref(true);
        const saving = ref(false);
        const promoVisible = ref(false);
        const editing = ref<Field | 'new' | null>(null);
        const form = ref(emptyForm());
        const typeOptions = [
            { value: 'text', label: 'Texte' },
            { value: 'textarea', label: 'Texte long' },
            { value: 'number', label: 'Nombre' },
            { value: 'select', label: 'Liste déroulante' },
            { value: 'radio', label: 'Choix unique' },
            { value: 'checkbox', label: 'Case à cocher' },
        ];
        const summary = computed(() => [
            { label: 'Champs', value: rows.value.length },
            { label: 'Actifs', value: rows.value.filter((field) => field.is_active).length },
            { label: 'Obligatoires', value: rows.value.filter((field) => field.is_required).length },
            { label: 'Système', value: rows.value.filter((field) => field.is_system).length },
        ]);

        const load = async () => {
            loading.value = true;
            try {
                const payload = await adminApi<{ data: Field[]; meta: { promo_code_field_visible: boolean } }>('checkout-fields');
                rows.value = payload.data;
                promoVisible.value = payload.meta.promo_code_field_visible;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement impossible.');
            } finally {
                loading.value = false;
            }
        };

        const open = (field?: Field) => {
            editing.value = field || 'new';
            form.value = field
                ? {
                      key: field.key,
                      label: field.label,
                      type: field.type,
                      optionsText: (field.options ?? []).join('\n'),
                      is_required: field.is_required,
                      is_active: field.is_active,
                  }
                : emptyForm();
        };

        const save = async () => {
            const existing = typeof editing.value === 'object' ? editing.value : null;
            saving.value = true;
            try {
                const choice = ['select', 'radio'].includes(form.value.type);
                const payload = existing?.is_system
                    ? { label: form.value.label, sort_order: existing.sort_order }
                    : {
                          key: form.value.key,
                          label: form.value.label,
                          type: form.value.type,
                          options: choice
                              ? form.value.optionsText.split('\n').map((option) => option.trim()).filter(Boolean)
                              : null,
                          is_required: form.value.is_required,
                          is_active: form.value.is_active,
                          sort_order: existing?.sort_order ?? rows.value.length,
                      };
                await adminApi(existing ? `checkout-fields/${existing.public_id}` : 'checkout-fields', existing ? 'PATCH' : 'POST', payload);
                showToast('success', existing ? 'Champ mis à jour.' : 'Champ ajouté.');
                editing.value = null;
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            } finally {
                saving.value = false;
            }
        };

        const move = async (index: number, delta: number) => {
            const target = index + delta;
            if (target < 0 || target >= rows.value.length) return;
            const reordered = [...rows.value];
            [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
            try {
                await adminApi('checkout-fields/reorder', 'POST', {
                    items: reordered.map((field, position) => ({ public_id: field.public_id, sort_order: position })),
                });
                rows.value = reordered;
                showToast('success', 'Ordre mis à jour.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Réorganisation impossible.');
            }
        };

        const savePromoVisibility = async () => {
            try {
                await adminApi('settings/checkout', 'PATCH', { promo_code_field_visible: promoVisible.value });
                showToast('success', 'Visibilité du code promo enregistrée.');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            }
        };

        onMounted(load);

        return {
            editing,
            form,
            load,
            loading,
            move,
            open,
            promoVisible,
            rows,
            save,
            savePromoVisibility,
            saving,
            summary,
            typeOptions,
        };
    },
    template: `<section class="admin-page admin-list-page checkout-fields-list-page"><header><div><p class="admin-eyebrow">Réglages / Commande</p><h1>Champs de commande</h1><p class="admin-subtitle">Les champs système restent protégés. Chaque modification actualise la version du formulaire.</p></div><button class="admin-action" @click="open()">Ajouter un champ</button></header>
      <form class="category-form checkout-promo-setting" @submit.prevent="savePromoVisibility"><label class="inline-check">Afficher le champ code promo au panier et à la commande <input v-model="promoVisible" type="checkbox"></label><button class="admin-outline">Enregistrer la visibilité</button></form>
      <form v-if="editing" class="category-form checkout-field-editor" @submit.prevent="save"><header><h2>{{ editing.public_id ? 'Modifier le champ' : 'Nouveau champ' }}</h2><button class="text-link" type="button" @click="editing = null">Fermer</button></header><div class="form-grid"><label>Clé<input v-model="form.key" :disabled="editing.is_system" required></label><label>Libellé français<input v-model="form.label" required></label><label>Type<SelectControl v-model="form.type" :options="typeOptions" :disabled="editing.is_system" /></label><label v-if="['select','radio'].includes(form.type)" class="full">Options, une par ligne<textarea v-model="form.optionsText"></textarea></label><label class="inline-check">Obligatoire <input v-model="form.is_required" type="checkbox" :disabled="editing.is_system"></label><label class="inline-check">Actif <input v-model="form.is_active" type="checkbox" :disabled="editing.is_system"></label></div><footer class="sticky-save-bar"><button class="admin-action" type="submit" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer le champ' }}</button></footer></form>
      <template v-else><section class="list-summary-strip" aria-label="Résumé des champs"><article v-for="item in summary" :key="item.label"><small>{{ item.label }}</small><strong>{{ item.value }}</strong></article></section><p class="list-instruction" role="note">Les champs système restent protégés. Utilisez les flèches pour modifier l’ordre du formulaire de commande.</p><p v-if="loading" class="admin-loading">Chargement…</p><div v-else class="admin-table checkout-fields-table admin-entity-list"><div class="admin-table-head"><span>Champ</span><span>Type</span><span>État</span><span>Actions</span></div><article v-for="(field,index) in rows" :key="field.public_id"><div><strong>{{ field.label }}</strong><small>{{ field.key }} {{ field.is_system ? '· système verrouillé' : '· personnalisé' }}</small></div><span>{{ typeOptions.find(option => option.value === field.type)?.label }}</span><span><span class="admin-badge" :class="{ 'is-published': field.is_active }">{{ field.is_active ? 'Actif' : 'Inactif' }}</span><small>{{ field.is_required ? 'Obligatoire' : 'Facultatif' }}</small></span><span class="admin-row-actions"><button class="admin-icon-action" type="button" :disabled="index === 0" title="Monter le champ" :aria-label="'Monter ' + field.label" @click="move(index,-1)">↑</button><button class="admin-icon-action" type="button" :disabled="index === rows.length - 1" title="Descendre le champ" :aria-label="'Descendre ' + field.label" @click="move(index,1)">↓</button><button class="admin-icon-action" type="button" title="Modifier le champ" :aria-label="'Modifier ' + field.label" @click="open(field)">✎</button></span></article></div></template></section>`,
};

export default CheckoutFieldsView;
