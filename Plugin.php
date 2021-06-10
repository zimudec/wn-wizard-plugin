<?php

namespace Zimudec\Wizard;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function registerComponents()
    {
        return [
            'Zimudec\Wizard\Components\Wizard' => 'wizard'
        ];
    }

    public function registerSettings()
    {
    }
}
