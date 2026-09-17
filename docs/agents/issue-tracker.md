# Issue tracker: GitHub

Issues and specs live in GitHub Issues for `42lizard/typo3totypo3`.
Use the `gh` CLI from this clone, or specify
`--repo 42lizard/typo3totypo3` when running elsewhere.

## Conventions

- Create: `gh issue create --title "..." --body-file <file>`
- Read: `gh issue view <number> --json title,body,labels,comments`
- List: `gh issue list --state open --json number,title,body,labels,comments`
- Comment: `gh issue comment <number> --body-file <file>`
- Label: `gh issue edit <number> --add-label "..." --remove-label "..."`
- Close: `gh issue close <number> --comment "..."`

For multiline bodies, write the exact Markdown to a temporary file
and pass it with `--body-file`.

When a skill says "publish to the issue tracker", create a GitHub issue.
When it says "fetch the relevant ticket", read the issue and its comments.

## Pull requests as a triage surface

**PRs as a request surface: no.**

## Wayfinding operations

- Map: one issue labelled `wayfinder:map`, containing Notes,
  Decisions-so-far, and Fog.
- Child tickets: link as GitHub sub-issues. If unavailable, use a task
  list in the map and put `Part of #<map>` in each child.
- Ticket labels: `wayfinder:research`, `wayfinder:prototype`,
  `wayfinder:grilling`, or `wayfinder:task`.
- Blocking: use GitHub native issue dependencies. If unavailable,
  record `Blocked by: #<number>` in the child. A ticket is unblocked
  when every blocker is closed.
- Frontier: select the first open, unassigned, unblocked child in map order.
- Claim: assign the ticket to the driving developer.
- Resolve: comment with the answer, close the ticket, and append a
  summary and link to the map's Decisions-so-far.
