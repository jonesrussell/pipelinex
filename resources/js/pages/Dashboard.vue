<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, CheckCircle, Zap, Key, ArrowRight } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Props = {
    stats: {
        crawls_used: number;
        crawl_limit: number;
        success_rate: number;
        avg_speed_ms: number;
    };
    recentCrawls: Array<{
        id: string;
        url: string;
        status: string;
        duration_ms: number | null;
        created_at: string;
    }>;
    hasApiKey: boolean;
    plan: string;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

function statusVariant(status: string) {
    switch (status) {
        case 'completed':
            return 'default';
        case 'failed':
            return 'destructive';
        default:
            return 'secondary';
    }
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Quick Start Alert (if no API key) -->
            <Alert v-if="!hasApiKey" class="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
                <Key class="size-4 text-blue-600 dark:text-blue-400" />
                <AlertTitle class="text-blue-800 dark:text-blue-200">Get Started</AlertTitle>
                <AlertDescription class="text-blue-700 dark:text-blue-300">
                    <p class="mb-3">Create an API key to start crawling pages. Here is an example cURL request:</p>
                    <pre class="mb-3 overflow-x-auto rounded-md bg-blue-100 p-3 text-xs dark:bg-blue-900">curl -X POST https://api.pipelinex.dev/v1/crawl \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://example.com"}'</pre>
                    <Link href="/dashboard/api-keys">
                        <Button size="sm" class="gap-1">
                            <Key class="size-3" />
                            Create API Key
                            <ArrowRight class="size-3" />
                        </Button>
                    </Link>
                </AlertDescription>
            </Alert>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Crawls Used</CardTitle>
                        <Activity class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.crawls_used }}<span class="text-sm font-normal text-muted-foreground"> / {{ stats.crawl_limit }}</span></div>
                        <p class="text-xs text-muted-foreground">This month on the {{ plan }} plan</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Success Rate</CardTitle>
                        <CheckCircle class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.success_rate }}%</div>
                        <p class="text-xs text-muted-foreground">Successful crawls this month</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Avg Speed</CardTitle>
                        <Zap class="size-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.avg_speed_ms }}<span class="text-sm font-normal text-muted-foreground">ms</span></div>
                        <p class="text-xs text-muted-foreground">Average crawl duration</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent Crawls -->
            <Card>
                <CardHeader class="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>Recent Crawls</CardTitle>
                        <CardDescription>Your 5 most recent crawl jobs</CardDescription>
                    </div>
                    <Link href="/dashboard/history">
                        <Button variant="outline" size="sm" class="gap-1">
                            View All
                            <ArrowRight class="size-3" />
                        </Button>
                    </Link>
                </CardHeader>
                <CardContent>
                    <Table v-if="recentCrawls.length > 0">
                        <TableHeader>
                            <TableRow>
                                <TableHead>URL</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead class="text-right">When</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="crawl in recentCrawls" :key="crawl.id">
                                <TableCell class="max-w-xs truncate font-mono text-sm">{{ crawl.url }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusVariant(crawl.status)">{{ crawl.status }}</Badge>
                                </TableCell>
                                <TableCell>{{ crawl.duration_ms ? `${crawl.duration_ms}ms` : '-' }}</TableCell>
                                <TableCell class="text-right text-muted-foreground">{{ crawl.created_at }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <div v-else class="py-8 text-center text-muted-foreground">
                        <p>No crawl jobs yet.</p>
                        <Link href="/dashboard/crawl" class="mt-2 inline-block">
                            <Button variant="outline" size="sm">Try the Playground</Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
