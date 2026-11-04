import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (titre) => (titre ? `${titre} · MediHome` : 'MediHome — La médecine vient à vous'),
    // Chaque écran est chargé à la demande : la première page reste légère en 3G.
    resolve: (nom) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        return pages[`./Pages/${nom}.vue`]();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#0060c8' },
});
