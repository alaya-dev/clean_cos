import { createApp, computed, nextTick, onMounted, reactive, ref } from 'vue';
import { createPinia } from 'pinia';
import {
    createRouter,
    createWebHistory,
    RouterLink,
    RouterView,
} from 'vue-router';
import {
    dismissError as dismissFeedbackError,
    dismissToast,
    feedbackState,
    resolveConfirmation,
    showError,
    showToast,
} from './feedback';
import { adminNewOrderCount, adminOrderAttentionPulse, setAdminNewOrderCount } from './order-attention';

const Shell = {
    components: { RouterLink, RouterView },
    setup() {
        const role = ref('');
        const passwordModalOpen = ref(false);
        const passwordSaving = ref(false);
        const passwordError = ref('');
        const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
        const passwordDialog = ref<HTMLElement | null>(null);
        const passwordTrigger = ref<HTMLElement | null>(null);
        const dismissError = () => {
            const requiresAuthentication = feedbackState.errorDialog.value?.message === 'Authentification requise.';
            dismissFeedbackError();
            if (requiresAuthentication) window.location.reload();
        };
        const navigationGroups = computed(() => {
            const groups = [
                {
                    label: 'Commerce',
                    links: [
                        { to: '/', label: 'Tableau de bord', icon: ['M4 5.5h6v6H4z', 'M14 5.5h6v6h-6z', 'M4 15.5h6v4H4z', 'M14 15.5h6v4h-6z'] },
                        { to: '/orders', label: 'Commandes', icon: ['M6 7h12l1 13H5L6 7Z', 'M9 8V5a3 3 0 0 1 6 0v3'] },
                        { to: '/products', label: 'Produits', icon: ['M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z', 'm4 7.5 8 4.5 8-4.5', 'M12 12v9'] },
                        { to: '/categories', label: 'Catégories', icon: ['M4 12V5h7l9 9-7 7-9-9Z', 'M8 8h.01'] },
                        { to: '/inventory', label: 'Inventaire', icon: ['m3 8 9-5 9 5v9l-9 5-9-5V8Z', 'm3 8 9 5 9-5', 'M12 13v9'] },
                    ],
                },
                {
                    label: 'Opérations',
                    links: [
                        { to: '/complaints', label: 'Réclamations', icon: ['M12 3a8 8 0 1 0 8 8', 'M12 7v5l3 2', 'M12 3v4h4'] },
                        ...(role.value === 'super_admin' ? [
                            { to: '/promotions', label: 'Promo Codes', icon: ['M5 5h5l9 9-5 5-9-9V5Z', 'M8 8h.01'] },
                            { to: '/checkout-fields', label: 'Champs commande', icon: ['M5 4h14v16H5z', 'M8 8h8M8 12h8M8 16h5'] },
                        ] : []),
                        ...(role.value === 'super_admin' ? [{ to: '/shipping', label: 'Livraison', icon: ['M3 7h11v10H3z', 'M14 10h3l3 3v4h-6z', 'M7 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM17 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z'] }] : []),
                    ],
                },
                {
                    label: 'Boutique',
                    links: role.value === 'super_admin' ? [
                        { to: '/content', label: 'Contenu', icon: ['M4 5h16v14H4z', 'M4 9h16', 'M8 13h4M8 17h7'] },
                        { to: '/static-pages', label: 'Pages', icon: ['M6 3h9l4 4v14H6z', 'M15 3v5h5', 'M9 12h6M9 16h6'] },
                    ] : [],
                },
                {
                    label: 'Suivi',
                    links: role.value === 'super_admin' ? [
                        { to: '/meta', label: 'Suivi Meta', icon: ['M4 19V9m5 10V5m5 14v-7m5 7V3'] },
                        { to: '/meta/diagnostics', label: 'Diagnostics Meta', icon: ['M12 3v9l4 4', 'M19.1 19.1A10 10 0 1 1 21 12a10 10 0 0 1-1.9 7.1Z'] },
                    ] : [],
                },
                {
                    label: 'Administration',
                    links: role.value === 'super_admin' ? [
                        { to: '/users', label: 'Utilisateurs', icon: ['M16 20v-1.5A3.5 3.5 0 0 0 12.5 15h-5A3.5 3.5 0 0 0 4 18.5V20', 'M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM17 11a3 3 0 0 0 0-6m3 15v-1.5A3.5 3.5 0 0 0 17 15.1'] },
                        { to: '/audit-logs', label: 'Journal d’audit', icon: ['M6 3h9l4 4v14H6z', 'M15 3v5h5', 'M9 12h6M9 16h6'] },
                    ] : [],
                },
            ];

            return groups.filter((group) => group.links.length > 0);
        });
        onMounted(async () => {
            const response = await fetch('/api/v1/admin/me', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.ok) {
                const currentUser = (await response.json()) as { data: { role: string; new_orders_count?: number } };
                role.value = currentUser.data.role;
                setAdminNewOrderCount(currentUser.data.new_orders_count ?? 0);
            }
        });
        const logout = async () => {
            const csrfToken =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content || '';
            try {
                const response = await fetch('/admin/logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                });
                if (!response.ok) throw new Error('Déconnexion impossible. Réessayez dans un instant.');
                window.location.assign('/admin/login');
            } catch (cause) {
                showError(cause instanceof Error ? cause.message : 'Déconnexion impossible.');
            }
        };
        const openPasswordModal = async (event: MouseEvent) => {
            passwordTrigger.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
            Object.assign(passwordForm, { current_password: '', password: '', password_confirmation: '' });
            passwordError.value = '';
            passwordModalOpen.value = true;
            await nextTick();
            passwordDialog.value?.querySelector<HTMLInputElement>('input')?.focus();
        };
        const closePasswordModal = async () => {
            if (passwordSaving.value) return;
            passwordModalOpen.value = false;
            await nextTick();
            passwordTrigger.value?.focus();
        };
        const keepPasswordFocus = (event: KeyboardEvent) => {
            if (event.key === 'Escape') { void closePasswordModal(); return; }
            if (event.key !== 'Tab' || !passwordDialog.value) return;
            const focusable = [...passwordDialog.value.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled)')];
            const first = focusable[0]; const last = focusable.at(-1);
            if (!first || !last) return;
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        };
        const changePassword = async () => {
            passwordSaving.value = true;
            passwordError.value = '';
            const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
            try {
                const response = await fetch('/api/v1/admin/me/password', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(passwordForm),
                });
                if (!response.ok) {
                    const failure = await response.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null;
                    throw new Error(failure?.errors ? Object.values(failure.errors).flat().join(' ') : failure?.message || 'La modification du mot de passe a échoué.');
                }
                passwordModalOpen.value = false;
                Object.assign(passwordForm, { current_password: '', password: '', password_confirmation: '' });
                showToast('success', 'Votre mot de passe a été mis à jour.');
            } catch (cause) {
                passwordError.value = cause instanceof Error ? cause.message : 'La modification du mot de passe a échoué.';
            } finally {
                passwordSaving.value = false;
            }
        };

        return {
            ...feedbackState,
            dismissError,
            dismissToast,
            logout,
            openPasswordModal,
            closePasswordModal,
            changePassword,
            passwordModalOpen,
            passwordSaving,
            passwordError,
            passwordForm,
            passwordDialog,
            resolveConfirmation,
            keepPasswordFocus,
            role,
            newOrdersCount: adminNewOrderCount,
            orderAttentionPulse: adminOrderAttentionPulse,
            navigationGroups,
        };
    },
    template: `<div class="admin-shell">
      <aside class="admin-sidebar">
        <a class="admin-brand" href="/admin"><img class="admin-brand-logo" src="/logo1.webp" alt="" width="23" height="23"><span class="admin-brand-copy">TT<br><small>DISPO · ADMIN</small></span></a>
        <nav class="admin-sidebar-navigation" aria-label="Navigation principale">
          <section v-for="group in navigationGroups" :key="group.label" class="admin-nav-group" :aria-label="group.label">
            <p class="admin-nav-group-label">{{ group.label }}</p>
            <RouterLink v-for="navigationLink in group.links" :key="navigationLink.to" :to="navigationLink.to" :aria-label="navigationLink.label" :title="navigationLink.label"><svg aria-hidden="true" viewBox="0 0 24 24"><path v-for="path in navigationLink.icon" :key="path" :d="path" /></svg><span>{{ navigationLink.label }}</span><b v-if="navigationLink.to === '/orders' && newOrdersCount > 0" class="admin-nav-count" :class="{ 'is-pulsing': orderAttentionPulse }" role="status" :aria-label="'Nouvelles commandes : ' + newOrdersCount">{{ newOrdersCount }}</b></RouterLink>
          </section>
        </nav>
        <footer class="admin-profile"><span>Administration</span><button class="text-link" type="button" @click="openPasswordModal($event)">Mot de passe</button><button class="text-link" type="button" @click="logout">Déconnexion</button></footer>
      </aside>
      <main><div class="admin-topbar"><span>ToutDispo</span><small>Back-office sécurisé</small></div><RouterView v-slot="{ Component }"><Transition name="admin-page" mode="out-in"><component :is="Component" /></Transition></RouterView></main>
      <TransitionGroup name="admin-toast" tag="div" class="admin-toast-stack" aria-live="polite" aria-relevant="additions">
        <article v-for="toast in toasts" :key="toast.id" class="admin-toast" :class="'is-' + toast.tone" role="status">
          <span class="admin-toast-mark" aria-hidden="true">{{ toast.tone === 'success' ? '✓' : toast.tone === 'info' ? 'i' : '!' }}</span>
          <p>{{ toast.message }}</p>
          <button type="button" :aria-label="'Fermer la notification'" @click="dismissToast(toast.id)">×</button>
        </article>
      </TransitionGroup>
      <Transition name="admin-overlay"><div v-if="errorDialog" class="admin-overlay" role="presentation" @click.self="dismissError">
        <section class="admin-feedback-dialog" role="alertdialog" aria-modal="true" aria-labelledby="admin-error-title" aria-describedby="admin-error-message">
          <span class="admin-dialog-mark is-error" aria-hidden="true">!</span>
          <div><p class="admin-eyebrow">Action requise</p><h2 id="admin-error-title">{{ errorDialog.title }}</h2><p id="admin-error-message">{{ errorDialog.message }}</p></div>
          <footer><button class="admin-action" type="button" @click="dismissError">Compris</button></footer>
        </section>
      </div></Transition>
      <Transition name="admin-overlay"><div v-if="confirmationDialog" class="admin-overlay" role="presentation" @click.self="resolveConfirmation(false)">
        <section class="admin-feedback-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-confirmation-title" aria-describedby="admin-confirmation-message">
          <span class="admin-dialog-mark" :class="confirmationDialog.tone === 'danger' ? 'is-error' : 'is-warning'" aria-hidden="true">!</span>
          <div><p class="admin-eyebrow">Confirmation</p><h2 id="admin-confirmation-title">{{ confirmationDialog.title }}</h2><p id="admin-confirmation-message">{{ confirmationDialog.message }}</p></div>
          <footer><button class="text-link" type="button" @click="resolveConfirmation(false)">Annuler</button><button class="admin-action" :class="{ 'danger-button': confirmationDialog.tone === 'danger' }" type="button" @click="resolveConfirmation(true)">{{ confirmationDialog.confirmLabel }}</button></footer>
        </section>
      </div></Transition>
      <Transition name="admin-overlay"><div v-if="passwordModalOpen" class="admin-overlay" role="presentation" @click.self="closePasswordModal">
        <section ref="passwordDialog" class="admin-password-dialog" role="dialog" aria-modal="true" aria-labelledby="password-modal-title" aria-describedby="password-modal-description" @keydown="keepPasswordFocus">
          <header><div><p class="admin-eyebrow">Sécurité du compte</p><h2 id="password-modal-title">Modifier mon mot de passe</h2><p id="password-modal-description">Pour votre sécurité, votre mot de passe actuel est requis, y compris pour les Super Admins.</p></div><button class="admin-dialog-close" type="button" aria-label="Fermer" :disabled="passwordSaving" @click="closePasswordModal">×</button></header>
          <form @submit.prevent="changePassword"><p v-if="passwordError" class="page-error" role="alert">{{ passwordError }}</p><label>Mot de passe actuel<input v-model="passwordForm.current_password" type="password" autocomplete="current-password" required></label><label>Nouveau mot de passe<input v-model="passwordForm.password" type="password" autocomplete="new-password" minlength="8" required><small>8 caractères minimum.</small></label><label>Confirmer le nouveau mot de passe<input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label><footer><button class="text-link" type="button" :disabled="passwordSaving" @click="closePasswordModal">Annuler</button><button class="admin-action" :disabled="passwordSaving">{{ passwordSaving ? 'Enregistrement…' : 'Mettre à jour' }}</button></footer></form>
        </section>
      </div></Transition>
    </div>`,
};

