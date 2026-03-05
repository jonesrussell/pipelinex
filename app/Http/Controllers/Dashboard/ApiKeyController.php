<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;

        return Inertia::render('dashboard/ApiKeys', [
            'apiKeys' => $tenant->apiKeys()
                ->whereNull('revoked_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'key_prefix' => $key->key_prefix,
                    'environment' => $key->environment,
                    'last_used_at' => $key->last_used_at?->diffForHumans(),
                    'created_at' => $key->created_at->toFormattedDateString(),
                ]),
            'newKey' => session('newKey'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:live,test'],
        ]);

        $tenant = $request->user()->tenant;
        $result = $tenant->generateApiKey($request->name, $request->environment);

        return to_route('dashboard.api-keys')->with('newKey', $result['key']);
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        if ($apiKey->tenant_id !== $tenant->id) {
            abort(403);
        }

        $apiKey->update(['revoked_at' => now()]);

        return to_route('dashboard.api-keys');
    }
}
