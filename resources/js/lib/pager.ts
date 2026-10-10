export function pageInfo(page: number, perPage: number, total: number) {
    const last = Math.max(1, Math.ceil(total / perPage));
    if (total === 0) {
        return { from: 0, to: 0, last, label: 'No results' };
    }
    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    return { from, to, last, label: `Showing ${from}–${to} of ${total}` };
}
