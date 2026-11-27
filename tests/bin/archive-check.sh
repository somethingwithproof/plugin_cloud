#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_cloud, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_cloud
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.

set -euo pipefail

repo=$(cd "$(dirname "$0")/../.." && pwd)
archive=$(mktemp)
trap 'rm -f "$archive"' EXIT

git -C "$repo" archive --worktree-attributes --format=tar --output="$archive" HEAD
listing=$(tar -tf "$archive")

while IFS= read -r path; do
	case "$path" in
		cloud_accounts.php|cloud_health.php|cloud_operator.php|index.php|poller_cloud.php|setup.php|ss_cloud.php|INFO|README.md|LICENSE|SECURITY.md) ;;
		LICENSES/|LICENSES/*|cli/|cli/*|docs/|docs/*|include/|include/*|lib/|lib/*|locales/|locales/*|templates/|templates/*) ;;
		*)
			printf 'Unexpected file in release archive: %s\n' "$path" >&2
			exit 1
			;;
	esac
done <<< "$listing"

for required in INFO README.md LICENSE setup.php cloud_accounts.php cloud_health.php cloud_operator.php poller_cloud.php ss_cloud.php index.php cli/cloud.php; do
	if ! grep -Fxq "$required" <<< "$listing"; then
		printf 'Required release file is missing: %s\n' "$required" >&2
		exit 1
	fi
done

version=$(tar -xOf "$archive" INFO | sed -n 's/^version[[:space:]]*=[[:space:]]*//p' | head -n 1)

is_semver() {
	local version=$1
	local numeric='(0|[1-9][0-9]*)'
	local prerelease_id='(0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)'
	local prerelease="(-${prerelease_id}(\\.${prerelease_id})*)?"
	local build='(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?'

	[[ $version =~ ^${numeric}\.${numeric}\.${numeric}${prerelease}${build}$ ]]
}

for valid in 0.0.0 1.2.3-alpha.1 1.2.3+build.7 1.2.3-rc.1+build.7; do
	if ! is_semver "$valid"; then
		printf 'Valid semantic version rejected: %s\n' "$valid" >&2
		exit 1
	fi
done

for invalid in 01.2.3 1.02.3 1.2.03 1.2.3-alpha..1 1.2.3+build..7; do
	if is_semver "$invalid"; then
		printf 'Invalid semantic version accepted: %s\n' "$invalid" >&2
		exit 1
	fi
done

if ! is_semver "$version"; then
	printf 'INFO version is not semantic: %s\n' "$version" >&2
	exit 1
fi

printf 'Release archive policy passed for plugin_cloud %s (%s files).\n' "$version" "$(wc -l <<< "$listing" | tr -d ' ')"
