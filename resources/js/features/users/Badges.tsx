import { useTranslation } from 'react-i18next';
import { cx } from '../../components/ui/cx';

const badgeClass = 'inline-flex items-center rounded-full px-2 py-px text-xs font-semibold whitespace-nowrap';

/** Active or Deactivated. */
export function StatusBadge({ status }: { status: 'active' | 'deactivated' }) {
    const { t } = useTranslation('users');
    return (
        <span className={cx(badgeClass, status === 'active' ? 'bg-tint text-teal-dark' : 'bg-subtle text-muted')}>
            {t(`status.${status}`)}
        </span>
    );
}

/** "2FA on" or "2FA off", for the list's compact status column. */
export function TwoFactorBadge({ on }: { on: boolean }) {
    const { t } = useTranslation('users');
    return (
        <span className={cx(badgeClass, on ? 'bg-tint text-teal-dark' : 'bg-warn-bg text-warn')}>
            {t(on ? 'twoFactorBadge.on' : 'twoFactorBadge.off')}
        </span>
    );
}
