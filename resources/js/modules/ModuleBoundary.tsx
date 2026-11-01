import { Component, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';

function ErrorPanel() {
    const { t } = useTranslation('modules');
    return (
        <div role="alert" className="rounded-[10px] border border-danger-line bg-danger-bg px-4 py-3 text-[13px] text-danger">
            <h2 className="text-sm font-bold">{t('boundary.title')}</h2>
            <p className="mt-1">{t('boundary.body')}</p>
        </div>
    );
}

class Boundary extends Component<{ resetKey: string; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidUpdate(prev: { resetKey: string }) {
        // Moving to another page of the module gives its screen another try.
        if (this.state.failed && prev.resetKey !== this.props.resetKey) this.setState({ failed: false });
    }

    render() {
        return this.state.failed ? <ErrorPanel /> : this.props.children;
    }
}

/** Keeps a module's render crash inside its own area: the console around it keeps working. */
export function ModuleBoundary({ children }: { children: ReactNode }) {
    const { pathname } = useLocation();
    return <Boundary resetKey={pathname}>{children}</Boundary>;
}
