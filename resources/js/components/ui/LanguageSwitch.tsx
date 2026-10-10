import { useTranslation } from 'react-i18next';
import { Button } from './Button';
import { setLocale, useLocale } from '../../i18n';

/** Link-style button that shows the other language's name and switches to it. */
export function LanguageSwitch() {
    const { t } = useTranslation('common');
    const locale = useLocale();
    return (
        <Button type="button" variant="link" size="sm" lang={locale === 'en' ? 'fi' : 'en'} onClick={() => void setLocale(locale === 'en' ? 'fi' : 'en')}>
            {t('language.switchLabel')}
        </Button>
    );
}
