<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Globe, Copy, Check } from 'lucide-vue-next';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type CrawlResponse = {
    id: string;
    status: string;
    url: string;
    final_url?: string;
    data?: {
        title: string | null;
        author: string | null;
        published_date: string | null;
        body: string | null;
        word_count: number | null;
        quality_score: number | null;
        topics: string[] | null;
        og: Record<string, string> | null;
        links: Array<{ href: string; text: string }> | null;
        images: Array<{ src: string; alt: string }> | null;
    };
    meta?: {
        status_code: number | null;
        content_type: string | null;
        crawled_at: string | null;
        duration_ms: number | null;
    };
    error?: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Playground', href: '/dashboard/crawl' },
];

const url = ref('');
const loading = ref(false);
const result = ref<CrawlResponse | null>(null);
const error = ref<string | null>(null);
const copied = ref(false);

const curlCommand = computed(() => {
    if (!url.value) return '';
    return `curl -X POST https://api.pipelinex.dev/v1/crawl \\
  -H "Authorization: Bearer YOUR_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d '{"url": "${url.value}"}'`;
});

async function executeCrawl() {
    if (!url.value) return;

    loading.value = true;
    result.value = null;
    error.value = null;

    try {
        const response = await axios.post('/dashboard/crawl', { url: url.value });
        result.value = response.data;
    } catch (err: any) {
        if (err.response?.data?.error) {
            error.value = err.response.data.error;
        } else if (err.response?.data?.errors?.url) {
            error.value = err.response.data.errors.url[0];
        } else {
            error.value = 'An unexpected error occurred. Please try again.';
        }
    } finally {
        loading.value = false;
    }
}

async function copyText(text: string) {
    await navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}

function qualityColor(score: number | null | undefined): string {
    if (!score) return 'secondary';
    if (score >= 0.8) return 'default';
    if (score >= 0.5) return 'secondary';
    return 'destructive';
}
</script>

<template>
    <Head title="Playground" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Input Section -->
            <Card>
                <CardHeader>
                    <CardTitle>Crawl Playground</CardTitle>
                    <CardDescription>Test the crawl API by entering a URL below</CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="executeCrawl" class="flex gap-3">
                        <div class="relative flex-1">
                            <Globe class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                v-model="url"
                                type="url"
                                placeholder="https://example.com/article"
                                class="pl-9"
                                required
                                :disabled="loading"
                            />
                        </div>
                        <Button type="submit" :disabled="loading || !url" class="min-w-[100px]">
                            <Spinner v-if="loading" class="size-4" />
                            <span v-else>Crawl</span>
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <!-- Error Alert -->
            <Alert v-if="error" variant="destructive">
                <AlertTitle>Crawl Failed</AlertTitle>
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <!-- Results -->
            <Card v-if="result">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Result</CardTitle>
                            <CardDescription>
                                <span v-if="result.meta?.duration_ms">Completed in {{ result.meta.duration_ms }}ms</span>
                                <span v-if="result.meta?.status_code"> &middot; HTTP {{ result.meta.status_code }}</span>
                            </CardDescription>
                        </div>
                        <Badge :variant="result.status === 'completed' ? 'default' : 'destructive'">
                            {{ result.status }}
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent>
                    <Tabs default-value="preview" class="w-full">
                        <TabsList class="mb-4">
                            <TabsTrigger value="preview">Preview</TabsTrigger>
                            <TabsTrigger value="json">JSON</TabsTrigger>
                            <TabsTrigger value="curl">cURL</TabsTrigger>
                        </TabsList>

                        <!-- Preview Tab -->
                        <TabsContent value="preview">
                            <div v-if="result.data" class="space-y-4">
                                <div v-if="result.data.title">
                                    <h3 class="text-lg font-semibold">{{ result.data.title }}</h3>
                                    <p v-if="result.data.author" class="text-sm text-muted-foreground">by {{ result.data.author }}</p>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <Badge v-if="result.data.quality_score != null" :variant="qualityColor(result.data.quality_score) as any">
                                        Quality: {{ (result.data.quality_score * 100).toFixed(0) }}%
                                    </Badge>
                                    <Badge v-if="result.data.word_count" variant="outline">
                                        {{ result.data.word_count }} words
                                    </Badge>
                                    <Badge
                                        v-for="topic in (result.data.topics || [])"
                                        :key="topic"
                                        variant="secondary"
                                    >
                                        {{ topic }}
                                    </Badge>
                                </div>

                                <div v-if="result.data.body" class="max-h-96 overflow-y-auto rounded-md border p-4">
                                    <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ result.data.body }}</p>
                                </div>

                                <div v-if="!result.data.title && !result.data.body" class="py-4 text-center text-muted-foreground">
                                    No content extracted.
                                </div>
                            </div>
                            <div v-else class="py-4 text-center text-muted-foreground">
                                <p v-if="result.error">{{ result.error }}</p>
                                <p v-else>No preview data available.</p>
                            </div>
                        </TabsContent>

                        <!-- JSON Tab -->
                        <TabsContent value="json">
                            <div class="relative">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="absolute right-2 top-2 gap-1"
                                    @click="copyText(JSON.stringify(result, null, 2))"
                                >
                                    <component :is="copied ? Check : Copy" class="size-3" />
                                    {{ copied ? 'Copied' : 'Copy' }}
                                </Button>
                                <pre class="max-h-[500px] overflow-auto rounded-md bg-muted p-4 text-xs">{{ JSON.stringify(result, null, 2) }}</pre>
                            </div>
                        </TabsContent>

                        <!-- cURL Tab -->
                        <TabsContent value="curl">
                            <div class="relative">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="absolute right-2 top-2 gap-1"
                                    @click="copyText(curlCommand)"
                                >
                                    <component :is="copied ? Check : Copy" class="size-3" />
                                    {{ copied ? 'Copied' : 'Copy' }}
                                </Button>
                                <pre class="overflow-auto rounded-md bg-muted p-4 text-sm">{{ curlCommand }}</pre>
                            </div>
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
