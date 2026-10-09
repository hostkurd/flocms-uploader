<?php
declare(strict_types=1);

namespace FloCMS\Uploader\Exceptions;

/**
 * A chunk was sent for the wrong position. The client should resume from
 * expectedOffset() (the number of bytes the server already has).
 */
class ChunkOffsetException extends ValidationException
{
    public function __construct(private readonly int $expectedOffset, int $givenOffset)
    {
        parent::__construct(sprintf(
            'Chunk offset %d does not match the %d bytes already received.',
            $givenOffset,
            $expectedOffset
        ));
    }

    public function expectedOffset(): int
    {
        return $this->expectedOffset;
    }
}
