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
 * Writes the shipped Cacti templates from the DigitalOcean metric catalog:
 *
 *   php tests/bin/generate-templates.php <out dir>
 *
 * <out dir>/cloud_digitalocean.xml is the package: one device template per
 * provider scope, a script server data query per resource type, and a data
 * template and graph template per catalog graph (ADR 0010). Each
 * <out dir>/resource/*.xml is the data query definition, which the plugin
 * copies into <path_cacti>/resource/script_server at import.
 *
 * The layout follows Cacti's own export (lib/export.php), field for field,
 * so the importer reads it as it reads any shipped package. Output is
 * reproducible: every hash is md5('plugin_cloud/' . key) over a fixed key.
 * The template unit test runs these functions and compares their output
 * with the shipped files.
 *
 * Kept parseable by PHP 8.0, like the other scripts here.
 */

require_once dirname(__DIR__, 2) . '/include/autoload.php';

use Somethingwithproof\Cloud\DigitalOceanCatalog;

/* include/global_arrays.php in 1.2.31 and 1.2.x. */
const CLOUD_TPL_VERSION = '0103';

const CLOUD_TPL_TYPES = array(
	'graph_template'            => '00',
	'data_template'             => '01',
	'host_template'             => '02',
	'data_input_method'         => '03',
	'data_query'                => '04',
	'gprint_preset'             => '06',
	'data_input_field'          => '07',
	'data_template_item'        => '08',
	'graph_template_input'      => '09',
	'graph_template_item'       => '10',
	'data_query_graph'          => '11',
	'data_query_sv_graph'       => '12',
	'data_query_sv_data_source' => '13',
	'data_source_profile'       => '20',
);

/* Stock objects from cacti.sql: "Get Script Server Data (Indexed)" with its
 * index_type, index_value and output_type fields, "5 Minute Collection",
 * and the "Normal" GPRINT preset. */
const CLOUD_TPL_SCRIPT_SERVER_INDEXED = '332111d8b54ac8ce939af87a7eac0c06';
const CLOUD_TPL_INDEX_FIELDS          = array(
	'172b4b0eacee4948c6479f587b62e512',
	'30fb5d5bcf3d66bb5abe88596f357c26',
	'31112c85ae4ff821d3b288336288818c',
);
const CLOUD_TPL_PROFILE_5MIN   = 'd62c52891f4f9688729a5bc9fad91b18';
const CLOUD_TPL_GPRINT_NORMAL  = 'e9c43831e54eca8069317a2ce8c6f751';

/* data_source_types, graph_item_types and consolidation_functions. */
const CLOUD_TPL_DS_GAUGE     = '1';
const CLOUD_TPL_ITEM_LINE1   = '4';
const CLOUD_TPL_ITEM_GPRINT  = '9';
const CLOUD_TPL_CF_AVERAGE   = '1';
const CLOUD_TPL_CF_MAX       = '3';
const CLOUD_TPL_CF_LAST      = '4';

/* Stock entries of the colors table, in the order a graph's lines use them. */
const CLOUD_TPL_COLORS = array('4668E4', '00CF00', 'EA8F00', 'F51D30', '8D00BA');

/* Per resource type: data query name, resource file, and the script
 * server's type argument. */
const CLOUD_TPL_QUERIES = array(
	DigitalOceanCatalog::DROPLET       => array('Cloud: DigitalOcean Droplets', 'cloud_do_droplet.xml', 'Droplet'),
	DigitalOceanCatalog::LOAD_BALANCER => array(
		'Cloud: DigitalOcean Load Balancers',
		'cloud_do_load_balancer.xml',
		'Load Balancer',
	),
);

/**
 * The XML element name or reference for one object, as get_hash_version()
 * builds it.
 */
function cloud_tpl_ref(string $type, string $md5): string {
	return 'hash_' . CLOUD_TPL_TYPES[$type] . CLOUD_TPL_VERSION . $md5;
}

