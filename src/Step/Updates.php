<?php
/**
 * @package    AkeebaReleaseMaker
 * @copyright  Copyright (c)2012-2021 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license    GNU General Public License version 3, or later
 */

namespace Akeeba\ReleaseMaker\Step;

use Akeeba\ReleaseMaker\Configuration\Configuration;
use Akeeba\ReleaseMaker\Configuration\Update\Source as UpdateSource;

class Updates extends AbstractStep
{
	/**
	 * ARS task and format for each update format.
	 */
	private const FORMATS = [
		'xml'       => ['stream', 'xml'],
		'ini'       => ['ini', 'ini'],
		'inibare'   => ['ini', 'ini'],
		'changelog' => ['changelog', 'xml'],
	];

	/**
	 * What follows the base name in the uploaded file's name.
	 */
	private const EXTENSIONS = [
		'xml'       => '.xml',
		'ini'       => '.ini',
		'inibare'   => '',
		'changelog' => '-changelog.xml',
	];

	public function execute(): void
	{
		$configuration = Configuration::getInstance();

		$this->io->section("Pushing update information");

		foreach ($configuration->updates->sources as $updateSource)
		{
			$this->io->writeln(sprintf('<info>Pushing updates: %s</info>', $updateSource->title));

			$this->pushUpdate($updateSource);
		}

		$this->io->newLine();
	}

	private function pushUpdate(UpdateSource $updateSource)
	{
		$configuration = Configuration::getInstance();

		if ((int) $updateSource->stream == 0)
		{
			$this->io->note('Will not fetch updates (no update stream ID was configured)');

			return;
		}

		if (($updateSource->baseName == '') || !count($updateSource->formats))
		{
			$this->io->note('Will not fetch updates (no base name or format was configured)');

			return;
		}

		$urlPattern = $configuration->api->endpoint . '?option=com_ars&view=update&task=%s&format=%s&id=' . $updateSource->stream;
		$formats    = $updateSource->formats;

		// Upload the changelog before the stream, so the stream never points to a changelog file which isn't there yet.
		usort($formats, function (string $a, string $b): int {
			return ($b === 'changelog') <=> ($a === 'changelog');
		});

		$changelogUploaded = false;

		foreach ($formats as $format)
		{
			[$task, $joomlaFormat] = self::FORMATS[$format];

			$url  = sprintf($urlPattern, $task, $joomlaFormat);
			$data = $this->fetch($url, $format);

			if ($data === null)
			{
				continue;
			}

			if ($format === 'xml' && $changelogUploaded && $updateSource->changelogUrl !== null)
			{
				$data = self::replaceChangelogUrl($data, $updateSource->changelogUrl);
			}

			$tempFilePointer = \tmpfile();
			$tempFilePath    = \stream_get_meta_data($tempFilePointer)['uri'];

			$written = fwrite($tempFilePointer, $data);

			if ($written === false)
			{
				continue;
			}

			$destName = $updateSource->baseName . self::EXTENSIONS[$format];

			$this->io->text(\sprintf("Pushing %s update format", $format));

			$updateSource->uploader->upload($tempFilePath, $destName);

			@fclose($tempFilePointer);

			$changelogUploaded = $changelogUploaded || ($format === 'changelog');
		}

		if (in_array('changelog', $formats) && $updateSource->changelogUrl === null && in_array('xml', $formats))
		{
			$this->io->note('The XML stream still links to the changelog in ARS. Set changelog_url to link to the uploaded file instead.');
		}
	}

	/**
	 * Point every <changelogurl> of an XML update stream to the static changelog file.
	 *
	 * ARS writes the URL of its own changelog endpoint, which is right for the stream ARS serves but not for the static
	 * copy we upload.
	 *
	 * @param   string  $xml           The XML update stream as ARS returned it.
	 * @param   string  $changelogUrl  The public URL of the uploaded changelog file.
	 *
	 * @return  string
	 */
	public static function replaceChangelogUrl(string $xml, string $changelogUrl): string
	{
		$escaped = htmlspecialchars($changelogUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');

		return preg_replace_callback(
			'#<changelogurl>[^<]*</changelogurl>#',
			function () use ($escaped): string {
				return '<changelogurl>' . $escaped . '</changelogurl>';
			},
			$xml
		) ?? $xml;
	}

	/**
	 * Fetch one update format from ARS.
	 *
	 * @param   string  $url     The ARS URL to read.
	 * @param   string  $format  The update format, for the error message.
	 *
	 * @return  string|null  The response body. NULL when ARS can't be reached or doesn't answer with HTTP 200.
	 */
	private function fetch(string $url, string $format): ?string
	{
		$configuration = Configuration::getInstance();

		// The site redirects public update stream requests to static XML files; this user agent lets us reach ARS.
		$context = \stream_context_create([
			'http' => [
				'method'          => 'GET',
				'user_agent'      => 'AkeebaReleaseMaker/2.0',
				'follow_location' => 0,
				'ignore_errors'   => true,
			],
			'ssl'  => [
				'verify_peer'  => true,
				'cafile'       => $configuration->api->CACertPath,
				'verify_depth' => 5,
			],
		]);
		$data    = @\file_get_contents($url, false, $context);

		if ($data === false)
		{
			$this->io->error(sprintf('Not pushing %s update format: could not read %s', $format, $url));

			return null;
		}

		$statusLine = $http_response_header[0] ?? '';

		if (!preg_match('#^HTTP/\S+\s+200\b#', $statusLine))
		{
			$this->io->error(sprintf('Not pushing %s update format: %s returned ‘%s’', $format, $url, $statusLine));

			return null;
		}

		return $data;
	}

}
