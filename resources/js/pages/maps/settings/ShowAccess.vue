<script setup lang="ts">
import DiscordAccessController from '@/actions/App/Http/Controllers/DiscordAccessController';
import MapAccessController from '@/actions/App/Http/Controllers/MapAccessController';
import AccessExpiryPicker from '@/components/access/AccessExpiryPicker.vue';
import EditIcon from '@/components/icons/EditIcon.vue';
import EyeIcon from '@/components/icons/EyeIcon.vue';
import InfoIcon from '@/components/icons/InfoIcon.vue';
import { AllianceLogo, CharacterImage, CorporationLogo } from '@/components/images';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSearch } from '@/composables/useSearch';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { TMapSummary } from '@/pages/maps';
import { router } from '@inertiajs/vue3';
import { Ban, Crown, Eye, Pencil, Settings, Shield } from 'lucide-vue-next';

type TSearchEntity = {
    id: number;
    name: string;
    type: 'character' | 'corporation' | 'alliance';
    permission: 'viewer' | 'member' | 'manager' | null;
    is_owner: boolean;
};

type TAccessEntity = TSearchEntity & {
    expires_at: string | null;
};

const { map, entities, entitiesWithAccess, discordAccess } = defineProps<{
    map: TMapSummary;
    entities: TSearchEntity[];
    entitiesWithAccess: TAccessEntity[];
    discordAccess: {
        status: 'pending' | 'active' | 'expired' | 'removed' | string | null;
        guild_name: string | null;
        unmatched_count: number;
        permission: 'viewer' | 'member' | null;
        invite_url: string | null;
        error: string | null;
        roster: {
            members: {
                username: string;
                user_name: string;
                characters: {
                    name: string;
                    corporation: { name: string; ticker: string; as_of: string | null } | null;
                    alliance: { name: string; ticker: string; as_of: string | null } | null;
                }[];
            }[];
            page: number;
            per_page: number;
            total: number;
            last_page: number;
            unmatched_count: number;
            corporations: { id: number; name: string; ticker: string; count: number }[];
            alliances: { id: number; name: string; ticker: string; count: number }[];
            corporation_id: number | null;
            alliance_id: number | null;
        } | null;
    };
}>();

function setDiscordPermission(permission: string) {
    router.post(DiscordAccessController.updatePermission(map.slug), { permission }, { preserveScroll: true });
}

function filterRoster(corporation_id: string | null, alliance_id: string | null) {
    router.get(
        MapAccessController.show(map.slug).url,
        {
            corporation_id: corporation_id || undefined,
            alliance_id: alliance_id || undefined,
            roster_page: 1,
        },
        { preserveState: true, preserveScroll: true, only: ['discordAccess'] },
    );
}

function rosterPage(page: number, corporation_id: number | null, alliance_id: number | null) {
    router.get(
        MapAccessController.show(map.slug).url,
        {
            corporation_id: corporation_id ?? undefined,
            alliance_id: alliance_id ?? undefined,
            roster_page: page,
        },
        { preserveState: true, preserveScroll: true, only: ['discordAccess'] },
    );
}

const search = useSearch();

