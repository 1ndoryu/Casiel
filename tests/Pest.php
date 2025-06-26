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

function something()
{
    // ..
}
