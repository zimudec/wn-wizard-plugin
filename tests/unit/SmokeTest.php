<?php

namespace Zimudec\Wizard\Tests\Unit;

use System\Classes\PluginManager;
use Zimudec\Wizard\Tests\BaseTestCase;

class SmokeTest extends BaseTestCase
{
    public function testPluginIsRegistered()
    {
        $this->assertTrue(PluginManager::instance()->hasPlugin('Zimudec.Wizard'));
    }
}
