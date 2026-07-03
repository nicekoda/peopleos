import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { ComponentType } from 'react';

const appName = 'PeopleOS';

const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx', { eager: true });

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ./Pages/${name}.tsx`);
        }

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
