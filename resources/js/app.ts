import { createInertiaApp, router } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import '../css/app.css';
import { initializeTheme } from './composables/useAppearance';
import { isPWA } from './composables/useOnClient';
import { initializeRouting } from './composables/useRoutingWorker';
import { preloadSovereigntyData } from './composables/useSovereigntyData';
import { preloadStaticData } from './composables/useStaticData';

const reverbConfig = (typeof window !== 'undefined' && (window as any).__reverb) || {};

configureEcho({
    broadcaster: 'reverb',
    key: reverbConfig.key || import.meta.env.VITE_REVERB_APP_KEY || 'local_reverb_key_12345',
    wsHost: reverbConfig.host || import.meta.env.VITE_REVERB_HOST || (typeof window !== 'undefined' ? window.location.hostname : 'localhost'),
    wsPort: Number(reverbConfig.port || import.meta.env.VITE_REVERB_PORT || 8091),
    wssPort: Number(reverbConfig.port || import.meta.env.VITE_REVERB_PORT || 8091),
    forceTLS: (reverbConfig.scheme ? reverbConfig.scheme === 'https' : (import.meta.env.VITE_REVERB_SCHEME ? import.meta.env.VITE_REVERB_SCHEME === 'https' : (typeof window !== 'undefined' && window.location.protocol === 'https:'))),
    enabledTransports: ['ws', 'wss'],
});

router.on('finish', () => {
    router.flushAll();
});

void preloadStaticData();
void initializeRouting();
void preloadSovereigntyData();

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => {
        if (isPWA()) {
            return 'wormhole.systems';
        }
        return title ? `${title} | ${appName}` : appName;
    },
    progress: {
        color: '#4B5563',
        delay: 1_000,
    },
});

initializeTheme();
