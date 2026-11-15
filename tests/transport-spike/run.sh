#!/bin/sh
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
#
# Runs the transport spike in containers and records distribution defaults.
#   sh tests/transport-spike/run.sh <work-dir>
# Writes <work-dir>/results-<php>-<transport>.jsonl and <work-dir>/distros.txt.
set -eu

here=$(cd "$(dirname "$0")" && pwd)
work=$(cd "$1" && pwd)
mkdir -p "$work/certs"

for php in 8.2 8.4; do
	img="php:$php-cli"
	docker run --rm -v "$here:/spike:ro" -v "$work:/work" "$img" php /spike/make-certs.php /work/certs >/dev/null
	for t in stream socket curl; do
		docker run --rm -v "$here:/spike:ro" -v "$work:/work" "$img" \
			php /spike/spike.php /work/certs "$t" >"$work/results-$php-$t.jsonl"
	done
done

# Package defaults: does a stock install enable allow_url_fopen, and is
# ext-curl present without its separate package?
probe='echo PHP_VERSION, " allow_url_fopen=", ini_get("allow_url_fopen"), " curl=", extension_loaded("curl") ? "yes" : "no", " openssl=", extension_loaded("openssl") ? "yes" : "no", "\n";'
{
	for img in debian:13 ubuntu:24.04 ubuntu:22.04; do
		printf '%s: ' "$img"
		docker run --rm "$img" sh -c "apt-get update -qq >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq php-cli >/dev/null 2>&1 && php -r '$probe'"
	done
	for img in almalinux:9 almalinux:10 fedora:43; do
		printf '%s: ' "$img"
		docker run --rm "$img" sh -c "dnf -q -y install php-cli >/dev/null 2>&1 && php -r '$probe'"
	done
} >"$work/distros.txt"
cat "$work/distros.txt"
