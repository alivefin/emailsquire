import { useMe } from '../features/auth/useMe';
import { ModuleBoundary } from './ModuleBoundary';
import { registeredModules } from './registry';

/** The module cards `/me` lists for this user, in its order; a key the module didn't register shows nothing. */
export function DashboardCards() {
    const modules = useMe().data?.modules ?? [];
    const registered = registeredModules();
    const cards = modules.flatMap((m) => {
        const components = registered.find((r) => r.name === m.name)?.dashboardCards ?? {};
        return m.dashboard_cards.flatMap((key) => {
            const Card = Object.hasOwn(components, key) ? components[key] : undefined;
            return Card ? [{ id: `${m.name}:${key}`, Card }] : [];
        });
    });
    if (cards.length === 0) return null;
    return (
        <div className="grid gap-3.5 lg:grid-cols-2">
            {cards.map(({ id, Card }) => (
                <ModuleBoundary key={id} variant="card">
                    <Card />
                </ModuleBoundary>
            ))}
        </div>
    );
}
