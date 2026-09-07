import PingController from '@/actions/App/Http/Controllers/PingController';
import { TMap } from '@/pages/maps';
import { useFetch, useIntervalFn } from '@vueuse/core';
import { MaybeRefOrGetter, toValue } from 'vue';

const PING_INTERVAL_SECONDS = 60 * 5 * 1000;

/**
 * Ping the server every 5 minutes to keep the server aware of the active map/user.
 */
export function usePing(map: MaybeRefOrGetter<TMap>) {
    // Explicit Accept header so Laravel recognizes this as an API-style
    // request when the session has expired. Without it, a plain fetch() looks
    // like a normal page visit to Laravel's auth middleware, which redirects
    // to login *and* remembers this ping URL as the post-login destination -
    // landing the next real login on this endpoint's raw JSON instead of the
    // app.
    const { execute } = useFetch(() => PingController.show(toValue(map).slug).url, {
        immediate: false,
        headers: { Accept: 'application/json' },
    });
    useIntervalFn(execute, PING_INTERVAL_SECONDS, {
        immediateCallback: true,
    });
}