const router = createRouter({
    history: createWebHistory('/admin'),
    routes: [
        { path: '/', component: () => import('./dashboard') },
        { path: '/products', component: () => import('./products') },
        { path: '/products/new', component: () => import('./product-editor') },
        { path: '/products/:reference', component: () => import('./product-editor') },
        { path: '/categories', component: () => import('./categories') },
        { path: '/orders', component: () => import('./orders') },
        { path: '/orders/new', component: () => import('./order-create') },
        { path: '/orders/drafts/:token', component: () => import('./order-draft') },
        { path: '/orders/:reference', component: () => import('./order-detail') },
        { path: '/inventory', component: () => import('./inventory') },
        { path: '/complaints', component: () => import('./complaints').then((module) => module.ComplaintsView) },
        { path: '/complaints/:reference', component: () => import('./complaints').then((module) => module.ComplaintDetailView) },
        { path: '/promotions', component: () => import('./promotions') },
        { path: '/shipping', component: () => import('./shipping-settings') },
        { path: '/navex', component: () => import('./navex') },
        { path: '/checkout-fields', component: () => import('./checkout-fields') },
        { path: '/content', component: () => import('./content') },
        { path: '/static-pages', component: () => import('./static-pages') },
        { path: '/meta', component: () => import('./meta-configuration') },
        { path: '/meta/diagnostics', component: () => import('./meta-diagnostics') },
        { path: '/users', component: () => import('./users') },
        { path: '/audit-logs', component: () => import('./audit-logs') },
        { path: '/:pathMatch(.*)*', component: () => import('./not-found') },
    ],
});

let likelyAdminRoutesPreloaded = false;
function allowsAdminChunkPreload(): boolean {
    const connection = (navigator as Navigator & { connection?: { saveData?: boolean; effectiveType?: string } }).connection;
    return !connection?.saveData && !['slow-2g', '2g'].includes(connection?.effectiveType ?? '');
}
function preloadLikelyAdminRoutes(): void {
    if (likelyAdminRoutesPreloaded || !allowsAdminChunkPreload()) return;
    likelyAdminRoutesPreloaded = true;
    void Promise.all([import('./products'), import('./orders')]);
}
router.afterEach((to) => {
    if (to.path !== '/') return;
    const idleWindow = window as Window & { requestIdleCallback?: (callback: IdleRequestCallback, options?: IdleRequestOptions) => number };
    if (idleWindow.requestIdleCallback) idleWindow.requestIdleCallback(preloadLikelyAdminRoutes, { timeout: 3000 });
    else window.setTimeout(preloadLikelyAdminRoutes, 1500);
});

const app = createApp(Shell);

app.use(createPinia()).use(router).mount('#admin-app');
