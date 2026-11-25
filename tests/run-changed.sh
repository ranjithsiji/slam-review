#!/bin/bash
# Runs only the test suites of the modules changed since a commit (default: HEAD, i.e. uncommitted work, new files included).
#   composer test:changed            # what you are working on now
#   composer test:changed -- main~3  # everything since three commits ago
# A change in modules/X or tests/Modules/X runs suite X. A change that every module depends on (modules/Core, app/,
# the test base class, composer or PHPUnit config) runs all suites. Frontend-only changes run nothing.
set -euo pipefail
cd "$(dirname "$0")/.."
BASE=${1:-HEAD}
files=$( { git diff --name-only "$BASE"; git ls-files --others --exclude-standard; } | sort -u)
suites=$(sed -n 's/.*<testsuite name="\([A-Za-z]*\)">.*/\1/p' phpunit.dist.xml)
all=0; run=()
while IFS= read -r f; do
  [ -z "$f" ] && continue
  case "$f" in
    modules/Core/*|app/*|tests/_support/*|composer.json|composer.lock|phpunit.dist.xml) all=1 ;;
    modules/*|tests/Modules/*)
      m=$(echo "$f" | cut -d/ -f2); [ "$f" != "${f#tests/}" ] && m=$(echo "$f" | cut -d/ -f3)
      if echo "$suites" | grep -qx "$m"; then run+=("$m"); fi ;;
  esac
done <<< "$files"
if [ "$all" = 1 ]; then
  echo "Shared code changed (Core, app/, test setup or config): running every suite."
  exec vendor/bin/phpunit --no-coverage
fi
mapfile -t run < <(printf '%s\n' "${run[@]}" | sort -u | sed '/^$/d')
if [ ${#run[@]} -eq 0 ]; then echo "No module with tests changed since $BASE: nothing to run."; exit 0; fi
echo "Changed modules: ${run[*]}"
exec vendor/bin/phpunit --no-coverage --testsuite "$(IFS=,; echo "${run[*]}")"
