#!/usr/bin/env bash
#
# tools/build-release.sh
#
# Copyright (c) 2026 the contactForm plugin authors
# Distributed under the GNU GPL v3. For full terms see the file LICENSE.
#
# Builds the tarball that OJS's "Upload A New Plugin" expects: a single
# top-level contactForm/ directory holding only the files that belong on a
# server.
#
# Usage: bash tools/build-release.sh

set -euo pipefail

PLUGIN="contactForm"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/build"

cd "${ROOT}"

if ! command -v git >/dev/null 2>&1; then
	echo "error: git is required; the release contains exactly the tracked files." >&2
	exit 1
fi

if ! git rev-parse --git-dir >/dev/null 2>&1; then
	echo "error: ${ROOT} is not a git repository." >&2
	exit 1
fi

VERSION="$(sed -n 's:.*<release>\(.*\)</release>.*:\1:p' version.xml | head -1)"
if [ -z "${VERSION}" ]; then
	echo "error: could not read <release> from version.xml." >&2
	exit 1
fi

# Development-only paths. Shipping them would not break anything, but a plugin
# directory on a public server should hold only what it needs.
is_excluded() {
	case "$1" in
		ojs/*|tests/*|cypress/*|.github/*|tools/*) return 0 ;;
		phpunit.xml|.php-cs-fixer.dist.php|.gitattributes|.gitignore|CLAUDE.md) return 0 ;;
		*) return 1 ;;
	esac
}

untracked="$(git ls-files --others --exclude-standard)"
if [ -n "${untracked}" ]; then
	echo "note: these files are untracked and will NOT be in the release:" >&2
	echo "${untracked}" | sed 's/^/  /' >&2
fi

rm -rf "${BUILD}"
mkdir -p "${BUILD}/${PLUGIN}"

count=0
while IFS= read -r -d '' file; do
	if is_excluded "${file}"; then
		continue
	fi
	mkdir -p "${BUILD}/${PLUGIN}/$(dirname "${file}")"
	cp "${file}" "${BUILD}/${PLUGIN}/${file}"
	count=$((count + 1))
done < <(git ls-files -z)

# Fail loudly rather than shipping an archive that cannot install.
for required in version.xml ContactFormPlugin.php locale/en/locale.po; do
	if [ ! -f "${BUILD}/${PLUGIN}/${required}" ]; then
		echo "error: ${required} is missing from the build." >&2
		exit 1
	fi
done

ARCHIVE="${ROOT}/${PLUGIN}-${VERSION}.tar.gz"
rm -f "${ARCHIVE}"
tar -czf "${ARCHIVE}" -C "${BUILD}" "${PLUGIN}"
rm -rf "${BUILD}"

echo "Built ${ARCHIVE} (${count} files)"
echo "Upload it under Dashboard > Settings > Website > Plugins > Upload A New Plugin."