function cloud_tpl_own(string $type, string $key): string {
	return cloud_tpl_ref($type, md5('plugin_cloud/' . $key));
}

/**
 * Renders nested arrays as Cacti's exporter does: one element per line,
 * tab indents, values escaped like xml_character_encode().
 *
 * @param array<string, mixed> $nodes
 */
function cloud_tpl_render(array $nodes, int $depth): string {
	$text   = '';
	$indent = str_repeat("\t", $depth);

	foreach ($nodes as $name => $value) {
		if (is_array($value)) {
			$text .= $indent . '<' . $name . ">\n" . cloud_tpl_render($value, $depth + 1) . $indent . '</' . $name . ">\n";
		} else {
			$text .= $indent . '<' . $name . '>' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8')
				. '</' . $name . ">\n";
		}
	}

	return $text;
}

/**
 * The catalog graphs, after checking that every metric and age name is in
 * exactly one graph of its own resource type.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: int|null, 4: list<string>}>
 */
function cloud_tpl_graphs(): array {
	$graphs  = DigitalOceanCatalog::GRAPHS;
	$placed  = array();

	foreach ($graphs as $key => $graph) {
		foreach ($graph[4] as $ds) {
			$metric = DigitalOceanCatalog::metric($ds);
			$type   = $metric === null ? array_search($ds, DigitalOceanCatalog::AGE, true) : $metric['type'];

			if ($type !== $graph[0]) {
				throw new RuntimeException($ds . ' in graph ' . $key . ' is not a ' . $graph[0] . ' metric');
			}

			if (isset($placed[$ds])) {
				throw new RuntimeException($ds . ' is in more than one graph');
			}

			if (count($graph[4]) > count(CLOUD_TPL_COLORS)) {
				throw new RuntimeException('graph ' . $key . ' has more lines than colors');
			}

			$placed[$ds] = true;
		}
	}

	$names = array_merge(DigitalOceanCatalog::names(), array_values(DigitalOceanCatalog::AGE));
	$found = array_keys($placed);

	sort($names);
	sort($found);

	if ($names !== $found) {
		throw new RuntimeException('every catalog metric and age must appear in a graph');
	}

	return $graphs;
}

/**
 * @param array{0: string, 1: string, 2: string, 3: int|null, 4: list<string>} $graph
 * @return array<string, mixed>
 */
function cloud_tpl_data_template(string $key, array $graph): array {
	$items = array();

	foreach ($graph[4] as $ds) {
		$items[cloud_tpl_own('data_template_item', 'data_template_item/' . $ds)] = array(
			't_data_source_name'    => '',
			'data_source_name'      => $ds,
			't_rrd_minimum'         => '',
			'rrd_minimum'           => '0',
			't_rrd_maximum'         => '',
			'rrd_maximum'           => $graph[3] === null ? 'U' : (string) $graph[3],
			't_data_source_type_id' => '',
			'data_source_type_id'   => CLOUD_TPL_DS_GAUGE,
			't_rrd_heartbeat'       => '',
			'rrd_heartbeat'         => '600',
			't_data_input_field_id' => '',
			'data_input_field_id'   => '0',
		);
	}

	$data = array();

	foreach (CLOUD_TPL_INDEX_FIELDS as $i => $field) {
		$data[sprintf('item_%03d', $i)] = array(
			'data_input_field_id' => cloud_tpl_ref('data_input_field', $field),
			't_value'             => 'on',
			'value'               => '',
		);
	}

	return array(
		'name'  => CLOUD_TPL_QUERIES[$graph[0]][0] . ' - ' . $graph[1],
		'ds'    => array(
			't_name'                   => '',
			'name'                     => '|host_description| - ' . $graph[1],
			'data_source_path'         => '',
			'data_input_id'            => cloud_tpl_ref('data_input_method', CLOUD_TPL_SCRIPT_SERVER_INDEXED),
			't_data_source_profile_id' => '',
			'data_source_profile_id'   => cloud_tpl_ref('data_source_profile', CLOUD_TPL_PROFILE_5MIN),
			't_rrd_step'               => '',
			'rrd_step'                 => '300',
			't_active'                 => '',
			'active'                   => 'on',
		),
		'items' => $items,
		'data'  => $data,
	);
}

