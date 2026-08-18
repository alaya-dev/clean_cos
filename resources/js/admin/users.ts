import { onMounted, reactive, ref, type Component } from 'vue';
import { adminApi } from './api';
import { confirmAction, showError, showToast } from './feedback';
import SelectControl from './select-control';

type User = {
    public_id: string;
    name: string;
    email: string;
    role: 'super_admin' | 'admin';
    is_active: boolean;
    force_password_change: boolean;
    created_at?: string;
};

type Page<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };
type CurrentUser = { public_id: string };

const roleLabel = (role: User['role']) => role === 'super_admin' ? 'Super Admin' : 'Administrateur';

const UsersView: Component = {
    components: { SelectControl },
    setup() {
        const users = ref<User[]>([]);
        const loading = ref(true);
        const error = ref('');
        const saving = ref(false);
        const formError = ref('');
        const page = ref(1);
        const meta = ref<Page<User>['meta']>({ current_page: 1, last_page: 1, total: 0 });
        const search = ref('');
        const role = ref('');
        const state = ref('');
        const editorOpen = ref(false);
        const editing = ref<User | null>(null);
        const currentUserPublicId = ref('');
        const deletingPublicId = ref('');
        const form = reactive({ name: '', email: '', role: 'admin', is_active: true, password: '', password_confirmation: '' });
        let debounce: number | undefined;

        const resetForm = () => Object.assign(form, { name: '', email: '', role: 'admin', is_active: true, password: '', password_confirmation: '' });
        const params = () => new URLSearchParams(Object.entries({ search: search.value, role: role.value, is_active: state.value, page: String(page.value), per_page: '15' }).filter(([, value]) => value !== ''));

        const load = async () => {
            loading.value = true;
            error.value = '';
            try {
                const response = await adminApi<{ data: Page<User> }>(`users?${params().toString()}`);
                users.value = response.data.data;
                meta.value = response.data.meta;
            } catch (cause) {
                error.value = cause instanceof Error ? cause.message : 'Le chargement des utilisateurs a échoué.';
            } finally {
                loading.value = false;
            }
        };

        const loadCurrentUser = async () => {
            try {
                const response = await adminApi<{ data: CurrentUser }>('me');
                currentUserPublicId.value = response.data.public_id;
            } catch {
                currentUserPublicId.value = '';
            }
        };

        const queueLoad = () => {
            window.clearTimeout(debounce);
            debounce = window.setTimeout(() => { page.value = 1; void load(); }, 300);
        };

        const openCreate = () => {
            editing.value = null;
            resetForm();
            formError.value = '';
            editorOpen.value = true;
        };

        const openEdit = (user: User) => {
            editing.value = user;
            Object.assign(form, { name: user.name, email: user.email, role: user.role, is_active: user.is_active, password: '', password_confirmation: '' });
            formError.value = '';
            editorOpen.value = true;
        };

        const save = async () => {
            saving.value = true;
            formError.value = '';
            try {
                const payload = editing.value
                    ? { name: form.name, email: form.email, role: form.role, is_active: form.is_active, ...(!isCurrentUser(editing.value) && form.password ? { password: form.password, password_confirmation: form.password_confirmation } : {}) }
                    : { ...form };
                await adminApi(`users${editing.value ? `/${editing.value.public_id}` : ''}`, editing.value ? 'PATCH' : 'POST', payload);
                editorOpen.value = false;
                await load();
            } catch (cause) {
                formError.value = cause instanceof Error ? cause.message : 'L’enregistrement a échoué.';
            } finally {
                saving.value = false;
            }
        };

        const resetFilters = () => {
            search.value = '';
            role.value = '';
            state.value = '';
            page.value = 1;
            void load();
        };

        const changePage = (next: number) => {
            if (next < 1 || next > meta.value.last_page) return;
            page.value = next;
            void load();
        };

        const isCurrentUser = (user: User | null) => user?.public_id === currentUserPublicId.value;
        const canEdit = (user: User) => user.role !== 'super_admin' || isCurrentUser(user);
        const canDelete = (user: User) => user.role === 'admin' && !isCurrentUser(user);
        const removeUser = async (user: User) => {
            if (!canDelete(user)) return;
            const confirmed = await confirmAction('Supprimer cet administrateur ?', `L’accès de « ${user.name} » sera retiré immédiatement. L’historique opérationnel reste conservé.`, 'Supprimer', 'danger');
            if (!confirmed) return;
            deletingPublicId.value = user.public_id;
            try {
                await adminApi(`users/${user.public_id}`, 'DELETE');
                users.value = users.value.filter((candidate) => candidate.public_id !== user.public_id);
                meta.value.total = Math.max(0, meta.value.total - 1);
                showToast('success', 'Administrateur supprimé.');
                if (!users.value.length && meta.value.current_page > 1) await changePage(meta.value.current_page - 1);
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Suppression impossible.');
            } finally {
                deletingPublicId.value = '';
            }
        };

        onMounted(() => { void loadCurrentUser(); void load(); });
        return { users, loading, error, saving, formError, page, meta, search, role, state, editorOpen, editing, form, roleLabel, queueLoad, load, openCreate, openEdit, save, resetFilters, changePage, isCurrentUser, canEdit, canDelete, removeUser, deletingPublicId,
            roleOptions: [{ value: '', label: 'Tous les rôles' }, { value: 'super_admin', label: 'Super Admin' }, { value: 'admin', label: 'Administrateur' }],
            formRoleOptions: [{ value: 'admin', label: 'Administrateur' }, { value: 'super_admin', label: 'Super Admin' }],
            stateOptions: [{ value: '', label: 'Tous les états' }, { value: '1', label: 'Actifs' }, { value: '0', label: 'Inactifs' }],
        };
    },
    template: `
      <section class="admin-page access-page">
        <header class="admin-page-header"><div><p class="admin-eyebrow">Accès</p><h1>Utilisateurs</h1><p class="admin-subtitle">Gérez les accès du back-office. Les changements sensibles restent protégés côté serveur.</p></div><button class="admin-action" type="button" @click="openCreate">Nouvel utilisateur</button></header>
        <section class="admin-filter-bar" aria-label="Filtres des utilisateurs"><label class="admin-search"><span class="sr-only">Rechercher un utilisateur</span><input v-model.trim="search" type="search" placeholder="Rechercher par nom ou e-mail…" @input="queueLoad"></label><label class="toolbar-select"><span>Rôle</span><SelectControl v-model="role" :options="roleOptions" @change="page = 1; load()" /></label><label class="toolbar-select"><span>État</span><SelectControl v-model="state" :options="stateOptions" @change="page = 1; load()" /></label><button class="text-link" type="button" @click="resetFilters">Réinitialiser</button></section>
        <p v-if="error" class="page-error" role="alert">{{ error }} <button class="text-link" type="button" @click="load">Réessayer</button></p>
        <p v-else-if="loading" class="admin-loading">Chargement des utilisateurs…</p>
        <section v-else-if="!users.length" class="admin-empty" aria-live="polite"><strong>Aucun utilisateur ne correspond à ces critères.</strong><span>Modifiez les filtres ou créez un nouvel accès.</span></section>
        <div v-else class="admin-table users-table"><div class="admin-table-head"><span>Utilisateur</span><span>Rôle</span><span>État</span><span>Action</span></div><article v-for="user in users" :key="user.public_id"><div><strong>{{ user.name }}</strong><small>{{ user.email }}</small></div><span class="admin-badge">{{ roleLabel(user.role) }}</span><span><span class="admin-badge" :class="user.is_active ? 'status-active' : 'warning'">{{ user.is_active ? 'Actif' : 'Inactif' }}</span></span><span><button v-if="canEdit(user)" class="text-link" type="button" @click="openEdit(user)">Modifier</button><small v-else>Protégé</small></span></article></div>
        <nav v-if="meta.last_page > 1" class="admin-pagination" aria-label="Pagination des utilisateurs"><button class="admin-outline" type="button" :disabled="page === 1" @click="changePage(page - 1)">Précédent</button><span>Page {{ meta.current_page }} sur {{ meta.last_page }} · {{ meta.total }} accès</span><button class="admin-outline" type="button" :disabled="page === meta.last_page" @click="changePage(page + 1)">Suivant</button></nav>
        <section v-if="editorOpen" class="management-panel" :aria-labelledby="editing ? 'edit-user-title' : 'create-user-title'"><header><div><p class="admin-eyebrow">{{ editing ? 'Modification' : 'Création' }}</p><h2 :id="editing ? 'edit-user-title' : 'create-user-title'">{{ editing ? 'Modifier l’utilisateur' : 'Nouvel utilisateur' }}</h2></div><button class="text-link" type="button" :disabled="saving" @click="editorOpen = false">Fermer</button></header><form class="category-form" @submit.prevent="save"><p v-if="formError" class="page-error" role="alert">{{ formError }}</p><div class="form-grid"><label>Nom complet <b aria-hidden="true">*</b><input v-model.trim="form.name" autocomplete="name" required maxlength="120"></label><label>E-mail professionnel <b aria-hidden="true">*</b><input v-model.trim="form.email" type="email" autocomplete="email" required maxlength="255"></label><label class="toolbar-select">Rôle <b aria-hidden="true">*</b><SelectControl v-model="form.role" :options="formRoleOptions" :disabled="isCurrentUser(editing)" /><small v-if="isCurrentUser(editing)">Votre rôle Super Admin ne peut pas être retiré depuis votre propre compte.</small></label><label v-if="!editing || !isCurrentUser(editing)">Nouveau mot de passe <b v-if="!editing" aria-hidden="true">*</b><input v-model="form.password" type="password" autocomplete="new-password" :required="!editing" minlength="8"><small>{{ editing ? 'Laissez vide pour le conserver.' : '8 caractères minimum.' }}</small></label><label v-if="!editing || !isCurrentUser(editing)">Confirmation <b v-if="!editing" aria-hidden="true">*</b><input v-model="form.password_confirmation" type="password" autocomplete="new-password" :required="!editing" minlength="8"></label><label class="inline-check">Accès actif <input v-model="form.is_active" type="checkbox" :disabled="isCurrentUser(editing)"></label></div><footer class="sticky-save-bar"><button class="text-link" type="button" :disabled="saving" @click="editorOpen = false">Annuler</button><button class="admin-action" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></footer></form></section>
      </section>`,
};

UsersView.template = (UsersView.template as string).replace(
    '<button v-if="canEdit(user)" class="text-link" type="button" @click="openEdit(user)">Modifier</button><small v-else>Protégé</small>',
    '<button v-if="canEdit(user)" class="text-link" type="button" @click="openEdit(user)">Modifier</button><button v-if="canDelete(user)" class="text-link danger" type="button" :disabled="deletingPublicId === user.public_id" @click="removeUser(user)">{{ deletingPublicId === user.public_id ? \'Suppression…\' : \'Supprimer\' }}</button><small v-if="!canEdit(user)">Protégé</small>',
);

export default UsersView;
