# Findings

Every finding of a review is entered here, whatever its weight, and keeps its number
for good. The rules of the reviews and the matrix the findings refer to are in
`docs/review.md`.

- **Open** findings carry their class (**blocker**, **should**, **low**; the criteria
  are in the release gate), the cell of the matrix, and why no guard saw them.
- A **fixed** finding leaves this file. Its commit names the number (`F-7`), the
  guard that holds it from now on, and the counter-check: the fix taken back, the
  guard seen red, the file restored. The history is `git log --grep='F-7'`.
- **Deferred** findings stay with the reason and with the condition that brings them
  back. A deferred finding is no free pass: the next review checks whether the
  reason still holds.
- **Rejected** findings and **refuted suspicions** stay as well, so that the next
  review does not spend its time on them again.

The next free number is F-3.

## Open

| No. | Class | Cell | Finding | Why no guard saw it |
| --- | --- | --- | --- | --- |
| F-1 | low | A × Form | `docs/rest-api.md` lists five tools for `POST /tools/{tool}`; the plugin has six, `refill_events` is missing. | `Docs_Test` compares the routes with the document, not the values a route parameter accepts. A guard would read the tools from the tools class and look for each in the row. |
| F-2 | should | Cross-cutting | The coverage job of the CI only reports. A line no test runs does not turn anything red, and a line that runs without an assertion looks covered. Mutations are done by hand for each change and are not repeated when the code around them changes. | There is no guard; this is the gap. To decide: a list of the uncovered lines with a reason for each, red at every new one, and a script that removes the candidates and runs the tests. |

## Deferred

None.

## Rejected

None.

## Refuted suspicions

None.
