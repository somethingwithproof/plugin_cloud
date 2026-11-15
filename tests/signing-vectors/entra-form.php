<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloud, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloud
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

/*
 * Checks the Entra client credentials request body (issue #23) against the
 * example on Microsoft's "OAuth 2.0 client credentials flow" page:
 *   php entra-form.php <saved HTML of that page>
 * The request is not signed, so the check is that building the form from
 * the example's fields gives the documented bytes.
 */

$html = file_get_contents($argv[1] ?? '');
$example = null;
preg_match_all('#<code[^>]*>(.*?)</code>#s', $html, $blocks);
foreach ($blocks[1] as $block) {
	$text = html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5);
	if (str_contains($text, 'grant_type=client_credentials') && str_contains($text, "\n&client_secret=")) {
		$example = $text;
		break;
	}
}
if ($example === null) {
	fwrite(STDERR, "documented example not found\n");
	exit(2);
}

// The example breaks the body before each '&' "for clarity".
$documented = (string) preg_replace('/\s*\n&/', '&', trim(substr($example, strpos($example, "\n\n") + 2)));
parse_str($documented, $fields);

$built = http_build_query(array(
	'client_id'     => $fields['client_id'],
	'scope'         => $fields['scope'],
	'client_secret' => $fields['client_secret'],
	'grant_type'    => 'client_credentials',
), '', '&', PHP_QUERY_RFC3986);

$ok = $built === $documented;
echo ($ok ? 'pass' : 'FAIL') . " client secret form matches the documented example\n";
if (!$ok) {
	echo "documented: $documented\nbuilt:      $built\n";
}
exit($ok ? 0 : 1);
