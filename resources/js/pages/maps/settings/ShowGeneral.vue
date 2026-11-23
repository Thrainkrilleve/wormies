<script setup lang="ts">
import MapLayoutController from '@/actions/App/Http/Controllers/MapLayoutController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import useIsMapOwner from '@/composables/useIsMapOwner';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { TMapSummary } from '@/pages/maps';
import { destroy, update } from '@/routes/maps';
import { router, useForm } from '@inertiajs/vue3';
import { Check, Copy, Globe, Link2, Link2Off, Waypoints, Workflow, type LucideIcon } from 'lucide-vue-next';
import { AcceptableValue } from 'reka-ui';
import { computed, ref } from 'vue';

const { map, is_public, share_token, regions } = defineProps<{
    map: TMapSummary;
    is_public: boolean;
    share_token: string | null;
    regions: { id: number; name: string }[];
}>();

const user_is_owner = useIsMapOwner();

const form = useForm({
    name: map.name,
});

const delete_confirmation = ref('');
const is_deleting = ref(false);
const copied = ref(false);
const shareUrl = computed(() => (share_token ? `${window.location.origin}/share/${share_token}` : ''));

function deleteMap() {
    if (delete_confirmation.value !== map.name) {
        return;
    }
    router.delete(destroy(map.slug));
}

const placementOptions: { value: 'manual' | 'tree'; label: string; hint: string; icon: LucideIcon }[] = [
    { value: 'manual', label: 'Custom', hint: 'Place systems anywhere yourself', icon: Waypoints },
    { value: 'tree', label: 'Automatic', hint: 'Systems arrange into branches from the pinned ones', icon: Workflow },
];

function setPlacement(layout: 'manual' | 'tree') {
    if (layout === map.layout) {
        return;
    }

    router.put(
        MapLayoutController.update(map.slug).url,
        { layout },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['map'],
        },
    );
}

function setAllowOverride(allow_layout_override: boolean) {
    router.put(
        MapLayoutController.update(map.slug).url,
        { allow_layout_override },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['map'],
        },
    );
}

function saveEveScout(patch: {
    eve_scout_sync_enabled?: boolean;
    eve_scout_hubs?: 'thera' | 'turnur' | 'both';
    eve_scout_filter?: 'jumps' | 'region';
    eve_scout_max_jumps?: number;
    eve_scout_region_ids?: number[];
}) {
    router.put(
        `/maps/${map.slug}/settings/eve-scout`,
        {
            eve_scout_sync_enabled: map.eve_scout_sync_enabled ?? false,
            eve_scout_hubs: map.eve_scout_hubs ?? 'both',
            eve_scout_filter: map.eve_scout_filter ?? 'jumps',
            eve_scout_max_jumps: map.eve_scout_max_jumps ?? 5,
            eve_scout_region_ids: map.eve_scout_region_ids ?? [],
            ...patch,
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['map'],
        },
    );
}

function setHubs(value: AcceptableValue) {
    if (value === 'thera' || value === 'turnur' || value === 'both') {
        saveEveScout({ eve_scout_hubs: value });
    }
}

function setFilter(value: AcceptableValue) {
    if (value === 'jumps' || value === 'region') {
        saveEveScout({ eve_scout_filter: value });
    }
}

function setMaxJumps(event: Event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
        return;
    }
    saveEveScout({ eve_scout_max_jumps: Number(target.value) });
}

function toggleRegion(regionId: number, event: Event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
        return;
    }
    const current = map.eve_scout_region_ids ?? [];
    const eve_scout_region_ids = target.checked ? [...current, regionId] : current.filter((id) => id !== regionId);
    saveEveScout({ eve_scout_region_ids });
}

function setConstantWidth(constant_width_enabled: boolean) {
    router.put(
        MapLayoutController.update(map.slug).url,
        { constant_width_enabled },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['map'],
        },
    );
}

