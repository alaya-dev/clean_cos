import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import { adminApi } from './api';
import { showError, showToast } from './feedback';
import '../../css/admin-list-pages.css';

type Page = { key: string; title: string; slug: string; content: string; seo_title: string | null; seo_description: string | null; is_active: boolean; updated_at: string };

const StaticPagesView: Component = {
    setup() {
        const pages = ref<Page[]>([]);
        const editing = ref<Page | null>(null);
        const saving = ref(false);
        const dirty = ref(false);
        const summary = computed(() => [
            { label: 'Pages', value: pages.value.length },
            { label: 'Actives', value: pages.value.filter((page) => page.is_active).length },
            { label: 'Inactives', value: pages.value.filter((page) => !page.is_active).length },
        ]);
        const load = async () => {
            try {
                pages.value = (await adminApi<{ data: Page[] }>('content/pages')).data;
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Chargement impossible.');
            }
        };
        const beginEdit = (page: Page) => { editing.value = { ...page }; dirty.value = false; };
        const closeEditor = () => {
            if (dirty.value && !window.confirm('Abandonner les modifications non enregistrées ?')) return;
            editing.value = null;
            dirty.value = false;
        };
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (!dirty.value) return;
            event.preventDefault();
            event.returnValue = '';
        };
        const save = async () => {
            if (!editing.value) return;
            saving.value = true;
            try {
                const page = editing.value;
                await adminApi(`content/pages/${page.key}`, 'PATCH', {
                    title: page.title,
                    slug: page.slug,
                    content: page.content,
                    seo_title: page.seo_title,
                    seo_description: page.seo_description,
                    is_active: page.is_active,
                });
                showToast('success', 'Page enregistrée.');
                editing.value = null;
                dirty.value = false;
                await load();
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Enregistrement impossible.');
            } finally {
                saving.value = false;
            }
        };

        onMounted(() => { void load(); window.addEventListener('beforeunload', beforeUnload); });
        onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

        return { beginEdit, closeEditor, dirty, editing, pages, save, saving, summary };
    },
    template: `<section class="admin-page admin-list-page static-pages-list-page">
      <header><div><p class="admin-eyebrow">Contenu / Pages</p><h1>Pages statiques</h1><p class="admin-subtitle">Contenus français sécurisés et métadonnées SEO.</p></div></header>
      <form v-if="editing" class="category-form long-form" @input="dirty = true" @change="dirty = true" @submit.prevent="save"><header><h2>{{ editing.title }}</h2><button class="text-link" type="button" @click="closeEditor">Fermer</button></header><div class="form-grid"><label>Titre<input v-model="editing.title" required></label><label>Slug<input v-model="editing.slug" required></label><label class="full">Contenu HTML autorisé<textarea v-model="editing.content" rows="16" required></textarea><small>Paragraphes, titres, listes, emphase et liens sûrs uniquement.</small></label><label>Titre SEO<input v-model="editing.seo_title" maxlength="255"></label><label>Description SEO<textarea v-model="editing.seo_description" maxlength="320"></textarea></label><label class="inline-check">Page active <input v-model="editing.is_active" type="checkbox"></label></div><footer class="sticky-save-bar"><a class="text-link" :href="'/pages/' + editing.slug" target="_blank">Aperçu public</a><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></footer></form>
      <template v-else><section class="list-summary-strip" aria-label="Résumé des pages"><article v-for="item in summary" :key="item.label"><small>{{ item.label }}</small><strong>{{ item.value }}</strong></article></section><p class="list-instruction" role="note">Modifiez une page pour mettre à jour son contenu, son URL et son statut de publication.</p><div class="admin-table static-pages-table admin-entity-list"><div class="admin-table-head"><span>Page</span><span>Slug</span><span>État</span><span>Action</span></div><article v-for="page in pages" :key="page.key"><div><strong>{{ page.title }}</strong><small>Dernière mise à jour : {{ new Date(page.updated_at).toLocaleDateString('fr-TN') }}</small></div><span><code>/{{ page.slug }}</code></span><span :class="page.is_active ? 'admin-badge is-published' : 'admin-badge'">{{ page.is_active ? 'Active' : 'Inactive' }}</span><span class="admin-row-actions"><button class="admin-icon-action" type="button" title="Modifier la page" :aria-label="'Modifier ' + page.title" @click="beginEdit(page)">✎</button></span></article></div></template>
    </section>`,
};

export default StaticPagesView;
