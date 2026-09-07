<script setup lang="ts">
import MinusIcon from '@/components/icons/MinusIcon.vue';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import PlusIcon from '@/components/icons/PlusIcon.vue';
import { CharacterImage } from '@/components/images';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/user/UserInfo.vue';
import { auth, logout } from '@/routes';
import UserCharacters from '@/routes/user-characters';
import type { User } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    user: User;
}

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();

// AuthController::show() always sends add_to_account through Alliance Auth,
// which makes no sense for a pilot who signed in with native EVE SSO
// specifically to avoid needing an AA account - offer both entry points, same
// as the login page does, rather than assuming everyone has AA.
const page = usePage();
const allianceAuthEnabled = computed(() => Boolean(page.props.allianceAuthEnabled));
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem v-for="character in user.characters" :key="character.id" as-child>
            <Link class="block w-full" :href="UserCharacters.update(character.id)" as="button" method="put">
                <CharacterImage :character_id="character.id" :character_name="character.name" class="mr-2 h-4 w-4" />
                {{ character.name }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="allianceAuthEnabled" as-child>
            <a
                class="block w-full"
                :href="
                    auth({
                        query: {
                            add_to_account: true,
                        },
                    }).url
                "
            >
                <PlusIcon class="mr-2 h-4 w-4" />
                Add Character (Alliance Auth)
            </a>
        </DropdownMenuItem>
        <DropdownMenuItem as-child>
            <a class="block w-full" href="/eve/add-character?add_to_account=1">
                <PlusIcon class="mr-2 h-4 w-4" />
                Add Character (EVE SSO)
            </a>
        </DropdownMenuItem>
        <DropdownMenuItem as-child v-if="user.characters.length > 1">
            <Link class="block w-full" :href="UserCharacters.delete(user.active_character.id)" method="delete" as="button">
                <MinusIcon class="mr-2 h-4 w-4" />
                Remove Character
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" :href="SettingsController.show()" prefetch>
            <Settings class="mr-2 h-4 w-4" />
            Settings
        </Link>
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" method="delete" :href="logout()" @click="handleLogout" as="button">
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
