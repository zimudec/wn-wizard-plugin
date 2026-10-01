<?php

namespace Zimudec\Wizard\Tests;

use Cms\Classes\Theme;

abstract class BaseTestCase extends \System\Tests\Bootstrap\PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->setThemesPath(plugins_path('zimudec/wizard/tests/fixtures/themes'));
        Theme::setActiveTheme('wizard-fixture');
    }

    public function ajaxRequest($url, $handler, $data = [])
    {
        $headers = [
            'X-WINTER-REQUEST-HANDLER' => $handler,
            'X-Requested-With' => 'XMLHttpRequest',
            'X-WINTER-REQUEST-PARTIALS' => '',
        ];

        return $this->withHeaders($headers)->post($url, $data);
    }

    public function assertAjaxException($response, $errorFields)
    {
        $exception = $response->exception;
        $this->assertInstanceOf(\Winter\Storm\Exception\AjaxException::class, $exception);
        $this->assertEquals($errorFields, $exception->getContents()['X_WINTER_ERROR_FIELDS']);
    }

    /**
     * The session key of the wizard declared by a page. The component scopes
     * its state by page and by alias, so a test that seeds or reads the
     * session has to name the page it is acting on.
     */
    protected function wizardSessionKey(string $page, string $alias = 'wizard'): string
    {
        return 'zimudec.wizard.' . $page . '.' . $alias;
    }
}