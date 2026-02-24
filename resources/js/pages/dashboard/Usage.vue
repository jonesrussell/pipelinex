<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type DailyUsageItem = {
    date: string;
    total: number;
    succeeded: number;
    failed: number;
};

type Props = {
    plan: string;
    crawls: {
        used: number;
        limit: number;
    };
    rateLimit: number;
    dailyUsage: DailyUsageItem[];
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Usage', href: '/dashboard/usage' },
];

const usagePercentage = computed(() => {
    if (props.crawls.limit === 0) return 0;
    return Math.min(Math.round((props.crawls.used / props.crawls.limit) * 100), 100);
});

const remaining = computed(() => Math.max(props.crawls.limit - props.crawls.used, 0));

function planLabel(plan: string): string {
    return plan.charAt(0).toUpperCase() + plan.slice(1);
}
</script>

<template>
    <Head title="Usage" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <!-- Plan & Quota -->
            <div class="grid gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium">Current Plan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ planLabel(plan) }}</div>
                        <p class="text-xs text-muted-foreground">{{ crawls.limit.toLocaleString() }} crawls/month</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium">Crawls Used</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ crawls.used.toLocaleString() }}<span class="text-sm font-normal text-muted-foreground"> / {{ crawls.limit.toLocaleString() }}</span></div>
                        <p class="text-xs text-muted-foreground">{{ remaining.toLocaleString() }} remaining this month</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium">Rate Limit</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ rateLimit }}<span class="text-sm font-normal text-muted-foreground"> req/min</span></div>
                        <p class="text-xs text-muted-foreground">Maximum concurrent requests</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Quota Progress Bar -->
            <Card>
                <CardHeader>
                    <CardTitle>Monthly Quota</CardTitle>
                    <CardDescription>{{ usagePercentage }}% of your monthly crawl limit used</CardDescription>
                </CardHeader>
                <CardContent>
                    <Progress :model-value="usagePercentage" class="h-3" />
                    <div class="mt-2 flex justify-between text-sm text-muted-foreground">
                        <span>{{ crawls.used.toLocaleString() }} used</span>
                        <span>{{ crawls.limit.toLocaleString() }} limit</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Daily Breakdown -->
            <Card>
                <CardHeader>
                    <CardTitle>Daily Breakdown</CardTitle>
                    <CardDescription>Crawl usage by day this month</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table v-if="dailyUsage.length > 0">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Succeeded</TableHead>
                                <TableHead>Failed</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="day in dailyUsage" :key="day.date">
                                <TableCell class="font-mono text-sm">{{ day.date }}</TableCell>
                                <TableCell>{{ day.total }}</TableCell>
                                <TableCell>
                                    <Badge variant="default">{{ day.succeeded }}</Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge v-if="day.failed > 0" variant="destructive">{{ day.failed }}</Badge>
                                    <span v-else class="text-muted-foreground">0</span>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <div v-else class="py-8 text-center text-muted-foreground">
                        <p>No usage data for this month yet.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
