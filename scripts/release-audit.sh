#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

echo "== Sensitive string scan =="
rg -n \
  "(api[_-]?key|token|secret|password|Authorization|Bearer |cookie|session|sk-[A-Za-z0-9]|xiaoyao\\.familyds\\.net|familyds|/Users/|127\\.0\\.0\\.1:11434\\?token=|cronQueue\\?token=)" \
  . \
  --glob '!dist/**' \
  --glob '!.git/**' \
  --glob '!node_modules/**' || true

echo
echo "== Local artifact scan =="
find . \
  \( -name '.DS_Store' -o -name '*.zip' -o -name '*.log' -o -name '*.tmp' \) \
  -not -path './.git/*' \
  -not -path './dist/*' \
  | sort || true

echo
echo "== Repository file list =="
find . \
  -type f \
  -not -path './.git/*' \
  -not -path './dist/*' \
  | sort

