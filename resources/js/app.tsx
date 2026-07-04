import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ComponentType } from 'react';

const appName = 'PeopleOS';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    // Checkpoint 26: lazy per-page resolution (each Pages/**.tsx becomes
    // its own chunk, fetched on navigation) instead of eagerly bundling
    // every page into the single main chunk — the actual cause of the
    // >500kB bundle-size advisory. Standard laravel-vite-plugin pattern,
    // no custom code-splitting logic. See docs/architecture.md.
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx'),
        );

        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