function toggleAccess(entity: TSearchEntity | TAccessEntity, permission: 'viewer' | 'member' | 'manager' | null, expires_at?: string | null) {
    router.post(
        MapAccessController.store(map.slug),
        {
            entity_id: entity.id,
            entity_type: entity.type,
            permission: permission,
            expires_at: expires_at !== undefined ? expires_at : 'expires_at' in entity ? entity.expires_at : null,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <SettingsLayout :map="map" title="Access Control" description="Manage who can view and edit this map">
        <div class="space-y-8">
            <div class="rounded-lg border border-border/50 p-4">
                <h3 class="text-lg font-semibold">Discord server</h3>
                <p class="text-sm text-muted-foreground">
                    Linked members of one Discord server get the level chosen below. They cannot manage this map. Nicknames are ignored. People who have not linked an account are counted, not listed.
                </p>
                <p v-if="discordAccess.error" class="mt-3 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                    {{ discordAccess.error }}
                </p>
                <p v-if="discordAccess.status === 'active'" class="mt-3 text-sm">
                    Active. Connected<span v-if="discordAccess.guild_name"> to {{ discordAccess.guild_name }}</span><span v-else> to a Discord server</span>.
                    {{ discordAccess.unmatched_count }} unmatched member<span v-if="discordAccess.unmatched_count === 1"> is</span><span v-else>s are</span> not granted access.
                </p>
                <p v-else-if="discordAccess.status === 'pending'" class="mt-3 text-sm">
                    Pending. Finish authorization for this map. This works when the bot is already in the server. The invite expires in 15 minutes.
                </p>
                <p v-else-if="discordAccess.status === 'expired'" class="mt-3 text-sm">
                    Expired. The last invite was not completed. Start a new one.
                </p>
                <p v-else-if="discordAccess.status === 'removed'" class="mt-3 text-sm">
                    Removed<span v-if="discordAccess.guild_name"> from {{ discordAccess.guild_name }}</span>. The access bot is not in that server, so derived access was dropped.
                </p>
                <p v-else class="mt-3 text-sm">No Discord server is connected.</p>
                <a v-if="discordAccess.invite_url" :href="discordAccess.invite_url" class="mt-3 inline-flex text-sm underline">
                    Open the Discord authorization
                </a>
                <div v-if="discordAccess.status === 'active'" class="mt-4 max-w-xs">
                    <p class="mb-2 text-sm font-medium">Access level</p>
                    <Select :model-value="discordAccess.permission ?? 'member'" @update:model-value="setDiscordPermission(String($event))">
                        <SelectTrigger class="w-40" size="sm">
                            <span class="flex items-center gap-2">
                                <Eye v-if="discordAccess.permission === 'viewer'" class="size-4 text-blue-500" />
                                <Pencil v-else class="size-4 text-green-500" />
                                {{ discordAccess.permission === 'viewer' ? 'Viewer' : 'Member' }}
                            </span>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="viewer">
                                <span class="flex items-center gap-2"><Eye class="size-4 text-blue-500" /> Viewer</span>
                            </SelectItem>
                            <SelectItem value="member">
                                <span class="flex items-center gap-2"><Pencil class="size-4 text-green-500" /> Member</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="mt-4 flex gap-2">
                    <button
                        v-if="discordAccess.status !== 'active'"
                        type="button"
                        class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground"
                        @click="router.post(DiscordAccessController.store(map.slug))"
                    >
                        Connect a Discord server
                    </button>
                    <button
                        v-if="discordAccess.status === 'active'"
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm"
                        @click="router.delete(DiscordAccessController.disconnect(map.slug))"
                    >
                        Disconnect
                    </button>
                </div>
                <div v-if="discordAccess.roster" class="mt-6 space-y-3">
                    <h4 class="text-sm font-medium">Linked members</h4>
                    <p class="text-sm text-muted-foreground">
                        {{ discordAccess.roster.unmatched_count }} server member<span v-if="discordAccess.roster.unmatched_count === 1"> is</span><span v-else>s are</span>
                        not linked and not listed. Corporation and alliance names are from the last stored refresh.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Select
                            :model-value="discordAccess.roster.corporation_id ? String(discordAccess.roster.corporation_id) : 'all'"
                            @update:model-value="filterRoster($event === 'all' ? null : String($event), discordAccess.roster?.alliance_id ? String(discordAccess.roster.alliance_id) : null)"
                        >
                            <SelectTrigger class="w-56" size="sm">
                                <span>Corporation</span>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All corporations</SelectItem>
                                <SelectItem v-for="corporation in discordAccess.roster.corporations" :key="corporation.id" :value="String(corporation.id)">
                                    {{ corporation.name }}<span v-if="corporation.ticker"> [{{ corporation.ticker }}]</span> ({{ corporation.count }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            :model-value="discordAccess.roster.alliance_id ? String(discordAccess.roster.alliance_id) : 'all'"
                            @update:model-value="filterRoster(discordAccess.roster?.corporation_id ? String(discordAccess.roster.corporation_id) : null, $event === 'all' ? null : String($event))"
                        >
                            <SelectTrigger class="w-56" size="sm">
                                <span>Alliance</span>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All alliances</SelectItem>
                                <SelectItem v-for="alliance in discordAccess.roster.alliances" :key="alliance.id" :value="String(alliance.id)">
                                    {{ alliance.name }}<span v-if="alliance.ticker"> [{{ alliance.ticker }}]</span> ({{ alliance.count }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <p v-if="discordAccess.roster.members.length === 0" class="text-sm text-muted-foreground">No linked members on this page.</p>
                    <ul v-else class="divide-y rounded-md border border-border/50">
                        <li v-for="member in discordAccess.roster.members" :key="member.username + member.user_name" class="p-3 text-sm">
                            <p class="font-medium">{{ member.username }}</p>
                            <p class="text-muted-foreground">{{ member.user_name }}</p>
                            <ul class="mt-2 space-y-1">
                                <li v-for="character in member.characters" :key="character.name">
                                    {{ character.name }}
                                    <span v-if="character.corporation">
                                        · {{ character.corporation.name }}<span v-if="character.corporation.ticker"> [{{ character.corporation.ticker }}]</span>
                                        <span v-if="character.corporation.as_of"> · as of {{ character.corporation.as_of }}</span>
                                    </span>
                                    <span v-if="character.alliance">
                                        · {{ character.alliance.name }}<span v-if="character.alliance.ticker"> [{{ character.alliance.ticker }}]</span>
                                    </span>
                                </li>
                            </ul>
                        </li>
                    </ul>
                    <div v-if="discordAccess.roster.last_page > 1" class="flex gap-2">
                        <button type="button" class="rounded-md border px-3 py-2 text-sm" :disabled="(discordAccess.roster?.page ?? 1) <= 1" @click="rosterPage((discordAccess.roster?.page ?? 1) - 1, discordAccess.roster?.corporation_id ?? null, discordAccess.roster?.alliance_id ?? null)">Previous</button>
                        <button type="button" class="rounded-md border px-3 py-2 text-sm" :disabled="(discordAccess.roster?.page ?? 1) >= (discordAccess.roster?.last_page ?? 1)" @click="rosterPage((discordAccess.roster?.page ?? 1) + 1, discordAccess.roster?.corporation_id ?? null, discordAccess.roster?.alliance_id ?? null)">Next</button>
                    </div>
                </div>
            </div>
            <!-- Section 1: Entities with Access -->
            <div>
                <h3 class="text-lg font-semibold">Entities with Access</h3>
                <p class="text-sm text-muted-foreground">All entities that currently have access to this map</p>

                <div v-if="entitiesWithAccess.length === 0" class="mt-4 flex flex-col items-center justify-center py-8 text-muted-foreground">
                    <Shield class="mb-2 size-8" />
                    <p class="text-sm">No entities currently have access to this map.</p>
                </div>

                <div v-else class="mt-4 rounded-lg border border-border/50">
                    <Table>
                        <TableHeader>
                            <TableRow class="border-border/50">
                                <TableHead class="w-12"></TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Access Level</TableHead>
                                <TableHead>Expires</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="entity in entitiesWithAccess" :key="entity.id" class="border-border/50">
                                <TableCell>
                                    <template v-if="entity.type === 'character'">
                                        <CharacterImage :character_id="entity.id" :character_name="entity.name" class="size-8 rounded-lg" />
                                    </template>
                                    <template v-else-if="entity.type === 'corporation'">
                                        <CorporationLogo :corporation_id="entity.id" :corporation_name="entity.name" class="size-8 rounded-lg" />
                                    </template>
                                    <template v-else-if="entity.type === 'alliance'">
                                        <AllianceLogo :alliance_id="entity.id" :alliance_name="entity.name" class="size-8 rounded-lg" />
                                    </template>
                                </TableCell>
                                <TableCell class="font-medium">{{ entity.name }}</TableCell>
                                <TableCell>
                                    <Badge variant="outline" class="capitalize">{{ entity.type }}</Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge v-if="entity.is_owner" class="bg-amber-500/10 text-amber-500 hover:bg-amber-500/10">
                                        <Crown class="mr-1 size-3" />
                                        Owner
                                    </Badge>
                                    <Select
                                        v-else
                                        :model-value="entity.permission ?? 'none'"
                                        @update:model-value="
                                            toggleAccess(entity, $event === 'none' ? null : ($event as 'viewer' | 'member' | 'manager'))
                                        "
                                    >
                                        <SelectTrigger class="w-36" size="sm">
                                            <span class="flex items-center gap-2">
                                                <Ban v-if="!entity.permission" class="size-4 text-muted-foreground" />
                                                <Eye v-else-if="entity.permission === 'viewer'" class="size-4 text-blue-500" />
                                                <Pencil v-else-if="entity.permission === 'member'" class="size-4 text-green-500" />
                                                <Settings v-else-if="entity.permission === 'manager'" class="size-4 text-purple-500" />
                                                {{
                                                    entity.permission
                                                        ? entity.permission.charAt(0).toUpperCase() + entity.permission.slice(1)
                                                        : 'None'
                                                }}
                                            </span>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                <span class="flex items-center gap-2">
                                                    <Ban class="size-4 text-muted-foreground" />
                                                    None
                                                </span>
                                            </SelectItem>
                                            <SelectItem value="viewer">
                                                <span class="flex items-center gap-2">
                                                    <Eye class="size-4 text-blue-500" />
                                                    Viewer
                                                </span>
                                            </SelectItem>
                                            <SelectItem value="member">
                                                <span class="flex items-center gap-2">
                                                    <Pencil class="size-4 text-green-500" />
                                                    Member
                                                </span>
                                            </SelectItem>
                                            <SelectItem value="manager">
                                                <span class="flex items-center gap-2">
                                                    <Settings class="size-4 text-purple-500" />
                                                    Manager
                                                </span>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                                <TableCell>
                                    <AccessExpiryPicker
                                        v-if="!entity.is_owner && entity.permission !== null"
                                        :expires-at="entity.expires_at"
                                        @update="toggleAccess(entity, entity.permission, $event)"
                                    />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </div>

            <Separator />

            <!-- Section 2: Permission Levels -->
            <div>
                <h3 class="text-lg font-semibold">Permission Levels</h3>
                <p class="text-sm text-muted-foreground">Understanding access levels</p>

                <div class="mt-4 grid grid-cols-2 gap-4">
                    <div class="border border-l-2 border-border/50 border-l-blue-500 bg-muted/30 p-4">
                        <div class="mb-3 flex items-center gap-2">
                            <EyeIcon />
                            <h4 class="font-medium">Viewer</h4>
                        </div>
                        <ul class="space-y-1 text-sm text-muted-foreground">
                            <li>• View map and systems</li>
                            <li>• See signatures and connections</li>
                            <li>• View killmails and EVE Scout</li>
                            <li>• Cannot see character locations</li>
                            <li>• Cannot see audits or ship history</li>
                            <li>• Cannot see or edit notes</li>
                            <li>• Cannot make any changes</li>
                        </ul>
                    </div>
                    <div class="border border-l-2 border-border/50 border-l-green-500 bg-muted/30 p-4">
                        <div class="mb-3 flex items-center gap-2">
                            <EditIcon />
                            <h4 class="font-medium">Member</h4>
                        </div>
                        <ul class="space-y-1 text-sm text-muted-foreground">
                            <li>• All Viewer permissions</li>
                            <li>• View character locations and intel</li>
                            <li>• Add/edit signatures</li>
                            <li>• Create/modify connections</li>
                            <li>• Add systems to the map</li>
                            <li>• Edit notes</li>
                        </ul>
                    </div>
                    <div class="border border-l-2 border-border/50 border-l-purple-500 bg-muted/30 p-4">
                        <div class="mb-3 flex items-center gap-2">
                            <Settings class="size-4" />
                            <h4 class="font-medium">Manager</h4>
                        </div>
                        <ul class="space-y-1 text-sm text-muted-foreground">
                            <li>• All Member permissions</li>
                            <li>• Grant and revoke permissions</li>
                            <li>• Configure map settings</li>
                            <li>• Manage public access</li>
                            <li>• Generate share links</li>
                        </ul>
                    </div>
                    <div class="border border-l-2 border-border/50 border-l-amber-500 bg-muted/30 p-4">
                        <div class="mb-3 flex items-center gap-2">
                            <Crown class="size-4" />
                            <h4 class="font-medium">Owner</h4>
                        </div>
                        <ul class="space-y-1 text-sm text-muted-foreground">
                            <li>• All Manager permissions</li>
                            <li>• Delete the map</li>
                            <li>• Cannot be removed</li>
                            <li>• One per map</li>
                        </ul>
                    </div>
                </div>
            </div>

            <Separator />

            <!-- Section 3: Manage Permissions -->
            <div>
                <h3 class="text-lg font-semibold">Manage Permissions</h3>
                <p class="text-sm text-muted-foreground">Search and grant access to characters, corporations, and alliances</p>

                <div class="mt-4 space-y-4">
                    <Input v-model="search" placeholder="Search characters, corporations, or alliances..." />

                    <div class="flex items-center gap-2 border-l-2 border-l-orange-500 bg-orange-500/10 p-4 text-sm text-orange-500">
                        <InfoIcon />
                        <p>Only characters, corporations, and alliances that have registered at least once will be shown here.</p>
                    </div>

                    <div class="rounded-lg border border-border/50">
                        <Table>
                            <TableHeader>
                                <TableRow class="border-border/50">
                                    <TableHead class="w-12"></TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Access</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="entity in entities" :key="entity.id" class="border-border/50">
                                    <TableCell>
                                        <template v-if="entity.type === 'character'">
                                            <CharacterImage :character_id="entity.id" :character_name="entity.name" class="size-8 rounded-lg" />
                                        </template>
                                        <template v-else-if="entity.type === 'corporation'">
                                            <CorporationLogo :corporation_id="entity.id" :corporation_name="entity.name" class="size-8 rounded-lg" />
                                        </template>
                                        <template v-else-if="entity.type === 'alliance'">
                                            <AllianceLogo :alliance_id="entity.id" :alliance_name="entity.name" class="size-8 rounded-lg" />
                                        </template>
                                    </TableCell>
                                    <TableCell class="font-medium">{{ entity.name }}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline" class="capitalize">{{ entity.type }}</Badge>
                                    </TableCell>
                                    <TableCell>
                                        <Badge v-if="entity.is_owner" class="bg-amber-500/10 text-amber-500 hover:bg-amber-500/10">
                                            <Crown class="mr-1 size-3" />
                                            Owner
                                        </Badge>
                                        <Select
                                            v-else
                                            :model-value="entity.permission ?? 'none'"
                                            @update:model-value="
                                                toggleAccess(entity, $event === 'none' ? null : ($event as 'viewer' | 'member' | 'manager'))
                                            "
                                        >
                                            <SelectTrigger class="w-36" size="sm">
                                                <span class="flex items-center gap-2">
                                                    <Ban v-if="!entity.permission" class="size-4 text-muted-foreground" />
                                                    <Eye v-else-if="entity.permission === 'viewer'" class="size-4 text-blue-500" />
                                                    <Pencil v-else-if="entity.permission === 'member'" class="size-4 text-green-500" />
                                                    <Settings v-else-if="entity.permission === 'manager'" class="size-4 text-purple-500" />
                                                    {{
                                                        entity.permission
                                                            ? entity.permission.charAt(0).toUpperCase() + entity.permission.slice(1)
                                                            : 'None'
                                                    }}
                                                </span>
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    <span class="flex items-center gap-2">
                                                        <Ban class="size-4 text-muted-foreground" />
                                                        None
                                                    </span>
                                                </SelectItem>
                                                <SelectItem value="viewer">
                                                    <span class="flex items-center gap-2">
                                                        <Eye class="size-4 text-blue-500" />
                                                        Viewer
                                                    </span>
                                                </SelectItem>
                                                <SelectItem value="member">
                                                    <span class="flex items-center gap-2">
                                                        <Pencil class="size-4 text-green-500" />
                                                        Member
                                                    </span>
                                                </SelectItem>
                                                <SelectItem value="manager">
                                                    <span class="flex items-center gap-2">
                                                        <Settings class="size-4 text-purple-500" />
                                                        Manager
                                                    </span>
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
