import './entry.css';
import { useTranslation } from 'react-i18next';
import enCard from './i18n/en/card.json';
import enNav from './i18n/en/nav.json';
import enPage from './i18n/en/page.json';
import fiCard from './i18n/fi/card.json';
import fiNav from './i18n/fi/nav.json';
import fiPage from './i18n/fi/page.json';

function NotesPage() {
    const { t } = useTranslation('example');
    return <h1 className="text-xl font-bold">{t('page.title')}</h1>;
}

function SummaryCard() {
    const { t } = useTranslation('example');
    return <div className="example-module-card rounded-[10px] border border-line bg-white px-4 py-3 text-sm">{t('card.summary')}</div>;
}

globalThis.__emailsquire.registerModule({
    name: 'example',
    routes: [{ path: '/example', element: <NotesPage /> }],
    catalogues: {
        en: { nav: enNav, page: enPage, card: enCard },
        fi: { nav: fiNav, page: fiPage, card: fiCard },
    },
    dashboardCards: { summary: SummaryCard },
});
