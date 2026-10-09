<?php

/**
 * @file tools/check-locale-keys.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Checks that every locale key this plugin references is defined, and reports
 * keys that are defined but never used. Missing keys would render as raw
 * identifiers in the interface, which is the kind of thing nobody notices
 * until a journal manager does.
 *
 * Usage: php tools/check-locale-keys.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Key prefixes this plugin owns. */
const OWNED_PREFIXES = ['plugins.generic.contactForm.', 'emails.contactForm'];

/** Directories that never contain shipped references. */
const SKIPPED_DIRS = ['ojs', 'build', 'node_modules', 'vendor', '.git'];

/**
 * @return string[]
 */
function collectFiles(string $root, array $extensions): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) {
                return !$file->isDir() || !in_array($file->getFilename(), SKIPPED_DIRS, true);
            }
        )
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);
    return $files;
}

function isOwned(string $key): bool
{
    foreach (OWNED_PREFIXES as $prefix) {
        if (str_starts_with($key, $prefix)) {
            return true;
        }
    }
    return false;
}

// Keys referenced from code and templates.
$used = [];
foreach (collectFiles($root, ['php', 'tpl', 'xml', 'js']) as $file) {
    if (str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'tools')) {
        continue;
    }

    preg_match_all('/[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_-]*)+/', (string) file_get_contents($file), $matches);
    foreach ($matches[0] as $candidate) {
        $candidate = rtrim($candidate, '.');
        if (isOwned($candidate)) {
            $used[$candidate][$file] = true;
        }
    }
}

// Keys defined in the PO files.
$defined = [];
foreach (collectFiles($root . '/locale', ['po']) as $file) {
    preg_match_all('/^msgid "([^"]+)"/m', (string) file_get_contents($file), $matches);
    foreach ($matches[1] as $key) {
        if (isOwned($key)) {
            $defined[$key] = $file;
        }
    }
}

$missing = array_diff(array_keys($used), array_keys($defined));
$unused = array_diff(array_keys($defined), array_keys($used));

sort($missing);
sort($unused);

foreach ($missing as $key) {
    echo "MISSING  {$key}\n";
    foreach (array_keys($used[$key]) as $file) {
        echo '           used in ' . substr($file, strlen($root) + 1) . "\n";
    }
}

foreach ($unused as $key) {
    echo "UNUSED   {$key}\n";
}

printf(
    "%d keys referenced, %d defined, %d missing, %d unused.\n",
    count($used),
    count($defined),
    count($missing),
    count($unused)
);

// Unused keys are untidy but harmless; missing ones are a visible bug.
exit($missing === [] ? 0 : 1);
