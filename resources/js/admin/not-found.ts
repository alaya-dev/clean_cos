import { type Component } from 'vue';

const AdminNotFoundView: Component = {
    template: '<section class="admin-page admin-empty"><p class="admin-eyebrow">Navigation</p><h1>Cette page est introuvable</h1><p>Le lien demandé n’existe pas dans le back-office.</p><div><RouterLink class="admin-action" to="/products">Retour au tableau de bord</RouterLink><button class="admin-outline" type="button" @click="$router.back()">Page précédente</button></div></section>',
};

export default AdminNotFoundView;
