<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Key, Plus, Copy, Check, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

type ApiKeyItem = {
    id: string;
    name: string;
    key_prefix: string;
    environment: string;
    last_used_at: string | null;
    created_at: string;
};

type Props = {
    apiKeys: ApiKeyItem[];
    newKey: string | null;
};

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'API Keys', href: '/dashboard/api-keys' },
];

const showCreateDialog = ref(false);
const createName = ref('');
const createEnvironment = ref('live');
const creating = ref(false);
const copied = ref(false);

function createApiKey() {
    creating.value = true;
    router.post('/dashboard/api-keys', {
        name: createName.value,
        environment: createEnvironment.value,
    }, {
        onFinish: () => {
            creating.value = false;
            showCreateDialog.value = false;
            createName.value = '';
            createEnvironment.value = 'live';
        },
    });
}

function revokeApiKey(id: string) {
    if (!confirm('Are you sure you want to revoke this API key? This cannot be undone.')) {
        return;
    }
    router.delete(`/dashboard/api-keys/${id}`);
}

async function copyToClipboard(text: string) {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}
</script>

<template>
    <Head title="API Keys" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- New Key Alert -->
            <Alert v-if="newKey" class="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                <Key class="size-4 text-green-600 dark:text-green-400" />
                <AlertTitle class="text-green-800 dark:text-green-200">API Key Created</AlertTitle>
                <AlertDescription class="text-green-700 dark:text-green-300">
                    <p class="mb-2">Copy your API key now. You will not be able to see it again.</p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 rounded-md bg-green-100 px-3 py-2 font-mono text-sm dark:bg-green-900">{{ newKey }}</code>
                        <Button variant="outline" size="sm" class="gap-1" @click="copyToClipboard(newKey!)">
                            <component :is="copied ? Check : Copy" class="size-3" />
                            {{ copied ? 'Copied' : 'Copy' }}
                        </Button>
                    </div>
                </AlertDescription>
            </Alert>

            <Card>
                <CardHeader class="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>API Keys</CardTitle>
                        <CardDescription>Manage your API keys for authenticating requests</CardDescription>
                    </div>
                    <Dialog v-model:open="showCreateDialog">
                        <DialogTrigger as-child>
                            <Button size="sm" class="gap-1">
                                <Plus class="size-3" />
                                Create API Key
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Create API Key</DialogTitle>
                                <DialogDescription>
                                    Create a new API key to authenticate your requests.
                                </DialogDescription>
                            </DialogHeader>
                            <form @submit.prevent="createApiKey" class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="key-name">Name</Label>
                                    <Input
                                        id="key-name"
                                        v-model="createName"
                                        placeholder="e.g. Production, Development"
                                        required
                                    />
                                </div>
                                <div class="space-y-2">
                                    <Label for="key-environment">Environment</Label>
                                    <Select v-model="createEnvironment">
                                        <SelectTrigger id="key-environment">
                                            <SelectValue placeholder="Select environment" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="live">Live</SelectItem>
                                            <SelectItem value="test">Test</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <DialogFooter>
                                    <Button type="submit" :disabled="creating || !createName">
                                        {{ creating ? 'Creating...' : 'Create Key' }}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </CardHeader>
                <CardContent>
                    <Table v-if="apiKeys.length > 0">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Key</TableHead>
                                <TableHead>Environment</TableHead>
                                <TableHead>Last Used</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="key in apiKeys" :key="key.id">
                                <TableCell class="font-medium">{{ key.name }}</TableCell>
                                <TableCell>
                                    <code class="rounded bg-muted px-1.5 py-0.5 text-xs font-mono">{{ key.key_prefix }}...</code>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="key.environment === 'live' ? 'default' : 'secondary'">
                                        {{ key.environment }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="text-muted-foreground">{{ key.last_used_at ?? 'Never' }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ key.created_at }}</TableCell>
                                <TableCell class="text-right">
                                    <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="revokeApiKey(key.id)">
                                        <Trash2 class="size-4" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <div v-else class="py-8 text-center text-muted-foreground">
                        <Key class="mx-auto mb-2 size-8 opacity-50" />
                        <p>No API keys yet. Create one to get started.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