/**
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function cloud_tpl_graph_fields(string $title, string $label, array $overrides): array {
	$values = array_merge(array(
		'title'                => $title,
		'vertical_label'       => $label,
		'image_format_id'      => '3',
		'height'               => '200',
		'width'                => '700',
		'base_value'           => '1000',
		'slope_mode'           => 'on',
		'auto_scale'           => 'on',
		'auto_scale_opts'      => '2',
		'auto_scale_log'       => '',
		'scale_log_units'      => '',
		'auto_scale_rigid'     => '',
		'upper_limit'          => '100',
		'lower_limit'          => '0',
		'unit_value'           => '',
		'unit_exponent_value'  => '',
		'unit_length'          => '',
		'no_gridfit'           => '',
		'alt_y_grid'           => '',
		'right_axis'           => '',
		'right_axis_label'     => '',
		'right_axis_format'    => '0',
		'right_axis_formatter' => '0',
		'left_axis_formatter'  => '0',
		'auto_padding'         => 'on',
		'dynamic_labels'       => '',
		'force_rules_legend'   => '',
		'tab_width'            => '40',
		'legend_position'      => '0',
		'legend_direction'     => '0',
	), $overrides);

	$fields = array();

	foreach ($values as $name => $value) {
		$fields['t_' . $name] = '';
		$fields[$name]        = $value;
	}

	return $fields;
}

/**
 * @param array{0: string, 1: string, 2: string, 3: string, 4: bool} $row
 *        graph item type, color, consolidation function, text, line break
 * @return array<string, string>
 */
function cloud_tpl_graph_item(string $task, array $row, int $sequence): array {
	return array(
		'graph_type_id'             => $row[0],
		'task_item_id'              => $task,
		'color_id'                  => $row[1],
		'alpha'                     => 'FF',
		'consolidation_function_id' => $row[2],
		'cdef_id'                   => '0',
		'vdef_id'                   => '0',
		'shift'                     => '',
		'value'                     => '',
		'gprint_id'                 => cloud_tpl_ref('gprint_preset', CLOUD_TPL_GPRINT_NORMAL),
		'textalign'                 => '',
		'text_format'               => $row[3],
		'hard_return'               => $row[4] ? 'on' : '',
		'line_width'                => $row[0] === CLOUD_TPL_ITEM_LINE1 ? '1.00' : '0.00',
		'dashes'                    => '',
		'dash_offset'               => '0',
		'sequence'                  => (string) $sequence,
	);
}

function cloud_tpl_title(array $graph): string {
	return '|host_description| - |query_resourceName| - ' . $graph[1];
}

/**
 * @param array{0: string, 1: string, 2: string, 3: int|null, 4: list<string>} $graph
 * @return array<string, mixed>
 */
