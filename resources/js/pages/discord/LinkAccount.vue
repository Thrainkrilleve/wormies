<script setup lang="ts">
import CharacterImage from '@/components/images/CharacterImage.vue';
import Logo from '@/components/icons/Logo.vue';
import { Button } from '@/components/ui/button';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    state: 'confirm' | 'success' | 'expired' | 'invalid' | 'taken';
    action: string | null;
    account: {
        name: string;
        character: {
            id: number;
            name: string;
        } | null;
    };
    cancelUrl: string;
}>();

const form = useForm({});

function submit() {
    if (props.action) {
        form.post(props.action);
    }
}
</script>

<template>
    <Head title="Link your Discord account" />
    <div class="flex min-h-screen items-center justify-center bg-background p-6 text-foreground">
        <div class="w-full max-w-md">
            <div class="mb-8 flex flex-col items-center gap-3">
                <Logo class="size-14 text-foreground" />
                <p class="font-display text-xl font-semibold tracking-tight">WormholeSystems</p>
            </div>

            <div class="rounded-md border border-neutral-300 bg-white p-8 shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
                <h1 class="text-center text-lg font-semibold">
                    <template v-if="state === 'success'">Discord account linked</template>
                    <template v-else-if="state === 'expired'">This link has expired</template>
                    <template v-else-if="state === 'invalid'">This link is not valid</template>
                    <template v-else-if="state === 'taken'">This Discord account is already linked</template>
                    <template v-else>Link your Discord account</template>
                </h1>

                <div class="mt-6 flex items-center gap-3 rounded-md border border-border/60 bg-muted/40 p-3">
                    <CharacterImage v-if="account.character" :character_id="account.character.id" :character_name="account.character.name" :size="64" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ account.name }}</p>
                        <p v-if="account.character" class="truncate text-sm text-muted-foreground">{{ account.character.name }}</p>
                    </div>
                </div>

                <p v-if="state === 'confirm'" class="mt-4 text-sm leading-6 text-muted-foreground">
                    This links the Discord account that ran /link to this Wormhole Systems user. Access comes from being in the connected Discord server. You can undo it anytime with /unlink.
                </p>
                <p v-else-if="state === 'success'" class="mt-4 text-sm leading-6 text-muted-foreground">
                    This Discord account is now linked to you. Map access from a connected Discord server applies to your characters. Run /unlink in Discord if you want to remove it.
                </p>
                <p v-else-if="state === 'expired'" class="mt-4 text-sm leading-6 text-muted-foreground">
                    This link has expired. Run /link again in Discord, then open the new link while you are signed in.
                </p>
                <p v-else-if="state === 'invalid'" class="mt-4 text-sm leading-6 text-muted-foreground">
                    This link is invalid or has already been used. Run /link again in Discord, then open the new link while you are signed in.
                </p>
                <p v-else class="mt-4 text-sm leading-6 text-muted-foreground">
                    This Discord account is already linked to another Wormhole Systems user. Sign in as that user, or run /unlink from that Discord account and try again.
                </p>

                <form v-if="state === 'confirm' && action" class="mt-6 flex flex-col gap-3" @submit.prevent="submit">
                    <Button type="submit" class="w-full" :disabled="form.processing">Link Discord account</Button>
                    <Button variant="outline" class="w-full" asChild>
                        <Link :href="cancelUrl">Cancel</Link>
                    </Button>
                </form>
                <div v-else class="mt-6">
                    <Button variant="outline" class="w-full" asChild>
                        <Link :href="cancelUrl">Back home</Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
