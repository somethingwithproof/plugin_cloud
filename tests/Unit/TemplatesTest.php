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


use Somethingwithproof\Cloud\DigitalOceanCatalog;
use Somethingwithproof\Cloud\TemplateRepository;

require_once dirname(__DIR__) . '/bin/generate-templates.php';

/*
 * The shipped template XML against the catalog it is generated from
 * (REQ-OBJ-001, REQ-OBJ-003). Regenerate with:
 *   php tests/bin/generate-templates.php templates
 */

it('ships exactly the templates the catalog generates', function () {
	$root = dirname(__DIR__, 2) . '/templates/';

	foreach (cloud_tpl_files() as $name => $contents) {
		expect(file_get_contents($root . $name))->toBe($contents, $name . ' is out of date; regenerate it');
	}

	$shipped = array_map(
		fn (string $path): string => substr($path, strlen($root)),
		array_merge(glob($root . '*.xml') ?: [], glob($root . 'resource/*.xml') ?: [])
	);
	sort($shipped);
	$generated = array_keys(cloud_tpl_files());
	sort($generated);

	expect($shipped)->toBe($generated);
});

it('places every catalog metric and age in exactly one graph of its resource type', function () {
	$placed = [];

	foreach (cloud_tpl_graphs() as $graph) {
		$placed = array_merge($placed, $graph[4]);
	}

	$expected = array_merge(DigitalOceanCatalog::names(), array_values(DigitalOceanCatalog::AGE));
	sort($placed);
	sort($expected);

	expect($placed)->toBe($expected);
});

it('gives every object the package defines the hash the repository looks up', function () {
	$xml = cloud_tpl_package();

	foreach (TemplateRepository::objects() as $keys) {
		foreach ($keys as $key) {
			expect(str_contains($xml, '0103' . TemplateRepository::hash($key) . '>'))->toBeTrue($key);
		}
	}
});

it('generates well-formed XML with no object hash defined twice', function () {
	foreach (cloud_tpl_files() as $name => $contents) {
		expect(simplexml_load_string($contents))->not->toBeFalse($name);
	}

	preg_match_all('/^\t+<(hash_\d{6}[0-9a-f]{32})>$/m', cloud_tpl_package(), $defined);

	expect(count($defined[1]))->toBe(count(array_unique($defined[1])));
});

it('maps each data query output field to a data source its graphs use', function () {
	foreach ([DigitalOceanCatalog::DROPLET, DigitalOceanCatalog::LOAD_BALANCER] as $type) {
		$resource = simplexml_load_string(cloud_tpl_resource($type));
		$outputs  = [];

		foreach ($resource->fields->children() as $name => $field) {
			if ((string) $field->direction === 'output') {
				$outputs[] = $name;
			}
		}

		$expected = [];

		foreach (DigitalOceanCatalog::GRAPHS as $graph) {
			if ($graph[0] === $type) {
				$expected = array_merge($expected, $graph[4]);
			}
		}

		expect($outputs)->toBe($expected)
			->and((string) $resource->arg_prepend)->toBe('|host_id| ' . $type);
	}
});

it('collects only a graph\'s own metrics, through one data template per graph', function () {
	$package = simplexml_load_string(cloud_tpl_package());

	foreach (DigitalOceanCatalog::GRAPHS as $key => $graph) {
		$template = $package->{cloud_tpl_own('data_template', 'data_template/' . $key)};
		$names    = [];

		foreach ($template->items->children() as $item) {
			$names[] = (string) $item->data_source_name;
		}

		expect($names)->toBe($graph[4], $key);
	}
});