function cloud_tpl_graph_template(string $key, array $graph): array {
	$items    = array();
	$inputs   = array();
	$sequence = 0;

	foreach ($graph[4] as $i => $ds) {
		$task = cloud_tpl_own('data_template_item', 'data_template_item/' . $ds);
		$rows = array(
			array(CLOUD_TPL_ITEM_LINE1, CLOUD_TPL_COLORS[$i], CLOUD_TPL_CF_AVERAGE, $ds, false),
			array(CLOUD_TPL_ITEM_GPRINT, '0', CLOUD_TPL_CF_LAST, 'Cur:', false),
			array(CLOUD_TPL_ITEM_GPRINT, '0', CLOUD_TPL_CF_AVERAGE, 'Avg:', false),
			array(CLOUD_TPL_ITEM_GPRINT, '0', CLOUD_TPL_CF_MAX, 'Max:', true),
		);
		$used = array();

		foreach ($rows as $row) {
			$sequence++;

			$item = 'graph_template_item/' . $key . '/' . $sequence;

			$items[cloud_tpl_own('graph_template_item', $item)] = cloud_tpl_graph_item($task, $row, $sequence);

			/* The exporter writes input items with the graph template type
			 * code, and the importer reads only the md5, so an export of the
			 * imported template matches this file. */
			$used[] = cloud_tpl_own('graph_template', $item);
		}

		$inputs[cloud_tpl_own('graph_template_input', 'graph_template_input/' . $key . '/' . $ds)] = array(
			'name'        => 'Data Source [' . $ds . ']',
			'description' => '',
			'column_name' => 'task_item_id',
			'items'       => implode('|', $used),
		);
	}

	$overrides = $graph[3] === null
		? array('upper_limit' => '100')
		: array('auto_scale' => '', 'auto_scale_rigid' => 'on', 'upper_limit' => (string) $graph[3]);

	return array(
		'name'        => CLOUD_TPL_QUERIES[$graph[0]][0] . ' - ' . $graph[1],
		'multiple'    => '',
		'test_source' => '',
		'graph'       => cloud_tpl_graph_fields(cloud_tpl_title($graph), $graph[2], $overrides),
		'items'       => $items,
		'inputs'      => $inputs,
	);
}

/**
 * @param array<string, array{0: string, 1: string, 2: string, 3: int|null, 4: list<string>}> $graphs
 * @return array<string, mixed>
 */
function cloud_tpl_data_query(string $type, array $graphs): array {
	$out = array();

	foreach ($graphs as $key => $graph) {
		if ($graph[0] !== $type) {
			continue;
		}

		$template = cloud_tpl_own('data_template', 'data_template/' . $key);
		$rrd      = array();

		foreach ($graph[4] as $i => $ds) {
			$rrd[sprintf('item_%03d', $i)] = array(
				'snmp_field_name'      => $ds,
				'data_template_id'     => $template,
				'data_template_rrd_id' => cloud_tpl_own('data_template_item', 'data_template_item/' . $ds),
			);
		}

		$out[cloud_tpl_own('data_query_graph', 'data_query_graph/' . $key)] = array(
			'graph_template_id' => cloud_tpl_own('graph_template', 'graph_template/' . $key),
			'name'              => $graph[1],
			'rrd'               => $rrd,
			'sv_graph'          => array(
				cloud_tpl_own('data_query_sv_graph', 'data_query_sv_graph/' . $key . '/title') => array(
					'field_name' => 'title',
					'sequence'   => '1',
					'text'       => cloud_tpl_title($graph),
				),
			),
			'sv_data_source'    => array(
				cloud_tpl_own('data_query_sv_data_source', 'data_query_sv_data_source/' . $key . '/name') => array(
					'field_name'       => 'name',
					'data_template_id' => $template,
					'sequence'         => '1',
					'text'             => '|host_description| - |query_resourceName| - ' . $graph[1],
				),
			),
		);
	}

	return array(
		'name'          => CLOUD_TPL_QUERIES[$type][0],
		'description'   => CLOUD_TPL_QUERIES[$type][0] . ' of the scope, read from the plugin cache.',
		'xml_path'      => '<path_cacti>/resource/script_server/' . CLOUD_TPL_QUERIES[$type][1],
		'data_input_id' => cloud_tpl_ref('data_input_method', CLOUD_TPL_SCRIPT_SERVER_INDEXED),
		'graphs'        => $out,
	);
}

