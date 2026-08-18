import { describe, expect, it } from 'vitest';
import { normalizeComplaint, normalizeComplaintMeta, normalizeComplaintRows } from './complaint-adapters';

describe('complaint response adapters', () => {
    it('normalizes Laravel paginator data without assuming a meta wrapper', () => {
        const payload = {
            current_page: 2,
            last_page: 3,
            total: 52,
            data: [{ public_reference: 'REC-001' }, { invalid: true }],
        };

        expect(normalizeComplaintRows(payload.data)).toEqual([{ public_reference: 'REC-001' }]);
        expect(normalizeComplaintMeta(payload)).toEqual({ current_page: 2, last_page: 3, total: 52 });
    });

    it('keeps the list stable for empty or malformed responses', () => {
        expect(normalizeComplaintRows(undefined)).toEqual([]);
        expect(normalizeComplaintMeta({ data: [] })).toEqual({ current_page: 1, last_page: 1, total: 0 });
    });

    it('normalizes a detail payload while preserving valid note and history collections', () => {
        expect(normalizeComplaint(null)).toBeNull();
        expect(normalizeComplaint({ public_reference: 'REC-002' })).toMatchObject({
            public_reference: 'REC-002', notes: [], status_history: [],
        });
        expect(normalizeComplaint({
            public_reference: 'REC-003', notes: [{ id: 1 }], status_history: [{ id: 2 }],
        })).toMatchObject({ public_reference: 'REC-003', notes: [{ id: 1 }], status_history: [{ id: 2 }] });
    });
});
