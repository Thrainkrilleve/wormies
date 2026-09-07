import { useShowMap } from '@/composables/useShowMap';
import useUser from '@/composables/useUser';
import { computed } from 'vue';

/**
 * Returns the active map character for the user that hold
 * the status of the user (solarsystem, ship, etc.).
 */
export function useActiveMapCharacter() {
    const user = useUser();
    const page = useShowMap();

    return computed(() => {
        const characters = page.props.map_characters ?? [];

        // map_characters only ever contains online, scoped characters. If exactly
        // one of the pilot's own characters is online right now, use it - this
        // lets someone browse the site as their main while flying an alt, without
        // needing to switch their site login/preferred character to match.
        // With zero or several online at once it's ambiguous, so fall back to
        // whichever character they're actually logged into the site as.
        const my_online_characters = characters.filter((c) => c.is_mine);
        if (my_online_characters.length === 1) {
            return my_online_characters[0];
        }

        return characters.find((c) => c.id === user.value?.active_character?.id);
    });
}
