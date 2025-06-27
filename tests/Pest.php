<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide here will be used as the base test case for
| all feature tests. You are free to add your own helper methods to
| this file to share between your tests.
|
*/

// Primero, arrancamos la aplicación Webman.
// Esto cargará el .env, las funciones de ayuda y otras configuraciones.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../support/bootstrap.php';


// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet
| certain conditions. Pest provides a powerful set of expectations
| to assert that values are equal, null, false, etc.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing
| helpers that you use frequently. You can add your own custom logic
| to this file and reuse it across your tests.
|
*/

/**
 * Recursively deletes a directory.
 * This is a cross-platform replacement for `rm -rf`.
 *
 * @param string $dirPath
 * @return void
 */
function delete_directory_recursively(string $dirPath): void
{
    if (!is_dir($dirPath)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }

    rmdir($dirPath);
}

/**
 * Helper to create a dummy file and its directory if it doesn't exist.
 *
 * @param string $path
 * @param string $content
 * @return void
 */
function create_dummy_file(string $path, string $content = 'dummy_content'): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
}