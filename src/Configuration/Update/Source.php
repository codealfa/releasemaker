<?php
/**
 * @package    AkeebaReleaseMaker
 * @copyright  Copyright (c)2012-2021 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    GNU General Public License version 3, or later
 */

namespace Akeeba\ReleaseMaker\Configuration\Update;


use Akeeba\ReleaseMaker\Configuration\Configuration;
use Akeeba\ReleaseMaker\Contracts\Uploader;
use Akeeba\ReleaseMaker\Exception\InvalidChangelogUrl;
use Akeeba\ReleaseMaker\Exception\InvalidConnectionKey;
use Akeeba\ReleaseMaker\Exception\InvalidUpdateFormat;
use Akeeba\ReleaseMaker\Exception\InvalidUpdateStream;
use Akeeba\ReleaseMaker\Mixin\MagicGetterAware;

/**
 * Update Source
 *
 * @property-read string      $title          The title for this update source.
 * @property-read string      $connectionName The name (key) of the connection used to upload this update stream.
 * @property-read string|null $directory      The directory override for the connection.
 * @property-read int         $stream         ARS update stream ID.
 * @property-read string      $baseName       Base name for the update stream file on the remote server.
 * @property-read string[]    $formats        Update formats, one or more of xml, ini, inibare and changelog.
 * @property-read string|null $changelogUrl   Public URL of the uploaded changelog file, written into the XML stream.
 * @property-read Uploader    $uploader       The Uploader object to be used for this update source.
 *
 * @since  2.0.0
 */
class Source
{
	use MagicGetterAware;

	private string $title;

	private string $connectionName;

	private ?string $directory;

	private int $stream;

	private string $baseName;

	private array $formats = [];

	private ?string $changelogUrl;

	private Configuration $parent;

	public function __construct(array $configuration, Configuration $parent)
	{
		$this->parent         = $parent;
		$this->stream         = (int) ($configuration['stream'] ?? 0);
		$this->title          = $configuration['title'] ?? sprintf('Updates for stream %d', $this->stream);
		$this->connectionName = $configuration['connection'] ?? '';
		$this->directory      = $configuration['directory'] ?? null;
		$this->baseName       = $configuration['base_name'] ?? sprintf('stream-%s', $this->stream);
		$this->formats        = $configuration['formats'] ?? ['xml'];
		$this->changelogUrl   = trim($configuration['changelog_url'] ?? '') ?: null;

		if ($this->stream <= 0)
		{
			throw new InvalidUpdateStream($this->stream);
		}

		if (!in_array($this->connectionName, $this->parent->connection->getConnectionKeys()))
		{
			throw new InvalidConnectionKey($this->connectionName);
		}

		if (empty($this->formats))
		{
			$this->formats = ['xml'];
		}

		$this->formats = array_unique($this->formats);

		foreach ($this->formats as $format)
		{
			if (!in_array($format, ['xml', 'ini', 'inibare', 'changelog']))
			{
				throw new InvalidUpdateFormat($format);
			}
		}

		// Joomla doesn't resolve relative URLs and opens an empty modal for anything but an HTTP 200.
		if (
			$this->changelogUrl !== null
			&& (
				!filter_var($this->changelogUrl, FILTER_VALIDATE_URL)
				|| !in_array(strtolower(parse_url($this->changelogUrl, PHP_URL_SCHEME) ?: ''), ['http', 'https'])
			)
		)
		{
			throw new InvalidChangelogUrl($this->changelogUrl);
		}
	}

	/** @noinspection PhpUnusedPrivateMethodInspection */
	private function getUploader(): Uploader
	{
		$connection = $this->parent->connection->getConnection($this->connectionName);

		return $connection->getUploader($this->directory);
	}
}