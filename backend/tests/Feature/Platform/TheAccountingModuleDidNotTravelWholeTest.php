<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Accounting was copied into this repo on 2026-09-05 from the build that owns
 * it. The copy took `app/Modules/Accounting/**` -- 82 files, whole, including
 * the module's own twelve migrations.
 *
 * Four of the module's tables are not created by those twelve. Their migrations
 * live in the source build's `database/migrations/`, outside the module folder,
 * and so did not come. Purchase Orders, Recurring Invoices, Bank Reconciliation
 * and Revenue Schedules arrived as models, services, controllers, routes and
 * screens -- with no tables underneath them.
 *
 * Nothing announced it. The list endpoints threw `no such table` and the
 * frontend rendered the thrown request as its empty state: "No purchase orders
 * yet." A 500 wearing the face of a working, unused feature -- the same lie as
 * the `200 {"data":[]}` that hid the missing chart of accounts, told louder.
 *
 * This is the seventh piece of Accounting found living outside the module it
 * belongs to (after `acc_admin`, the onboarding provisioning call and the
 * `GenerateRecurringInvoices` command). So this test does not name the four.
 * It asks the only question that keeps working: does every model in the module
 * have the table it reads from? Port a module, forget a migration, and this
 * fails on the model -- not on a screen, in front of the client.
 */
class TheAccountingModuleDidNotTravelWholeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Model class names, read straight off disk. A data provider runs before
     * the application boots, so this may not touch `app_path()` or the container.
     *
     * @return array<string, array{0: string}>
     */
    public static function accountingModels(): array
    {
        $dir = dirname(__DIR__, 3).'/app/Modules/Accounting/Models';
        $cases = [];

        foreach (scandir($dir) ?: [] as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }

            $name = basename($file, '.php');
            $cases[$name] = ['App\\Modules\\Accounting\\Models\\'.$name];
        }

        return $cases;
    }

    #[DataProvider('accountingModels')]
    public function test_every_model_in_the_module_has_the_table_it_reads_from(string $class): void
    {
        $table = (new $class)->getTable();

        $this->assertTrue(
            Schema::hasTable($table),
            "{$class} reads from `{$table}`, which no migration in this repo creates. "
            .'Its migration is probably still in the build Accounting was copied from.',
        );
    }
}
