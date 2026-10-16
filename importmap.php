<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    'easymde' => ['version' => '2.21.0'],
    'easymde/dist/easymde.min.css' => ['version' => '2.21.0', 'type' => 'css'],
    'codemirror' => ['version' => '5.65.21'],
    'codemirror/addon/edit/continuelist.js' => ['version' => '5.65.21'],
    'codemirror/addon/display/fullscreen.js' => ['version' => '5.65.21'],
    'codemirror/mode/markdown/markdown.js' => ['version' => '5.65.21'],
    'codemirror/addon/mode/overlay.js' => ['version' => '5.65.21'],
    'codemirror/addon/display/placeholder.js' => ['version' => '5.65.21'],
    'codemirror/addon/display/autorefresh.js' => ['version' => '5.65.21'],
    'codemirror/addon/selection/mark-selection.js' => ['version' => '5.65.21'],
    'codemirror/addon/search/searchcursor.js' => ['version' => '5.65.21'],
    'codemirror/mode/gfm/gfm.js' => ['version' => '5.65.21'],
    'codemirror/mode/xml/xml.js' => ['version' => '5.65.21'],
    'codemirror-spell-checker' => ['version' => '1.1.2'],
    'marked' => ['version' => '4.3.0'],
    'typo-js' => ['version' => '1.3.2'],
    'codemirror/lib/codemirror.min.css' => ['version' => '5.65.21', 'type' => 'css'],
];
