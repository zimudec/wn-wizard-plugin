<?php

namespace Zimudec\Wizard\Support;

use Winter\Storm\Exception\ApplicationException;

/**
 * Declares that the wizard configuration violates one of its requirements.
 * The message is translated and explains how to correct the configuration.
 */
class ConfigurationException extends ApplicationException
{
}
