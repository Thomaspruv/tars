<?php

use App\Enums\GitSyncStatus;
use App\Support\Brain\GitPullResult;
use App\Support\Brain\GitRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
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
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Mocks the vault's git repository with the surface every machine write
 * touches before it writes anything — is this a repository, pull before
 * writing, push afterwards — so a test only has to declare the writes it
 * actually cares about (`commit`, `commitPaths`).
 *
 * @param  ?Closure(MockInterface): void  $expectations
 */
function mockVaultGit(?Closure $expectations = null): void
{
    test()->mock(GitRepository::class, function (MockInterface $mock) use ($expectations): void {
        // byDefault() so a test that cares about one of these can still
        // declare its own expectation and have it win.
        $mock->shouldReceive('isRepository')->andReturn(true)->byDefault();
        $mock->shouldReceive('pullRebase')->andReturn(new GitPullResult(successful: true, message: ''))->byDefault();
        $mock->shouldReceive('push')->andReturnNull()->byDefault();
        $mock->shouldReceive('lastPushAt')->andReturnNull()->byDefault();
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced)->byDefault();

        $expectations?->__invoke($mock);
    });
}
