<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Dto;

use InvalidArgumentException;

/**
 * A link to an entity of the project, e.g. a stage. The service keeps it as is; `label` is the caption at the moment
 * of writing, the project shows its own current name.
 */
final class ExternalRef
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?string $label = null,
    ) {
        if ($type === '' || str_contains($type, ':') || $id === '' || str_contains($id, '/')) {
            throw new InvalidArgumentException("Invalid external ref {$type}:{$id}");
        }
    }

    /**
     * @param  array{type: string, id: string|int, label?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['type'], (string) $data['id'], $data['label'] ?? null);
    }

    public static function fromKey(string $key): self
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, '');

        return new self($type, $id);
    }

    /**
     * `<type>:<id>`, as in paths and queries.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * @return array{type: string, id: string, label: string|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'label' => $this->label];
    }
}
