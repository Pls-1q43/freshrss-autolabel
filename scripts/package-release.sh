#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

version="$(sed -n 's/.*"version":[[:space:]]*"\([^"]*\)".*/\1/p' metadata.json | head -n1)"
if [[ -z "$version" ]]; then
  echo "Failed to read version from metadata.json" >&2
  exit 1
fi

package_root="xExtension-AutoLabel"
dist_dir="$repo_root/dist"
staging_dir="$dist_dir/$package_root"
archive_path="$dist_dir/${package_root}-${version}.zip"

rm -rf "$staging_dir"
mkdir -p "$staging_dir" "$dist_dir"

cp metadata.json "$staging_dir/"
cp extension.php "$staging_dir/"
cp configure.phtml "$staging_dir/"
cp README.md "$staging_dir/"
cp LICENSE "$staging_dir/"
cp -R docs "$staging_dir/"
cp -R Controllers "$staging_dir/"
cp -R lib "$staging_dir/"
cp -R static "$staging_dir/"
cp -R views "$staging_dir/"
cp -R i18n "$staging_dir/"

rm -f "$archive_path"
(
  cd "$dist_dir"
  zip -rq "$(basename "$archive_path")" "$package_root"
)

echo "Created: $archive_path"
