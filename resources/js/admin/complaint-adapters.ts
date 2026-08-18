export type Complaint = {
    public_reference: string;
    customer_name: string;
    customer_phone: string;
    subject: string;
    description: string;
    status: 'nouvelle' | 'en_cours' | 'resolue';
    created_at: string;
    has_attachment: boolean;
    order?: { public_reference: string } | null;
    notes?: { id: number; body: string; created_at: string; user?: { name: string } | null }[];
    status_history?: { id: number; from_status: string | null; to_status: string; created_at: string; actor?: { name: string } | null }[];
};

export type ComplaintMeta = { current_page: number; last_page: number; total: number };
export type ComplaintPagePayload = Partial<ComplaintMeta> & { data?: unknown };

export function normalizeComplaintRows(value: unknown): Complaint[] {
    return Array.isArray(value)
        ? value.filter((entry): entry is Complaint => Boolean(entry && typeof entry === 'object' && typeof (entry as Complaint).public_reference === 'string'))
        : [];
}

export function normalizeComplaintMeta(value: ComplaintPagePayload | null | undefined): ComplaintMeta {
    return {
        current_page: typeof value?.current_page === 'number' && value.current_page > 0 ? value.current_page : 1,
        last_page: typeof value?.last_page === 'number' && value.last_page > 0 ? value.last_page : 1,
        total: typeof value?.total === 'number' && value.total >= 0 ? value.total : 0,
    };
}

export function normalizeComplaint(value: unknown): Complaint | null {
    if (!value || typeof value !== 'object' || typeof (value as Complaint).public_reference !== 'string') return null;
    const complaint = value as Complaint;

    return {
        ...complaint,
        notes: Array.isArray(complaint.notes) ? complaint.notes : [],
        status_history: Array.isArray(complaint.status_history) ? complaint.status_history : [],
    };
}
