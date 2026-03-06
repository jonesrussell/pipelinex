#!/usr/bin/env bash
# drift-detector.sh — map recent git changes to affected subsystem specs
# Usage: ./tools/drift-detector.sh [num_commits]
#   num_commits: how many commits back to check (default: 1)

set -euo pipefail

NUM_COMMITS="${1:-1}"
CHANGED_FILES=$(git diff --name-only "HEAD~${NUM_COMMITS}" HEAD 2>/dev/null || git diff --name-only HEAD 2>/dev/null || true)

if [ -z "$CHANGED_FILES" ]; then
    echo "No changed files found."
    exit 0
fi

declare -A AFFECTED
AFFECTED["api-pipeline"]=""
AFFECTED["auth-tenancy"]=""
AFFECTED["dashboard"]=""
AFFECTED["cli"]=""

while IFS= read -r file; do
    case "$file" in
        app/Http/Controllers/Api/*|app/Jobs/*|app/Services/NorthCloud*)
            AFFECTED["api-pipeline"]+=" $file"
            ;;
        app/Http/Middleware/Auth*|app/Http/Middleware/EnforceQuota*|app/Http/Middleware/RateLimit*|app/Models/ApiKey*|app/Models/Tenant*|app/Actions/Fortify*)
            AFFECTED["auth-tenancy"]+=" $file"
            ;;
        resources/js/*|app/Http/Controllers/Dashboard/*|app/Http/Controllers/Settings/*|app/Listeners/Stripe*)
            AFFECTED["dashboard"]+=" $file"
            ;;
        cli/*)
            AFFECTED["cli"]+=" $file"
            ;;
    esac
done <<< "$CHANGED_FILES"

echo "Changed files in last ${NUM_COMMITS} commit(s) affect these specs:"
echo ""

FOUND=0
for spec in "api-pipeline" "auth-tenancy" "dashboard" "cli"; do
    if [ -n "${AFFECTED[$spec]}" ]; then
        FOUND=1
        echo "  → ${spec}"
        for f in ${AFFECTED[$spec]}; do
            echo "      ${f}"
        done
    fi
done

if [ "$FOUND" -eq 0 ]; then
    echo "  (no spec-related files changed)"
fi
