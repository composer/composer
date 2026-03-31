#!/usr/bin/env bash
# Detects new public/protected methods, classes, and constants added in a PR
# and posts/updates a PR comment highlighting them.
# Uses a companion PHP script (check-api-surface.php) with ReflectionClass
# for reliable docblock association.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_REF="origin/${GITHUB_BASE_REF}"
COMMENT_MARKER="<!-- api-surface-bot -->"

# Get changed/added PHP files in src/Composer/
changed_files=$(git diff "${BASE_REF}...HEAD" --diff-filter=AM --name-only -- 'src/Composer/*.php' 'src/Composer/**/*.php') || true

if [[ -z "$changed_files" ]]; then
    echo "No PHP source files changed."
    exit 0
fi

# Get added line numbers from a diff, returned as comma-separated string
get_added_lines() {
    local file="$1"
    git diff "${BASE_REF}...HEAD" --unified=0 -- "$file" | \
        grep '^@@' | \
        sed -E 's/.*\+([0-9]+)(,([0-9]+))?.*/\1 \3/' | \
        while read -r start count; do
            count="${count:-1}"
            for (( i=0; i<count; i++ )); do
                echo $(( start + i ))
            done
        done | paste -sd, -
}

CLASSES=()
METHODS=()
CONSTANTS=()

for file in $changed_files; do
    [[ -f "$file" ]] || continue

    added_lines=$(get_added_lines "$file") || true
    [[ -z "$added_lines" ]] && continue

    # Run PHP reflection check and parse output
    output=$(php "${SCRIPT_DIR}/check-api-surface.php" "$file" "$added_lines" 2>/dev/null) || true
    [[ -z "$output" ]] && continue

    while IFS='|' read -r type fqcn visibility location; do
        case "$type" in
            CLASS)
                CLASSES+=("- \`${fqcn}\` (${visibility}) in \`${location}\`")
                ;;
            METHOD)
                METHODS+=("- \`${fqcn}\` (${visibility}) in \`${location}\`")
                ;;
            CONST)
                CONSTANTS+=("- \`${fqcn}\` in \`${location}\`")
                ;;
        esac
    done <<< "$output"
done

# Build the comment body
total=$(( ${#CLASSES[@]} + ${#METHODS[@]} + ${#CONSTANTS[@]} ))

manage_comment() {
    # Find existing comment
    local existing_comment_id
    existing_comment_id=$(gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" --paginate -q ".[] | select(.body | contains(\"${COMMENT_MARKER}\")) | .id" | head -1) || true

    if (( total == 0 )); then
        # Delete existing comment if any
        if [[ -n "$existing_comment_id" ]]; then
            gh api -X DELETE "repos/${GITHUB_REPOSITORY}/issues/comments/${existing_comment_id}" || true
            echo "Deleted stale API surface comment."
        fi
        echo "No new API surface detected."
        return
    fi

    local body="${COMMENT_MARKER}"$'\n'
    body+="## New API Surface"$'\n\n'
    body+="The following public API additions were detected in this PR. If this was not intentional, add \`@internal\` to the docblock."$'\n'

    if (( ${#CLASSES[@]} > 0 )); then
        body+=$'\n'"### New Classes"$'\n'
        for item in "${CLASSES[@]}"; do
            body+="${item}"$'\n'
        done
    fi

    if (( ${#METHODS[@]} > 0 )); then
        body+=$'\n'"### New Methods"$'\n'
        for item in "${METHODS[@]}"; do
            body+="${item}"$'\n'
        done
    fi

    if (( ${#CONSTANTS[@]} > 0 )); then
        body+=$'\n'"### New Constants"$'\n'
        for item in "${CONSTANTS[@]}"; do
            body+="${item}"$'\n'
        done
    fi

    if [[ -n "$existing_comment_id" ]]; then
        gh api -X PATCH "repos/${GITHUB_REPOSITORY}/issues/comments/${existing_comment_id}" -f body="$body" > /dev/null
        echo "Updated API surface comment."
    else
        gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" -f body="$body" > /dev/null
        echo "Posted API surface comment."
    fi
}

manage_comment
