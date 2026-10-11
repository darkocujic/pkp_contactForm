<?php

/**
 * @file tools/extract-changelog.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Prints the CHANGELOG.md section for one version. Exits non-zero with
 * nothing on stdout if that version has no section yet, so the release
 * workflow can fall back to something else rather than publish an empty
 * release.
 *
 * Usage: php tools/extract-changelog.php <version>
 */

declare(strict_types=1);

$version = $argv[1] ?? '';
if ($version === '') {
    fwrite(STDERR, "Usage: php tools/extract-changelog.php <version>\n");
    exit(2);
}

$path = dirname(__DIR__) . '/CHANGELOG.md';
$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read {$path}\n");
    exit(2);
}

// Plain string matching throughout — no regex metacharacters to worry about
// escaping, since version numbers are full of literal dots.
$heading = '## [' . $version . ']';
$inSection = false;
$section = [];

foreach ($lines as $line) {
    if (str_starts_with($line, '## ')) {
        if ($inSection) {
            break; // the next version heading ends this one
        }
        if (str_starts_with($line, $heading)) {
            $inSection = true;
        }
        continue;
    }
    if ($inSection) {
        $section[] = $line;
    }
}

$text = trim(implode("\n", $section));
if ($text === '') {
    exit(1);
}

echo $text, "\n";
