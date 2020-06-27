<?php

namespace Agnes\Models\Policies;

use Agnes\Models\Filter;
use Agnes\Services\Policy\PolicyVisitor;
use Exception;

class ReleaseWhitelistPolicy extends Policy
{
    /**
     * @var string[]
     */
    private $commitishes;

    /**
     * ReleaseWhitelistPolicy constructor.
     */
    public function __construct(?Filter $filter, array $commitishes)
    {
        parent::__construct($filter);

        $this->commitishes = $commitishes;
    }

    /**
     * @return bool
     *
     * @throws Exception
     */
    public function accept(PolicyVisitor $visitor)
    {
        return $visitor->visitReleaseWhitelist($this);
    }

    /**
     * @return string[]
     */
    public function getCommitishes(): array
    {
        return $this->commitishes;
    }
}
