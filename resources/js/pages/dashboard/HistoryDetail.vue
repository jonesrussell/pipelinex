<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';

type CrawlJobDetail = {
    id: string;
    url: string;
    final_url: string | null;
    status: string;
    http_status_code: number | null;
    duration_ms: number | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
    result: {
        title: string | null;
        author: string | null;
        body: string | null;
        word_count: number | null;
        quality_score: number | null;
        topics: string[] | null;
        og: Record<string, string> | null;
        content_type: string | null;
    } | null;
};

type Props = {
    crawlJob: CrawlJobDetail;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'History', href: '/dashboard/history' },
    { title: props.crawlJob.id },
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

function formatDate(isoString: string | null): string {
    if (!isoString) return '-';
    return new Date(isoString).toLocaleString();
}
</script>

<template>
    <Head :title="`Crawl ${crawlJob.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Back link -->
            <div>
                <Link href="/dashboard/history">
                    <Button variant="ghost" size="sm" class="gap-1">
                        <ArrowLeft class="size-4" />
                        Back to History
                    </Button>
                </Link>
            </div>

            <!-- Job Info -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="font-mono text-base">{{ crawlJob.id }}</CardTitle>
                            <CardDescription class="mt-1 break-all">{{ crawlJob.url }}</CardDescription>
                        </div>
                        <Badge :variant="statusVariant(crawlJob.status)">{{ crawlJob.status }}</Badge>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">HTTP Status</p>
                            <p class="text-sm">{{ crawlJob.http_status_code ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Duration</p>
                            <p class="text-sm">{{ crawlJob.duration_ms ? `${crawlJob.duration_ms}ms` : '-' }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Started</p>
                            <p class="text-sm">{{ formatDate(crawlJob.created_at) }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Completed</p>
                            <p class="text-sm">{{ formatDate(crawlJob.completed_at) }}</p>
                        </div>
                    </div>

                    <div v-if="crawlJob.final_url && crawlJob.final_url !== crawlJob.url" class="mt-4">
                        <p class="text-sm font-medium text-muted-foreground">Final URL (after redirects)</p>
                        <p class="break-all text-sm">{{ crawlJob.final_url }}</p>
                    </div>

                    <!-- Error Info -->
                    <div v-if="crawlJob.error_code" class="mt-4 rounded-md border border-destructive/20 bg-destructive/5 p-3">
                        <p class="text-sm font-medium text-destructive">Error: {{ crawlJob.error_code }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ crawlJob.error_message }}</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Result -->
            <Card v-if="crawlJob.result">
                <CardHeader>
                    <CardTitle>Extracted Content</CardTitle>
                    <CardDescription v-if="crawlJob.result.content_type">
                        Content-Type: {{ crawlJob.result.content_type }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="crawlJob.result.title">
                        <p class="text-sm font-medium text-muted-foreground">Title</p>
                        <p class="text-lg font-semibold">{{ crawlJob.result.title }}</p>
                    </div>

                    <div v-if="crawlJob.result.author">
                        <p class="text-sm font-medium text-muted-foreground">Author</p>
                        <p class="text-sm">{{ crawlJob.result.author }}</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Badge v-if="crawlJob.result.quality_score != null" variant="outline">
                            Quality: {{ (crawlJob.result.quality_score * 100).toFixed(0) }}%
                        </Badge>
                        <Badge v-if="crawlJob.result.word_count" variant="outline">
                            {{ crawlJob.result.word_count }} words
                        </Badge>
                        <Badge
                            v-for="topic in (crawlJob.result.topics || [])"
                            :key="topic"
                            variant="secondary"
                        >
                            {{ topic }}
                        </Badge>
                    </div>

                    <Separator v-if="crawlJob.result.body" />

                    <div v-if="crawlJob.result.body">
                        <p class="mb-2 text-sm font-medium text-muted-foreground">Body</p>
                        <div class="max-h-96 overflow-y-auto rounded-md border p-4">
                            <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ crawlJob.result.body }}</p>
                        </div>
                    </div>

                    <!-- Open Graph Data -->
                    <div v-if="crawlJob.result.og && Object.keys(crawlJob.result.og).length > 0">
                        <p class="mb-2 text-sm font-medium text-muted-foreground">Open Graph</p>
                        <div class="rounded-md border p-3">
                            <div v-for="(value, key) in crawlJob.result.og" :key="key" class="flex gap-2 py-1 text-sm">
                                <span class="font-mono text-muted-foreground">{{ key }}:</span>
                                <span>{{ value }}</span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
