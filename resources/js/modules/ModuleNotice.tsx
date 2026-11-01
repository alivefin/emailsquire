import { useTranslation } from 'react-i18next';
import { failedModules } from './registry';

/** Names each module that couldn't be loaded. The layout shows it to server admins only. */
export function ModuleNotice() {
    const { t } = useTranslation('modules');
    // Loading settles before the router is built, so the list doesn't change while the console runs.
    const names = [...new Set(failedModules().map((f) => f.name))];
    if (names.length === 0) return null;
    return (
        <div role="status" className="flex flex-col gap-1 rounded-[10px] border border-warn-line bg-warn-bg px-4 py-3 text-[13px] text-warn">
            {names.map((name) => (
                <p key={name}>{t('notice.failed', { name })}</p>
            ))}
        </div>
    );
}
