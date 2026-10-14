<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Update;

use Mfonte\Arkhive\Exceptions\ArkhiveException;
use Mfonte\Arkhive\Exceptions\NoInputException;
use Mfonte\Arkhive\Exceptions\ProtocolException;

/**
 * One GitHub release, from the REST API JSON.
 */
final readonly class Release
{
    /**
     * @param array<string, array{url: string, size: int}> $assets by name
     */
    public function __construct(
        public string $tag,
        public string $version,
        public bool $prerelease,
        public bool $draft,
        public array $assets,
        public string $htmlUrl,
    ) {
    }

    /**
     * @throws ArkhiveException not a release object, or a tag that is not a semantic version
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data) || !isset($data['tag_name']) || !is_string($data['tag_name'])) {
            throw new ProtocolException('The releases API answered something that is not a release');
        }
        $version = Version::normalize($data['tag_name']);
        if ($version === null) {
            throw new ProtocolException("The release tag {$data['tag_name']} is not a semantic version");
        }
        $assets = [];
        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['browser_download_url'] ?? null)) {
                $assets[$asset['name']] = ['url' => $asset['browser_download_url'], 'size' => is_int($asset['size'] ?? null) ? $asset['size'] : 0];
            }
        }

        return new self(
            $data['tag_name'],
            $version,
            ($data['prerelease'] ?? false) === true,
            ($data['draft'] ?? false) === true,
            $assets,
            is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
        );
    }

    /**
     * @return array{url: string, size: int}
     *
     * @throws ArkhiveException
     */
    public function asset(string $name): array
    {
        if (!isset($this->assets[$name])) {
            throw new NoInputException("The release {$this->tag} has no {$name} asset: it cannot be installed", hint: 'Pick another release (arkhive self-update <tag>), or report it at https://github.com/mauriziofonte/arkhive/issues');
        }

        return $this->assets[$name];
    }
}
