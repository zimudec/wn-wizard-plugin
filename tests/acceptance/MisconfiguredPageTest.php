<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Support\ConfigurationException;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Explicit configuration errors: a page whose URL does not declare the
 * optional step parameter cannot host the wizard (bug B5 in 1.0.7 printed a
 * dump() and let the request continue); 2.0 fails fast with a clear,
 * translated exception and no debug output in the response.
 */
class MisconfiguredPageTest extends BaseTestCase
{
    public function test_page_without_step_parameter_fails_with_a_clear_exception()
    {
        $response = $this->get('/level-bad');

        $exception = $response->exception;
        $this->assertInstanceOf(ConfigurationException::class, $exception);
        $this->assertStringContainsString('optional step parameter', $exception->getMessage());

        // No debug output in the response (no dump()).
        $content = $response->getContent();
        $this->assertStringNotContainsString('You must add', $content);
        $this->assertStringNotContainsString('screenshot-debug', $content);
    }
}
