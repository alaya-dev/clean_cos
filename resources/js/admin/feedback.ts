import { ref } from 'vue';

export type ToastTone = 'success' | 'error' | 'warning' | 'info';

type Toast = {
    id: number;
    tone: ToastTone;
    message: string;
};

type ErrorDialog = {
    title: string;
    message: string;
} | null;

type ConfirmationDialog = {
    title: string;
    message: string;
    confirmLabel: string;
    tone: 'default' | 'danger';
    resolve: (confirmed: boolean) => void;
} | null;

const toasts = ref<Toast[]>([]);
const errorDialog = ref<ErrorDialog>(null);
const confirmationDialog = ref<ConfirmationDialog>(null);
let nextToastId = 1;

export const showToast = (tone: ToastTone, message: string) => {
    const toast = { id: nextToastId++, tone, message };
    toasts.value = [...toasts.value, toast].slice(-3);
    globalThis.setTimeout(() => dismissToast(toast.id), tone === 'error' || tone === 'warning' ? 7500 : 4500);
};

export const dismissToast = (toastId: number) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== toastId);
};

export const showError = (message: string, title = 'Une action est nécessaire') => {
    errorDialog.value = { title, message };
};

export const dismissError = () => {
    errorDialog.value = null;
};

export const confirmAction = (
    title: string,
    message: string,
    confirmLabel: string,
    tone: 'default' | 'danger' = 'default',
) => new Promise<boolean>((resolve) => {
    confirmationDialog.value = { title, message, confirmLabel, tone, resolve };
});

export const resolveConfirmation = (confirmed: boolean) => {
    confirmationDialog.value?.resolve(confirmed);
    confirmationDialog.value = null;
};

export const feedbackState = {
    toasts,
    errorDialog,
    confirmationDialog,
};
