<?php

declare(strict_types=1);

namespace ApiPlatform\Hydra;

use Symfony\Component\SerDes\Attribute\Name;
use Symfony\Component\SerDes\Attribute\Serializable;


/**
 * @template T of object
 */
#[Serializable]
class Collection
{
    #[Name('hydra:member')]
    /** @var list<T> */
    public array $partialCollection;

    #[Name('@type')]
    public string $type = 'hydra:Collection';

    #[Name('hydra:totalItems')]
    public int $totalItems = 0;
}
