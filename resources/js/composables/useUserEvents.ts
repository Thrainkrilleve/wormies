import { useOnClient } from '@/composables/useOnClient';
import useUser from '@/composables/useUser';
import { getUserChannelName } from '@/const/channels';
import { UserCharacterStatusUpdatedEvent } from '@/const/events';
import { createReconnectListener, type ConnectionStateChange } from '@/map/sync/reconnect';
import { router } from '@inertiajs/vue3';
import { echo, useEcho } from '@laravel/echo-vue';
import { onMounted, onUnmounted } from 'vue';

type PusherConnection = {
    bind(event: 'state_change', callback: (states: ConnectionStateChange) => void): void;
    unbind(event: 'state_change', callback: (states: ConnectionStateChange) => void): void;
};

/**
 * Subscribe to the authenticated user's private channel and refresh the shared
 * `auth` prop whenever one of their characters' status changes. A dropped
 * websocket reloads that prop as well, so a missed event does not leave the
 * character list stale until the next jump.
 */
export function useUserEvents() {
    const user = useUser();

    useOnClient(() => {
        const userId = user.value?.id;
        if (!userId) {
            return;
        }

        useEcho(getUserChannelName(userId), UserCharacterStatusUpdatedEvent, () => {
            router.reload({ only: ['auth'] });
        });
    });

    const handleConnectionStateChange = createReconnectListener(() => {
        router.reload({ only: ['auth'] });
    });

    onMounted(() => {
        connection()?.bind('state_change', handleConnectionStateChange);
    });

    onUnmounted(() => {
        connection()?.unbind('state_change', handleConnectionStateChange);
    });
}

function connection(): PusherConnection | null {
    try {
        const connector = echo().connector as { pusher?: { connection?: PusherConnection } };
        return connector.pusher?.connection ?? null;
    } catch {
        return null;
    }
}
