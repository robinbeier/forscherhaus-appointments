---
name: linear
description: |
    Use Symphony's `linear_graphql` tool for Linear issue reads, state changes,
    PR attachments, and the single persistent `## Codex Workpad` comment.
---

# Linear GraphQL

Use this skill during Symphony sessions when the issue state, PR linkage, or
workpad comment must be updated.

Default order:

1. Read the issue and current comments.
2. Find or create the single persistent `## Codex Workpad` comment.
3. Refresh that workpad before new implementation work.
4. Keep later updates in the same comment instead of posting separate summary
   comments.

## Primary Tool

Use the `linear_graphql` client tool exposed by Symphony's app-server session.
It reuses Symphony's configured Linear auth.

Tool input:

```json
{
    "query": "query or mutation document",
    "variables": {
        "optional": "graphql variables object"
    }
}
```

Rules:

-   Prefer a single GraphQL operation per tool call, but multi-operation
    documents are allowed and may require an explicit operation name on the
    Linear side.
-   Treat a top-level `errors` array as failure.
-   Ask only for the fields you need.

## Required Repo States

This repo expects the Linear workflow states:

-   `Todo`
-   `In Progress`
-   `In Review`
-   `Rework`
-   `Ready to Merge`
-   `Done`
-   `Canceled`

## Common Queries And Mutations

### Query an issue by key or id

```graphql
query IssueByKey($key: String!) {
    issue(id: $key) {
        id
        identifier
        title
        description
        branchName
        url
        updatedAt
        state {
            id
            name
            type
        }
        team {
            id
            key
            name
            states {
                nodes {
                    id
                    name
                    type
                }
            }
        }
    }
}
```

### Read issue comments to find the workpad

```graphql
query IssueComments($id: String!) {
    issue(id: $id) {
        id
        comments(first: 50) {
            nodes {
                id
                body
                url
                updatedAt
            }
        }
    }
}
```

Look for exactly one comment whose body starts with `## Codex Workpad`. If none
exists, create it. If more than one exists, keep the newest authoritative one
and update that comment only.

### Create a comment

```graphql
mutation CreateComment($issueId: String!, $body: String!) {
    commentCreate(input: {issueId: $issueId, body: $body}) {
        success
        comment {
            id
            url
        }
    }
}
```

### Update a comment

```graphql
mutation UpdateComment($id: String!, $body: String!) {
    commentUpdate(id: $id, input: {body: $body}) {
        success
        comment {
            id
            body
            url
        }
    }
}
```

### Move an issue to a different state

```graphql
mutation MoveIssueToState($id: String!, $stateId: String!) {
    issueUpdate(id: $id, input: {stateId: $stateId}) {
        success
        issue {
            id
            identifier
            state {
                id
                name
            }
        }
    }
}
```

### Attach a GitHub PR to an issue

```graphql
mutation AttachGitHubPR($issueId: String!, $url: String!, $title: String) {
    attachmentLinkGitHubPR(issueId: $issueId, url: $url, title: $title, linkKind: links) {
        success
        attachment {
            id
            title
            url
        }
    }
}
```

## Workpad Contract

Maintain one persistent comment per issue whose body starts with:

```md
## Codex Workpad
```

Keep it concise and rewrite it in place instead of creating new plan comments.
Recommended sections:

```md
## Codex Workpad

### Status

-   Summary: <where the issue stands now>
-   Next: <next concrete action>

### Plan

-   <next concrete steps only>

### Validation

-   Done: <commands or checks already run>
-   Pending: <remaining checks if any>

### Blockers

-   None.
```

Update the workpad at least:

-   when a run starts and you have learned new facts
-   before opening or updating a PR
-   when moving the issue to `In Review`, `Rework`, `Ready to Merge`, or `Done`

Workpad rules:

-   Keep any environment stamp to one short line.
-   Do not duplicate issue title, labels, blocker metadata, or PR URL in the
    workpad.
-   Summarize evidence instead of pasting long command output.
-   For small or already-understood tasks, update the workpad and move on instead
    of expanding it into a long narrative.

## Usage Rules

-   Fetch the exact destination `stateId` from `issue.team.states` before
    changing state.
-   Prefer `attachmentLinkGitHubPR` over a plain URL when attaching a PR.
-   Do not create multiple workpad comments.
-   Do not put the PR URL into the workpad; keep PR linkage on the issue itself.
-   Do not add raw-token shell helpers for Linear GraphQL; use `linear_graphql`.
