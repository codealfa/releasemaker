<?php
/**
 * @package    AkeebaReleaseMaker
 * @copyright  Copyright (c)2012-2021 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    GNU General Public License version 3, or later
 */

namespace Akeeba\ReleaseMaker\Exception;


use Akeeba\ReleaseMaker\Contracts\ExceptionCode;
use Throwable;

/**
 * Configuration exception: Invalid public URL of the static changelog file
 *
 * @since  2.0.0
 */
class InvalidChangelogUrl extends ConfigurationError
{
	public function __construct(string $url, Throwable $previous = null)
	{
		$message = sprintf("Invalid changelog URL ‘%s’. It must be the absolute http(s) URL the uploaded changelog file is served from.", $url);
		$code    = ExceptionCode::CONFIG_INVALID_CHANGELOG_URL;

		parent::__construct($message, $code, $previous);
	}
}
