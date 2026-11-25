/**
 * Label variants for run and job states (RunStatus / JobStatus).
 */
const STATUS_VARIANTS = {
    queued: 'neutral',
    claimed: 'info',
    running: 'info',
    cancel_requested: 'warning',
    succeeded: 'success',
    failed: 'danger',
    timed_out: 'danger',
    expired: 'warning',
    unknown: 'warning',
};

export function statusVariant(status) {
    return STATUS_VARIANTS[status] ?? 'neutral';
}

/**
 * Duration between two timestamps as `1 min 5 s`; empty while not finished.
 */
export function formatDuration(start, end) {
    if (!start || !end) {
        return '–';
    }
    const seconds = Math.max(0, Math.round((new Date(end) - new Date(start)) / 1000));
    const minutes = Math.floor(seconds / 60);

    return minutes > 0 ? `${minutes} min ${seconds % 60} s` : `${seconds} s`;
}
