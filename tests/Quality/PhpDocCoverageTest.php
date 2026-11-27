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
 * Enforces 100% PHPDoc coverage across every production PHP file using PHP's
 * token_get_all(). Every class, interface, trait, enum, constant, property,
 * method, and named function outside tests/ must carry a DocBlock (/** ... *\/).
 */

/**
 * Collect all production PHP files outside tests/ and .git/.
 *
 * @return list<string> relative file paths sorted alphabetically
 */
function cloud_phpdoc_sources(): array {
	$root     = dirname(__DIR__, 2);
	$files    = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen($root) + 1);

		if (
			$file->getExtension() === 'php'
			&& !str_starts_with($relative, 'tests/')
			&& !str_starts_with($relative, '.git/')
			&& !str_starts_with($relative, 'vendor/')
		) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

/**
 * Check whether a declaration token index is preceded by a T_DOC_COMMENT.
 *
 * Allows whitespace, visibility/type/declaration modifiers, and PHP attributes
 * between the DocBlock and the declaration token. Plain comments do not count.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens    token stream from token_get_all()
 * @param int                                           $declIndex token index of the symbol keyword or modifier start
 * @param list<int>                                     $allowed   token IDs permitted between DocBlock and symbol
 * @return bool true when a DocBlock immediately documents the symbol
 */
function cloud_has_docblock(array $tokens, int $declIndex, array $allowed): bool {
	$attrDepth = 0;

	for ($k = $declIndex - 1; $k >= 0; $k--) {
		$tok = $tokens[$k];

		if (!is_array($tok)) {
			if ($tok === ']') {
				$attrDepth++;

				continue;
			}

			if ($attrDepth > 0) {
				if ($tok === '[') {
					$attrDepth--;
				}

				continue;
			}

			return false;
		}

		if ($tok[0] === T_ATTRIBUTE) {
			if ($attrDepth > 0) {
				$attrDepth--;

				continue;
			}

			return false;
		}

		if ($attrDepth > 0) {
			continue;
		}

		if ($tok[0] === T_DOC_COMMENT) {
			return true;
		}

		if (in_array($tok[0], $allowed, true)) {
			continue;
		}

		return false;
	}

	return false;
}

/**
 * Scan one PHP file and report all declared symbols and any missing DocBlocks.
 *
 * @param string $relativePath path relative to repository root
 * @return array{total: int, missing: list<string>}
 */
function cloud_scan_file_phpdoc(string $relativePath): array {
	$root       = dirname(__DIR__, 2);
	$source     = (string) file_get_contents($root . '/' . $relativePath);
	$tokens     = token_get_all($source);
	$count      = count($tokens);
	$braceDepth = 0;
	$parenDepth = 0;
	$classStack = [];
	$total      = 0;
	$missing    = [];

	$typeAllowed = [T_WHITESPACE, T_FINAL, T_ABSTRACT, T_READONLY];
	$funcAllowed = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
	$constAllowed = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL];
	$propAllowed = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR];

	for ($i = 0; $i < $count; $i++) {
		$tok = $tokens[$i];

		if (!is_array($tok)) {
			if ($tok === '{') {
				$braceDepth++;
			} elseif ($tok === '}') {
				$braceDepth--;

				if ($classStack !== [] && end($classStack) === $braceDepth) {
					array_pop($classStack);
				}
			} elseif ($tok === '(') {
				$parenDepth++;
			} elseif ($tok === ')') {
				$parenDepth--;
			}

			continue;
		}

		$id   = $tok[0];
		$line = $tok[2];

		if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
			$braceDepth++;

			continue;
		}

		// Classes, interfaces, traits, and enums (skip ::class and anonymous classes).
		if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
			$prev = $i - 1;

			while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
				$prev--;
			}

			if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
				continue;
			}

			$next = $i + 1;

			while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
				$next++;
			}

			if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
				$classStack[] = $braceDepth;
				$total++;

				if (!cloud_has_docblock($tokens, $i, $typeAllowed)) {
					$missing[] = sprintf('%s:%d %s %s', $relativePath, $line, strtolower($tok[1]), $tokens[$next][1]);
				}
			}

			continue;
		}

		// Named functions and methods (skip anonymous closures).
		if ($id === T_FUNCTION) {
			$next = $i + 1;

			while (
				$next < $count
				&& ((is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) || $tokens[$next] === '&')
			) {
				$next++;
			}

			if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
				$total++;

				if (!cloud_has_docblock($tokens, $i, $funcAllowed)) {
					$kind      = ($classStack !== [] && end($classStack) + 1 === $braceDepth) ? 'method' : 'function';
					$missing[] = sprintf('%s:%d %s %s()', $relativePath, $line, $kind, $tokens[$next][1]);
				}
			}

			continue;
		}

		// Top-level and class/interface/trait/enum constants.
		if ($id === T_CONST) {
			$inTypeBody = $classStack !== [] && end($classStack) + 1 === $braceDepth;
			$topLevel   = $braceDepth === 0;

			if ($inTypeBody || $topLevel) {
				$next = $i + 1;

				while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
					$next++;
				}

				$name = ($next < $count && is_array($tokens[$next])) ? $tokens[$next][1] : '?';
				$total++;

				if (!cloud_has_docblock($tokens, $i, $constAllowed)) {
					$missing[] = sprintf('%s:%d const %s', $relativePath, $line, $name);
				}
			}

			continue;
		}

		// Explicit class/trait properties at class body depth (outside constructor parameter lists).
		if ($id === T_VARIABLE && $classStack !== [] && end($classStack) + 1 === $braceDepth && $parenDepth === 0) {
			$prev       = $i - 1;
			$isProperty = false;
			$modStart   = $i;

			while ($prev >= 0) {
				$pt = $tokens[$prev];

				if (is_array($pt)) {
					$pid = $pt[0];

					if (in_array($pid, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
						$isProperty = true;
						$modStart   = $prev;

						break;
					}

					if (
						in_array(
							$pid,
							[
								T_WHITESPACE,
								T_STATIC,
								T_READONLY,
								T_STRING,
								T_NAME_QUALIFIED,
								T_NAME_FULLY_QUALIFIED,
								T_ARRAY,
								T_CALLABLE,
							],
							true
						)
					) {
						$prev--;

						continue;
					}

					break;
				}

				if (in_array($pt, ['?', '|', '&'], true)) {
					$prev--;

					continue;
				}

				break;
			}

			if ($isProperty) {
				$total++;

				if (!cloud_has_docblock($tokens, $modStart, $propAllowed)) {
					$missing[] = sprintf('%s:%d property %s', $relativePath, $line, $tok[1]);
				}
			}
		}
	}

	return ['total' => $total, 'missing' => $missing];
}

it('documents every class, interface, trait, enum, constant, property, method, and function in production PHP', function () {
	$files   = cloud_phpdoc_sources();
	$total   = 0;
	$missing = [];

	expect($files)->not->toBeEmpty();

	foreach ($files as $file) {
		$result  = cloud_scan_file_phpdoc($file);
		$total  += $result['total'];
		$missing = array_merge($missing, $result['missing']);
	}

	expect($total)->toBeGreaterThan(0)
		->and($missing)->toBe([], "Missing PHPDoc on:\n" . implode("\n", $missing));
});
