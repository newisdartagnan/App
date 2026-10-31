<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou : les tests vident et recréent la base (RefreshDatabase).
     * Ils refusent de démarrer sur une base qui n'est pas dédiée aux tests,
     * pour qu'une configuration mal surchargée ne puisse jamais effacer les
     * données d'un hôpital. Le suffixe _test_N couvre les tests parallèles.
     */
    protected function setUpTraits()
    {
        $base = (string) $this->app['db']->connection()->getDatabaseName();

        if ($base !== ':memory:' && ! preg_match('/_test(_\d+)?$/', $base)) {
            throw new RuntimeException(
                "Tests refusés sur la base « {$base} » : seule une base dont le nom finit par _test "
                .'peut être vidée par les tests (voir phpunit.xml et le README).'
            );
        }

        return parent::setUpTraits();
    }
}
