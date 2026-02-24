<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Clock } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type CrawlJobItem = {
    id: string;
    url: string;
    status: string;
    duration_ms: number | null;
    created_at: string;
};

type PaginatedData<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};

type Props = {
    crawlJobs: PaginatedData<CrawlJobItem>;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'History', href: '/dashboard/history' },
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

function viewDetail(id: string) {
    router.visit(`/dashboard/history/${id}`);
}
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Crawl History</CardTitle>
                    <CardDescription>Browse all your past crawl jobs</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table v-if="crawlJobs.data.length > 0">
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>URL</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead class="text-right">Date</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="job in crawlJobs.data"
                                :key="job.id"
                                class="cursor-pointer"
                                @click="viewDetail(job.id)"
                            >
                                <TableCell class="font-mono text-xs">{{ job.id }}</TableCell>
                                <TableCell class="max-w-sm truncate font-mono text-sm">{{ job.url }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusVariant(job.status)">{{ job.status }}</Badge>
                                </TableCell>
                                <TableCell>{{ job.duration_ms ? `${job.duration_ms}ms` : '-' }}</TableCell>
                                <TableCell class="text-right text-muted-foreground">{{ job.created_at }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <div v-else class="py-8 text-center text-muted-foreground">
                        <Clock class="mx-auto mb-2 size-8 opacity-50" />
                        <p>No crawl history yet.</p>
                    </div>

                    <!-- Pagination -->
                    <div v-if="crawlJobs.last_page > 1" class="mt-4 flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">
                            Showing page {{ crawlJobs.current_page }} of {{ crawlJobs.last_page }}
                            ({{ crawlJobs.total }} total)
                        </p>
                        <div class="flex gap-2">
                            <Link v-if="crawlJobs.prev_page_url" :href="crawlJobs.prev_page_url">
                                <Button variant="outline" size="sm">Previous</Button>
                            </Link>
                            <Link v-if="crawlJobs.next_page_url" :href="crawlJobs.next_page_url">
                                <Button variant="outline" size="sm">Next</Button>
                            </Link>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