function cloud_tpl_package(): string {
	$graphs  = cloud_tpl_graphs();
	$queries = array();

	foreach (array_keys(CLOUD_TPL_QUERIES) as $type) {
		$queries[] = cloud_tpl_own('data_query', 'data_query/' . $type);
	}

	$objects = array(
		cloud_tpl_own('host_template', 'host_template/digitalocean') => array(
			'name'            => 'Cloud: DigitalOcean Scope',
			'class'           => 'application',
			'graph_templates' => '',
			'data_queries'    => implode('|', $queries),
		),
	);

	foreach ($graphs as $key => $graph) {
		$objects[cloud_tpl_own('graph_template', 'graph_template/' . $key)] = cloud_tpl_graph_template($key, $graph);
	}

	foreach ($graphs as $key => $graph) {
		$objects[cloud_tpl_own('data_template', 'data_template/' . $key)] = cloud_tpl_data_template($key, $graph);
	}

	/* Packages carry the GPRINT presets they use; an install may have
	 * deleted "Normal", and the importer would then store 0. */
	$objects[cloud_tpl_ref('gprint_preset', CLOUD_TPL_GPRINT_NORMAL)] = array(
		'name'        => 'Normal',
		'gprint_text' => '%8.2lf %s',
	);

	foreach (array_keys(CLOUD_TPL_QUERIES) as $type) {
		$objects[cloud_tpl_own('data_query', 'data_query/' . $type)] = cloud_tpl_data_query($type, $graphs);
	}

	return cloud_tpl_render(array('cacti' => $objects), 0);
}

/**
 * The data query definition for one resource type, laid out like
 * resource/script_server/host_cpu.xml. Field names equal their query_name,
 * which is what the script server function receives.
 */
function cloud_tpl_resource(string $type): string {
	$fields = array(
		'resourceId'   => array('name' => 'Resource ID', 'direction' => 'input', 'query_name' => 'resourceId'),
		'resourceName' => array('name' => 'Resource Name', 'direction' => 'input', 'query_name' => 'resourceName'),
	);

	foreach (DigitalOceanCatalog::GRAPHS as $graph) {
		if ($graph[0] !== $type) {
			continue;
		}

		foreach ($graph[4] as $ds) {
			$fields[$ds] = array('name' => $graph[1] . ': ' . $ds, 'direction' => 'output', 'query_name' => $ds);
		}
	}

	return cloud_tpl_render(array('interface' => array(
		'name'               => 'Get ' . CLOUD_TPL_QUERIES[$type][0],
		'script_path'        => '|path_cacti|/plugins/cloud/ss_cloud.php',
		'script_function'    => 'ss_cloud',
		'script_server'      => 'php',
		'arg_prepend'        => '|host_id| ' . $type,
		'arg_index'          => 'index',
		'arg_query'          => 'query',
		'arg_get'            => 'get',
		'arg_num_indexes'    => 'num_indexes',
		'output_delimiter'   => '!',
		'index_order'        => 'resourceId',
		'index_order_type'   => 'alphabetic',
		'index_title_format' => '|chosen_order_field|',
		'fields'             => $fields,
	)), 0);
}

/**
 * @return array<string, string> path relative to the templates directory => contents
 */
function cloud_tpl_files(): array {
	$files = array('cloud_digitalocean.xml' => cloud_tpl_package());

	foreach (CLOUD_TPL_QUERIES as $type => $query) {
		$files['resource/' . $query[1]] = cloud_tpl_resource($type);
	}

	return $files;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
	if (!isset($argv[1]) || $argv[1] === '') {
		fwrite(STDERR, "usage: php tests/bin/generate-templates.php <out dir>\n");
		exit(2);
	}

	foreach (cloud_tpl_files() as $name => $contents) {
		$path = rtrim($argv[1], '/') . '/' . $name;

		if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
			fwrite(STDERR, 'cannot create ' . dirname($path) . "\n");
			exit(1);
		}

		if (file_put_contents($path, $contents) !== strlen($contents)) {
			fwrite(STDERR, 'cannot write ' . $path . "\n");
			exit(1);
		}
	}
}
