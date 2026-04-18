#!/usr/bin/env bash
# Posts, updates, or deletes the API surface PR comment.
# Called by the api-surface-comment workflow after downloading the artifact
# produced by the detection workflow.
set -euo pipefail

COMMENT_MARKER="<!-- api-surface-bot -->"
PR_NUMBER=$(cat api-surface-result/pr-number.txt)
COMMENT_BODY=$(cat api-surface-result/comment-body.txt)

# Find existing comment
existing_comment_id=$(gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" --paginate -q ".[] | select(.body | contains(\"${COMMENT_MARKER}\")) | .id" | head -1) || true

if [[ -z "$COMMENT_BODY" ]]; then
    # No API surface detected — delete existing comment if any
    if [[ -n "$existing_comment_id" ]]; then
        gh api -X DELETE "repos/${GITHUB_REPOSITORY}/issues/comments/${existing_comment_id}" || true
        echo "Deleted stale API surface comment."
    fi
    echo "No new API surface detected."
    exit 0
fi

if [[ -n "$existing_comment_id" ]]; then
    gh api -X PATCH "repos/${GITHUB_REPOSITORY}/issues/comments/${existing_comment_id}" -f body="$COMMENT_BODY" > /dev/null
    echo "Updated API surface comment."
else
    gh api "repos/${GITHUB_REPOSITORY}/issues/${PR_NUMBER}/comments" -f body="$COMMENT_BODY" > /dev/null
    echo "Posted API surface comment."
fi
