import { useTranslation } from 'react-i18next';

/** Blank canvas with a centred spinner, shown while the session is being checked. */
export function PageSpinner() {
    const { t } = useTranslation('common');
    return (
        <div className="grid min-h-dvh place-items-center bg-canvas">
            <span
                role="status"
                aria-label={t('layout.loading')}
                className="size-6 animate-spin rounded-full border-2 border-line-strong border-t-teal-dark"
            />
        </div>
    );
}