function togglePublic() {
    router.post(
        `/maps/${map.slug}/settings/toggle-public`,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function generateShareToken() {
    router.post(
        `/maps/${map.slug}/settings/generate-share-token`,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function revokeShareToken() {
    router.delete(`/maps/${map.slug}/settings/revoke-share-token`, {
        preserveState: true,
        preserveScroll: true,
    });
}

function copyShareLink() {
    if (!shareUrl.value) return;
    navigator.clipboard.writeText(shareUrl.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <SettingsLayout :map="map" title="General Settings" description="Configure your personal preferences and tracking settings for this map">
        <div class="space-y-6">
            <!-- Map Management Section (Owner Only) -->
            <Card v-if="user_is_owner">
                <CardHeader>
                    <CardTitle class="text-xl font-semibold">Map Management</CardTitle>
                    <CardDescription>Configure basic map settings (owner only)</CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="() => form.put(update(map.slug).url)">
                        <Label for="name" class="text-sm font-medium">Map Name</Label>
                        <div class="flex gap-2">
                            <Input name="name" id="name" v-model:model-value="form.name" />
                            <Button type="submit" :disabled="!form.isDirty"> Update </Button>
                        </div>
                        <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
                        <div class="text-sm text-muted-foreground">The display name for this map</div>
                    </form>
                </CardContent>
            </Card>

            <!-- System Placement Section -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-xl font-semibold">
                        <Workflow class="size-5" />
                        System Placement
                    </CardTitle>
                    <CardDescription>How systems are positioned for everyone viewing this map</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="option in placementOptions"
                            :key="option.value"
                            type="button"
                            class="flex items-start gap-3 rounded-lg border p-3 text-left transition-colors"
                            :class="map.layout === option.value ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/40'"
                            @click="setPlacement(option.value)"
                        >
                            <component
                                :is="option.icon"
                                class="size-5 shrink-0"
                                :class="map.layout === option.value ? 'text-primary' : 'text-muted-foreground'"
                            />
                            <span>
                                <span class="flex items-center gap-1.5 text-sm font-medium">
                                    {{ option.label }}
                                </span>
                                <span class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                            </span>
                        </button>
                    </div>

                    <div class="mt-4 flex items-center justify-between border-t border-border/50 pt-4">
                        <div>
                            <h4 class="text-sm font-medium">Let viewers choose their own</h4>
                            <p class="text-xs text-muted-foreground">
                                When enabled, anyone can switch their own view between Custom and Automatic. Otherwise the placement above is enforced
                                for everyone.
                            </p>
                        </div>
                        <Switch :model-value="map.allow_layout_override" @update:modelValue="setAllowOverride" />
                    </div>

                    <div class="mt-4 flex items-center justify-between border-t border-border/50 pt-4">
                        <div>
                            <h4 class="text-sm font-medium">Uniform system width</h4>
                            <p class="text-xs text-muted-foreground">
                                Give every system node the same fixed width so they all line up neatly, instead of each one sizing to its contents.
                            </p>
                        </div>
                        <Switch :model-value="map.constant_width_enabled" @update:modelValue="setConstantWidth" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-xl font-semibold">EVE-Scout import</CardTitle>
                    <CardDescription>
                        Off by default. Every 30 minutes, maps that enable this receive Thera and Turnur holes from EVE-Scout. The default filter
                        keeps a hole only when its other side is within 5 stargate jumps of the home system or a pinned system. A map with neither
                        gets nothing, so the full public list is not dropped onto the map. Holes this import created are updated when their mass or
                        lifetime changes and removed when EVE-Scout drops them or they expire. Holes added by hand are left alone.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-medium">Import Thera and Turnur</h4>
                            <p class="text-xs text-muted-foreground">
                                Managers and the owner can change this. It stays off until someone turns it on.
                            </p>
                        </div>
                        <Switch
                            :model-value="map.eve_scout_sync_enabled ?? false"
                            @update:model-value="(value) => saveEveScout({ eve_scout_sync_enabled: value === true })"
                        />
                    </div>

                    <div class="grid gap-4 border-t border-border/50 pt-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label>Hubs</Label>
                            <Select :model-value="map.eve_scout_hubs ?? 'both'" @update:model-value="setHubs">
                                <SelectTrigger>
                                    <SelectValue placeholder="Both hubs" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="both">Thera and Turnur</SelectItem>
                                    <SelectItem value="thera">Thera only</SelectItem>
                                    <SelectItem value="turnur">Turnur only</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="space-y-2">
                            <Label>Limit</Label>
                            <Select :model-value="map.eve_scout_filter ?? 'jumps'" @update:model-value="setFilter">
                                <SelectTrigger>
                                    <SelectValue placeholder="Jumps from home" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="jumps">Jumps from home or pins</SelectItem>
                                    <SelectItem value="region">Selected regions</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div v-if="(map.eve_scout_filter ?? 'jumps') === 'jumps'" class="space-y-2">
                        <Label for="eve-scout-jumps">Maximum jumps</Label>
                        <Input
                            id="eve-scout-jumps"
                            type="number"
                            min="0"
                            max="50"
                            :model-value="String(map.eve_scout_max_jumps ?? 5)"
                            @change="setMaxJumps"
                        />
                        <p class="text-xs text-muted-foreground">0 keeps only holes that land on the home system or a pinned system.</p>
                    </div>

                    <div v-else class="space-y-2">
                        <Label>Regions</Label>
                        <div class="max-h-48 space-y-2 overflow-auto rounded-md border p-3">
                            <label v-for="region in regions" :key="region.id" class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="(map.eve_scout_region_ids ?? []).includes(region.id)"
                                    @change="(event: Event) => toggleRegion(region.id, event)"
                                />
                                {{ region.name }}
                            </label>
                            <p v-if="regions.length === 0" class="text-xs text-muted-foreground">No regions are loaded yet.</p>
                        </div>
                        <p class="text-xs text-muted-foreground">With no region selected, the import adds nothing.</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Public Access Section -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-xl font-semibold">
                        <Globe class="size-5" />
                        Public Access
                    </CardTitle>
                    <CardDescription>Allow anyone to view this map without logging in</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="font-medium">Public Map</h4>
                                <p class="text-sm text-muted-foreground">
                                    When enabled, anyone can view this map as a Viewer without needing an account.
                                </p>
                            </div>
                            <Switch :model-value="is_public" @update:modelValue="togglePublic" />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Share Link Section -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-xl font-semibold">
                        <Link2 class="size-5" />
                        Share Link
                    </CardTitle>
                    <CardDescription>Generate a shareable link for viewer-only access</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <p class="text-sm text-muted-foreground">
                            Share links provide viewer-only access to this map. Anyone with the link can view the map topology, signatures, killmails,
                            and EVE Scout connections.
                        </p>

                        <div v-if="share_token" class="space-y-3">
                            <div class="flex items-center gap-2">
                                <Input :model-value="shareUrl" readonly class="font-mono text-sm" />
                                <Button variant="outline" size="icon" @click="copyShareLink">
                                    <Check v-if="copied" class="size-4 text-green-500" />
                                    <Copy v-else class="size-4" />
                                </Button>
                            </div>
                            <Button variant="destructive" size="sm" @click="revokeShareToken">
                                <Link2Off class="mr-2 size-4" />
                                Revoke Share Link
                            </Button>
                        </div>

                        <Button v-else variant="outline" @click="generateShareToken">
                            <Link2 class="mr-2 size-4" />
                            Generate Share Link
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Danger Zone (Owner Only) -->
            <Card v-if="user_is_owner">
                <CardHeader>
                    <CardTitle class="text-xl font-semibold">Danger Zone</CardTitle>
                    <CardDescription>Irreversible and destructive actions</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="font-medium">Delete Map</h4>
                            <p class="text-sm text-muted-foreground">Permanently delete this map and all its data. This action cannot be undone.</p>
                        </div>
                        <Dialog v-model:open="is_deleting">
                            <DialogTrigger as-child>
                                <Button variant="destructive">Delete Map</Button>
                            </DialogTrigger>
                            <DialogContent class="max-w-md">
                                <DialogHeader>
                                    <DialogTitle class="text-foreground">Delete Map</DialogTitle>
                                    <DialogDescription class="text-muted-foreground">
                                        This will permanently delete <strong class="text-foreground">"{{ map.name }}"</strong> and all associated
                                        data:
                                    </DialogDescription>
                                </DialogHeader>

                                <div class="space-y-4">
                                    <div class="rounded-lg border border-border/50 p-3">
                                        <ul class="space-y-1 text-sm text-muted-foreground">
                                            <li>• Solar systems and positions</li>
                                            <li>• Wormhole connections</li>
                                            <li>• Signatures and bookmarks</li>
                                            <li>• Access permissions</li>
                                            <li>• User preferences</li>
                                        </ul>
                                    </div>

                                    <div>
                                        <Label for="delete-confirmation" class="text-sm font-medium">
                                            Type <strong>{{ map.name }}</strong> to confirm:
                                        </Label>
                                        <Input
                                            id="delete-confirmation"
                                            v-model="delete_confirmation"
                                            placeholder="Enter map name"
                                            class="mt-2"
                                            @keydown.enter="deleteMap"
                                        />
                                    </div>
                                </div>

                                <DialogFooter class="gap-2">
                                    <Button variant="outline" @click="is_deleting = false">Cancel</Button>
                                    <Button variant="destructive" @click="deleteMap" :disabled="delete_confirmation !== map.name">
                                        Delete Forever
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </CardContent>
            </Card>
        </div>
    </SettingsLayout>
</template>
