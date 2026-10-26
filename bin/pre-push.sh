#!/usr/bin/env bash
#
# easyQ – pre-push hook: runs the local CI (bin/ci.sh) for every commit that is pushed.
#
# Activate once:        ln -sf ../../bin/pre-push.sh .git/hooks/pre-push
# Push once unchecked:  git push --no-verify
#
# Git passes one line `<local ref> <local sha> <remote ref> <remote sha>` per ref. The local sha is checked;
# one the CI has seen green before costs seconds. Deleted refs (sha of zeros) are not checked.
#
set -uo pipefail
PROJECT=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
ZERO=0000000000000000000000000000000000000000
checked=""
while read -r _ local_sha _ _; do
	[ "$local_sha" = "$ZERO" ] && continue
	case " $checked " in *" $local_sha "*) continue ;; esac
	checked="$checked $local_sha"
	bash "$PROJECT/bin/ci.sh" "$local_sha" || { echo "✗ CI red for $(git rev-parse --short "$local_sha") — push aborted." >&2; exit 1; }
done
